<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

        config(['app.amministratori' => ['maria.rossi@associazione.example'], 'app.token_migrazioni' => 'token-di-prova-lunghissimo']);
    }

    private function utente(string $email): User
    {
        $utente = User::factory()->create(['email' => $email]);
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

    public function test_chi_non_e_amministratore_riceve_403(): void
    {
        $this->utente('mario.bianchi@associazione.example');

        $this->get(route('migrazioni'))->assertForbidden();
        $this->post(route('migrazioni.avvia'))->assertForbidden();
    }

    public function test_l_amministratore_vede_la_pagina(): void
    {
        $this->utente('maria.rossi@associazione.example');

        $this->get(route('migrazioni'))->assertOk()->assertSee('Migrazioni del database');
    }

    public function test_l_amministratore_avvia_le_migrazioni_e_ne_legge_l_esito(): void
    {
        $this->utente('maria.rossi@associazione.example');
        $this->schemaIndietroDiUna();

        $this->post(route('migrazioni.avvia'))->assertRedirect(route('migrazioni'));

        $this->assertTrue(Schema::hasTable('jobs'));
        $this->get(route('migrazioni'))->assertSee('create_jobs_table');
    }

    public function test_senza_amministratori_configurati_nessuno_entra(): void
    {
        config(['app.amministratori' => []]);
        $this->utente('maria.rossi@associazione.example');

        $this->get(route('migrazioni'))->assertForbidden();
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

        // Ripristina lo schema per i test successivi.
        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_la_prima_installazione_e_chiusa_quando_lo_schema_esiste_gia(): void
    {
        $this->postJson(self::PRIMA_INSTALLAZIONE, [], self::TOKEN)->assertStatus(409);
    }
}
