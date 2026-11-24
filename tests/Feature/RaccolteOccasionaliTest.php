<?php

namespace Tests\Feature;

use App\Esercizio\Deposito;
use App\Esercizio\Prospetto;
use App\Esercizio\Raccolte;
use App\Import\Foglio;
use App\Models\Attivita;
use App\Models\Conto;
use App\Models\DatiEsercizio;
use App\Models\ImpostazioniAssociazione;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\LiberalitaNonMonetaria;
use App\Models\Movimento;
use App\Models\RaccoltaOccasionale;
use App\Models\Tipologia;
use App\Models\User;
use App\Models\VersioneEsercizio;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Le raccolte fondi occasionali dal database (#61, #18): voce C1 o C2 dall'attività, una raccolta per iniziativa, quadratura con C2. Dati inventati. */
class RaccolteOccasionaliTest extends TestCase
{
    use RefreshDatabase;

    private User $tesoriere;

    private Conto $cassa;

    private Conto $banca;

    private Iniziativa $notte;

    private Iniziativa $banchetto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-20 09:30');
        $this->tesoriere = $this->utenteCon('Tesoriere');
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa contanti', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
        $this->banca = Conto::factory()->create(['tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
        $this->notte = Iniziativa::create(['nome' => 'Notte delle lanterne']);
        $this->banchetto = Iniziativa::create(['nome' => 'Banchetto di primavera']);
    }

    private function occasionale(): Attivita
    {
        return Attivita::where('raccolta', 'occasionale')->firstOrFail();
    }

    /** Un'imputazione in area C, sul conto dato: l'attività è quella occasionale se non si dice altro. */
    private function imputaC(string $tipologia, int $importo, ?Iniziativa $iniziativa, array $extra = [], ?Conto $conto = null, string $verso = 'entrata'): Imputazione
    {
        $m = Movimento::factory()->confermato()->create(['conto_id' => ($conto ?? $this->cassa)->id, 'verso' => $verso, 'importo' => $importo, 'data' => '2026-05-10', 'inserito_da' => $this->tesoriere->id]);

        return Imputazione::create($extra + [
            'movimento_id' => $m->id, 'importo' => $importo, 'area' => 'C', 'attivita_id' => $this->occasionale()->id, 'iniziativa_id' => $iniziativa?->id,
            'tipologia_id' => Tipologia::where('nome', $tipologia)->firstOrFail()->id,
        ]);
    }

    public function test_le_attivita_di_raccolta_e_le_voci_c_sono_nei_dati_iniziali(): void
    {
        $this->assertSame(['Raccolta fondi abituale' => 'abituale', 'Raccolta fondi occasionale' => 'occasionale'], Attivita::where('area', 'C')->pluck('raccolta', 'nome')->all());
        $this->assertContains('liberalita', Tipologia::where('nome', 'Erogazioni liberali')->firstOrFail()->proprieta);
        $voce = fn (string $t) => DB::table('voci_modello_d')->where(['esercizio' => 2026, 'area' => 'C', 'tipologia_id' => Tipologia::where('nome', $t)->value('id')])->value('voce');
        $this->assertSame('C1', $voce('Erogazioni liberali'));
        $this->assertSame('C1', $voce('Materiale di comunicazione'));
        $this->assertNull($voce('Quote associative')); // una quota non è una raccolta fondi
        $this->assertSame('liberalita_monetarie', DB::table('righe_raccolta')->where(['esercizio' => 2026, 'tipologia_id' => Tipologia::where('nome', 'Erogazioni liberali')->value('id')])->value('riga'));
    }

    public function test_la_proprieta_liberalita_non_segna_da_rivedere_le_forzature_gia_fatte(): void
    {
        $i = $this->imputaC('Erogazioni liberali', 1000, $this->notte);

        $this->assertContains('liberalita', $i->tipologia->proprieta);
        $this->assertNotContains('liberalita', $i->ingressiNatura()['proprieta']);
    }

    public function test_in_area_c_la_voce_dipende_dall_attivita_c1_abituale_c2_occasionale(): void
    {
        $this->imputaC('Erogazioni liberali', 10000, $this->notte);
        $this->imputaC('Erogazioni liberali', 3000, null, ['attivita_id' => Attivita::where('raccolta', 'abituale')->value('id')]);
        $this->imputaC('Materiale di comunicazione', 2000, $this->notte, [], null, 'uscita');

        $m = Prospetto::modelloD(2026);

        $c = $m['aree']['C'];
        $this->assertSame(3000, $c['entrate']['righe'][0]['importo']);  // C1
        $this->assertSame(10000, $c['entrate']['righe'][1]['importo']); // C2
        $this->assertSame(2000, $c['uscite']['righe'][1]['importo']);   // C2 uscite
        $this->assertSame(0, $m['senzaRiga']['entrate']);
        $this->assertSame('C2', Imputazione::where('importo', 10000)->firstOrFail()->voce());
        $this->assertSame('C1', Imputazione::where('importo', 3000)->firstOrFail()->voce());
    }

    public function test_una_raccolta_per_iniziativa_con_il_denaro_diviso_tra_cassa_e_conti(): void
    {
        $this->imputaC('Erogazioni liberali', 30000, $this->notte);
        $this->imputaC('Erogazioni liberali', 20000, $this->notte, [], $this->banca);
        $this->imputaC('Corrispettivi da terzi', 700, $this->notte);
        $this->imputaC('Affitti e noleggi', 4000, $this->notte, [], null, 'uscita');
        $this->imputaC('Erogazioni liberali', 5000, $this->banchetto);

        $r = Raccolte::calcola(2026);

        $this->assertSame(['Banchetto di primavera', 'Notte delle lanterne'], array_column($r['raccolte'], 'nome'));
        $notte = $r['raccolte'][1];
        $this->assertSame(50000, $notte['proventi']['liberalita_monetarie']);
        $this->assertSame(700, $notte['proventi']['altri_proventi']);
        $this->assertSame(4000, $notte['oneri']['righe']['noleggi_affitti']);
        $this->assertSame(['contanti' => 30700, 'conti' => 20000, 'totale' => 50700], $notte['relazione']['denaro']);
        $this->assertTrue($r['quadratura']['quadra']);
        $this->assertSame([], $r['bloccanti']);
        $this->assertSame(55700, $r['quadratura']['entrate']['c2']);
    }

    public function test_una_c2_senza_iniziativa_o_senza_riga_dello_schema_rompe_la_quadratura_e_si_segnala(): void
    {
        $this->imputaC('Erogazioni liberali', 10000, $this->notte);
        $this->imputaC('Erogazioni liberali', 2500, null);
        DB::table('righe_raccolta')->where('tipologia_id', Tipologia::where('nome', 'Materiale di comunicazione')->value('id'))->delete();
        $this->imputaC('Materiale di comunicazione', 900, $this->notte, [], null, 'uscita');

        $r = Raccolte::calcola(2026);

        $this->assertFalse($r['quadratura']['quadra']);
        $this->assertSame(2500, $r['quadratura']['entrate']['senzaIniziativa']);
        $this->assertSame(900, $r['quadratura']['uscite']['senzaRiga']);
        $this->assertCount(2, $r['quadratura']['messaggi']);
        $this->assertSame($r['quadratura']['messaggi'], array_slice($r['bloccanti'], 0, 2));
        $this->assertSame(2, $r['senzaRiga']); // quella senza iniziativa e quella senza riga
    }

    public function test_la_relazione_riporta_le_date_dei_movimenti_se_nessuno_le_ha_scritte(): void
    {
        $this->raccoltaCheQuadra();

        $campi = Raccolte::calcola(2026)['raccolte'][0]['relazione']['campi'];

        $this->assertSame('2026-05-10', $campi['dal']);
        $this->assertSame('2026-05-10', $campi['al']);
        $this->come($this->tesoriere)->get('/esercizi/2026/raccolte/'.$this->notte->id)->assertSeeText('dal 10/05/2026 al 10/05/2026');
    }

    public function test_un_iniziativa_con_dati_di_raccolta_non_si_cancella_si_archivia_e_la_raccolta_resta(): void
    {
        $raccolta = RaccoltaOccasionale::create(['iniziativa_id' => $this->banchetto->id, 'esercizio' => 2026, 'occasione' => 'Giornata del volontariato']);
        $raccolta->liberalita()->create(['descrizione' => 'Cesti', 'quantita' => 1, 'valore' => 1000, 'criterio' => 'Listino', 'documento' => 'Ricevuta n. 1']);

        $this->actingAs($this->tesoriere)->delete("/classificazione/iniziative/{$this->banchetto->id}")->assertSessionHasErrors('elimina');

        $this->assertNotNull(Iniziativa::find($this->banchetto->id));
        $this->assertSame(1, LiberalitaNonMonetaria::count());
        $this->assertThrows(fn () => DB::table('iniziative')->where('id', $this->banchetto->id)->delete(), QueryException::class); // nemmeno per altra strada la raccolta sparisce in silenzio
    }

    public function test_un_attivita_di_destinazione_di_una_raccolta_non_si_cancella_si_archivia(): void
    {
        $lettera = Attivita::where('area', 'A')->firstOrFail();
        RaccoltaOccasionale::create(['iniziativa_id' => $this->banchetto->id, 'esercizio' => 2026, 'attivita_destinazione_id' => $lettera->id]);

        $this->actingAs($this->tesoriere)->delete("/classificazione/attivita/{$lettera->id}")->assertSessionHasErrors('elimina');

        $this->assertNotNull(Attivita::find($lettera->id));
    }

    public function test_un_iniziativa_con_i_dati_della_raccolta_ma_senza_imputazioni_compare_lo_stesso(): void
    {
        RaccoltaOccasionale::create(['iniziativa_id' => $this->banchetto->id, 'esercizio' => 2026, 'occasione' => 'Giornata del volontariato']);

        $r = Raccolte::calcola(2026);

        $this->assertSame('Giornata del volontariato', $r['raccolte'][0]['relazione']['campi']['occasione']);
        $this->assertSame(0, $r['raccolte'][0]['risultato']);
    }

    private function come(User $utente): static
    {
        $this->flushSession();

        return $this->actingAs($utente)->withSession(['auth.password_confirmed_at' => now()->unix()]);
    }

    /** Una raccolta che quadra: 500,00 di offerte e 120,00 di spese, il dato per l'approvazione. */
    private function raccoltaCheQuadra(): void
    {
        ImpostazioniAssociazione::updateOrCreate(['dal_esercizio' => 2026], ['denominazione' => 'Contrada La Torre', 'codice_fiscale' => '00000000000', 'numero_runts' => '12345']);
        $this->imputaC('Erogazioni liberali', 50000, $this->notte);
        $this->imputaC('Materiale di comunicazione', 12000, $this->notte, [], null, 'uscita');
    }

    private function approva(): TestResponse
    {
        $this->travelTo('2027-04-20 10:00');

        return $this->come($this->tesoriere)->post('/esercizi/2026/approvazione', ['data' => '2027-04-20']);
    }

    public function test_non_si_approva_se_la_somma_delle_raccolte_non_e_la_voce_c2(): void
    {
        $this->raccoltaCheQuadra();
        $this->imputaC('Erogazioni liberali', 2500, null); // C2 senza iniziativa: in nessuna raccolta

        $this->approva()->assertSessionHasErrors('raccolte');

        $this->assertSame('aperto', DatiEsercizio::per(2026)->stato);
        $this->assertSame(0, VersioneEsercizio::where('esercizio', 2026)->count());
    }

    public function test_non_si_approva_senza_la_motivazione_di_una_raccolta_oltre_il_90_per_cento_e_con_la_motivazione_si(): void
    {
        $this->raccoltaCheQuadra();
        $this->imputaC('Materiale di comunicazione', 40000, $this->notte, [], null, 'uscita'); // spese 520,00 su entrate 500,00: risultato negativo

        $this->approva()->assertSessionHasErrors('raccolte');
        $this->assertSame('aperto', DatiEsercizio::per(2026)->stato);

        RaccoltaOccasionale::create(['iniziativa_id' => $this->notte->id, 'esercizio' => 2026, 'motivazione' => 'Allestimento del palco più costoso del previsto']);
        $this->approva()->assertSessionHasNoErrors();

        $versione = VersioneEsercizio::vigente(2026);
        $this->assertSame(['Notte delle lanterne'], array_column($versione->istantanea['raccolte']['raccolte'], 'nome'));
        $this->assertSame(-2000, $versione->istantanea['raccolte']['raccolte'][0]['risultato']);
    }

    public function test_il_pdf_di_deposito_allega_una_raccolta_per_iniziativa_dopo_il_modello_d(): void
    {
        $this->raccoltaCheQuadra();
        $this->imputaC('Erogazioni liberali', 5000, $this->banchetto);
        $this->approva()->assertSessionHasNoErrors();

        $documenti = Deposito::documenti(Deposito::istantanea(2026));

        $this->assertSame(['Modello D · Rendiconto per cassa 2026', 'Raccolta fondi occasionale · Banchetto di primavera · 2026', 'Raccolta fondi occasionale · Notte delle lanterne · 2026'], array_column($documenti, 'titolo'));
        $r = $this->come($this->tesoriere)->get('/esercizi/2026/deposito.pdf')->assertOk();
        $this->assertStringContainsString('<pdfaid:part>1</pdfaid:part>', $r->getContent());
        $this->assertStringContainsString('<pdfaid:conformance>B</pdfaid:conformance>', $r->getContent());
    }

    public function test_senza_raccolte_il_pdf_di_deposito_e_il_solo_modello_d(): void
    {
        ImpostazioniAssociazione::updateOrCreate(['dal_esercizio' => 2026], ['denominazione' => 'Contrada La Torre', 'codice_fiscale' => '00000000000', 'numero_runts' => '12345']);
        $this->approva()->assertSessionHasNoErrors();

        $this->assertSame(['Modello D · Rendiconto per cassa 2026'], array_column(Deposito::documenti(Deposito::istantanea(2026)), 'titolo'));
    }

    public function test_un_istantanea_di_prima_delle_raccolte_si_stampa_ancora(): void
    {
        ImpostazioniAssociazione::updateOrCreate(['dal_esercizio' => 2026], ['denominazione' => 'Contrada La Torre', 'codice_fiscale' => '00000000000', 'numero_runts' => '12345']);
        $this->approva()->assertSessionHasNoErrors();
        $istantanea = Deposito::istantanea(2026);
        unset($istantanea['prospetti']['raccolte']);

        $this->assertStringStartsWith('%PDF-', Deposito::pdf($istantanea));
    }

    public function test_l_elenco_di_controllo_segnala_le_imputazioni_senza_riga_della_raccolta(): void
    {
        $this->raccoltaCheQuadra();
        DB::table('righe_raccolta')->where('tipologia_id', Tipologia::where('nome', 'Materiale di comunicazione')->value('id'))->delete();

        $this->come($this->tesoriere)->get('/esercizi/2026/approvazione')->assertOk()->assertSeeText('1 imputazione delle raccolte fondi senza riga dello schema');
    }

    public function test_in_area_c_si_classifica_solo_con_un_attivita_di_raccolta(): void
    {
        $m = Movimento::factory()->create(['conto_id' => $this->cassa->id, 'verso' => 'entrata', 'importo' => 3000, 'data' => '2026-05-10', 'inserito_da' => $this->tesoriere->id]);
        $liberalita = Tipologia::where('nome', 'Erogazioni liberali')->value('id');
        $riga = fn (array $extra) => ['imputazioni' => [['tipologia_id' => $liberalita, 'area' => 'C', 'iniziativa_id' => $this->notte->id] + $extra]];

        $this->actingAs($this->tesoriere)->put("/movimenti/{$m->id}/classificazione", $riga([]))->assertSessionHasErrors('imputazioni.0.attivita_id');
        $this->actingAs($this->tesoriere)->put("/movimenti/{$m->id}/classificazione", $riga(['attivita_id' => Attivita::where('area', 'A')->first()->id]))->assertSessionHasErrors('imputazioni.0.attivita_id');
        $this->assertSame(0, $m->imputazioni()->count());

        $this->actingAs($this->tesoriere)->put("/movimenti/{$m->id}/classificazione", $riga(['attivita_id' => $this->occasionale()->id]))->assertSessionHasNoErrors();
        $this->assertSame('C2', $m->imputazioni()->sole()->voce());
    }

    public function test_il_tipo_di_raccolta_si_sceglie_alla_creazione_di_un_attivita_in_area_c_e_non_cambia(): void
    {
        $this->actingAs($this->tesoriere)->post('/classificazione/attivita', ['area' => 'C', 'nome' => 'Cassetta delle offerte'])->assertSessionHasErrors('raccolta');
        $this->actingAs($this->tesoriere)->post('/classificazione/attivita', ['area' => 'C', 'nome' => 'Cassetta delle offerte', 'raccolta' => 'occasionale'])->assertSessionHasNoErrors();
        $this->actingAs($this->tesoriere)->post('/classificazione/attivita', ['area' => 'B', 'nome' => 'Vendita di pane', 'raccolta' => 'occasionale'])->assertSessionHasNoErrors();

        $this->assertSame('occasionale', Attivita::where('nome', 'Cassetta delle offerte')->value('raccolta'));
        $this->assertNull(Attivita::where('nome', 'Vendita di pane')->value('raccolta')); // fuori dall'area C non conta
        $id = Attivita::where('nome', 'Cassetta delle offerte')->value('id');
        $this->actingAs($this->tesoriere)->put("/classificazione/attivita/$id", ['nome' => 'Cassetta', 'raccolta' => 'abituale'])->assertSessionHasNoErrors();
        $this->assertSame('occasionale', Attivita::find($id)->raccolta);
    }

    private function dati(array $extra = []): array
    {
        return $extra + ['dal' => '2026-12-08', 'al' => '2026-12-09', 'occasione' => 'Giornata del volontariato', 'beni_servizi' => 'Nessuno', 'descrizione' => 'Banchetto in piazza', 'luogo' => 'Piazza', 'modalita' => 'Offerte libere',
            'finalita' => 'Restauro del lavatoio', 'dettaglio_costi' => 'Stampa dei volantini', 'attivita_destinazione_id' => Attivita::where('area', 'A')->value('id'),
            'beni_modico_numero' => '12', 'beni_modico_prezzo' => '2,50', 'elargizioni_persone_fisiche' => '400,00', 'elargizioni_persone_giuridiche' => '100,00', 'motivazione' => ''];
    }

    public function test_l_elenco_e_la_pagina_della_raccolta_mostrano_rendiconto_relazione_e_guida(): void
    {
        $this->raccoltaCheQuadra();

        $this->come($this->tesoriere)->get('/esercizi/2026/raccolte')->assertOk()->assertSeeText('Notte delle lanterne')->assertSeeText('500,00')->assertSeeText('Quadratura con la voce C2')
            ->assertSeeText('La somma delle raccolte coincide con la voce C2');
        $pagina = $this->come($this->tesoriere)->get('/esercizi/2026/raccolte/'.$this->notte->id)->assertOk();
        $pagina->assertSeeText('Proventi (a)')->assertSeeText('Oneri (b)')->assertSeeText('Relazione illustrativa')->assertSeeText('Elenco guida per la relazione')->assertSeeText('da completare')
            ->assertSeeText('Denaro raccolto in contanti')->assertSeeText('380,00');
        $this->come($this->tesoriere)->get('/esercizi/2026/raccolte/'.$this->banchetto->id)->assertNotFound(); // senza imputazioni né dati: non è una raccolta dell'esercizio
    }

    public function test_il_tesoriere_scrive_i_dati_della_raccolta_e_la_relazione_li_riporta(): void
    {
        $this->raccoltaCheQuadra();

        $this->come($this->tesoriere)->put('/esercizi/2026/raccolte/'.$this->notte->id, $this->dati())->assertSessionHasNoErrors();

        $r = RaccoltaOccasionale::firstOrFail();
        $this->assertSame('2026-12-08', $r->dal->toDateString());
        $this->assertSame(250, $r->beni_modico_prezzo);
        $this->assertSame(40000, $r->elargizioni_persone_fisiche);
        $this->assertNull($r->motivazione); // il testo vuoto è «non scritto»
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'raccolta_occasionale', 'oggetto_id' => $r->id, 'azione' => 'creato', 'user_id' => $this->tesoriere->id]);
        $pagina = $this->come($this->tesoriere)->get('/esercizi/2026/raccolte/'.$this->notte->id)->assertOk();
        $pagina->assertSeeText('Banchetto in piazza')->assertSeeText('dal 08/12/2026 al 09/12/2026')->assertDontSeeText('da completare');

        $this->come($this->tesoriere)->put('/esercizi/2026/raccolte/'.$this->notte->id, $this->dati(['luogo' => 'Sagrato']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'raccolta_occasionale', 'azione' => 'modificato']);
        $this->come($this->tesoriere)->put('/esercizi/2026/raccolte/'.$this->notte->id, $this->dati(['luogo' => 'Sagrato']))->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('registro_modifiche')->where('oggetto_tipo', 'raccolta_occasionale')->count(), 'Salvare senza cambiare nulla non è una modifica');
    }

    public function test_l_attivita_di_destinazione_resta_scelta_alla_riapertura_della_pagina(): void
    {
        $this->raccoltaCheQuadra();
        $lettera = Attivita::where('area', 'A')->value('id');
        $this->come($this->tesoriere)->put('/esercizi/2026/raccolte/'.$this->notte->id, $this->dati(['attivita_destinazione_id' => $lettera]))->assertSessionHasNoErrors();

        $this->come($this->tesoriere)->get('/esercizi/2026/raccolte/'.$this->notte->id)->assertSee('value="'.$lettera.'" selected', false);
    }

    public function test_dopo_l_approvazione_i_dati_della_raccolta_non_si_scrivono_piu_perche_il_pdf_stampa_la_versione_approvata(): void
    {
        $this->raccoltaCheQuadra();
        $this->approva()->assertSessionHasNoErrors();

        $this->come($this->tesoriere)->put('/esercizi/2026/raccolte/'.$this->notte->id, $this->dati())->assertSessionHasErrors('esercizio');
        $this->come($this->tesoriere)->post('/esercizi/2026/raccolte/'.$this->notte->id.'/liberalita', ['descrizione' => 'x', 'quantita' => 1, 'valore' => '1,00', 'criterio' => 'x', 'documento' => 'y'])->assertSessionHasErrors('esercizio');
        $this->assertSame(0, RaccoltaOccasionale::count());
        $this->come($this->tesoriere)->get('/esercizi/2026/raccolte/'.$this->notte->id)->assertOk()->assertDontSee('data-merlo="raccolta-modulo"', false)->assertSeeText('riapre l’esercizio');

        // riaperto, si scrive di nuovo
        $this->come($this->amministratore())->post('/esercizi/2026/riapertura', ['motivazione' => 'Relazione da completare'])->assertSessionHasNoErrors();
        $this->come($this->tesoriere)->put('/esercizi/2026/raccolte/'.$this->notte->id, $this->dati())->assertSessionHasNoErrors();
    }

    private function amministratore(): User
    {
        return $this->utenteCon('Amministratore');
    }

    public function test_l_elenco_di_controllo_dice_quanti_campi_della_relazione_mancano(): void
    {
        $this->raccoltaCheQuadra();

        $this->come($this->tesoriere)->get('/esercizi/2026/approvazione')->assertOk()->assertSeeText('«Notte delle lanterne»: 12 campi della relazione da completare');
        $this->come($this->tesoriere)->put('/esercizi/2026/raccolte/'.$this->notte->id, $this->dati())->assertSessionHasNoErrors();
        $this->come($this->tesoriere)->get('/esercizi/2026/approvazione')->assertOk()->assertDontSeeText('della relazione da completare');
    }

    public function test_i_dati_della_raccolta_si_convalidano(): void
    {
        $this->raccoltaCheQuadra();
        $url = '/esercizi/2026/raccolte/'.$this->notte->id;

        $this->come($this->tesoriere)->put($url, $this->dati(['dal' => '2026-12-10', 'al' => '2026-12-09']))->assertSessionHasErrors('al');
        $this->come($this->tesoriere)->put($url, $this->dati(['elargizioni_persone_fisiche' => 'molto']))->assertSessionHasErrors('elargizioni_persone_fisiche');
        $this->come($this->tesoriere)->put($url, $this->dati(['beni_modico_prezzo' => '-1,00']))->assertSessionHasErrors('beni_modico_prezzo');
        $this->come($this->tesoriere)->put($url, $this->dati(['attivita_destinazione_id' => Attivita::where('area', 'B')->value('id')]))->assertSessionHasErrors('attivita_destinazione_id');
        $this->come($this->tesoriere)->put($url, $this->dati(['dal' => '', 'al' => '']))->assertSessionHasNoErrors(); // le date sono facoltative: la guida le chiede
        $this->assertSame(1, RaccoltaOccasionale::count());
        // un'iniziativa che non è una raccolta dell'esercizio non ne ha una
        $this->come($this->tesoriere)->put('/esercizi/2026/raccolte/'.$this->banchetto->id, $this->dati())->assertNotFound();
        $this->assertSame(1, RaccoltaOccasionale::count());
    }

    public function test_le_liberalita_non_monetarie_entrano_solo_nel_rendiconto_della_raccolta(): void
    {
        $this->raccoltaCheQuadra();
        $url = '/esercizi/2026/raccolte/'.$this->notte->id.'/liberalita';

        $this->come($this->tesoriere)->post($url, ['descrizione' => 'Cesti di prodotti locali', 'quantita' => '4', 'valore' => '80,00', 'criterio' => 'Prezzo di listino del donatore', 'documento' => 'Ricevuta n. 1'])->assertSessionHasNoErrors();
        $this->come($this->tesoriere)->post($url, ['descrizione' => 'Niente', 'quantita' => '0', 'valore' => '10,00', 'criterio' => 'x', 'documento' => 'y'])->assertSessionHasErrors('quantita');
        $this->come($this->tesoriere)->post($url, ['descrizione' => 'Niente', 'quantita' => '1', 'valore' => '0', 'criterio' => 'x', 'documento' => 'y'])->assertSessionHasErrors('valore');

        $raccolta = Raccolte::calcola(2026);
        $this->assertSame(8000, $raccolta['raccolte'][0]['proventi']['liberalita_non_monetarie']);
        $this->assertSame(58000, $raccolta['raccolte'][0]['proventi']['totale']);
        $this->assertTrue($raccolta['quadratura']['quadra'], 'Non sono nel Modello D: C2 resta quella dei soli movimenti');
        $this->assertSame(50000, Prospetto::modelloD(2026)['aree']['C']['entrate']['totale']['importo']);
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'raccolta_occasionale', 'azione' => 'liberalita_aggiunta']);
        $this->come($this->tesoriere)->get('/esercizi/2026/raccolte/'.$this->notte->id)->assertSeeText('Cesti di prodotti locali')->assertSeeText('Prezzo di listino del donatore');

        $id = $raccolta['raccolte'][0]['liberalita'][0]['id'];
        $this->come($this->tesoriere)->delete("$url/$id")->assertSessionHasNoErrors();
        $this->assertSame(0, LiberalitaNonMonetaria::count());
        $this->assertDatabaseHas('registro_modifiche', ['azione' => 'liberalita_tolta']);
        $this->come($this->tesoriere)->delete("$url/$id")->assertSessionHasErrors('liberalita'); // già tolta
    }

    public function test_una_liberalita_non_si_toglie_dopo_l_approvazione_ne_se_si_puo_solo_vedere(): void
    {
        $this->raccoltaCheQuadra();
        $raccolta = RaccoltaOccasionale::create(['iniziativa_id' => $this->notte->id, 'esercizio' => 2026]);
        $id = $raccolta->liberalita()->create(['descrizione' => 'Cesti', 'quantita' => 1, 'valore' => 1000, 'criterio' => 'Listino', 'documento' => 'Ricevuta n. 1'])->id;
        $url = '/esercizi/2026/raccolte/'.$this->notte->id."/liberalita/$id";

        $this->come($this->utenteCon('Consigliere'))->delete($url)->assertForbidden();
        $this->assertSame(1, LiberalitaNonMonetaria::count());

        $this->approva()->assertSessionHasNoErrors();
        $this->come($this->tesoriere)->delete($url)->assertSessionHasErrors('esercizio');
        $this->assertSame(1, LiberalitaNonMonetaria::count());
    }

    public function test_chi_vede_non_scrive(): void
    {
        $this->raccoltaCheQuadra();
        $consigliere = $this->utenteCon('Consigliere');

        $this->come($consigliere)->get('/esercizi/2026/raccolte/'.$this->notte->id)->assertOk()->assertDontSee('data-merlo="raccolta-modulo"', false);
        $this->come($consigliere)->put('/esercizi/2026/raccolte/'.$this->notte->id, $this->dati())->assertForbidden();
        $this->come($consigliere)->post('/esercizi/2026/raccolte/'.$this->notte->id.'/liberalita', ['descrizione' => 'x', 'quantita' => 1, 'valore' => '1,00', 'criterio' => 'x', 'documento' => 'y'])->assertForbidden();
        $this->assertSame(0, RaccoltaOccasionale::count());
    }

    public function test_dopo_il_deposito_niente_si_scrive_e_le_pagine_leggono_l_istantanea(): void
    {
        $this->raccoltaCheQuadra();
        $this->come($this->tesoriere)->put('/esercizi/2026/raccolte/'.$this->notte->id, $this->dati())->assertSessionHasNoErrors();
        $this->approva()->assertSessionHasNoErrors();
        $this->come($this->tesoriere)->post('/esercizi/2026/deposito', ['data' => '2027-04-20', 'impronta' => Deposito::impronta(2026)])->assertSessionHasNoErrors();
        $this->assertSame('depositato', DatiEsercizio::per(2026)->stato);

        $this->come($this->tesoriere)->put('/esercizi/2026/raccolte/'.$this->notte->id, $this->dati(['luogo' => 'Altrove']))->assertSessionHasErrors('esercizio');
        $this->come($this->tesoriere)->post('/esercizi/2026/raccolte/'.$this->notte->id.'/liberalita', ['descrizione' => 'x', 'quantita' => 1, 'valore' => '1,00', 'criterio' => 'x', 'documento' => 'y'])->assertSessionHasErrors('esercizio');

        $this->assertSame('Piazza', RaccoltaOccasionale::firstOrFail()->luogo);
        $pagina = $this->come($this->tesoriere)->get('/esercizi/2026/raccolte/'.$this->notte->id)->assertOk();
        $pagina->assertSeeText('Piazza')->assertDontSee('data-merlo="raccolta-modulo"', false);
        // un dato scritto di nascosto dopo il deposito non si vede: né a schermo né nel file
        RaccoltaOccasionale::query()->update(['luogo' => 'Altrove']);
        $this->come($this->tesoriere)->get('/esercizi/2026/raccolte/'.$this->notte->id)->assertSeeText('Piazza')->assertDontSeeText('Altrove');
        $xlsx = Foglio::leggi($this->come($this->tesoriere)->get('/esercizi/2026/raccolte/'.$this->notte->id.'/xlsx')->assertOk()->getContent());
        $this->assertContains('Piazza', collect($xlsx['Raccolta'])->flatten()->all());
    }

    public function test_ogni_raccolta_si_scarica_da_sola_in_pdf_e_in_xlsx_senza_nomi_di_persone(): void
    {
        $this->raccoltaCheQuadra();
        $this->come($this->tesoriere)->put('/esercizi/2026/raccolte/'.$this->notte->id, $this->dati())->assertSessionHasNoErrors();

        $pdf = $this->come($this->tesoriere)->get('/esercizi/2026/raccolte/'.$this->notte->id.'/pdf')->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="Raccolta_2026_ContradaLaTorre_NotteDelleLanterne.pdf"', $pdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertDatabaseHas('registro_accessi', ['evento' => 'esportazione_raccolta_pdf_2026']);

        $r = $this->come($this->tesoriere)->get('/esercizi/2026/raccolte/'.$this->notte->id.'/xlsx')->assertOk();
        $this->assertSame('attachment; filename="Raccolta_2026_ContradaLaTorre_NotteDelleLanterne.xlsx"', $r->headers->get('Content-Disposition'));
        $celle = collect(Foglio::leggi($r->getContent())['Raccolta'])->flatten()->all();
        $this->assertContains('Raccolta fondi occasionale · Notte delle lanterne · 2026', $celle);
        $this->assertContains(500.0, $celle);
        $this->assertContains(120.0, $celle);
        $this->assertContains('Banchetto in piazza', $celle);
        $this->assertContains('Provvisorio – aggiornato al 20/12/2026 09:30. Solo le imputazioni confermate.', $celle);

        $this->come($this->tesoriere)->get('/esercizi/2026/raccolte/'.$this->banchetto->id.'/pdf')->assertNotFound();
        $this->come($this->tesoriere)->get('/esercizi/2026/raccolte/'.$this->notte->id.'/csv')->assertNotFound();
    }

    public function test_aprire_un_esercizio_copia_anche_la_tabella_tipologia_riga_delle_raccolte(): void
    {
        $this->come($this->tesoriere)->post('/esercizi/2027/apertura')->assertSessionHasNoErrors();

        $this->assertGreaterThan(0, DB::table('righe_raccolta')->where('esercizio', 2026)->count());
        $this->assertSame(
            DB::table('righe_raccolta')->where('esercizio', 2026)->orderBy('tipologia_id')->pluck('riga', 'tipologia_id')->all(),
            DB::table('righe_raccolta')->where('esercizio', 2027)->orderBy('tipologia_id')->pluck('riga', 'tipologia_id')->all(),
        );
    }

    public function test_un_iniziativa_predefinita_in_area_c_occasionale_compare_prima_di_avere_movimenti(): void
    {
        DB::table('iniziativa_esercizio')->insert(['iniziativa_id' => $this->notte->id, 'esercizio' => 2026, 'area' => 'C', 'attivita_id' => $this->occasionale()->id]);
        DB::table('iniziativa_esercizio')->insert(['iniziativa_id' => $this->banchetto->id, 'esercizio' => 2026, 'area' => 'C', 'attivita_id' => Attivita::where('raccolta', 'abituale')->value('id')]);

        $this->assertSame(['Notte delle lanterne'], array_column(Raccolte::calcola(2026)['raccolte'], 'nome'));
    }
}
