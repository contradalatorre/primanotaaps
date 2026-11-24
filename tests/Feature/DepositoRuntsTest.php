<?php

namespace Tests\Feature;

use App\Esercizio\Deposito;
use App\Esercizio\Pdf;
use App\Import\Foglio;
use App\Models\Attivita;
use App\Models\Conto;
use App\Models\DatiEsercizio;
use App\Models\DestinazioneAvanzo;
use App\Models\EsercizioStorico;
use App\Models\ImpostazioniAssociazione;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RaccoltaOccasionale;
use App\Models\RegistroModifica;
use App\Models\RigaFigurativa;
use App\Models\Tipologia;
use App\Models\User;
use App\Models\VersioneEsercizio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Il deposito al RUNTS (#60, #12, #18): PDF/A di deposito, data dell'invio, istantanea immutabile, movimenti bloccati. Dati inventati. */
class DepositoRuntsTest extends TestCase
{
    use RefreshDatabase;

    private User $tesoriere;

    private User $amministratore;

    private Conto $cassa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2027-04-20 10:00');
        foreach ([2026, 2027] as $dal) {
            ImpostazioniAssociazione::updateOrCreate(['dal_esercizio' => $dal], ['denominazione' => 'Contrada La Torre', 'codice_fiscale' => '00000000000', 'numero_runts' => '12345']);
        }
        $this->tesoriere = $this->utenteCon('Tesoriere');
        $this->amministratore = $this->utenteCon('Amministratore');
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa contanti', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
    }

    private function imputa(string $verso, int $importo, string $data, string $tipologia, string $area = 'A', string $stato = 'confermato'): Imputazione
    {
        $m = Movimento::factory()->create(['conto_id' => $this->cassa->id, 'verso' => $verso, 'importo' => $importo, 'data' => $data, 'inserito_da' => $this->tesoriere->id, 'stato' => $stato]);

        return Imputazione::create([
            'movimento_id' => $m->id, 'importo' => $importo, 'area' => $area, 'tipologia_id' => Tipologia::where('nome', $tipologia)->firstOrFail()->id,
        ] + ($area === 'A' ? [
            'iniziativa_id' => Iniziativa::where('nome', 'Festa della Torre')->firstOrFail()->id,
            'attivita_id' => Attivita::where('nome', 'Lettera i (art. 5 CTS)')->firstOrFail()->id,
        ] : []));
    }

    private function esercizio2026(): void
    {
        $this->imputa('entrata', 80000, '2026-06-01', 'Somministrazione');
        $this->imputa('uscita', 35000, '2026-06-02', 'Acquisti per la somministrazione');
    }

    /** Una raccolta fondi occasionale (#61) con relazione scritta e una liberalità non monetaria: il PDF di deposito ha un allegato in più, e veraPDF lo controlla con il resto. */
    private function raccoltaOccasionale(string $nome = 'Notte delle lanterne'): void
    {
        $iniziativa = Iniziativa::create(['nome' => $nome]);
        foreach ([['entrata', 50000, 'Erogazioni liberali'], ['uscita', 12000, 'Materiale di comunicazione']] as [$verso, $importo, $tipologia]) {
            $m = Movimento::factory()->create(['conto_id' => $this->cassa->id, 'verso' => $verso, 'importo' => $importo, 'data' => '2026-05-10', 'inserito_da' => $this->tesoriere->id, 'stato' => 'confermato']);
            Imputazione::create(['movimento_id' => $m->id, 'importo' => $importo, 'area' => 'C', 'attivita_id' => Attivita::where('raccolta', 'occasionale')->value('id'), 'iniziativa_id' => $iniziativa->id, 'tipologia_id' => Tipologia::where('nome', $tipologia)->value('id')]);
        }
        $raccolta = RaccoltaOccasionale::create([
            'iniziativa_id' => $iniziativa->id, 'esercizio' => 2026, 'dal' => '2026-05-10', 'al' => '2026-05-10', 'occasione' => 'Giornata del volontariato', 'descrizione' => 'Banchetto in piazza',
            'luogo' => 'Piazza', 'modalita' => 'Offerte libere', 'finalita' => 'Restauro del lavatoio', 'beni_servizi' => 'Nessuno', 'dettaglio_costi' => 'Stampa dei volantini',
        ]);
        $raccolta->liberalita()->create(['descrizione' => 'Cesti di prodotti locali', 'quantita' => 4, 'valore' => 8000, 'criterio' => 'Prezzo di listino', 'documento' => 'Ricevuta n. 1']);
    }

    private function come(User $utente, bool $riautenticato = true): static
    {
        $this->flushSession(); // la sessione dei test sopravvive tra una richiesta e l'altra: l'accesso recente di prima non deve valere

        return $this->actingAs($utente)->withSession($riautenticato ? ['auth.password_confirmed_at' => now()->unix()] : []);
    }

    private function approva(int $anno = 2026): void
    {
        $this->come($this->tesoriere)->post("/esercizi/$anno/approvazione", ['data' => '2027-04-20'])->assertSessionHasNoErrors();
    }

    private function deposita(string $data = '2027-04-25', ?User $utente = null, int $anno = 2026)
    {
        // L'impronta è quella che la pagina porta nel modulo (di un esercizio non approvato non c'è: un valore qualunque).
        return $this->come($utente ?? $this->tesoriere)->post("/esercizi/$anno/deposito", ['data' => $data, 'impronta' => Deposito::impronta($anno) ?? str_repeat('0', 8)]);
    }

    /** L'impronta che chi deposita legge nel piede del PDF scaricato (e riscrive nel modulo). */
    private function improntaDelPdfScaricato(): string
    {
        $this->assertSame(1, preg_match('/impronta ([0-9A-F]{8})$/', Deposito::piede(Deposito::istantanea(2026)), $m));
        $this->come($this->tesoriere)->get('/esercizi/2026/deposito.pdf')->assertOk();

        return $m[1];
    }

    public function test_se_le_impostazioni_cambiano_dopo_il_pdf_non_si_deposita_con_l_impronta_stampata(): void
    {
        $this->esercizio2026();
        $this->approva();
        $stampata = $this->improntaDelPdfScaricato();
        ImpostazioniAssociazione::where('dal_esercizio', 2026)->update(['denominazione' => 'Un altro nome']); // dopo il download, anche a pagina del modulo mai aperta

        $this->come($this->tesoriere)->post('/esercizi/2026/deposito', ['data' => '2027-04-20', 'impronta' => $stampata])->assertSessionHasErrors('impronta');

        $this->assertSame('approvato', DatiEsercizio::per(2026)->stato);
        $this->assertNotSame($stampata, Deposito::impronta(2026));
        $this->deposita('2027-04-20')->assertSessionHasNoErrors(); // con l'impronta del PDF riscaricato si deposita
        $this->assertSame('Un altro nome', Deposito::salvata(2026)['impostazioni']['denominazione']);
    }

    public function test_se_lo_storico_dell_anno_prima_cambia_dopo_il_pdf_non_si_deposita_con_l_impronta_stampata(): void
    {
        $this->esercizio2026();
        $storico = EsercizioStorico::create(['esercizio' => 2025, 'importi' => ['entrate:A1' => 1000], 'saldo_cassa' => 0, 'saldo_depositi' => 0, 'figurativi' => [], 'differenze' => []]);
        $this->approva();
        $stampata = $this->improntaDelPdfScaricato();
        $storico->update(['importi' => ['entrate:A1' => 2000]]);

        $this->come($this->tesoriere)->post('/esercizi/2026/deposito', ['data' => '2027-04-20', 'impronta' => $stampata])->assertSessionHasErrors('impronta');

        $this->assertSame('approvato', DatiEsercizio::per(2026)->stato);
    }

    public function test_l_impronta_si_riscrive_anche_in_minuscolo_e_con_gli_spazi_ma_una_sbagliata_si_rifiuta(): void
    {
        $this->esercizio2026();
        $this->approva();
        $stampata = $this->improntaDelPdfScaricato();

        $this->come($this->tesoriere)->post('/esercizi/2026/deposito', ['data' => '2027-04-20', 'impronta' => '00000000'])->assertSessionHasErrors('impronta');
        $this->come($this->tesoriere)->post('/esercizi/2026/deposito', ['data' => '2027-04-20', 'impronta' => ' '.strtolower($stampata).' '])->assertSessionHasNoErrors();

        $this->assertSame('depositato', DatiEsercizio::per(2026)->stato);
    }

    public function test_la_pagina_chiede_di_riscrivere_l_impronta_del_piede_del_pdf(): void
    {
        $this->esercizio2026();
        $this->approva();

        $this->come($this->tesoriere)->get('/esercizi/2026/approvazione')->assertOk()->assertSeeText('Impronta del PDF')->assertDontSee(Deposito::impronta(2026), false);
    }

    public function test_la_data_dell_invio_porta_l_esercizio_a_depositato_e_salva_l_istantanea(): void
    {
        $this->esercizio2026();
        $this->approva();
        $this->travelTo('2027-04-26 10:00');

        $this->deposita('2027-04-25')->assertRedirect(route('esercizio.approvazione', 2026))->assertSessionHasNoErrors();

        $this->assertSame('depositato', DatiEsercizio::per(2026)->stato);
        $versione = VersioneEsercizio::vigente(2026);
        $this->assertSame('2027-04-25', $versione->depositata_il->toDateString());
        $this->assertSame($this->tesoriere->id, $versione->depositata_da);
        $this->assertSame(80000, $versione->istantanea_deposito['prospetti']['modello_d']['aree']['A']['entrate']['totale']['importo']);
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'esercizio', 'oggetto_id' => 2026, 'azione' => 'depositato', 'user_id' => $this->tesoriere->id]);
    }

    public function test_l_esercizio_approvato_si_scarica_in_pdfa_1b_con_il_nome_di_deposito(): void
    {
        $this->esercizio2026();
        $this->raccoltaOccasionale(); // l'allegato di una raccolta sta nello stesso PDF/A
        $this->approva();

        $r = $this->come($this->tesoriere)->get('/esercizi/2026/deposito.pdf')->assertOk();

        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="Deposito_2026_ContradaLaTorre.pdf"', $r->headers->get('Content-Disposition'));
        $pdf = $r->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('<pdfaid:part>1</pdfaid:part>', $pdf);
        $this->assertStringContainsString('<pdfaid:conformance>B</pdfaid:conformance>', $pdf);
        $this->assertDatabaseHas('registro_accessi', ['evento' => 'esportazione_deposito_pdf_2026']);
        $this->assertGreaterThan(0, preg_match_all('/\/Subtype \/Link/', $pdf), "L'indice rimanda al Modello D con un collegamento");

        // I font sono sottoinsiemi: ognuno ha il suo CIDSet (veraPDF lo vuole, mPDF non lo scrive: `PdfA`) e la tabella xref punta ancora agli oggetti.
        $this->assertSame(preg_match_all('/\/FontName \/MPDF\w+\+/', $pdf), preg_match_all('/\/CIDSet \d+ 0 R/', $pdf));
        preg_match('/startxref\n(\d+)\n%%EOF/', $pdf, $m);
        $this->assertSame('xref', substr($pdf, (int) $m[1], 4));
        preg_match('/xref\n0 (\d+)\n/', $pdf, $x);
        $this->assertSame(1, preg_match('/\/Size '.$x[1].'\b/', $pdf), 'Il /Size del trailer segue la tabella');

        // Su GitHub (test.yml) questo file lo controlla veraPDF, con il profilo PDF/A-1b: PDF_DI_PROVA è il percorso dove scriverlo.
        if ($percorso = getenv('PDF_DI_PROVA')) {
            file_put_contents($percorso, $pdf);
        }
    }

    public function test_il_pdf_di_deposito_ha_l_indice_prima_del_modello_d_e_quello_di_un_prospetto_no(): void
    {
        $sezioni = [['titolo' => 'Sezione', 'colonne' => [], 'righe' => [['celle' => ['Voce'], 'grassetto' => false]]]];

        $deposito = Pdf::html('T', ['Assoc'], [], 'p', [['titolo' => 'Modello D · Rendiconto per cassa 2026', 'sezioni' => $sezioni]])['corpo'];
        $prospetto = Pdf::html('T', ['Assoc'], $sezioni, 'p')['corpo'];

        $this->assertLessThan(strpos($deposito, '<h1>Modello D'), strpos($deposito, '<tocpagebreak'));
        $this->assertStringNotContainsString('<tocpagebreak', $prospetto);
    }

    public function test_un_esercizio_aperto_non_ha_il_pdf_di_deposito(): void
    {
        $this->esercizio2026();

        $this->come($this->tesoriere)->get('/esercizi/2026/deposito.pdf')->assertNotFound();
    }

    /** Un esercizio depositato, e poi un movimento in più nel database (come se qualcosa avesse scritto sotto i piedi del blocco): i prospetti non lo vedono. */
    private function depositatoConUnMovimentoInPiu(): void
    {
        $this->esercizio2026();
        $this->approva();
        $this->deposita('2027-04-20')->assertSessionHasNoErrors();
        $this->imputa('entrata', 10000, '2026-07-01', 'Somministrazione');
    }

    public function test_un_esercizio_depositato_si_legge_solo_dall_istantanea(): void
    {
        $this->depositatoConUnMovimentoInPiu();

        $pagina = $this->come($this->tesoriere)->get('/esercizi/2026/modello-d')->assertOk();
        $pagina->assertSeeText('Depositato il 20/04/2027')->assertSeeText('800,00')->assertDontSeeText('900,00')->assertDontSeeText('Provvisorio')->assertDontSeeText('Si può ancora correggere');
        $this->come($this->tesoriere)->get('/esercizi/2026/rendiconto')->assertOk()->assertSeeText('Depositato il 20/04/2027')->assertSeeText('800,00')->assertDontSeeText('900,00');
        $this->come($this->tesoriere)->get('/esercizi/2026/test')->assertOk()->assertSeeText('Depositato il 20/04/2027');

        $fogli = Foglio::leggi($this->come($this->tesoriere)->get('/esercizi/2026/modello-d/xlsx')->assertOk()->getContent());
        $this->assertContains('Dati di oggi, non quelli depositati', collect($fogli['Dettaglio'])->flatten()->all());
        $celle = collect($fogli['Modello D'])->flatten()->all();
        $this->assertContains(800.0, $celle);
        $this->assertNotContains(900.0, $celle);
        $this->assertContains('Depositato il 20/04/2027 · versione 1 · istantanea del deposito.', $celle);
    }

    /** #90: il titolo dell'indice è testo, mai HTML. mPDF lo decodifica e lo riscrive vivo: un nome con un link o un'immagine remota diventerebbe un `/URI`, una richiesta di rete o un PDF che non si genera. */
    public function test_un_nome_con_html_nel_titolo_resta_testo_nel_pdf_di_deposito(): void
    {
        $sezioni = [['titolo' => 'Sezione', 'colonne' => [], 'righe' => [['celle' => ['Voce'], 'grassetto' => false]]]];
        $nomi = ['<a href="https://esempio.invalid/">clic</a>', '<img src="http://127.0.0.1:9/x.png">', 'Fiera "dei" <b>Fiori</b> & C.'];

        $pdf = Pdf::deposito('T', ['Assoc'], array_map(fn (string $nome) => ['titolo' => 'Raccolta fondi occasionale · '.$nome.' · 2026', 'sezioni' => $sezioni], $nomi), 'p');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringNotContainsString('/URI', $pdf);
        $this->assertSame(6, preg_match_all('/\/Subtype \/Link/', $pdf), "Solo i collegamenti interni dell'indice: titolo e pagina di ognuno dei tre documenti");
    }

    public function test_il_nome_di_un_iniziativa_con_un_link_non_porta_un_uri_nel_pdf_scaricato(): void
    {
        $this->esercizio2026();
        $this->raccoltaOccasionale('<a href="https://esempio.invalid/">clic</a>');
        $this->approva();

        $pdf = $this->come($this->tesoriere)->get('/esercizi/2026/deposito.pdf')->assertOk()->getContent();

        $this->assertStringNotContainsString('/URI', $pdf);
        $this->assertSame(4, preg_match_all('/\/Subtype \/Link/', $pdf), 'Modello D e raccolta, ognuno con titolo e pagina');
    }

    /** #90, #18: dal 2027 il PDF di deposito, la pagina e l'esportazione hanno la colonna 2026 anche se il 2026 non è uno storico ma un esercizio depositato o approvato. */
    private function conIl2026(bool $depositato): void
    {
        $this->esercizio2026();
        $this->approva();
        if ($depositato) {
            $this->deposita('2027-04-20')->assertSessionHasNoErrors();
        }
        $this->imputa('entrata', 20000, '2027-02-01', 'Somministrazione');
    }

    public static function stati2026(): array
    {
        return ['depositato' => [true], 'solo approvato' => [false]];
    }

    #[DataProvider('stati2026')]
    public function test_la_colonna_dell_esercizio_precedente_viene_dal_2026_depositato_o_approvato(bool $depositato): void
    {
        $this->conIl2026($depositato);
        // Dopo l'approvazione i dati del 2026 possono cambiare: la colonna è quella approvata, non quella di oggi.
        $this->imputa('entrata', 10000, '2026-07-01', 'Somministrazione');

        $this->assertSame(80000, Deposito::precedente(2027)['gestione']['entrate']['importo']);
        $this->come($this->tesoriere)->get('/esercizi/2027/modello-d')->assertOk()->assertSeeText('Esercizio 2026')->assertSeeText('800,00')->assertDontSeeText('900,00')->assertDontSeeText('non caricato');
        $celle = collect(Foglio::leggi($this->come($this->tesoriere)->get('/esercizi/2027/modello-d/xlsx')->assertOk()->getContent())['Modello D'])->flatten()->all();
        $this->assertContains('Esercizio 2026 (€)', $celle);
        $this->assertContains(800.0, $celle);
        $this->assertNotContains(900.0, $celle);

        // Il rendiconto 2027 approvato fissa nel suo PDF di deposito la colonna 2026 (storia 115).
        $this->travelTo('2028-02-01 10:00');
        $this->come($this->tesoriere)->post('/esercizi/2027/apertura')->assertRedirect();
        $this->come($this->tesoriere)->post('/esercizi/2027/approvazione', ['data' => '2028-02-01'])->assertSessionHasNoErrors();
        $this->assertSame(80000, Deposito::istantanea(2027)['precedente']['gestione']['entrate']['importo']);
        $this->assertContains('Esercizio 2026 (€)', Deposito::documenti(Deposito::istantanea(2027))[0]['sezioni'][0]['colonne']);
    }

    public function test_senza_il_2026_approvato_la_colonna_viene_dallo_storico_e_senza_storico_manca(): void
    {
        $this->assertNull(Deposito::precedente(2027));
        $storico = EsercizioStorico::create(['esercizio' => 2026, 'importi' => ['entrate:A1' => 1000], 'saldo_cassa' => 0, 'saldo_depositi' => 0, 'figurativi' => [], 'differenze' => []]);

        $this->assertSame($storico->prospetto(), Deposito::precedente(2027));
    }

    public function test_il_2026_riaperto_non_ha_una_versione_vigente_e_la_colonna_non_viene_dall_approvazione_di_prima(): void
    {
        $this->esercizio2026();
        $this->approva();
        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore nel Modello D'])->assertSessionHasNoErrors();

        $this->assertNull(Deposito::precedente(2027));
    }

    public function test_il_pdf_dopo_il_deposito_e_quello_dell_istantanea_e_non_cambia_con_i_dati(): void
    {
        $this->esercizio2026();
        $this->approva();
        $prima = $this->come($this->tesoriere)->get('/esercizi/2026/deposito.pdf')->assertOk()->getContent();
        $this->deposita('2027-04-20')->assertSessionHasNoErrors();
        $this->imputa('entrata', 10000, '2026-07-01', 'Somministrazione');
        ImpostazioniAssociazione::where('dal_esercizio', 2026)->update(['denominazione' => 'Un altro nome']);

        $dopo = $this->come($this->tesoriere)->get('/esercizi/2026/deposito.pdf')->assertOk();

        $this->assertSame('attachment; filename="Deposito_2026_ContradaLaTorre.pdf"', $dopo->headers->get('Content-Disposition'));
        $this->assertSame(VersioneEsercizio::vigente(2026)->istantanea_deposito, Deposito::istantanea(2026), 'Il PDF di dopo parte dall’istantanea salvata, non dai dati di oggi');
        $this->assertSame('Contrada La Torre', Deposito::istantanea(2026)['impostazioni']['denominazione']);
        $this->assertSame(80000, Deposito::istantanea(2026)['prospetti']['modello_d']['aree']['A']['entrate']['totale']['importo']);
        $this->assertStringStartsWith('%PDF-', $prima);
    }

    public function test_la_riapertura_sblocca_i_movimenti_e_la_nuova_approvazione_e_il_nuovo_deposito_sono_la_versione_due(): void
    {
        $this->esercizio2026();
        $this->approva();
        $this->deposita('2027-04-20')->assertSessionHasNoErrors();
        $manuale = Movimento::factory()->confermato()->create(['conto_id' => $this->cassa->id, 'verso' => 'uscita', 'importo' => 700, 'data' => '2026-03-02', 'manuale' => true, 'inserito_da' => $this->tesoriere->id]);
        $this->come($this->tesoriere)->post("/movimenti/{$manuale->id}/eliminazione", ['motivazione' => 'Errore'])->assertSessionHasErrors('movimento');

        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore nel Modello D'])->assertSessionHasNoErrors();

        $this->assertSame('aperto', DatiEsercizio::per(2026)->stato);
        $this->come($this->tesoriere)->post("/movimenti/{$manuale->id}/eliminazione", ['motivazione' => 'Errore'])->assertSessionHasNoErrors();
        $this->assertSoftDeleted($manuale);
        $this->assertNull(Deposito::salvata(2026), 'Riaperto, l’esercizio si legge di nuovo dal vivo');
        $this->come($this->tesoriere)->get('/esercizi/2026/modello-d')->assertOk()->assertSeeText('Provvisorio');
        $this->come($this->tesoriere)->get('/esercizi/2026/deposito.pdf')->assertNotFound();

        $this->imputa('entrata', 2000, '2026-07-01', 'Somministrazione');
        $this->approva();
        $this->deposita('2027-04-20')->assertSessionHasNoErrors();

        $versioni = VersioneEsercizio::where('esercizio', 2026)->orderBy('numero')->get();
        $this->assertSame([1, 2], $versioni->pluck('numero')->all());
        $this->assertNotNull($versioni[0]->riaperta_il);
        $this->assertSame([true, true], $versioni->map(fn ($v) => $v->depositata_il !== null)->all(), 'Il deposito della versione 1 resta nello storico');
        $this->assertSame(80000, $versioni[0]->istantanea_deposito['prospetti']['modello_d']['aree']['A']['entrate']['totale']['importo']);
        $this->assertSame(82000, $versioni[1]->istantanea_deposito['prospetti']['modello_d']['aree']['A']['entrate']['totale']['importo']);
        $this->assertSame(82000, Deposito::salvata(2026)['prospetti']['modello_d']['aree']['A']['entrate']['totale']['importo']);
        $this->assertSame(2, Deposito::salvata(2026)['versione']);
    }

    public function test_il_deposito_non_contiene_nomi_di_persone(): void
    {
        $this->esercizio2026();
        $this->imputa('entrata', 1000, '2026-06-03', 'Somministrazione')->movimento->update(['descrizione' => 'Bonifico di Mario Rossi per la cena']);
        $this->approva();
        $this->deposita('2027-04-20')->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('Mario Rossi', json_encode(Deposito::salvata(2026)));
        $this->assertStringNotContainsString('Mario Rossi', json_encode(Deposito::istantanea(2026)));
    }

    public function test_la_pagina_dell_approvazione_ha_il_pdf_e_la_data_dell_invio(): void
    {
        $this->esercizio2026();
        $this->approva();

        $this->come($this->tesoriere)->get('/esercizi/2026/approvazione')->assertOk()
            ->assertSeeText('Deposito al RUNTS')->assertSee(route('esporta.deposito', 2026), false)->assertSeeText('Deposita l’esercizio 2026');
        $this->come($this->utenteCon('Consigliere'))->get('/esercizi/2026/approvazione')->assertOk()->assertSeeText('Deposita chi gestisce i dati dell’esercizio');

        $this->deposita('2027-04-20')->assertSessionHasNoErrors();

        $this->come($this->tesoriere)->get('/esercizi/2026/approvazione')->assertOk()
            ->assertSeeText('Depositato il 20/04/2027')->assertSeeText('Scarica il PDF/A depositato')->assertDontSeeText('Deposita l’esercizio 2026');
    }

    public function test_il_pdf_dice_se_i_dati_sono_cambiati_dopo_l_approvazione(): void
    {
        $this->esercizio2026();
        $this->approva();
        $this->imputa('entrata', 2000, '2026-07-01', 'Somministrazione'); // dopo l'approvazione

        $this->come($this->tesoriere)->get('/esercizi/2026/approvazione')->assertOk()->assertSeeText('il PDF di deposito non li contiene');
        $this->deposita('2027-04-20')->assertSessionHasNoErrors();
        $this->assertSame(80000, Deposito::salvata(2026)['prospetti']['modello_d']['aree']['A']['entrate']['totale']['importo'], 'Si deposita ciò che l’assemblea ha approvato');
    }

    public function test_senza_riautenticazione_non_si_deposita(): void
    {
        $this->esercizio2026();
        $this->approva();

        $this->come($this->tesoriere, false)->from('/esercizi/2026/approvazione')->post('/esercizi/2026/deposito', ['data' => '2027-04-20'])->assertRedirect(route('riautentica'));

        $this->assertSame('approvato', DatiEsercizio::per(2026)->stato);
    }

    public function test_chi_non_ha_il_permesso_riceve_403_anche_senza_riautenticazione(): void
    {
        $this->esercizio2026();
        $this->approva();

        $this->come($this->utenteCon('Lettore esterno'), false)->post('/esercizi/2026/deposito', ['data' => '2027-04-20'])->assertForbidden();
        $this->come($this->utenteCon('Consigliere'), false)->post('/esercizi/2026/deposito', ['data' => '2027-04-20'])->assertForbidden();
        $this->assertSame('approvato', DatiEsercizio::per(2026)->stato);
    }

    public function test_un_esercizio_aperto_non_si_deposita(): void
    {
        $this->esercizio2026();

        $this->deposita('2027-04-20')->assertSessionHasErrors('stato');

        $this->assertSame('aperto', DatiEsercizio::per(2026)->stato);
    }

    public function test_un_secondo_clic_non_deposita_due_volte(): void
    {
        $this->esercizio2026();
        $this->approva();
        $this->deposita('2027-04-20')->assertSessionHasNoErrors();

        $this->deposita('2027-04-20')->assertSessionHasErrors('stato');

        $this->assertSame(1, VersioneEsercizio::where('esercizio', 2026)->whereNotNull('depositata_il')->count());
        $this->assertSame(1, RegistroModifica::where('azione', 'depositato')->count());
    }

    public function test_la_data_dell_invio_va_dall_approvazione_a_oggi(): void
    {
        $this->esercizio2026();
        $this->approva();

        $this->deposita('2027-04-19')->assertSessionHasErrors('data');
        $this->deposita('2027-04-21')->assertSessionHasErrors('data');
        $this->deposita('')->assertSessionHasErrors('data');

        $this->assertSame('approvato', DatiEsercizio::per(2026)->stato);
    }

    public function test_l_impronta_nel_piede_resta_la_stessa_dopo_il_deposito(): void
    {
        $this->esercizio2026();
        $this->approva();
        $prima = Deposito::piede(Deposito::istantanea(2026));

        $this->deposita('2027-04-20')->assertSessionHasNoErrors();

        // L'istantanea riletta dal database (colonna JSON: MySQL riordina le chiavi) stampa lo stesso piede del file firmato e inviato.
        $this->assertSame($prima, Deposito::piede(Deposito::istantanea(2026)));
    }

    public function test_senza_codice_fiscale_o_numero_runts_non_si_deposita(): void
    {
        $this->esercizio2026();
        $this->approva();

        foreach (['codice_fiscale', 'numero_runts'] as $campo) {
            $originale = ImpostazioniAssociazione::per(2026)->$campo;
            ImpostazioniAssociazione::where('dal_esercizio', 2026)->update([$campo => null]);

            $this->deposita('2027-04-20')->assertSessionHasErrors('impostazioni');

            $this->assertSame('approvato', DatiEsercizio::per(2026)->stato);
            ImpostazioniAssociazione::where('dal_esercizio', 2026)->update([$campo => $originale]);
        }
        $this->deposita('2027-04-20')->assertSessionHasNoErrors();
    }

    public function test_gli_elenchi_di_un_esercizio_depositato_dicono_che_sono_dati_di_oggi(): void
    {
        $this->esercizio2026();
        $this->approva();
        $this->come($this->tesoriere)->get('/esercizi/2026/imputazioni')->assertOk()->assertDontSeeText('dati di oggi');
        $this->come($this->tesoriere)->get('/esercizi/2026/imputazioni.csv')->assertDownload('Imputazioni_2026_ContradaLaTorre.csv');
        $this->deposita('2027-04-20')->assertSessionHasNoErrors();

        $this->come($this->tesoriere)->get('/esercizi/2026/imputazioni')->assertOk()->assertSeeText('questo elenco è letto dai dati di oggi, non è quello depositato');
        $this->come($this->tesoriere)->get('/esercizi/2026/imputazioni.csv')->assertDownload('Imputazioni_2026_ContradaLaTorre_DatiDiOggi.csv');
        $this->come($this->tesoriere)->get('/esercizi/2026/movimenti.csv')->assertDownload('Movimenti_2026_ContradaLaTorre_DatiDiOggi.csv');
        $this->assertDatabaseHas('registro_accessi', ['evento' => 'esportazione_imputazioni_csv_2026']);
    }

    private function datiConto(Conto $conto, array $cambi = []): array
    {
        return $cambi + ['nome' => $conto->nome, 'formato_import' => $conto->formato_import, 'riga_modello_d' => $conto->riga_modello_d, 'aperto_il' => $conto->aperto_il->toDateString(), 'saldo_iniziale' => number_format($conto->saldo_iniziale / 100, 2, ',', '')];
    }

    public function test_in_un_esercizio_depositato_non_nasce_un_conto_e_non_ne_cambiano_saldo_o_apertura(): void
    {
        $this->esercizio2026();
        $altro = Conto::factory()->create(['nome' => 'Cassa 2027', 'aperto_il' => '2027-01-01', 'saldo_iniziale' => 0]);
        $this->approva();
        $this->deposita('2027-04-20')->assertSessionHasNoErrors();
        $nuovo = fn (string $data) => ['tipo' => 'cassa', 'nome' => 'Cassa '.$data, 'formato_import' => 'manuale', 'aperto_il' => $data, 'saldo_iniziale' => '0'];

        $this->come($this->tesoriere)->post('/conti', $nuovo('2026-03-01'))->assertSessionHasErrors('aperto_il');
        $this->come($this->tesoriere)->post('/conti', $nuovo('2026-01-01'))->assertSessionHasErrors('aperto_il');
        $this->come($this->tesoriere)->put("/conti/{$this->cassa->id}", $this->datiConto($this->cassa, ['saldo_iniziale' => '5,00']))->assertSessionHasErrors('aperto_il');
        $this->come($this->tesoriere)->put("/conti/{$altro->id}", $this->datiConto($altro, ['aperto_il' => '2026-05-01']))->assertSessionHasErrors('aperto_il'); // spostato in un anno depositato

        $this->assertSame(2, Conto::count());
        $this->assertSame(0, $this->cassa->fresh()->saldo_iniziale);
        $this->assertSame('2027-01-01', $altro->fresh()->aperto_il->toDateString());
        // Il nome si cambia, e un conto del 2027 nasce.
        $this->come($this->tesoriere)->put("/conti/{$this->cassa->id}", $this->datiConto($this->cassa, ['nome' => 'Cassa principale']))->assertSessionHasNoErrors();
        $this->come($this->tesoriere)->post('/conti', $nuovo('2027-02-01'))->assertSessionHasNoErrors();
        $this->assertSame('Cassa principale', $this->cassa->fresh()->nome);
    }

    public function test_dopo_il_deposito_i_dati_dell_esercizio_non_si_scrivono_piu(): void
    {
        $this->esercizio2026();
        $figurativa = RigaFigurativa::create(['esercizio' => 2026, 'tipo' => 'costo', 'area' => 'A', 'descrizione' => 'Gruppo cucina', 'importo' => 1000]);
        $destinazione = DestinazioneAvanzo::create(['esercizio' => 2026, 'descrizione' => 'Fondo scorta', 'importo' => 500]);
        $this->approva();
        $this->deposita('2027-04-20')->assertSessionHasNoErrors();
        $campi = ['criterio_riparto', 'attivo', 'dipendenti_medi', 'figurativi_dichiarati'];
        $dati = DatiEsercizio::per(2026)->only($campi);
        $registri = RegistroModifica::count();

        $this->come($this->tesoriere)->put('/esercizi/2026/dati', ['dipendenti_medi' => 9, 'attivo' => '1,00', 'criterio_riparto' => 'costi_diretti', 'figurativi_dichiarati' => '1'])->assertSessionHasErrors('stato');
        $this->come($this->tesoriere)->post('/esercizi/2026/dati/figurativi', ['tipo' => 'provento', 'area' => 'A', 'descrizione' => 'Gruppo bar', 'importo' => '10,00'])->assertSessionHasErrors('stato');
        $this->come($this->tesoriere)->post('/esercizi/2026/dati/destinazione', ['descrizione' => 'Fondo nuovo', 'importo' => '10,00'])->assertSessionHasErrors('stato');
        $this->come($this->tesoriere)->delete("/esercizi/2026/dati/figurativi/{$figurativa->id}")->assertSessionHasErrors('stato');
        $this->come($this->tesoriere)->delete("/esercizi/2026/dati/destinazione/{$destinazione->id}")->assertSessionHasErrors('stato');

        $this->assertSame($dati, DatiEsercizio::per(2026)->only($campi));
        $this->assertSame(1, RigaFigurativa::count());
        $this->assertSame(1, DestinazioneAvanzo::count());
        $this->assertSame($registri, RegistroModifica::count());
        $this->come($this->tesoriere)->get('/esercizi/2026/dati')->assertOk()->assertSeeText('L’esercizio 2026 è depositato');
    }
}
