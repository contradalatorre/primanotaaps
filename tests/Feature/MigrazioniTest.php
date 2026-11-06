<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrazioniTest extends TestCase
{
    use RefreshDatabase;

    private const PRIMA_INSTALLAZIONE = '/manutenzione/migrazioni/prima-installazione';

    private const TOKEN = ['Authorization' => 'Bearer token-di-prova-lunghissimo'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.token_migrazioni' => 'token-di-prova-lunghissimo']);
    }

    protected function tearDown(): void
    {
        // DROP TABLE e db:wipe chiudono la transazione di RefreshDatabase e lascerebbero righe e tabelle
        // di questa classe in `contabilita_test`: si rifà lo schema da capo prima di chiudere.
        Artisan::call('migrate:fresh', ['--force' => true]);

        parent::tearDown();
    }

    private function utente(string $ruolo): User
    {
        $utente = $this->utenteCon($ruolo);
        $this->actingAs($utente);

        return $utente;
    }

    /** Come un aggiornamento con una migrazione nuova: lo schema è indietro di una migrazione. */
    private function schemaIndietroDiUna(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
        DB::table('migrations')->where('migration', 'like', '%create_jobs_table')->delete();
    }

    public function test_un_ospite_va_all_accesso(): void
    {
        $this->get(route('migrazioni'))->assertRedirect(route('accesso'));
        $this->post(route('migrazioni.avvia'))->assertRedirect(route('accesso'));
    }

    public function test_chi_non_ha_la_gestione_utenti_riceve_403(): void
    {
        $this->utente('Tesoriere');

        $this->get(route('migrazioni'))->assertForbidden();
        $this->post(route('migrazioni.avvia'))->assertForbidden();
    }

    public function test_l_amministratore_vede_la_pagina(): void
    {
        $this->utente('Amministratore');

        $this->get(route('migrazioni'))->assertOk()->assertSee('Migrazioni del database');
    }

    public function test_l_amministratore_avvia_le_migrazioni_e_ne_legge_l_esito(): void
    {
        $this->utente('Amministratore');
        $this->schemaIndietroDiUna();

        $this->post(route('migrazioni.avvia'))->assertRedirect(route('migrazioni'));

        $this->assertTrue(Schema::hasTable('jobs'));
        $this->get(route('migrazioni'))->assertSee('create_jobs_table');
    }

    public function test_la_prima_installazione_con_il_token_crea_lo_schema_su_un_database_vuoto(): void
    {
        Artisan::call('db:wipe', ['--force' => true]);
        // Come in produzione: sessioni su database, e la tabella `sessions` non c'è ancora.
        config(['session.driver' => 'database']);

        $this->postJson(self::PRIMA_INSTALLAZIONE, [], self::TOKEN)->assertOk();

        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_la_prima_installazione_rifiuta_un_token_sbagliato_o_assente(): void
    {
        Artisan::call('db:wipe', ['--force' => true]);

        $this->postJson(self::PRIMA_INSTALLAZIONE, [], ['Authorization' => 'Bearer sbagliato'])->assertForbidden();
        $this->postJson(self::PRIMA_INSTALLAZIONE)->assertForbidden();
        $this->assertFalse(Schema::hasTable('users'));

        // Neanche con il token vuoto di una configurazione dimenticata.
        config(['app.token_migrazioni' => '']);
        $this->postJson(self::PRIMA_INSTALLAZIONE, [], ['Authorization' => 'Bearer '])->assertForbidden();
    }

    /** Il passo del workflow accetta solo 200 e 409: un'installazione fallita deve rispondere altro. */
    public function test_la_prima_installazione_che_fallisce_risponde_424_senza_il_messaggio_dell_eccezione(): void
    {
        Artisan::call('db:wipe', ['--force' => true]);

        // Una cartella di migrazioni con una sola migrazione che fallisce, come un database che non risponde bene.
        $cartella = sys_get_temp_dir() . '/migrazioni-prova-' . uniqid();
        mkdir($cartella . '/migrations', 0777, true);
        file_put_contents($cartella . '/migrations/2000_01_01_000000_fallisce.php', <<<'PHP'
            <?php
            return new class extends Illuminate\Database\Migrations\Migration {
                public function up(): void { throw new RuntimeException('Access denied for user contabilita@db'); }
            };
            PHP);
        $this->app->useDatabasePath($cartella);

        try {
            $risposta = $this->postJson(self::PRIMA_INSTALLAZIONE, [], self::TOKEN);
        } finally {
            $this->app->useDatabasePath(base_path('database'));
            unlink($cartella . '/migrations/2000_01_01_000000_fallisce.php');
            rmdir($cartella . '/migrations');
            rmdir($cartella);
        }

        $risposta->assertStatus(424);
        $this->assertStringNotContainsString('Access denied', $risposta->getContent());
    }

    public function test_la_prima_installazione_riparte_se_la_tabella_migrations_e_vuota(): void
    {
        // Come dopo una prima migrazione fallita: la tabella esiste ma non registra nulla.
        Artisan::call('db:wipe', ['--force' => true]);
        Artisan::call('migrate:install');

        $this->postJson(self::PRIMA_INSTALLAZIONE, [], self::TOKEN)->assertOk();

        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_l_avvio_dalla_pagina_e_isolato(): void
    {
        $this->utente('Amministratore');
        $this->schemaIndietroDiUna();
        $lock = Cache::lock('framework' . DIRECTORY_SEPARATOR . 'command-migrate', 60);
        $lock->get();

        // Un'altra migrazione è già in corso: questa non parte.
        $this->post(route('migrazioni.avvia'))->assertRedirect(route('migrazioni'));

        $this->assertFalse(Schema::hasTable('jobs'));
        $lock->release();
    }

    public function test_la_prima_installazione_e_chiusa_quando_lo_schema_esiste_gia(): void
    {
        $this->postJson(self::PRIMA_INSTALLAZIONE, [], self::TOKEN)->assertStatus(409);
    }
}
