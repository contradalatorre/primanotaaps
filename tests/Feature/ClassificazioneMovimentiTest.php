<?php

namespace Tests\Feature;

use App\Classificazione;
use App\Models\Attivita;
use App\Models\Conto;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Tag;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Imputazioni, voce Modello D che ne risulta, controparte e conferma manuale di un movimento. */
class ClassificazioneMovimentiTest extends TestCase
{
    use RefreshDatabase;

    private Conto $festa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-20 10:00');
        $this->festa = Conto::factory()->create(['nome' => 'Cassa Festa', 'aperto_il' => '2026-05-01']);
    }

    private function movimento(array $attributi = [], ?User $autore = null): Movimento
    {
        return Movimento::factory()->create($attributi + ['conto_id' => $this->festa->id, 'inserito_da' => ($autore ?? User::factory()->create())->id]);
    }

    private function tipologia(string $nome): int
    {
        return Tipologia::where('nome', $nome)->value('id');
    }

    private function iniziativa(string $nome): int
    {
        return Iniziativa::where('nome', $nome)->value('id');
    }

    /** La conferma che parte dalla pagina: porta con sé la versione che l'utente ha davanti. */
    private function conferma(Movimento $movimento): TestResponse
    {
        return $this->post("/movimenti/{$movimento->id}/conferma", ['versione' => Classificazione::versione($movimento->refresh())]);
    }

    private function url(Movimento $movimento): string
    {
        return "/movimenti/{$movimento->id}/classificazione";
    }

    public function test_tipologia_e_iniziativa_ereditano_area_e_attivita_e_il_movimento_diventa_proposto(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $movimento = $this->movimento(); // uscita di 12,50 del 10/05/2026

        $this->actingAs($tesoriere)->put($this->url($movimento), ['imputazioni' => [
            ['tipologia_id' => $this->tipologia('Materiali e beni di consumo'), 'iniziativa_id' => $this->iniziativa('Carnevale')],
        ]])->assertRedirect()->assertSessionHasNoErrors();

        $imputazione = $movimento->imputazioni()->with('attivita', 'iniziativa', 'tipologia')->sole();
        $this->assertSame(1250, $imputazione->importo); // una sola imputazione: tutto l'importo
        $this->assertSame('A', $imputazione->area->value);
        $this->assertSame('Lettera i (art. 5 CTS)', $imputazione->attivita->nome);
        $this->assertSame('Carnevale', $imputazione->iniziativa->nome);
        $this->assertSame('Uscite A1', $imputazione->etichettaVoce());
        $movimento->refresh();
        $this->assertSame('proposto', $movimento->stato);
        $this->assertSame($tesoriere->id, $movimento->proposto_da);
    }

    public function test_area_e_attivita_ereditate_si_cambiano_sul_singolo_movimento(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $movimento = $this->movimento();
        $lettera = Attivita::firstWhere('nome', 'Lettera k (art. 5 CTS)');

        $this->put($this->url($movimento), ['imputazioni' => [
            ['tipologia_id' => $this->tipologia('Materiali e beni di consumo'), 'iniziativa_id' => $this->iniziativa('Carnevale'), 'attivita_id' => $lettera->id],
        ]])->assertSessionHasNoErrors();
        $this->assertSame($lettera->id, $movimento->imputazioni()->sole()->attivita_id);

        $this->put($this->url($movimento), ['imputazioni' => [
            ['tipologia_id' => $this->tipologia('Materiali e beni di consumo'), 'iniziativa_id' => $this->iniziativa('Carnevale'), 'area' => 'E'],
        ]])->assertSessionHasNoErrors();
        $imputazione = $movimento->imputazioni()->sole();
        $this->assertSame('E', $imputazione->area->value);
        $this->assertNull($imputazione->attivita_id); // l'attività predefinita è dell'area A: non segue l'area cambiata
        $this->assertSame('Uscite E1', $imputazione->etichettaVoce());
    }

    public function test_un_movimento_si_divide_in_piu_imputazioni_la_cui_somma_e_il_suo_importo(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $movimento = $this->movimento(['importo' => 10000]);
        $materiali = $this->tipologia('Materiali e beni di consumo');

        $this->put($this->url($movimento), ['imputazioni' => [
            ['importo' => '60,00', 'tipologia_id' => $materiali, 'iniziativa_id' => $this->iniziativa('Carnevale')],
            ['importo' => '40,00', 'tipologia_id' => $materiali, 'iniziativa_id' => $this->iniziativa('Infiorata')],
            ['importo' => '', 'tipologia_id' => '', 'iniziativa_id' => ''], // riga lasciata vuota
        ]])->assertSessionHasNoErrors();

        $this->assertSame([6000, 4000], $movimento->imputazioni()->orderBy('id')->pluck('importo')->all());
    }

    public function test_una_somma_diversa_dall_importo_si_rifiuta_e_non_salva_niente(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $movimento = $this->movimento(['importo' => 10000]);
        $materiali = $this->tipologia('Materiali e beni di consumo');

        $this->put($this->url($movimento), ['imputazioni' => [
            ['importo' => '60,00', 'tipologia_id' => $materiali, 'area' => 'A'],
            ['importo' => '30,00', 'tipologia_id' => $materiali, 'area' => 'A'],
        ]])->assertSessionHasErrors('imputazioni');

        $this->assertSame(0, $movimento->imputazioni()->count());
        $this->assertSame('da_classificare', $movimento->refresh()->stato);
    }

    public function test_le_combinazioni_area_tipologia_non_ammesse_si_rifiutano(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $entrata = $this->movimento(['verso' => 'entrata']);

        // Le quote associative stanno solo nell'area A.
        $this->put($this->url($entrata), ['imputazioni' => [['tipologia_id' => $this->tipologia('Quote associative'), 'area' => 'B']]])
            ->assertSessionHasErrors('imputazioni.0.tipologia_id');
        $this->assertSame(0, $entrata->imputazioni()->count());

        // L'esercizio è l'anno del movimento: per il 2027 la tabella delle voci non c'è ancora.
        $futuro = $this->movimento(['verso' => 'entrata', 'data' => '2027-01-10']);
        $this->put($this->url($futuro), ['imputazioni' => [['tipologia_id' => $this->tipologia('Quote associative'), 'area' => 'A']]])
            ->assertSessionHasErrors('imputazioni.0.tipologia_id');
    }

    public function test_la_tipologia_deve_avere_il_verso_del_movimento(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $uscita = $this->movimento();

        $this->put($this->url($uscita), ['imputazioni' => [['tipologia_id' => $this->tipologia('Quote associative'), 'area' => 'A']]])
            ->assertSessionHasErrors('imputazioni.0.tipologia_id');
    }

    public function test_la_voce_non_si_forza_ma_si_ricava_da_area_e_tipologia(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $movimento = $this->movimento();

        $this->put($this->url($movimento), ['imputazioni' => [
            ['tipologia_id' => $this->tipologia('Utenze'), 'area' => 'E', 'voce' => 'A1'],
        ]])->assertSessionHasNoErrors();

        $this->get('/movimenti')->assertSee('Uscite E2')->assertDontSee('Uscite A1');
        $this->assertFalse(\Schema::hasColumn('imputazioni', 'voce'));
    }

    public function test_i_tag_non_cambiano_la_voce(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $movimento = $this->movimento();
        $tag = Tag::firstWhere('nome', 'Microprogetto');
        $riga = ['tipologia_id' => $this->tipologia('Utenze'), 'area' => 'E'];

        $this->put($this->url($movimento), ['imputazioni' => [$riga]]);
        $this->get('/movimenti')->assertSee('Uscite E2');
        $this->put($this->url($movimento), ['imputazioni' => [$riga + ['tag' => [$tag->id]]]]);

        $this->assertSame(['Microprogetto'], $movimento->imputazioni()->with('tag')->sole()->tag->pluck('nome')->all());
        $this->get('/movimenti')->assertSee('Uscite E2')->assertSee('Microprogetto');
    }

    public function test_elementi_archiviati_non_si_scelgono_ma_restano_su_cio_che_li_aveva_gia(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $movimento = $this->movimento();
        $riga = ['tipologia_id' => $this->tipologia('Utenze'), 'iniziativa_id' => $this->iniziativa('La Casa')];
        $this->put($this->url($movimento), ['imputazioni' => [$riga]])->assertSessionHasNoErrors();

        Tipologia::where('nome', 'Utenze')->update(['archiviata_il' => now()]);
        Iniziativa::where('nome', 'La Casa')->update(['archiviata_il' => now()]);

        // Già sul movimento: si può ripetere il salvataggio. Su un altro movimento no.
        $this->put($this->url($movimento), ['imputazioni' => [$riga]])->assertSessionHasNoErrors();
        $this->put($this->url($this->movimento()), ['imputazioni' => [$riga]])->assertSessionHasErrors(['imputazioni.0.tipologia_id', 'imputazioni.0.iniziativa_id']);
    }

    public function test_area_e_attivita_ereditate_restano_tali_riaprendo_la_pagina_e_seguono_un_altra_iniziativa(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $movimento = $this->movimento();
        $this->put($this->url($movimento), ['imputazioni' => [['tipologia_id' => $this->tipologia('Utenze'), 'iniziativa_id' => $this->iniziativa('La Casa')]]]);

        // La Casa è in area E: riaprendo la pagina l'area non è precompilata, quindi cambiare iniziativa la cambia con lei.
        $html = $this->get($this->url($movimento))->assertOk()->getContent();
        $this->assertStringNotContainsString('<option value="E" selected', $html);

        $this->put($this->url($movimento), ['imputazioni' => [['tipologia_id' => $this->tipologia('Utenze'), 'iniziativa_id' => $this->iniziativa('Carnevale'), 'area' => '']]])->assertSessionHasNoErrors();
        $this->assertSame('A', $movimento->imputazioni()->sole()->area->value);
    }

    public function test_l_attivita_predefinita_archiviata_si_eredita_comunque(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        Attivita::firstWhere('nome', 'Lettera i (art. 5 CTS)')->forceFill(['archiviata_il' => now()])->save();

        $this->put($this->url($this->movimento()), ['imputazioni' => [['tipologia_id' => $this->tipologia('Utenze'), 'iniziativa_id' => $this->iniziativa('Carnevale'), 'area' => 'A']]])
            ->assertSessionHasNoErrors();
    }

    public function test_un_giustificativo_di_un_movimento_ritirato_non_si_tocca(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');
        $movimento = $this->movimento();
        $giustificativo = $movimento->giustificativi()->create(['riferimento' => 'Fattura']);
        $movimento->delete();

        $this->actingAs($tesoriere)->delete("/giustificativi/{$giustificativo->id}")->assertNotFound();
    }

    public function test_l_attivita_deve_stare_nell_area_scelta(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $lettera = Attivita::firstWhere('nome', 'Lettera k (art. 5 CTS)');

        $this->put($this->url($this->movimento()), ['imputazioni' => [['tipologia_id' => $this->tipologia('Utenze'), 'area' => 'E', 'attivita_id' => $lettera->id]]])
            ->assertSessionHasErrors('imputazioni.0.attivita_id');
    }

    public function test_senza_area_ne_iniziativa_con_area_predefinita_si_rifiuta(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));

        $this->put($this->url($this->movimento()), ['imputazioni' => [['tipologia_id' => $this->tipologia('Utenze')]]])->assertSessionHasErrors('imputazioni.0.area');
    }

    public function test_la_controparte_e_socio_terzo_o_non_pertinente(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $movimento = $this->movimento(['verso' => 'entrata']);

        $this->put($this->url($movimento), ['tipo_controparte' => 'socio', 'imputazioni' => []])->assertSessionHasNoErrors();
        $this->assertSame('socio', $movimento->refresh()->tipo_controparte);
        $this->assertSame('da_classificare', $movimento->stato); // senza imputazioni non è proposto

        $this->put($this->url($movimento), ['tipo_controparte' => 'cliente', 'imputazioni' => []])->assertSessionHasErrors('tipo_controparte');
    }

    public function test_la_classificazione_finisce_nel_registro_delle_modifiche(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');
        $movimento = $this->movimento();

        $this->actingAs($tesoriere)->put($this->url($movimento), ['imputazioni' => [['tipologia_id' => $this->tipologia('Utenze'), 'area' => 'E']]]);

        $riga = RegistroModifica::where('oggetto_tipo', 'movimento')->where('azione', 'classificato')->firstOrFail();
        $this->assertSame($tesoriere->id, $riga->user_id);
        $this->assertNull($riga->prima['imputazioni']);
        $this->assertStringContainsString('Utenze', $riga->dopo['imputazioni']);
        $this->assertSame('proposto', $riga->dopo['stato']);
        $this->actingAs($tesoriere)->get('/registro')->assertOk()->assertSee('ha classificato');
    }

    public function test_il_tesoriere_conferma_un_movimento_classificato(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');
        $movimento = $this->movimento();
        $this->actingAs($tesoriere)->put($this->url($movimento), ['imputazioni' => [['tipologia_id' => $this->tipologia('Utenze'), 'area' => 'E']]]);

        $this->conferma($movimento)->assertRedirect()->assertSessionHasNoErrors();

        $movimento->refresh();
        $this->assertSame('confermato', $movimento->stato);
        $this->assertSame($tesoriere->id, $movimento->confermato_da);
        $this->assertNotNull($movimento->confermato_il);
        $this->assertTrue(RegistroModifica::where('azione', 'confermato')->exists());
    }

    public function test_senza_tipologia_o_senza_imputazioni_non_si_conferma(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $senzaImputazioni = $this->movimento();
        $senzaTipologia = $this->movimento();
        $this->put($this->url($senzaTipologia), ['imputazioni' => [['iniziativa_id' => $this->iniziativa('Carnevale')]]])->assertSessionHasNoErrors();

        $this->conferma($senzaImputazioni)->assertSessionHasErrors('conferma');
        $this->conferma($senzaTipologia)->assertSessionHasErrors('conferma');

        $this->assertSame('da_classificare', $senzaImputazioni->refresh()->stato);
        $this->assertSame('proposto', $senzaTipologia->refresh()->stato);
    }

    public function test_un_importo_o_un_verso_cambiati_dopo_la_classificazione_impediscono_la_conferma(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $movimento = $this->movimento();
        $this->put($this->url($movimento), ['imputazioni' => [['tipologia_id' => $this->tipologia('Utenze'), 'area' => 'E']]]);

        $movimento->update(['importo' => 9999]);
        $this->conferma($movimento)->assertSessionHasErrors('conferma');

        $movimento->update(['importo' => 1250, 'verso' => 'entrata']);
        $this->conferma($movimento)->assertSessionHasErrors('conferma');
        $this->assertSame('proposto', $movimento->refresh()->stato);
    }

    public function test_riclassificare_un_movimento_confermato_lo_lascia_confermato_a_nome_di_chi_lo_ha_rifatto_e_l_autore_non_riprende_i_diritti(): void
    {
        $consigliere = $this->utenteCon('Consigliere');
        $movimento = $this->movimento([], $consigliere);
        $this->actingAs($consigliere)->put($this->url($movimento), ['imputazioni' => [['tipologia_id' => $this->tipologia('Utenze'), 'area' => 'E']]]);
        $tesoriere = $this->utenteCon('Tesoriere');
        $this->actingAs($tesoriere);
        $this->conferma($movimento);

        $this->put($this->url($movimento), ['imputazioni' => [['tipologia_id' => $this->tipologia('Assicurazioni'), 'area' => 'E']]])->assertSessionHasNoErrors();

        $movimento->refresh();
        $this->assertSame('confermato', $movimento->stato);
        $this->assertSame($tesoriere->id, $movimento->confermato_da);
        $this->assertNotNull($movimento->confermato_il);
        $this->assertSame('Assicurazioni', $movimento->imputazioni()->first()->tipologia->nome);
        $this->actingAs($consigliere)->post("/movimenti/{$movimento->id}/ritiro")->assertForbidden();
        $this->put($this->url($movimento), ['imputazioni' => []])->assertForbidden();
    }

    public function test_cambiare_solo_i_tag_non_cambia_lo_stato(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');
        $consigliere = $this->utenteCon('Consigliere');
        $movimento = $this->movimento([], $consigliere);
        $imputazione = ['tipologia_id' => $this->tipologia('Utenze'), 'area' => 'E'];
        $tag = Tag::create(['nome' => 'Festa 2026'])->id;
        $this->actingAs($consigliere)->put($this->url($movimento), ['imputazioni' => [$imputazione]]);

        $this->actingAs($tesoriere)->put($this->url($movimento), ['imputazioni' => [$imputazione + ['tag' => [$tag]]]])->assertSessionHasNoErrors();
        $movimento->refresh();
        $this->assertSame('proposto', $movimento->stato);
        $this->assertSame($consigliere->id, $movimento->proposto_da);

        $this->conferma($movimento);
        $this->put($this->url($movimento), ['imputazioni' => [$imputazione]])->assertSessionHasNoErrors();
        $movimento->refresh();
        $this->assertSame('confermato', $movimento->stato);
        $this->assertSame($tesoriere->id, $movimento->confermato_da);
    }

    public function test_un_movimento_confermato_non_si_riclassifica_in_modo_che_non_si_confermerebbe(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $movimento = $this->movimento();
        $this->put($this->url($movimento), ['imputazioni' => [['tipologia_id' => $this->tipologia('Utenze'), 'area' => 'E']]]);
        $this->conferma($movimento);

        $this->put($this->url($movimento), ['imputazioni' => [['iniziativa_id' => $this->iniziativa('Carnevale')]]])->assertSessionHasErrors('conferma');

        $this->assertSame('confermato', $movimento->refresh()->stato);
        $this->assertSame('Utenze', $movimento->imputazioni()->first()->tipologia->nome);
    }

    public function test_la_conferma_e_legata_alla_versione_vista(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $movimento = $this->movimento();
        $this->put($this->url($movimento), ['imputazioni' => [['tipologia_id' => $this->tipologia('Utenze'), 'area' => 'E']]]);
        $vista = Classificazione::versione($movimento);

        $this->put($this->url($movimento), ['imputazioni' => [['tipologia_id' => $this->tipologia('Assicurazioni'), 'area' => 'E']]]);
        $this->post("/movimenti/{$movimento->id}/conferma", ['versione' => $vista])->assertSessionHasErrors('conferma');
        $this->post("/movimenti/{$movimento->id}/conferma")->assertSessionHasErrors('versione');
        $this->assertSame('proposto', $movimento->refresh()->stato);

        $this->conferma($movimento)->assertSessionHasNoErrors();
        $this->assertSame('confermato', $movimento->refresh()->stato);
    }

    public function test_una_data_spostata_in_un_esercizio_senza_voce_impedisce_la_conferma(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $movimento = $this->movimento();
        $this->put($this->url($movimento), ['imputazioni' => [['tipologia_id' => $this->tipologia('Utenze'), 'area' => 'E']]]);

        $movimento->update(['data' => '2027-01-10']);

        $this->conferma($movimento)->assertSessionHasErrors('conferma');
        $this->assertSame('proposto', $movimento->refresh()->stato);
    }

    public function test_un_consigliere_propone_sul_proprio_movimento_non_confermato_e_non_conferma(): void
    {
        $consigliere = $this->utenteCon('Consigliere', [], ['name' => 'Dario Gialli']);
        $movimento = $this->movimento([], $consigliere);

        $this->actingAs($consigliere)->put($this->url($movimento), ['imputazioni' => [['tipologia_id' => $this->tipologia('Utenze'), 'area' => 'E']]])->assertSessionHasNoErrors();
        $this->assertSame('proposto', $movimento->refresh()->stato);
        $this->assertSame($consigliere->id, $movimento->proposto_da);
        $this->get('/movimenti')->assertSee('Proposta da Dario Gialli');

        $this->post("/movimenti/{$movimento->id}/conferma")->assertForbidden();
        $this->assertSame('proposto', $movimento->refresh()->stato);
    }

    public function test_un_consigliere_non_classifica_i_movimenti_degli_altri_ne_i_confermati_ne_fuori_ambito(): void
    {
        $consigliere = $this->utenteCon('Consigliere');
        $altrui = $this->movimento();
        $confermato = $this->movimento(['stato' => 'confermato'], $consigliere);
        $altroConto = Conto::factory()->create(['aperto_il' => '2026-01-01']);
        $limitato = $this->utenteCon('Consigliere', ['conti' => [$this->festa->id]]);
        $suo = $this->movimento([], $limitato);
        $suoFuori = Movimento::factory()->create(['conto_id' => $altroConto->id, 'inserito_da' => $limitato->id]);
        $dati = ['imputazioni' => [['tipologia_id' => $this->tipologia('Utenze'), 'area' => 'E']]];

        $this->actingAs($consigliere);
        $this->put($this->url($altrui), $dati)->assertForbidden();
        $this->put($this->url($confermato), $dati)->assertForbidden();
        $this->get($this->url($altrui))->assertForbidden();

        $this->actingAs($limitato);
        $this->put($this->url($suo), $dati)->assertSessionHasNoErrors();
        $this->put($this->url($suoFuori), $dati)->assertForbidden();

        $this->assertSame(0, $altrui->imputazioni()->count());
        $this->assertSame(0, $suoFuori->imputazioni()->count());
    }

    public function test_un_lettore_esterno_non_classifica_ne_conferma(): void
    {
        $this->actingAs($this->utenteCon('Lettore esterno'));
        $movimento = $this->movimento();

        $this->put($this->url($movimento), ['imputazioni' => []])->assertForbidden();
        $this->post("/movimenti/{$movimento->id}/conferma")->assertForbidden();
        $this->post("/movimenti/{$movimento->id}/giustificativi", ['riferimento' => 'Fattura 1'])->assertForbidden();
    }

    public function test_si_registrano_i_giustificativi_e_si_filtrano_le_spese_senza(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');
        $con = $this->movimento(['descrizione' => 'Spesa con fattura']);
        $senza = $this->movimento(['descrizione' => 'Spesa senza carte']);
        $entrata = $this->movimento(['descrizione' => 'Incasso quote', 'verso' => 'entrata']);
        $this->actingAs($tesoriere);

        $this->post("/movimenti/{$con->id}/giustificativi", ['riferimento' => 'Fattura 12 del 3/5/2026', 'link' => 'https://drive.google.com/file/d/abc'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/movimenti/{$con->id}/giustificativi", ['riferimento' => 'Scontrino cartaceo nel faldone 2026'])->assertSessionHasNoErrors();

        $this->assertSame(2, $con->giustificativi()->count());
        $this->get('/movimenti')->assertSee('Fattura 12 del 3/5/2026')->assertSee('https://drive.google.com/file/d/abc');
        $this->get('/movimenti?senza_giustificativo=1')->assertOk()
            ->assertSee('Spesa senza carte')->assertDontSee('Spesa con fattura')->assertDontSee('Incasso quote'); // solo le uscite

        $this->delete('/giustificativi/'.$con->giustificativi()->first()->id)->assertRedirect();
        $this->assertSame(1, $con->giustificativi()->count());
    }

    public function test_il_link_di_un_giustificativo_deve_essere_un_indirizzo_web(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'));
        $movimento = $this->movimento();

        $this->post("/movimenti/{$movimento->id}/giustificativi", ['riferimento' => 'Fattura', 'link' => 'javascript:alert(1)'])->assertSessionHasErrors('link');
        $this->post("/movimenti/{$movimento->id}/giustificativi", ['riferimento' => ''])->assertSessionHasErrors('riferimento');
        $this->assertSame(0, $movimento->giustificativi()->count());
    }

    public function test_l_autore_aggiunge_giustificativi_al_proprio_movimento_ma_non_a_quello_degli_altri(): void
    {
        $consigliere = $this->utenteCon('Consigliere');
        $suo = $this->movimento([], $consigliere);
        $altrui = $this->movimento();

        $this->actingAs($consigliere);
        $this->post("/movimenti/{$suo->id}/giustificativi", ['riferimento' => 'Scontrino'])->assertSessionHasNoErrors();
        $this->post("/movimenti/{$altrui->id}/giustificativi", ['riferimento' => 'Scontrino'])->assertForbidden();
        $this->delete('/giustificativi/'.$suo->giustificativi()->sole()->id)->assertRedirect();
        $this->assertSame(0, $suo->giustificativi()->count());
    }

    public function test_la_pagina_di_classificazione_mostra_la_spiegazione_delle_tipologie_e_le_imputazioni_salvate(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');
        $movimento = $this->movimento();
        $this->actingAs($tesoriere)->put($this->url($movimento), ['imputazioni' => [['tipologia_id' => $this->tipologia('Utenze'), 'area' => 'E']]]);

        $this->get($this->url($movimento))->assertOk()
            ->assertSee('Luce, gas, acqua, telefono.')->assertSee('Uscite E2')
            ->assertDontSee('Quote pagate dai soci per l’iscrizione.'); // tipologia di entrata su un'uscita
    }
}
