<?php

namespace Tests\Feature;

use App\Http\Middleware\ProvaMiddleware;
use App\Models\Conto;
use App\Models\Menzione;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Ruolo;
use App\Models\SpazioProva;
use App\Models\User;
use App\Onboarding\Catalogo;
use App\Onboarding\Prova;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\ConSpazioDiProva;
use Tests\TestCase;

/** Lo spazio di prova (#71, ADR 0003): separato dal database vero, uno per utente, azzerato a ogni avvio, senza chiamate esterne. */
class SpazioDiProvaTest extends TestCase
{
    use ConSpazioDiProva, RefreshDatabase;

    public function test_avviare_una_missione_prepara_lo_spazio_con_lo_scenario_e_non_tocca_il_database_vero(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');

        $this->actingAs($tesoriere)->post(route('impara.avvia', 'import-e-revisione'))->assertRedirect(route('conti'));

        // Nel database vero non è entrato nulla: né conti né movimenti né registro, solo l'avanzamento e l'elenco degli spazi.
        $this->assertSame(0, Conto::count());
        $this->assertSame(0, Movimento::count());
        $this->assertSame(0, RegistroModifica::count());
        $this->assertTrue(SpazioProva::whereKey($tesoriere->id)->exists());
        // Nello spazio di prova c'è lo scenario, con l'utente ricopiato (stesso id) e quelli finti.
        $this->assertSame(1, $this->contiInProva($tesoriere));
        $this->assertEqualsCanonicalizing(['Assistente AI (di prova)', $tesoriere->name, 'Tina Esempio (finta)'], $this->prova()->per($tesoriere, null, fn () => User::pluck('name')->all()));
        $this->assertFalse($this->prova()->attiva(), 'fuori dalla richiesta il default torna la connessione vera');
        $this->assertSame('mysql', DB::getDefaultConnection());
    }

    public function test_in_prova_le_schermate_vere_mostrano_i_dati_di_prova_con_la_cornice(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');
        $this->actingAs($tesoriere)->post(route('impara.avvia', 'import-e-revisione'));

        $this->get(route('conti'))
            ->assertOk()
            ->assertSee('Conto corrente (prova)')
            ->assertSeeInOrder(['Stai facendo pratica', ': nessun dato vero'], false)
            ->assertSee('Esci dalla prova');
        $this->assertSame(0, Conto::count());

        // Fuori dalla prova (Impara è una pagina vera) niente cornice né dati finti.
        $this->get(route('impara'))->assertOk()->assertDontSee('Stai facendo pratica');
    }

    public function test_riavviare_la_missione_azzera_lo_spazio(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');
        $this->actingAs($tesoriere)->post(route('impara.avvia', 'import-e-revisione'));
        $this->prova()->per($tesoriere, null, fn () => Conto::create(['tipo' => 'cassa', 'nome' => 'Cassa in più', 'formato_import' => 'manuale', 'riga_modello_d' => 'cassa', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]));
        $this->assertSame(2, $this->contiInProva($tesoriere));

        $this->post(route('impara.avvia', 'import-e-revisione'));

        $this->assertSame(1, $this->contiInProva($tesoriere));
    }

    public function test_ogni_utente_ha_il_suo_spazio_e_cancellarne_uno_non_tocca_gli_altri(): void
    {
        $prima = $this->utenteCon('Tesoriere');
        $seconda = $this->utenteCon('Tesoriere');
        $this->actingAs($prima)->post(route('impara.avvia', 'benvenuto'));
        $this->actingAs($seconda)->post(route('impara.avvia', 'benvenuto'));
        $this->prova()->per($prima, null, fn () => Conto::create(['tipo' => 'cassa', 'nome' => 'Solo della prima', 'formato_import' => 'manuale', 'riga_modello_d' => 'cassa', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]));

        $this->assertSame(3, $this->contiInProva($prima));
        $this->assertSame(2, $this->contiInProva($seconda));

        $this->prova()->elimina($prima->id);

        $this->assertFalse($this->prova()->esiste($prima->id));
        $this->assertSame(2, $this->contiInProva($seconda));
        $this->assertFalse(SpazioProva::whereKey($prima->id)->exists());
        $this->assertTrue(SpazioProva::whereKey($seconda->id)->exists());
    }

