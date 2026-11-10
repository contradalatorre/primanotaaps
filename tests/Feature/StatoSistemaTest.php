<?php

namespace Tests\Feature;

use App\Mail\ErroreGrave;
use App\Models\Backup;
use App\Models\Conto;
use App\Models\Errore;
use App\Models\ImportEstratto;
use App\Models\Ruolo;
use App\Models\User;
use App\Onboarding\Prova;
use App\Permesso;
use App\RegistroErrori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\ConSpazioDiProva;
use Tests\TestCase;

/** Lo stato del sistema e il registro degli errori (#68). Dati inventati. */
class StatoSistemaTest extends TestCase
{
    use ConSpazioDiProva, RefreshDatabase;

    private User $amministratore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-10 10:00');
        Mail::fake();
        $this->amministratore = $this->utenteCon('Amministratore', [], ['name' => 'Anna Bianchi', 'email' => 'anna@associazione.example']);
    }

    private function pagina()
    {
        return $this->actingAs($this->amministratore)->get(route('sistema'));
    }

    public function test_mostra_ultimo_backup_import_e_riconciliazione_per_conto_errori_e_amministratori(): void
    {
        Backup::create(['tipo' => 'mensile', 'esito' => 'riuscito', 'avviato_il' => '2026-06-01 03:00', 'concluso_il' => '2026-06-01 03:00', 'file' => 'prima-nota-backup-2026-06-01-030000.zip', 'drive_id' => 'x', 'dimensione' => 20480]);
        $banca = Conto::factory()->create(['tipo' => 'banca', 'nome' => 'Conto corrente inventato', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi', 'saldo_iniziale' => 100000]);
        Conto::factory()->create(['nome' => 'Cassa senza import']);
        Conto::factory()->create(['nome' => 'Cassa chiusa', 'chiuso_il' => '2026-03-01']);
        ImportEstratto::create(['conto_id' => $banca->id, 'user_id' => $this->amministratore->id, 'stato' => 'confermato', 'periodo_dal' => '2026-05-01', 'periodo_al' => '2026-05-31', 'saldo_iniziale' => 100000, 'saldo_finale' => 100000, 'righe' => 0, 'confermato_il' => '2026-06-02 09:00']);
        RegistroErrori::registra('backup', 'Backup non riuscito: Drive: caricamento non riuscita, risposta 500.', grave: true);
        $this->utenteCon('Amministratore', [], ['name' => 'Bruno Neri']);
        $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);

        $this->pagina()->assertOk()
            ->assertSee('01/06/2026 03:00')->assertSee('prima-nota-backup-2026-06-01-030000.zip')
            ->assertSee('Conto corrente inventato')->assertSee('fino al 31/05/2026')->assertSee('confermato il 02/06/2026')
            ->assertSee('Cassa senza import')->assertSee('nessun import')->assertDontSee('Cassa chiusa')
            ->assertSee('Backup non riuscito: Drive: caricamento non riuscita, risposta 500.')
            ->assertSeeInOrder(['Amministratori', '2'])->assertSee('Bruno Neri')->assertDontSee('Tina Verdi');
    }

    public function test_senza_backup_o_con_l_ultimo_tentativo_fallito_lo_dice(): void
    {
        $this->pagina()->assertOk()->assertSee('Nessun backup riuscito finora.')->assertSee('Nessun errore aperto.');

        Backup::create(['tipo' => 'mensile', 'esito' => 'fallito', 'avviato_il' => '2026-06-09 03:00', 'errore' => 'Drive: caricamento non riuscita, risposta 503.']);

        $this->pagina()->assertSee('non è riuscito')->assertSee('risposta 503');
    }

    public function test_e_riservato_a_chi_ha_il_permesso(): void
    {
        foreach (['Tesoriere', 'Consigliere', 'Lettore esterno'] as $ruolo) {
            $this->actingAs($this->utenteCon($ruolo))->get(route('sistema'))->assertForbidden();
        }
        $this->actingAs(User::factory()->create())->get(route('sistema'))->assertForbidden(); // senza ruolo
        auth()->logout();
        $this->get(route('sistema'))->assertRedirect(route('accesso'));
    }

    public function test_chi_ha_le_impostazioni_ma_non_gli_utenti_vede_quanti_sono_gli_amministratori_non_chi(): void
    {
        $ruolo = Ruolo::create(['nome' => 'Responsabile del sistema', 'permessi' => [Permesso::Vedere->value, Permesso::GestireImpostazioni->value]]);
        $this->actingAs($this->utenteCon($ruolo))->get(route('sistema'))
            ->assertOk()->assertSeeInOrder(['Amministratori', '1'])
            ->assertDontSee('Anna Bianchi')->assertDontSee('anna@associazione.example');
    }

    public function test_si_segna_un_errore_come_risolto_e_se_ritorna_se_ne_apre_uno_nuovo(): void
    {
        RegistroErrori::registra('backup', 'Backup non riuscito', grave: true);
        $errore = Errore::sole();

        $this->actingAs($this->amministratore)->post(route('sistema.errori.risolvi', $errore))->assertRedirect(route('sistema'));

        $errore->refresh();
        $this->assertNotNull($errore->risolto_il);
        $this->assertSame($this->amministratore->id, $errore->risolto_da);
        $this->pagina()->assertSee('Nessun errore aperto.');

        RegistroErrori::registra('backup', 'Backup non riuscito', grave: true);

        $this->assertSame(2, Errore::count());
        $this->assertSame(1, Errore::aperti()->count());
        Mail::assertSent(ErroreGrave::class, 2);
    }

    public function test_chi_non_ha_il_permesso_non_risolve_gli_errori(): void
    {
        RegistroErrori::registra('backup', 'Backup non riuscito', grave: true);

        $this->actingAs($this->utenteCon('Tesoriere'))->post(route('sistema.errori.risolvi', Errore::sole()))->assertForbidden();

        $this->assertNull(Errore::sole()->risolto_il);
    }

    public function test_un_errore_che_si_ripete_si_conta_e_avvisa_al_massimo_una_volta_al_giorno(): void
    {
        RegistroErrori::registra('backup', 'Backup non riuscito', grave: true);
        $this->travelTo('2026-06-10 15:00');
        RegistroErrori::registra('backup', 'Backup non riuscito', grave: true);
        RegistroErrori::registra('backup', 'Backup non riuscito', grave: true);

        $this->assertSame(3, Errore::sole()->volte);
        $this->assertSame('2026-06-10 15:00:00', Errore::sole()->ultimo_il->format('Y-m-d H:i:s'));
        Mail::assertSent(ErroreGrave::class, 1);

        $this->travelTo('2026-06-11 10:01');
        RegistroErrori::registra('backup', 'Backup non riuscito', grave: true);

        Mail::assertSent(ErroreGrave::class, 2);
        Mail::assertSent(ErroreGrave::class, fn ($m) => $m->volte === 4);
    }

    public function test_se_l_email_non_parte_l_errore_resta_da_avvisare_e_lo_stato_del_sistema_lo_mostra(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Posta non raggiungibile'));

        RegistroErrori::registra('backup', 'Backup non riuscito', grave: true);

        $this->assertNull(Errore::where('origine', 'backup')->sole()->avvisato_il); // alla prossima volta riprova, senza aspettare un giorno
        $posta = Errore::where('origine', 'posta')->sole();
        $this->assertFalse($posta->grave);
        $this->pagina()->assertSee('Email di avviso non partita');
    }

    public function test_in_prova_nessun_errore_entra_nel_registro_vero(): void
    {
        $utente = User::factory()->create();

        $this->app->make(Prova::class)->per($utente, null, fn () => RegistroErrori::registra('app', 'Errore dentro una missione', grave: true));

        $this->assertSame(0, Errore::count());
        Mail::assertNothingSent();
    }

    public function test_un_backup_rimasto_in_corso_oltre_l_ora_e_un_errore_grave(): void
    {
        $interrotto = Backup::create(['tipo' => 'mensile', 'esito' => 'in_corso', 'avviato_il' => '2026-06-10 08:30']);
        $recente = Backup::create(['tipo' => 'su_richiesta', 'esito' => 'in_corso', 'avviato_il' => '2026-06-10 09:30']);

        $this->pagina()->assertOk()->assertSee('si è interrotto senza finire');

        $this->assertSame('fallito', $interrotto->refresh()->esito);
        $this->assertSame('in_corso', $recente->refresh()->esito); // ancora nell'ora di lucchetto: può essere vivo
        $this->assertTrue(Errore::sole()->grave);
        Mail::assertSent(ErroreGrave::class, 1);
    }

    public function test_un_errore_non_grave_si_registra_senza_email(): void
    {
        RegistroErrori::registra('backup.rotazione', 'Un backup vecchio non si è potuto togliere dal drive');

        $this->assertFalse(Errore::sole()->grave);
        Mail::assertNothingSent();
    }

    public function test_di_un_eccezione_si_registrano_classe_e_punto_non_il_messaggio(): void
    {
        RegistroErrori::registra('app', new \RuntimeException('SELECT * FROM movimenti WHERE controparte = Mario Rossi'), grave: true);

        $this->assertStringStartsWith('RuntimeException in StatoSistemaTest.php:', Errore::sole()->messaggio);
        $this->assertStringNotContainsString('Mario Rossi', Errore::sole()->messaggio);
    }

    public function test_un_eccezione_non_gestita_in_una_richiesta_e_un_errore_grave(): void
    {
        Route::get('/prova-errore', fn () => throw new \RuntimeException('Rotto'));

        $this->get('/prova-errore')->assertStatus(500);

        $errore = Errore::sole();
        $this->assertSame('app', $errore->origine);
        $this->assertTrue($errore->grave);
        Mail::assertSent(ErroreGrave::class, fn ($m) => $m->hasTo('anna@associazione.example'));
    }

    public function test_un_errore_http_voluto_non_e_un_errore_grave(): void
    {
        Route::get('/prova-assente', fn () => abort(404));
        Route::get('/prova-503', fn () => abort(503));

        $this->get('/prova-assente')->assertNotFound();
        $this->get('/prova-503')->assertStatus(503);

        $this->assertSame(0, Errore::count());
    }

    public function test_un_compito_periodico_che_si_ferma_e_un_errore_grave(): void
    {
        config(['app.token_cron' => 'token-di-prova-lunghissimo']);
        $url = '/manutenzione/cron?token=token-di-prova-lunghissimo';
        $this->travelTo('2026-07-01 02:55');
        $this->get($url);
        $this->travelTo('2026-07-01 03:07');
        Cache::shouldReceive('lock')->andThrow(new \RuntimeException('Cache rotta'));

        $this->get($url)->assertOk(); // il cron non si ferma

        $errore = Errore::sole();
        $this->assertSame('compito:backup:esegui', $errore->origine);
        $this->assertTrue($errore->grave);
        Mail::assertSent(ErroreGrave::class, fn ($m) => $m->origine === 'compito:backup:esegui');
    }
}
