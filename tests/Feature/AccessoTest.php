<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use RuntimeException;
use Tests\TestCase;

class AccessoTest extends TestCase
{
    use RefreshDatabase;

    /** Account di Google come lo restituisce il Workspace: `hd` è il dominio del Workspace. */
    private function googleCome(array $attributi = []): void
    {
        Socialite::fake('google', GoogleUser::fake($attributi + [
            'id' => '1001',
            'name' => 'Maria Rossi',
            'email' => 'maria.rossi@associazione.example',
            'hd' => 'associazione.example',
            'email_verified' => true,
        ]));
    }

    private function entra()
    {
        return $this->get(route('accesso.ritorno'));
    }

    public function test_la_home_e_protetta(): void
    {
        $this->get(route('home'))->assertRedirect(route('accesso'));
    }

    public function test_la_pagina_di_accesso_invita_a_entrare_con_google(): void
    {
        $this->get(route('accesso'))
            ->assertOk()
            ->assertSee('Entra con Google')
            ->assertSee(route('accesso.google'), false);
    }

    public function test_un_account_del_workspace_entra_e_vede_la_home(): void
    {
        $this->googleCome();

        $this->entra()->assertRedirect(route('home'));

        $this->assertDatabaseHas('users', ['google_id' => '1001', 'email' => 'maria.rossi@associazione.example']);
        $this->get(route('home'))->assertOk()->assertSee('Maria Rossi');
    }

    public function test_chi_rientra_non_crea_un_secondo_utente_e_aggiorna_nome_ed_email(): void
    {
        User::factory()->create(['google_id' => '1001', 'name' => 'M. Rossi', 'email' => 'm.rossi@associazione.example']);
        $this->googleCome();

        $this->entra();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['google_id' => '1001', 'email' => 'maria.rossi@associazione.example', 'name' => 'Maria Rossi']);
    }

    public function test_un_account_ricreato_con_la_stessa_email_ma_un_altro_id_google_entra_sullo_stesso_utente(): void
    {
        $esistente = User::factory()->create(['google_id' => 'vecchio', 'email' => 'maria.rossi@associazione.example']);
        $this->googleCome();

        $this->entra()->assertRedirect(route('home'));

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $esistente->id, 'google_id' => '1001']);
    }

    public function test_un_account_di_un_altro_dominio_e_rifiutato_con_un_messaggio_chiaro(): void
    {
        $this->googleCome(['email' => 'maria@gmail.com', 'hd' => null]);

        $this->entra()->assertRedirect(route('accesso'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->get(route('home'))->assertRedirect(route('accesso'));
    }

    public function test_il_messaggio_di_rifiuto_compare_nella_pagina_di_accesso(): void
    {
        $this->googleCome(['email' => 'maria@gmail.com', 'hd' => null]);

        $this->followingRedirects()->get(route('accesso.ritorno'))
            ->assertSee('Questo account non fa parte del Workspace dell’associazione', false)
            ->assertSee('@associazione.example');
    }

    public function test_un_dominio_simile_non_basta(): void
    {
        $this->googleCome(['email' => 'maria@associazione.example.altro.it', 'hd' => 'associazione.example.altro.it']);

        $this->entra()->assertRedirect(route('accesso'));
        $this->assertGuest();
    }

    public function test_l_email_non_verificata_e_rifiutata(): void
    {
        $this->googleCome(['email_verified' => false]);

        $this->entra()->assertRedirect(route('accesso'));
        $this->assertGuest();
    }

    public function test_senza_il_dominio_del_workspace_configurato_non_entra_nessuno(): void
    {
        config(['services.google.workspace_domain' => null]);
        $this->googleCome();

        $this->entra()->assertRedirect(route('accesso'));
        $this->assertGuest();
    }

    public function test_se_google_rifiuta_l_account_sospeso_o_eliminato_non_entra(): void
    {
        Socialite::fake('google', fn () => throw new RuntimeException('invalid_grant'));

        $this->followingRedirects()->entra()
            ->assertSee('sospeso o eliminato');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_una_richiesta_di_accesso_scaduta_non_accusa_l_account(): void
    {
        Socialite::fake('google', fn () => throw new InvalidStateException);

        $this->followingRedirects()->entra()
            ->assertSee('Riprova')
            ->assertDontSee('sospeso o eliminato');

        $this->assertGuest();
    }

    public function test_la_sessione_dura_30_giorni_sul_dispositivo(): void
    {
        $this->googleCome();

        $cookie = $this->entra()->getCookie(config('session.cookie'));

        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $cookie->getExpiresTime(), 60);
    }

    public function test_chi_e_dentro_e_porta_alla_home_dalla_pagina_di_accesso(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('accesso'))->assertRedirect(route('home'));
    }

    public function test_si_esce_dal_menu(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('esci'))->assertRedirect(route('accesso'));

        $this->assertGuest();
    }
}
