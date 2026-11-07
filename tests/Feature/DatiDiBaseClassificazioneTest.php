<?php

namespace Tests\Feature;

use App\Models\Attivita;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Tag;
use App\Models\Tipologia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Gestione di tipologie, attività, iniziative e tag: gestire_regole scrive, tutti i ruoli interni leggono. */
class DatiDiBaseClassificazioneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-20 10:00');
    }

    public function test_la_pagina_elenca_i_dati_di_base_a_chi_puo_vedere(): void
    {
        $this->actingAs($this->utenteCon('Lettore esterno'))->get('/classificazione')->assertOk()
            ->assertSee('Materiali e beni di consumo')->assertSee('Lettera i (art. 5 CTS)')->assertSee('Festa della Torre')
            ->assertSee('Microprogetto')->assertDontSee('Nuova tipologia');
        $this->actingAs($this->utenteCon('Tesoriere'))->get('/classificazione')->assertOk()->assertSee('Nuova tipologia');
    }

    public function test_il_tesoriere_crea_una_tipologia_con_verso_spiegazione_e_proprieta(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');

        $this->actingAs($tesoriere)->post('/classificazione/tipologie', [
            'nome' => 'Vendita gadget', 'verso' => 'entrata', 'spiegazione' => 'Gadget venduti ai banchetti.', 'proprieta' => ['commerciale_per_natura'],
        ])->assertRedirect('/classificazione');

        $tipologia = Tipologia::firstWhere('nome', 'Vendita gadget');
        $this->assertSame('entrata', $tipologia->verso);
        $this->assertSame(['commerciale_per_natura'], $tipologia->proprieta);
        $riga = RegistroModifica::where('oggetto_tipo', 'tipologia')->firstOrFail();
        $this->assertSame($tesoriere->id, $riga->user_id);
        $this->assertSame('Vendita gadget', $riga->dopo['nome']);
    }

    public function test_un_nome_gia_usato_si_rifiuta(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'))->post('/classificazione/tag', ['nome' => 'Microprogetto'])->assertSessionHasErrors('nome');
        $this->assertSame(2, Tag::count());
    }

    public function test_si_crea_un_tag_un_attivita_e_un_iniziativa_con_area_e_attivita_predefinite(): void
    {
        $this->actingAs($tesoriere = $this->utenteCon('Tesoriere'));
        $lettera = Attivita::firstWhere('nome', 'Lettera k (art. 5 CTS)');

        $this->post('/classificazione/tag', ['nome' => 'Bando Fondazione X'])->assertRedirect();
        $this->post('/classificazione/attivita', ['nome' => 'Lettera x', 'area' => 'A'])->assertRedirect();
        $this->post('/classificazione/iniziative', ['nome' => 'Sagra d’autunno', 'esercizio' => 2026, 'area' => 'A', 'attivita_id' => $lettera->id])->assertRedirect();

        $this->assertNotNull(Tag::firstWhere('nome', 'Bando Fondazione X'));
        $this->assertSame('A', Attivita::firstWhere('nome', 'Lettera x')->area->value);
        $predefiniti = Iniziativa::firstWhere('nome', 'Sagra d’autunno')->predefinitiPer(2026);
        $this->assertSame('A', $predefiniti->area->value);
        $this->assertSame($lettera->id, $predefiniti->attivita_id);
    }

    public function test_l_attivita_predefinita_deve_stare_nell_area_scelta(): void
    {
        $lettera = Attivita::firstWhere('nome', 'Lettera k (art. 5 CTS)');

        $this->actingAs($this->utenteCon('Tesoriere'))->post('/classificazione/iniziative', ['nome' => 'Sagra', 'esercizio' => 2026, 'area' => 'B', 'attivita_id' => $lettera->id])
            ->assertSessionHasErrors('attivita_id');
        $this->assertNull(Iniziativa::firstWhere('nome', 'Sagra'));
    }

    public function test_si_rinomina_e_si_cambiano_i_predefiniti_di_un_esercizio_senza_toccare_gli_altri(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $festa = Iniziativa::firstWhere('nome', 'Festa della Torre');

        $this->put("/classificazione/iniziative/{$festa->id}", ['nome' => 'Festa della Torre 2.0', 'esercizio' => 2027, 'area' => 'C', 'attivita_id' => ''])->assertRedirect();

        $festa->refresh()->load('predefiniti');
        $this->assertSame('Festa della Torre 2.0', $festa->nome);
        $this->assertSame('A', $festa->predefinitiPer(2026)->area->value);
        $this->assertSame('C', $festa->predefinitiPer(2027)->area->value);

        $this->put("/classificazione/iniziative/{$festa->id}", ['nome' => 'Festa della Torre 2.0', 'esercizio' => 2027, 'area' => '', 'attivita_id' => ''])->assertRedirect();
        $this->assertNull($festa->refresh()->load('predefiniti')->predefinitiPer(2027));
    }

    public function test_si_rinomina_una_tipologia_ma_non_si_cambia_il_verso(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $quote = Tipologia::firstWhere('nome', 'Quote associative');

        $this->put("/classificazione/tipologie/{$quote->id}", ['nome' => 'Quote dei soci', 'verso' => 'uscita', 'spiegazione' => 'Le quote.', 'proprieta' => []])->assertRedirect();

        $quote->refresh();
        $this->assertSame('Quote dei soci', $quote->nome);
        $this->assertSame('entrata', $quote->verso);
        $this->assertSame('Le quote.', $quote->spiegazione);
        $riga = RegistroModifica::where('oggetto_tipo', 'tipologia')->firstOrFail();
        $this->assertSame('Quote associative', $riga->prima['nome']);
        $this->assertSame('Quote dei soci', $riga->dopo['nome']);
    }

    public function test_si_archivia_e_si_ripristina_e_l_elemento_archiviato_non_si_sceglie_piu(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $tag = Tag::firstWhere('nome', 'Gruppo CLT');

        $this->post("/classificazione/tag/{$tag->id}/archiviazione")->assertRedirect();
        $this->assertTrue($tag->refresh()->archiviata());
        $this->assertSame(['Microprogetto'], Tag::attive()->pluck('nome')->all());

        $this->post("/classificazione/tag/{$tag->id}/archiviazione")->assertRedirect();
        $this->assertFalse($tag->refresh()->archiviata());
        $this->assertSame(['archiviato', 'ripristinato'], RegistroModifica::where('oggetto_tipo', 'tag')->orderBy('id')->pluck('azione')->all());
    }

    public function test_si_cancella_solo_cio_che_non_e_mai_stato_usato(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $tipologia = Tipologia::firstWhere('nome', 'Utenze');
        $iniziativa = Iniziativa::firstWhere('nome', 'Carnevale');
        $tag = Tag::firstWhere('nome', 'Microprogetto');
        $movimento = Movimento::factory()->create();
        $imputazione = Imputazione::create(['movimento_id' => $movimento->id, 'importo' => 1250, 'area' => 'A', 'iniziativa_id' => $iniziativa->id, 'tipologia_id' => $tipologia->id]);
        $imputazione->tag()->attach($tag);

        foreach (["tipologie/{$tipologia->id}", "iniziative/{$iniziativa->id}", "tag/{$tag->id}"] as $percorso) {
            $this->delete("/classificazione/$percorso")->assertRedirect()->assertSessionHasErrors('elimina');
        }
        $this->assertNotNull($tipologia->fresh());
        $this->assertNotNull($iniziativa->fresh());
        $this->assertNotNull($tag->fresh());

        // Un'attività che è il predefinito di un'iniziativa è usata anche senza imputazioni.
        $lettera = Attivita::firstWhere('nome', 'Lettera i (art. 5 CTS)');
        $this->delete("/classificazione/attivita/{$lettera->id}")->assertSessionHasErrors('elimina');
        $this->assertNotNull($lettera->fresh());

        // Mai usati: spariscono, con le loro voci e i loro predefiniti.
        $libri = Tipologia::firstWhere('nome', 'Libri e pubblicazioni');
        $casa = Iniziativa::firstWhere('nome', 'La Casa');
        $altro = Tag::firstWhere('nome', 'Gruppo CLT');
        $this->delete("/classificazione/tipologie/{$libri->id}")->assertRedirect()->assertSessionHasNoErrors();
        $this->delete("/classificazione/iniziative/{$casa->id}")->assertRedirect()->assertSessionHasNoErrors();
        $this->delete("/classificazione/tag/{$altro->id}")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($libri->fresh());
        $this->assertNull($casa->fresh());
        $this->assertNull($altro->fresh());
        $this->assertSame(0, \DB::table('voci_modello_d')->where('tipologia_id', $libri->id)->count());
        $this->assertSame(['eliminato'], RegistroModifica::where('oggetto_tipo', 'tag')->pluck('azione')->all());
    }

    public function test_senza_gestire_regole_non_si_scrive(): void
    {
        $tag = Tag::firstWhere('nome', 'Gruppo CLT');
        foreach (['Consigliere', 'Lettore esterno'] as $ruolo) {
            $this->actingAs($this->utenteCon($ruolo));
            $this->post('/classificazione/tag', ['nome' => 'Nuovo'])->assertForbidden();
            $this->put("/classificazione/tag/{$tag->id}", ['nome' => 'Cambiato'])->assertForbidden();
            $this->post("/classificazione/tag/{$tag->id}/archiviazione")->assertForbidden();
            $this->delete("/classificazione/tag/{$tag->id}")->assertForbidden();
        }
        $this->assertSame(['Gruppo CLT', 'Microprogetto'], Tag::orderBy('nome')->pluck('nome')->all());
    }
}
