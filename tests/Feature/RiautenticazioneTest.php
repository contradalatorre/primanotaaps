<?php

namespace Tests\Feature;

use App\Models\Ruolo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

class RiautenticazioneTest extends TestCase
{
    use RefreshDatabase;

    private function googleCome(string $id = '1001'): void
    {
        Socialite::fake('google', GoogleUser::fake([
            'id' => $id, 'name' => 'Anna Bianchi', 'email' => 'anna.bianchi@associazione.example',
            'hd' => 'associazione.example', 'email_verified' => true, 'refreshToken' => 'token',
        ]));
    }

    private function amministratore(): User
    {
        return $this->utenteCon('Amministratore', [], ['google_id' => '1001', 'email' => 'anna.bianchi@associazione.example']);
    }

    private function nuovoEsterno(): array
    {
        return ['name' => 'Paolo Verdi', 'email' => 'paolo@studio.example', 'ruolo_id' => $this->ruoloId('Lettore esterno'), 'scade_il' => now()->addMonth()->toDateString()];
    }

    private function ruoloId(string $nome): int
    {
        return Ruolo::where('nome', $nome)->value('id');
    }

    public function test_dopo_un_accesso_google_si_gestiscono_gli_utenti_ma_non_dopo_un_ora(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'x'])]);
        $this->amministratore();
        $this->googleCome();
        $this->get(route('accesso.ritorno'))->assertRedirect(route('home'));

        $this->post('/utenti/esterni', $this->nuovoEsterno())->assertRedirect(route('utenti'));
        $this->assertDatabaseHas('users', ['email' => 'paolo@studio.example']);

        $this->travel(61)->minutes();
        $altro = ['email' => 'luca@studio.example'] + $this->nuovoEsterno();
        $this->from('/utenti')->post('/utenti/esterni', $altro)->assertRedirect(route('riautentica'));
        $this->assertDatabaseMissing('users', ['email' => 'luca@studio.example']);
    }

    public function test_le_pagine_di_sola_lettura_non_chiedono_la_riautenticazione(): void
    {
        $this->actingAs($this->amministratore())->withSession(['auth.password_confirmed_at' => now()->subDay()->unix()]);

        $this->get('/utenti')->assertOk();
        $this->get('/ruoli')->assertOk();
    }

    public function test_chi_non_ha_il_permesso_resta_con_il_403_anche_con_l_accesso_vecchio(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'))->withSession(['auth.password_confirmed_at' => now()->subDay()->unix()]);

        $this->post('/utenti/esterni', $this->nuovoEsterno())->assertForbidden();
    }

    public function test_la_pagina_propone_di_rifare_l_accesso_con_google(): void
    {
        $this->actingAs($this->amministratore())->get(route('riautentica'))
            ->assertOk()->assertSee(route('accesso.google', ['riautentica' => 1]), false);
    }

    public function test_la_riautenticazione_manda_a_google_chiedendo_di_rifare_l_accesso(): void
    {
        $url = $this->actingAs($this->amministratore())->get(route('accesso.google', ['riautentica' => 1]))
            ->assertRedirect()->headers->get('Location');

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('login', $query['prompt']);
        $this->assertSame('anna.bianchi@associazione.example', $query['login_hint']);
    }

    public function test_tornando_da_google_con_lo_stesso_account_si_riprende_da_dove_si_era(): void
    {
        $this->actingAs($this->amministratore())->withSession(['auth.password_confirmed_at' => now()->subHours(2)->unix()]);
        $this->from('/utenti')->post('/utenti/esterni', $this->nuovoEsterno())->assertRedirect(route('riautentica'));
        $this->get(route('accesso.google', ['riautentica' => 1]));
        $this->googleCome();

        $this->get(route('accesso.ritorno'))->assertRedirect('/utenti');

        $this->post('/utenti/esterni', $this->nuovoEsterno())->assertRedirect(route('utenti'));
        $this->assertDatabaseHas('users', ['email' => 'paolo@studio.example']);
    }

    public function test_tornando_da_google_con_un_altro_account_non_si_riautentica_nessuno(): void
    {
        $admin = $this->amministratore();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->subHours(2)->unix()]);
        $this->get(route('accesso.google', ['riautentica' => 1]));
        $this->googleCome('altro-id');

        $this->get(route('accesso.ritorno'))->assertRedirect(route('riautentica'))->assertSessionHas('errore');

        $this->assertAuthenticatedAs($admin);
        $this->post('/utenti/esterni', $this->nuovoEsterno())->assertRedirect(route('riautentica'));
    }

    public function test_il_ritorno_da_google_senza_una_riautenticazione_richiesta_non_riautentica(): void
    {
        $this->actingAs($this->amministratore())->withSession(['auth.password_confirmed_at' => now()->subHours(2)->unix()]);
        $this->googleCome();

        $this->get(route('accesso.ritorno'))->assertRedirect(route('home'));

        $this->post('/utenti/esterni', $this->nuovoEsterno())->assertRedirect(route('riautentica'));
    }
}