    public function test_in_prova_valgono_i_permessi_della_missione_e_solo_li(): void
    {
        $senzaRuolo = User::factory()->create(); // nessuna assegnazione: un candidato tesoriere
        $this->actingAs($senzaRuolo)->get(route('conti'))->assertForbidden();

        $this->post(route('impara.avvia', 'import-e-revisione'));
        $this->get(route('conti'))->assertOk();
        $this->get(route('ruoli'))->assertForbidden(); // la missione non dà la gestione utenti

        $this->post(route('prova.esci'))->assertRedirect(route('impara'));
        $this->get(route('conti'))->assertForbidden();
    }

    public function test_in_prova_non_parte_nessuna_chiamata_esterna_ne_email(): void
    {
        Route::middleware(['web', 'auth', ProvaMiddleware::class])->get('/_prova/esterno', function () {
            Mail::raw('Ciao', fn ($m) => $m->to('qualcuno@esempio.test')->subject('x'));
            try {
                Http::get('https://api.anthropic.com/v1/messages');
                $esterna = 'partita';
            } catch (StrayRequestException) {
                $esterna = 'bloccata';
            }

            return response($esterna.'|'.config('mail.default'));
        });
        config(['mail.default' => 'smtp']); // quello vero: in prova lo scambia con il mailer che non spedisce
        $this->actingAs($this->utenteCon('Tesoriere'))->post(route('impara.avvia', 'benvenuto'));

        $this->get('/_prova/esterno')->assertOk()->assertSee('bloccata|array', false);
        $this->assertSame('smtp', config('mail.default'), 'uscendo dalla prova il mailer torna quello di prima');
        $this->assertFalse(Http::preventingStrayRequests());
    }

    public function test_se_il_database_di_prova_non_risponde_non_si_continua_sul_database_vero(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');
        $database = config('database.connections.prova.database');
        config(['database.connections.prova.database' => 'database_che_non_esiste']);

        try {
            $this->actingAs($tesoriere)->post(route('impara.avvia', 'benvenuto'))
                ->assertRedirect(route('impara'))
                ->assertSessionHas('avviso');
            $this->assertSame(0, Conto::count());
            $this->assertFalse(session()->has('prova.missione'));
        } finally {
            config(['database.connections.prova.database' => $database]);
        }
    }

    public function test_cancellare_gli_spazi_non_tocca_le_tabelle_con_lo_stesso_prefisso_in_altri_database(): void
    {
        // Il database vero, visibile allo stesso utente MySQL, con una tabella che sembra di uno spazio. Una connessione
        // a parte: il DDL chiude la transazione di RefreshDatabase.
        $altro = DB::connectUsing('altro', config('database.connections.mysql'));
        $altro->statement('create table p9_users (id int)');
        try {
            $tesoriere = $this->utenteCon('Tesoriere');
            $this->actingAs($tesoriere)->post(route('impara.avvia', 'benvenuto'));

            $this->prova()->elimina($tesoriere->id);
            $this->prova()->elimina(9);
            $this->prova()->eliminaTutto();

            $this->assertTrue($altro->getSchemaBuilder()->hasTable('p9_users'));
        } finally {
            $altro->statement('drop table if exists p9_users');
        }
    }

    public function test_se_il_database_di_prova_e_quello_vero_non_si_entra(): void
    {
        $prova = config('database.connections.prova.database');
        config(['database.connections.prova.database' => config('database.connections.mysql.database')]);

        try {
            $this->actingAs($this->utenteCon('Tesoriere'))->post(route('impara.avvia', 'benvenuto'))
                ->assertRedirect(route('impara'))
                ->assertSessionHas('avviso');
            $this->assertFalse(Schema::hasTable('p'.User::first()->id.'_users'), 'nel database vero non nasce nessuna tabella di prova');
        } finally {
            config(['database.connections.prova.database' => $prova]);
        }
    }

