<?php

namespace Tests\Feature;

use App\Esercizio\Pdf;
use App\Esercizio\Tabelle;
use App\Import\Foglio;
use App\Models\Attivita;
use App\Models\Conto;
use App\Models\DestinazioneAvanzo;
use App\Models\EsercizioStorico;
use App\Models\Giroconto;
use App\Models\ImpostazioniAssociazione;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RegistroAccesso;
use App\Models\Ruolo;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Le esportazioni dei prospetti (#58): file scaricati, nomi, permessi delle pagine e note. Dati inventati. */
class EsportazioniProspettiTest extends TestCase
{
    use RefreshDatabase;

    private User $tesoriere;

    private Conto $cassa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-20 09:30');
        // La migrazione ne semina una dal 2026: la si riscrive, e se ne aggiunge una dal 2025 per lo storico.
        foreach ([2025, 2026] as $dal) {
            ImpostazioniAssociazione::updateOrCreate(['dal_esercizio' => $dal], ['denominazione' => 'Contrada La Torre', 'codice_fiscale' => '00000000000', 'numero_runts' => '12345']);
        }
        $this->tesoriere = $this->utenteCon('Tesoriere');
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa contanti', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
    }

    private function imputa(string $verso, int $importo, string $data, string $tipologia, array $movimento = [], string $stato = 'confermato'): Imputazione
    {
        $m = Movimento::factory()->create($movimento + ['conto_id' => $this->cassa->id, 'verso' => $verso, 'importo' => $importo, 'data' => $data, 'inserito_da' => $this->tesoriere->id, 'stato' => $stato]);

        return Imputazione::create([
            'movimento_id' => $m->id, 'importo' => $importo, 'area' => 'A', 'tipologia_id' => Tipologia::where('nome', $tipologia)->firstOrFail()->id,
            'iniziativa_id' => Iniziativa::where('nome', 'Festa della Torre')->firstOrFail()->id,
            'attivita_id' => Attivita::where('nome', 'Lettera i (art. 5 CTS)')->firstOrFail()->id,
        ]);
    }

    private function esercizio(): void
    {
        $this->imputa('entrata', 80000, '2026-06-01', 'Somministrazione', ['descrizione' => 'Incasso serata']);
        $this->imputa('uscita', 35000, '2026-06-02', 'Acquisti per la somministrazione', ['descrizione' => '=SOMMA(A1) fornitore']);
    }

    private function scarica(string $percorso, ?User $utente = null): TestResponse
    {
        return $this->actingAs($utente ?? $this->tesoriere)->get($percorso)->assertOk();
    }

    /** @return array<string, list<list<mixed>>> */
    private function fogli(TestResponse $risposta): array
    {
        return Foglio::leggi($risposta->getContent());
    }

    /** @return list<list<string>> */
    private function csv(TestResponse $risposta): array
    {
        $righe = explode("\n", trim(substr($risposta->getContent(), 3))); // senza il BOM

        return array_map(fn (string $r) => str_getcsv($r, ';', '"', ''), $righe);
    }

    public function test_il_rendiconto_in_pdf_si_scarica_col_nome_finale_ed_e_provvisorio_quindi_non_pdf_a(): void
    {
        $this->esercizio();

        $r = $this->scarica('/esercizi/2026/rendiconto/pdf');

        $r->assertDownload('Rendiconto_2026_ContradaLaTorre.pdf');
        $r->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $r->getContent());
        $this->assertStringNotContainsString('pdfaid', $r->getContent()); // il provvisorio non è PDF/A: non lo si deposita per sbaglio
        $this->assertSame(1, RegistroAccesso::where('evento', 'esportazione_rendiconto_pdf_2026')->where('user_id', $this->tesoriere->id)->count()); // ogni esportazione va nel registro degli accessi, con l'anno
    }

    public function test_il_pdf_porta_provvisorio_nell_intestazione_di_ogni_pagina_e_data_versione_e_pagina_nel_piede(): void
    {
        $ora = now();
        $html = Pdf::html('Rendiconto per l’assemblea · 2026', Tabelle::intestazione(['denominazione' => 'Contrada La Torre'], 'Rendiconto per l’assemblea · 2026', false, false, $ora), [], Tabelle::piede(2026, $ora, 1, false));

        $this->assertStringContainsString('Contrada La Torre', $html['intestazione']);
        $this->assertStringContainsString('Provvisorio – aggiornato al 20/12/2026 09:30', $html['intestazione']);
        $this->assertStringContainsString('Generato il 20/12/2026 09:30 · Esercizio 2026 · versione 1 (provvisoria) · Pagina {PAGENO} di {nbpg}', $html['piede']);
        $this->assertStringNotContainsStringIgnoringCase('opacity', $html['corpo'].$html['intestazione']); // niente filigrana né trasparenze
    }

    public function test_il_modello_d_in_pdf_e_in_xlsx_ha_il_suo_nome_e_nell_xlsx_l_intestazione_i_totali_e_il_dettaglio(): void
    {
        $this->esercizio();

        $this->scarica('/esercizi/2026/modello-d/pdf')->assertDownload('ModelloD_2026_ContradaLaTorre.pdf');
        $r = $this->scarica('/esercizi/2026/modello-d/xlsx');

        $r->assertDownload('ModelloD_2026_ContradaLaTorre.xlsx');
        $fogli = $this->fogli($r);
        $this->assertSame(['Modello D', 'Dettaglio'], array_keys($fogli));
        $this->assertSame(['Contrada La Torre'], $fogli['Modello D'][0]);
        $this->assertSame(['Codice fiscale 00000000000 · numero RUNTS 12345'], $fogli['Modello D'][1]);
        $this->assertSame(['Provvisorio – aggiornato al 20/12/2026 09:30. Solo le imputazioni confermate.'], $fogli['Modello D'][3]);
        $complessivo = collect($fogli['Modello D'])->first(fn (array $riga) => ($riga[0] ?? null) === 'Avanzo/disavanzo complessivo');
        $this->assertSame(450.0, $complessivo[1]); // 800,00 − 350,00
        $this->assertContains(['«Cassa contanti» non è mai stato riconciliato (ultimo movimento del 02/06/2026)'], $fogli['Modello D']); // gli impedimenti, accanto al provvisorio
        // Il Dettaglio ha tutte le imputazioni dell'esercizio: l'intestazione e le due righe, uscite col meno.
        $this->assertCount(3, $fogli['Dettaglio']);
        $this->assertSame([800.0, -350.0], [$fogli['Dettaglio'][1][3], $fogli['Dettaglio'][2][3]]);
    }

    public function test_il_rendiconto_in_xlsx_non_ha_il_dettaglio_ne_descrizioni_di_movimenti(): void
    {
        $this->esercizio();

        $fogli = $this->fogli($this->scarica('/esercizi/2026/rendiconto/xlsx')); // spec #27: il rendiconto per l'assemblea non ha mai nomi di persone

        $this->assertSame(['Rendiconto'], array_keys($fogli));
        $this->assertStringNotContainsString('Incasso serata', json_encode($fogli));
        $this->assertStringContainsString('Somministrazione', json_encode($fogli['Rendiconto']));
    }

    public function test_la_nota_della_destinazione_dell_avanzo_esce_solo_a_chi_gestisce_l_esercizio(): void
    {
        $this->esercizio();
        DestinazioneAvanzo::create(['esercizio' => 2026, 'descrizione' => 'Fondo per il restauro', 'importo' => 100, 'nota' => 'Come proposto dal direttivo']);
        $consigliere = $this->utenteCon('Consigliere');

        $con = json_encode($this->fogli($this->scarica('/esercizi/2026/rendiconto/xlsx'))['Rendiconto']);
        $senza = json_encode($this->fogli($this->scarica('/esercizi/2026/rendiconto/xlsx', $consigliere))['Rendiconto']);

        $this->assertStringContainsString('Come proposto dal direttivo', $con);
        $this->assertStringContainsString('Fondo per il restauro', $senza);
        $this->assertStringNotContainsString('Come proposto', $senza);
    }

    public function test_chi_non_ha_vedere_non_scarica_niente_e_senza_accesso_si_va_all_accesso(): void
    {
        $senza = $this->utenteCon(Ruolo::create(['nome' => 'Solo utenti', 'permessi' => ['gestire_utenti']]));
        $percorsi = ['/esercizi/2026/modello-d/pdf', '/esercizi/2026/rendiconto/xlsx', '/esercizi/2026/imputazioni.csv', '/esercizi/2026/movimenti.csv'];

        foreach ($percorsi as $percorso) {
            $this->get($percorso)->assertRedirect();
        }
        foreach ($percorsi as $percorso) {
            $this->actingAs($senza)->get($percorso)->assertForbidden();
        }
    }

    public function test_il_csv_delle_imputazioni_ha_le_note_visibili_il_numero_e_il_dubbio_aperto(): void
    {
        $this->esercizio();
        $incasso = Movimento::where('verso', 'entrata')->firstOrFail();
        $uscita = Movimento::where('verso', 'uscita')->firstOrFail();
        $autore = ['user_id' => $this->tesoriere->id];
        $incasso->note()->create($autore + ['testo' => 'Quadra con la cassa', 'dubbio' => false]);
        $incasso->note()->create($autore + ['testo' => 'Testo oscurato', 'dubbio' => false, 'oscurata_il' => now()]);
        $incasso->note()->create($autore + ['testo' => 'Testo eliminato', 'dubbio' => false, 'eliminata_il' => now()]);
        $uscita->note()->create($autore + ['testo' => 'Fattura mancante', 'dubbio' => true]);
        $uscita->note()->create($autore + ['testo' => 'Dubbio oscurato ma ancora aperto', 'dubbio' => true, 'oscurata_il' => now()]);
        $uscita->note()->create($autore + ['testo' => 'Dubbio chiuso', 'dubbio' => true, 'chiuso_il' => now()]);

        $r = $this->scarica('/esercizi/2026/imputazioni.csv');

        $r->assertDownload('Imputazioni_2026_ContradaLaTorre.csv');
        $righe = $this->csv($r);
        $this->assertSame(['Data', 'Conto', 'Entrata/uscita', 'Importo (€)', 'Area', 'Attività', 'Iniziativa', 'Tipologia', 'Voce Modello D', 'Stato', 'Descrizione', 'Giustificativi', 'Id movimento', 'Note', 'Numero note', 'Dubbio aperto'], $righe[0]);
        $this->assertCount(3, $righe);
        // Entrata: una sola nota visibile; nessun dubbio.
        $this->assertSame(['01/06/2026', 'Cassa contanti', 'Entrata', '800,00', 'A', 'Lettera i (art. 5 CTS)', 'Festa della Torre', 'Somministrazione'], array_slice($righe[1], 0, 8));
        $this->assertSame(['Quadra con la cassa', '1', 'no'], array_slice($righe[1], 13));
        // Uscita: col meno; il dubbio oscurato non si legge ma tiene il dubbio aperto; il dubbio chiuso resta una nota da leggere ma non tiene aperto il dubbio.
        $this->assertSame('-350,00', $righe[2][3]);
        $this->assertSame("'=SOMMA(A1) fornitore", $righe[2][10]); // una descrizione che sembra una formula non si esegue in Excel
        $this->assertSame(['Fattura mancante | Dubbio chiuso', '2', 'sì'], array_slice($righe[2], 13));
        $this->assertStringNotContainsString('oscurato', $r->getContent());
        $this->assertStringNotContainsString('eliminato', $r->getContent());
    }

    public function test_senza_il_permesso_delle_note_le_colonne_delle_note_non_ci_sono(): void
    {
        $this->esercizio();
        Movimento::firstOrFail()->note()->create(['user_id' => $this->tesoriere->id, 'testo' => 'Una nota riservata', 'dubbio' => true]);
        $soloVista = $this->utenteCon(Ruolo::create(['nome' => 'Solo vista', 'permessi' => ['vedere']]));

        foreach (['imputazioni', 'movimenti'] as $elenco) {
            $r = $this->scarica("/esercizi/2026/$elenco.csv", $soloVista);
            $this->assertNotContains('Note', $this->csv($r)[0]);
            $this->assertStringNotContainsString('riservata', $r->getContent());
        }
        $dettaglio = $this->fogli($this->scarica('/esercizi/2026/modello-d/xlsx', $soloVista))['Dettaglio'];
        $this->assertStringNotContainsString('riservata', json_encode($dettaglio));
        $this->assertNotContains('Note', $dettaglio[0]);
    }

    public function test_il_csv_dei_movimenti_ha_ogni_stato_il_giroconto_e_non_gli_eliminati(): void
    {
        $this->esercizio();
        $this->imputa('entrata', 1000, '2026-07-01', 'Somministrazione', [], 'proposto');
        $banca = Conto::factory()->create(['tipo' => 'banca', 'nome' => 'Conto corrente', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
        $uscita = Movimento::factory()->confermato()->create(['conto_id' => $this->cassa->id, 'verso' => 'uscita', 'importo' => 30000, 'data' => '2026-08-01', 'inserito_da' => $this->tesoriere->id]);
        $entrata = Movimento::factory()->confermato()->create(['conto_id' => $banca->id, 'verso' => 'entrata', 'importo' => 30000, 'data' => '2026-08-01', 'inserito_da' => $this->tesoriere->id]);
        Giroconto::create(['uscita_id' => $uscita->id, 'entrata_id' => $entrata->id, 'regola' => 'prova', 'confermato_da' => $this->tesoriere->id, 'confermato_il' => now()]);
        Movimento::factory()->create(['conto_id' => $this->cassa->id, 'verso' => 'entrata', 'importo' => 999, 'data' => '2026-09-01', 'inserito_da' => $this->tesoriere->id, 'descrizione' => 'Cancellato'])->delete();
        Movimento::factory()->create(['conto_id' => $this->cassa->id, 'verso' => 'entrata', 'importo' => 555, 'data' => '2025-12-31', 'inserito_da' => $this->tesoriere->id, 'descrizione' => 'Dell’anno prima']);

        $r = $this->scarica('/esercizi/2026/movimenti.csv');

        $r->assertDownload('Movimenti_2026_ContradaLaTorre.csv');
        $righe = $this->csv($r);
        $this->assertSame(['Data', 'Conto', 'Entrata/uscita', 'Importo (€)', 'Stato', 'Giroconto', 'Descrizione'], array_slice($righe[0], 0, 7));
        $this->assertCount(1 + 5, $righe); // due imputati, un proposto, le due metà del giroconto
        $this->assertSame(['Proposto'], array_unique(array_column(array_filter($righe, fn (array $x) => $x[3] === '10,00'), 4)));
        $this->assertSame(['sì', 'sì'], array_values(array_column(array_filter($righe, fn (array $x) => $x[3] === '300,00' || $x[3] === '-300,00'), 5)));
        $this->assertStringNotContainsString('Cancellato', $r->getContent());
        $this->assertStringNotContainsString('anno prima', $r->getContent());
    }

    public function test_le_imputazioni_proposte_ci_sono_solo_se_la_pagina_le_include(): void
    {
        $this->esercizio();
        $this->imputa('entrata', 1000, '2026-07-01', 'Somministrazione', [], 'proposto');

        $this->assertCount(3, $this->csv($this->scarica('/esercizi/2026/imputazioni.csv')));
        $this->assertCount(4, $this->csv($this->scarica('/esercizi/2026/imputazioni.csv?proposte=1')));
        $this->assertCount(4, $this->fogli($this->scarica('/esercizi/2026/modello-d/xlsx?proposte=1'))['Dettaglio']);
    }

    public function test_senza_denominazione_il_nome_del_file_ha_un_ente_di_riserva_e_l_ascii_pulisce_gli_apostrofi(): void
    {
        ImpostazioniAssociazione::query()->update(['denominazione' => 'Società “L’Ànte” & C.']);
        $this->scarica('/esercizi/2026/movimenti.csv')->assertDownload('Movimenti_2026_SocietaLAnteC.csv');
        ImpostazioniAssociazione::query()->update(['denominazione' => '']);
        $this->scarica('/esercizi/2026/movimenti.csv')->assertDownload('Movimenti_2026_Associazione.csv');
    }

    public function test_un_esercizio_storico_esporta_il_modello_d_senza_provvisorio_e_non_ha_il_rendiconto(): void
    {
        EsercizioStorico::create(['esercizio' => 2025, 'importi' => ['entrate:A1' => 50000], 'saldo_cassa' => 0, 'saldo_depositi' => 0, 'figurativi' => ['costi' => [], 'proventi' => []], 'differenze' => []]);

        $fogli = $this->fogli($this->scarica('/esercizi/2025/modello-d/xlsx'));

        $this->assertSame(['Contrada La Torre'], $fogli['Modello D'][0]); // l'intestazione viene dalle impostazioni, non dal calcolo dello storico
        $this->assertSame(['Esercizio storico: importi caricati dal foglio approvato'], $fogli['Modello D'][3]);
        $this->assertCount(1, $fogli['Dettaglio']); // solo le colonne: lo storico non ha imputazioni
        $this->scarica('/esercizi/2025/modello-d/pdf')->assertDownload('ModelloD_2025_ContradaLaTorre.pdf');
        $this->actingAs($this->tesoriere)->get('/esercizi/2025/rendiconto/pdf')->assertNotFound();
    }

    public function test_il_modello_d_2026_porta_la_colonna_2025_nel_foglio(): void
    {
        EsercizioStorico::create(['esercizio' => 2025, 'importi' => ['entrate:A1' => 50000], 'saldo_cassa' => 0, 'saldo_depositi' => 0, 'figurativi' => ['costi' => [], 'proventi' => []], 'differenze' => []]);
        $this->esercizio();

        $fogli = $this->fogli($this->scarica('/esercizi/2026/modello-d/xlsx'));

        $complessivo = collect($fogli['Modello D'])->first(fn (array $riga) => ($riga[0] ?? null) === 'Avanzo/disavanzo complessivo');
        $this->assertSame([450.0, 500.0], [$complessivo[1], $complessivo[2]]);
    }

    public function test_le_pagine_dei_prospetti_portano_ai_file_con_la_stessa_scelta_sulle_proposte(): void
    {
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d?proposte=1')->assertOk()
            ->assertSee('href="http://localhost/esercizi/2026/modello-d/pdf?proposte=1"', false)
            ->assertSee('href="http://localhost/esercizi/2026/imputazioni.csv?proposte=1"', false)
            ->assertSee('href="http://localhost/esercizi/2026/movimenti.csv"', false);
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/rendiconto')->assertOk()->assertSee('href="http://localhost/esercizi/2026/rendiconto/xlsx"', false);
    }
}
