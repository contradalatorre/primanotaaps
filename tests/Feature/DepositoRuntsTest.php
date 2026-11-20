<?php

namespace Tests\Feature;

use App\Esercizio\Deposito;
use App\Import\Foglio;
use App\Models\Attivita;
use App\Models\Conto;
use App\Models\DatiEsercizio;
use App\Models\ImpostazioniAssociazione;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Tipologia;
use App\Models\User;
use App\Models\VersioneEsercizio;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        return $this->come($utente ?? $this->tesoriere)->post("/esercizi/$anno/deposito", ['data' => $data, 'impronta' => Deposito::impronta($anno) ?? str_repeat('0', 40)]);
    }

    public function test_se_le_impostazioni_cambiano_dopo_il_pdf_non_si_deposita_con_la_vecchia_impronta(): void
    {
        $this->esercizio2026();
        $this->approva();
        $impronta = Deposito::impronta(2026);
        ImpostazioniAssociazione::where('dal_esercizio', 2026)->update(['numero_runts' => '99999']);

        $this->come($this->tesoriere)->post('/esercizi/2026/deposito', ['data' => '2027-04-20', 'impronta' => $impronta])->assertSessionHasErrors('impronta');

        $this->assertSame('approvato', DatiEsercizio::per(2026)->stato);
        $this->deposita('2027-04-20')->assertSessionHasNoErrors(); // con l'impronta di adesso (PDF riscaricato) si deposita
        $this->assertSame('99999', Deposito::salvata(2026)['impostazioni']['numero_runts']);
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
        $this->approva();

        $r = $this->come($this->tesoriere)->get('/esercizi/2026/deposito.pdf')->assertOk();

        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="Deposito_2026_ContradaLaTorre.pdf"', $r->headers->get('Content-Disposition'));
        $pdf = $r->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('<pdfaid:part>1</pdfaid:part>', $pdf);
        $this->assertStringContainsString('<pdfaid:conformance>B</pdfaid:conformance>', $pdf);
        $this->assertDatabaseHas('registro_accessi', ['evento' => 'esportazione_deposito_pdf_2026']);

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

        $xlsx = Foglio::leggi($this->come($this->tesoriere)->get('/esercizi/2026/modello-d/xlsx')->assertOk()->getContent())['Modello D'];
        $celle = collect($xlsx)->flatten()->all();
        $this->assertContains(800.0, $celle);
        $this->assertNotContains(900.0, $celle);
        $this->assertContains('Depositato il 20/04/2027 · versione 1 · istantanea del deposito.', $celle);
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
}