    public function test_l_anteprima_di_prova_si_cancella_con_lo_spazio(): void
    {
        Storage::fake('local');
        $tesoriere = $this->utenteCon('Tesoriere');
        $this->actingAs($tesoriere)->post(route('impara.avvia', 'import-e-revisione'));
        $this->post(route('prova.avanti'));
        $conto = $this->prova()->per($tesoriere, null, fn () => Conto::where('tipo', 'banca')->value('id'));

        $this->post(route('prova.estratto', $conto));
        $this->assertCount(1, Storage::disk('local')->allFiles('estratti'));

        $this->post(route('impara.avvia', 'import-e-revisione')); // «Ricomincia»
        $this->assertCount(0, Storage::disk('local')->allFiles('estratti'));

        $this->post(route('prova.avanti'));
        $this->post(route('prova.estratto', $conto));
        $this->assertCount(1, Storage::disk('local')->allFiles('estratti'));
        $this->prova()->elimina($tesoriere->id); // come prova:cancella
        $this->assertCount(0, Storage::disk('local')->allFiles('estratti'));
    }

    public function test_usare_lo_spazio_aggiorna_se_si_ha_un_ruolo(): void
    {
        $candidato = User::factory()->create();
        $this->actingAs($candidato)->post(route('impara.avvia', 'benvenuto'));
        $this->assertFalse((bool) SpazioProva::find($candidato->id)->con_assegnazione);

        // Poi arriva il ruolo; dopo un po' l'uso dello spazio lo registra, così la revoca lo cancellerà.
        $this->assegna($candidato, 'Consigliere');
        SpazioProva::whereKey($candidato->id)->update(['ultimo_uso' => now()->subMinutes(2)]);
        $this->get(route('home'));

        $this->assertTrue((bool) SpazioProva::find($candidato->id)->con_assegnazione);
    }

    public function test_in_prova_le_menzioni_a_un_ruolo_valgono_per_i_ruoli_dello_spazio_non_per_quelli_veri(): void
    {
        $consigliere = $this->utenteCon('Consigliere'); // il ruolo vero; nello spazio di prova non ha assegnazioni
        $this->prova()->prepara($consigliere, Catalogo::predefinito()->missione('import-e-revisione'));
        $rivolte = $this->prova()->per($consigliere, null, function () use ($consigliere) {
            $nota = DB::table('note')->insertGetId(['oggetto_tipo' => 'movimento', 'oggetto_id' => 1, 'user_id' => $consigliere->id, 'testo' => 'Prova', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('menzioni')->insert(['nota_id' => $nota, 'ruolo_id' => Ruolo::where('nome', 'Consigliere')->value('id'), 'created_at' => now(), 'updated_at' => now()]);

            return Menzione::rivolteA($consigliere)->count();
        });

        $this->assertSame(0, $rivolte);
        $this->assertSame('mysql', $consigliere->getConnectionName(), 'uscendo dalla prova l’utente torna sulla connessione vera');
    }

    public function test_in_prova_le_preferenze_si_scrivono_nello_spazio_non_sulla_riga_vera(): void
    {
        $utente = $this->utenteCon('Tesoriere');
        $this->actingAs($utente)->post(route('impara.avvia', 'import-e-revisione'));

        $this->put(route('preferenze.salva'), ['email_menzioni' => 'nessuna'])->assertRedirect(route('preferenze'));

        $this->assertSame('riepilogo', User::find($utente->id)->email_menzioni);
        $this->assertSame('nessuna', $this->prova()->per($utente, null, fn () => User::find($utente->id)->email_menzioni));
    }

    public function test_in_prova_la_pagina_delle_preferenze_mostra_quella_dello_spazio_non_quella_vera(): void
    {
        $utente = $this->utenteCon('Tesoriere'); // riga vera: riepilogo
        $this->actingAs($utente)->post(route('impara.avvia', 'import-e-revisione'));
        $this->prova()->per($utente, null, fn () => DB::table('users')->where('id', $utente->id)->update(['email_menzioni' => 'nessuna']));

        // Una richiesta nuova: `auth` rilegge l'utente dal database vero, come in produzione (nei test `actingAs` ne riusa l'istanza).
        $this->actingAs(User::find($utente->id))->get(route('preferenze'))->assertOk()
            ->assertSee('value="nessuna" checked', false)->assertDontSee('value="riepilogo" checked', false);
        $this->assertSame('riepilogo', User::find($utente->id)->email_menzioni);
    }
}
