<?php

namespace Tests\Feature;

use App\Mail\AccessoDiEmergenza;
use App\Models\RegistroAccesso;
use App\Models\Ruolo;
use App\Models\User;
use App\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccessoEmergenzaTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'una frase lunghissima e inventata per i soli test';

    private string $segreto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->segreto = Totp::nuovoSegreto();
        config(['prima_nota.emergenza' => ['password_hash' => Hash::make(self::PASSWORD), 'totp_secret' => $this->segreto]]);
        Mail::fake();
    }

    private function codice(int $scarto = 0): string
    {
        return Totp::codice($this->segreto, intdiv(now()->unix(), 30) + $scarto);
    }

    private function entra(?string $password = self::PASSWORD, ?string $codice = null)
    {
        return $this->post(route('accesso.emergenza'), ['password' => $password, 'codice' => $codice ?? $this->codice()]);
    }

    public function test_senza_credenziali_configurate_l_accesso_di_emergenza_non_esiste(): void
    {
        foreach ([[], ['password_hash' => 'x'], ['totp_secret' => 'y']] as $parziale) {
            config(['prima_nota.emergenza' => $parziale + ['password_hash' => null, 'totp_secret' => null]]);

            $this->get(route('accesso.emergenza'))->assertNotFound();
            $this->entra()->assertNotFound();
            $this->get(route('accesso'))->assertDontSee(route('accesso.emergenza'), false);
        }
        $this->assertGuest();
    }

    public function test_la_pagina_di_accesso_rimanda_all_accesso_di_emergenza(): void
    {
        $this->get(route('accesso'))->assertSee(route('accesso.emergenza'), false);
        $this->get(route('accesso.emergenza'))->assertOk()->assertSee('name="password"', false)->assertSee('name="codice"', false);
    }

    public function test_password_e_codice_giusti_aprono_una_sessione_con_i_permessi_da_amministratore(): void
    {
        $this->entra()->assertRedirect(route('home'));

        $emergenza = User::firstWhere('emergenza', true);
        $this->assertAuthenticatedAs($emergenza);
        $this->get('/utenti')->assertOk();
        $this->get(route('home'))->assertOk(); // niente token Google: VerificaAccountGoogle non lo pretende
    }

    public function test_ogni_uso_finisce_nel_registro_degli_accessi(): void
    {
        $this->withHeader('User-Agent', 'Browser inventato')->entra();

        $riga = RegistroAccesso::sole();
        $this->assertSame('emergenza.accesso', $riga->evento);
        $this->assertSame(User::firstWhere('emergenza', true)->id, $riga->user_id);
        $this->assertSame('127.0.0.1', $riga->ip);
        $this->assertSame('Browser inventato', $riga->user_agent);
    }

    public function test_ogni_uso_avvisa_gli_amministratori_attivi_e_solo_loro(): void
    {
        $uno = $this->utenteCon('Amministratore');
        $due = $this->utenteCon('Amministratore', ['scade_il' => now()->addMonth()->toDateString()]);
        $this->utenteCon('Amministratore', ['scade_il' => now()->subDay()->toDateString()]); // scaduto
        $this->utenteCon('Tesoriere');

        $this->entra();

        Mail::assertSent(AccessoDiEmergenza::class, 1);
        Mail::assertSent(AccessoDiEmergenza::class, fn ($m) => $m->hasTo($uno->email) && $m->hasTo($due->email) && count($m->to) === 2
            && str_contains($m->render(), '127.0.0.1'));
    }

    public function test_senza_amministratori_attivi_l_avviso_va_a_chi_lo_era_e_la_scadenza_l_ha_tolto(): void
    {
        $scaduto = $this->utenteCon('Amministratore', ['scade_il' => now()->subDay()->toDateString()]);
        $this->utenteCon('Tesoriere');

        $this->entra()->assertRedirect(route('home'));

        Mail::assertSent(AccessoDiEmergenza::class, fn ($m) => $m->hasTo($scaduto->email) && count($m->to) === 1);
    }

    public function test_se_la_posta_non_funziona_si_entra_lo_stesso(): void
    {
        $this->utenteCon('Amministratore');
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Posta non raggiungibile'));

        $this->entra()->assertRedirect(route('home'));

        $this->assertAuthenticated();
        $this->assertSame(['emergenza.accesso'], RegistroAccesso::pluck('evento')->all());
    }

    public function test_password_o_codice_sbagliati_non_fanno_entrare_e_restano_nel_registro_senza_email(): void
    {
        $this->utenteCon('Amministratore');

        $this->entra('la password sbagliata')->assertRedirect(route('accesso.emergenza'))->assertSessionHas('errore');
        $this->entra(self::PASSWORD, $this->codice() === '000000' ? '111111' : '000000')->assertRedirect(route('accesso.emergenza'))->assertSessionHas('errore');

        $this->assertGuest();
        $this->assertSame(['emergenza.fallita', 'emergenza.fallita'], RegistroAccesso::pluck('evento')->all());
        $this->assertNull(RegistroAccesso::first()->user_id);
        $this->assertDatabaseMissing('users', ['emergenza' => true]);
        Mail::assertNothingSent();
    }

    public function test_un_codice_gia_usato_non_vale_due_volte(): void
    {
        $this->entra()->assertRedirect(route('home'));
        $this->post(route('esci'));

        $this->entra()->assertRedirect(route('accesso.emergenza'));

        $this->assertGuest();
    }

    public function test_i_tentativi_sono_limitati(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->entra('sbagliata')->assertRedirect(route('accesso.emergenza'));
        }

        $this->entra()->assertStatus(429);
        $this->assertGuest();
    }

    public function test_la_sessione_di_emergenza_dura_un_ora(): void
    {
        $this->entra();

        $this->travel(59)->minutes();
        $this->get(route('home'))->assertOk();

        $this->travel(2)->minutes();
        $this->get(route('home'))->assertRedirect(route('accesso.emergenza'));
        $this->assertGuest();
    }

    public function test_senza_amministratori_l_account_di_emergenza_ne_nomina_uno(): void
    {
        $persona = User::factory()->create();
        $this->entra();

        $this->post("/utenti/{$persona->id}/assegnazioni", ['ruolo_id' => Ruolo::where('nome', 'Amministratore')->value('id')])
            ->assertRedirect(route('utenti'));

        $this->assertTrue($persona->fresh()->puo(\App\Permesso::GestireUtenti));
    }

    public function test_l_account_di_emergenza_non_e_un_amministratore_ne_una_persona_da_gestire(): void
    {
        $this->entra();
        $emergenza = User::firstWhere('emergenza', true);

        $this->assertSame(0, User::amministratoriAttivi()->count());
        $this->get('/utenti')->assertOk()->assertDontSee($emergenza->email);
        $this->post("/utenti/{$emergenza->id}/assegnazioni", ['ruolo_id' => Ruolo::where('nome', 'Consigliere')->value('id')])->assertNotFound();
    }

    public function test_dopo_un_uso_e_dopo_un_altro_resta_una_sola_riga_di_emergenza(): void
    {
        $this->entra();
        $this->post(route('esci'));
        $this->travel(2)->minutes();
        $this->entra();

        $this->assertSame(1, User::where('emergenza', true)->count());
        $this->assertSame(2, RegistroAccesso::where('evento', 'emergenza.accesso')->count());
    }
}
