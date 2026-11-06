<?php

namespace Tests\Feature;

use App\Models\User;
use App\Permesso;
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

    public function test_un_google_id_nuovo_con_un_indirizzo_gia_presente_non_eredita_la_riga_di_un_altro(): void
    {
        User::factory()->create(['google_id' => 'vecchio', 'email' => 'maria.rossi@associazione.example']);
        $this->googleCome();

        $this->followingRedirects()->entra()->assertSee('rivolgiti a un Amministratore');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['google_id' => 'vecchio']);
    }

    public function test_un_indirizzo_aggiornato_che_collide_con_un_altro_utente_e_rifiutato_senza_errore(): void
    {
        User::factory()->create(['google_id' => '1001', 'email' => 'm.rossi@associazione.example']);
        User::factory()->create(['google_id' => '2002', 'email' => 'maria.rossi@associazione.example']);
        $this->googleCome();

        $this->followingRedirects()->entra()->assertSee('rivolgiti a un Amministratore');

        $this->assertGuest();
        $this->assertDatabaseHas('users', ['google_id' => '1001', 'email' => 'm.rossi@associazione.example']);
    }

    public function test_chi_annulla_sulla_schermata_di_google_non_e_accusato_di_essere_sospeso(): void
    {
        $this->followingRedirects()->get(route('accesso.ritorno', ['error' => 'access_denied']))
            ->assertSee('Accesso annullato')
            ->assertDontSee('sospeso o eliminato');

        $this->assertGuest();
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

    // Primo Amministratore: l'app parte senza nessuno e su Aruba non c'è una riga di comando per crearlo.
    // Solo avvio a freddo: il recupero quando non resta nessun Amministratore è dell'account di emergenza (#36).

    public function test_chi_e_nell_elenco_iniziale_diventa_amministratore_se_non_ce_ne_sono(): void
    {
        config(['prima_nota.amministratori_iniziali' => ['maria.rossi@associazione.example']]);
        $this->googleCome();

        $this->entra();

        $this->assertTrue(User::firstWhere('google_id', '1001')->puo(Permesso::GestireUtenti));
    }

    public function test_l_elenco_iniziale_non_vale_se_un_amministratore_c_e_gia(): void
    {
        config(['prima_nota.amministratori_iniziali' => ['maria.rossi@associazione.example']]);
        $this->utenteCon('Amministratore');
        $this->googleCome();

        $this->entra();

        $this->assertFalse(User::firstWhere('google_id', '1001')->puo(Permesso::Vedere));
    }

    public function test_con_tutti_gli_amministratori_scaduti_l_elenco_iniziale_non_riscatta_in_silenzio(): void
    {
        // Il recupero senza Amministratori attivi è dell'account di emergenza (#36), con registro ed email.
        config(['prima_nota.amministratori_iniziali' => ['maria.rossi@associazione.example']]);
        $this->utenteCon('Amministratore', ['scade_il' => now()->subDay()->toDateString()]);
        $this->googleCome();

        $this->entra();

        $this->assertFalse(User::firstWhere('google_id', '1001')->puo(Permesso::GestireUtenti));
    }

    public function test_un_indirizzo_aggiunto_senza_google_id_che_collide_e_rifiutato_senza_errore(): void
    {
        User::factory()->create(['google_id' => null, 'email' => 'maria.rossi@associazione.example']);
        $this->googleCome();

        $this->followingRedirects()->entra()->assertSee('rivolgiti a un Amministratore');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_chi_non_e_nell_elenco_iniziale_non_diventa_amministratore(): void
    {
        config(['prima_nota.amministratori_iniziali' => ['altro@associazione.example']]);
        $this->googleCome();

        $this->entra();

        $this->assertFalse(User::firstWhere('google_id', '1001')->puo(Permesso::Vedere));
    }

    // Account esterni (commercialista, revisore): aggiunti dall'Amministratore, entrano con un Google personale.

    private function esternoInvitato(array $attributi = []): User
    {
        return $this->utenteCon('Lettore esterno', ['scade_il' => now()->addMonth()->toDateString()], $attributi + [
            'esterno' => true, 'google_id' => null, 'name' => 'Paolo Verdi', 'email' => 'paolo@studio.example',
        ]);
    }

    private function googleEsterno(array $attributi = []): void
    {
        $this->googleCome($attributi + ['id' => '2002', 'name' => 'Paolo V.', 'email' => 'paolo@studio.example', 'hd' => null]);
    }

    public function test_un_esterno_aggiunto_entra_con_il_suo_account_personale_e_il_google_id_si_lega(): void
    {
        $this->esternoInvitato();
        $this->googleEsterno();

        $this->entra()->assertRedirect(route('home'));

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'paolo@studio.example', 'google_id' => '2002', 'esterno' => true, 'name' => 'Paolo Verdi']);
        $this->get(route('home'))->assertOk();
    }

    public function test_un_esterno_che_rientra_e_riconosciuto_dal_google_id(): void
    {
        $this->esternoInvitato(['google_id' => '2002']);
        $this->googleEsterno();

        $this->entra()->assertRedirect(route('home'));
        $this->assertAuthenticated();
    }

    public function test_un_altro_account_con_lo_stesso_indirizzo_non_prende_il_posto_dell_esterno(): void
    {
        $this->esternoInvitato(['google_id' => '2002']);
        $this->googleEsterno(['id' => '9999']);

        $this->entra()->assertRedirect(route('accesso'));
        $this->assertGuest();
    }

    public function test_un_esterno_con_la_nomina_scaduta_non_entra(): void
    {
        $esterno = $this->esternoInvitato();
        $esterno->assegnazioni()->update(['scade_il' => now()->subDay()->toDateString()]);
        $this->googleEsterno();

        $this->followingRedirects()->entra()->assertSee('nomina');

        $this->assertGuest();
        $this->assertDatabaseHas('users', ['email' => 'paolo@studio.example', 'google_id' => null]);
    }

    public function test_un_esterno_con_email_non_verificata_non_entra(): void
    {
        $this->esternoInvitato();
        $this->googleEsterno(['email_verified' => false]);

        $this->entra()->assertRedirect(route('accesso'));
        $this->assertGuest();
    }

    public function test_un_indirizzo_non_aggiunto_come_esterno_resta_rifiutato(): void
    {
        $this->esternoInvitato();
        $this->googleEsterno(['email' => 'altro@studio.example']);

        $this->entra()->assertRedirect(route('accesso'));
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_un_utente_interno_non_entra_con_un_account_personale(): void
    {
        User::factory()->create(['google_id' => null, 'email' => 'paolo@studio.example']);
        $this->googleEsterno();

        $this->entra()->assertRedirect(route('accesso'));
        $this->assertGuest();
    }

    public function test_un_esterno_non_diventa_amministratore_dall_elenco_iniziale(): void
    {
        config(['prima_nota.amministratori_iniziali' => ['paolo@studio.example']]);
        $this->esternoInvitato();
        $this->googleEsterno();

        $this->entra();

        $this->assertFalse(User::firstWhere('email', 'paolo@studio.example')->puo(Permesso::GestireUtenti));
    }

    public function test_l_accesso_per_esterni_non_restringe_la_scelta_al_dominio_del_workspace(): void
    {
        $this->assertStringContainsString('hd=associazione.example', $this->get(route('accesso.google'))->headers->get('Location'));
        $this->assertStringNotContainsString('hd=', $this->get(route('accesso.google', ['esterno' => 1]))->headers->get('Location'));
        $this->get(route('accesso'))->assertSee(route('accesso.google', ['esterno' => 1]), false);
    }

    public function test_l_elenco_iniziale_e_vuoto_di_serie(): void
    {
        $this->assertSame([], config('prima_nota.amministratori_iniziali'));
    }
}
