<?php

namespace Tests\Feature;

use App\Esercizio\Prospetto;
use App\Models\Attivita;
use App\Models\ContaDiCassa;
use App\Models\Conto;
use App\Models\DestinazioneAvanzo;
use App\Models\EsercizioStorico;
use App\Models\Giroconto;
use App\Models\ImportEstratto;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\RigaFigurativa;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Il rendiconto per l'assemblea (#56): la pagina, la destinazione dell'avanzo e l'assenza di nomi di persona. Dati inventati. */
class RendicontoAssembleaPaginaTest extends TestCase
{
    use RefreshDatabase;

    private User $tesoriere;

    private Conto $cassa;

    private Conto $banca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-20 09:30');
        $this->tesoriere = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa contanti', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
        $this->banca = Conto::factory()->create(['tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 100000]);
    }

    private function imputa(Conto $conto, string $verso, int $importo, string $data, string $tipologia, string $area = 'A', ?string $iniziativa = null, array $movimento = [], ?string $attivita = null): Imputazione
    {
        $m = Movimento::factory()->confermato()->create($movimento + ['conto_id' => $conto->id, 'verso' => $verso, 'importo' => $importo, 'data' => $data, 'inserito_da' => $this->tesoriere->id]);

        return Imputazione::create([
            'movimento_id' => $m->id, 'importo' => $importo, 'area' => $area, 'tipologia_id' => Tipologia::where('nome', $tipologia)->firstOrFail()->id,
            'iniziativa_id' => $iniziativa ? Iniziativa::where('nome', $iniziativa)->firstOrFail()->id : null,
            'attivita_id' => $attivita ? Attivita::where('nome', $attivita)->firstOrFail()->id : null,
        ]);
    }

    /** Festa della Torre: 800,00 di somministrazione e 350,00 di acquisti in cassa; 200,00 di quote in banca senza iniziativa, nell'attività «Lettera i». */
    private function esercizio(): void
    {
        $this->imputa($this->cassa, 'entrata', 80000, '2026-06-01', 'Somministrazione', 'A', 'Festa della Torre', [], 'Lettera i (art. 5 CTS)');
        $this->imputa($this->cassa, 'uscita', 35000, '2026-06-02', 'Acquisti per la somministrazione', 'A', 'Festa della Torre', [], 'Lettera i (art. 5 CTS)');
        $this->imputa($this->banca, 'entrata', 20000, '2026-07-01', 'Quote associative', 'A', null, [], 'Lettera i (art. 5 CTS)');
    }

    private function riconcilia(Conto $conto, string $al): void
    {
        if ($conto->tipo === 'cassa') { // la cassa non ha estratti: la riconcilia una conta che concorda col saldo dell'app
            $saldo = $conto->saldoAl($al);
            ContaDiCassa::create(['conto_id' => $conto->id, 'data' => $al, 'importo_contato' => $saldo, 'saldo_app' => $saldo, 'ultimo_movimento_id' => Movimento::max('id'), 'differenza' => 0, 'contata_da' => $this->tesoriere->id]);

            return;
        }
        ImportEstratto::create(['conto_id' => $conto->id, 'user_id' => $this->tesoriere->id, 'stato' => 'confermato', 'periodo_dal' => '2026-01-01', 'periodo_al' => $al, 'saldo_iniziale' => $conto->saldo_iniziale, 'saldo_finale' => $conto->saldoAl($al), 'righe' => 0, 'confermato_il' => now()]);
    }

    private function pagina(string $query = '')
    {
        return $this->actingAs($this->tesoriere)->get('/esercizi/2026/rendiconto'.$query)->assertOk();
    }

    public function test_righe_per_area_attivita_iniziativa_e_tipologia_con_una_colonna_per_conto(): void
    {
        $this->esercizio();

        $pagina = $this->pagina();

        $pagina->assertSeeText('Provvisorio – aggiornato al 20/12/2026 09:30');
        $pagina->assertSeeTextInOrder(['Area A', 'Attività di interesse generale', 'Lettera i (art. 5 CTS)', 'Festa della Torre', 'Entrate', 'Somministrazione', 'Uscite', 'Acquisti per la somministrazione', 'Altro – Lettera i (art. 5 CTS)', 'Quote associative']);
        $pagina->assertSeeInOrder(['Cassa contanti</th>', 'Conto corrente</th>', 'Totale</th>'], false);
        // Somministrazione: 800,00 in cassa, 0 in banca, 800,00 in tutto; quote: 0 in cassa, 200,00 in banca.
        $pagina->assertSeeInOrder(['<td>Somministrazione</td><td class="text-right">800,00</td><td class="text-right">0,00</td><td class="text-right font-bold">800,00</td>'], false);
        $pagina->assertSeeInOrder(['<td>Quote associative</td><td class="text-right">0,00</td><td class="text-right">200,00</td><td class="text-right font-bold">200,00</td>'], false);
        // Totali e avanzo: entrate 1.000,00, uscite 350,00, avanzo 650,00.
        $pagina->assertSeeInOrder(['<td>Totale entrate</td><td class="text-right">800,00</td><td class="text-right">200,00</td><td class="text-right font-bold">1.000,00</td>'], false);
        $pagina->assertSeeInOrder(['<td>Avanzo (disavanzo) dell’esercizio</td><td class="text-right">450,00</td><td class="text-right">200,00</td><td class="text-right font-bold">650,00</td>'], false);
    }

    public function test_l_interruttore_raggruppa_per_voce_modello_d_poi_per_tipologia(): void
    {
        $this->esercizio();

        $pagina = $this->pagina('?per=voce');

        $pagina->assertSeeTextInOrder(['Per voce del Modello D', 'Entrate', 'A1) Entrate da quote associative e apporti dei fondatori', 'Quote associative', 'A7) Entrate per prestazioni e cessioni a terzi', 'Somministrazione', 'Uscite']);
        $pagina->assertDontSeeText('Area A ·')->assertDontSeeText('Altro – Lettera i');
        // Senza interruttore la vista è quella per area.
        $this->pagina()->assertDontSeeText('Per voce del Modello D');
    }

    public function test_senza_iniziativa_ne_attivita_la_commissione_sta_sotto_senza_attivita_e_altro(): void
    {
        $incasso = $this->imputa($this->banca, 'entrata', 9650, '2026-04-01', 'Erogazioni liberali', 'A', null, ['commissione' => 350]);
        $incasso->update(['importo' => 10000]);
        Imputazione::create(['movimento_id' => $incasso->movimento_id, 'importo' => -350, 'area' => 'D', 'tipologia_id' => Tipologia::where('nome', Tipologia::COMMISSIONI)->firstOrFail()->id]);

        $pagina = $this->pagina();

        $pagina->assertSeeTextInOrder(['Area D', 'Senza attività', 'Altro', Tipologia::COMMISSIONI, '3,50']);
        // Per il conto la commissione è un'uscita: 100,00 lordi − 3,50 = 96,50 entrati davvero, quadra senza «non classificato».
        $pagina->assertSeeInOrder(['Conto corrente</td>', '1.000,00', '100,00', '3,50', '0,00', '0,00', '1.096,50', '0,00'], false);
    }

    public function test_i_giroconti_stanno_solo_nel_riepilogo_per_conto_fuori_dai_totali(): void
    {
        $this->esercizio();
        $uscita = Movimento::factory()->confermato()->create(['conto_id' => $this->cassa->id, 'verso' => 'uscita', 'importo' => 30000, 'data' => '2026-08-01', 'inserito_da' => $this->tesoriere->id]);
        $entrata = Movimento::factory()->confermato()->create(['conto_id' => $this->banca->id, 'verso' => 'entrata', 'importo' => 30000, 'data' => '2026-08-01', 'inserito_da' => $this->tesoriere->id]);
        Giroconto::create(['uscita_id' => $uscita->id, 'entrata_id' => $entrata->id, 'regola' => 'prova', 'confermato_da' => $this->tesoriere->id, 'confermato_il' => now()]);

        $pagina = $this->pagina();

        // Cassa: 0 + 800 − 350 − 300 di giroconto = 150; banca: 1.000 + 200 + 300 di giroconto = 1.500.
        $pagina->assertSeeInOrder(['Cassa contanti</td>', '0,00', '800,00', '350,00', '0,00', '300,00', '150,00', '0,00'], false);
        $pagina->assertSeeInOrder(['Conto corrente</td>', '1.000,00', '200,00', '0,00', '300,00', '0,00', '1.500,00', '0,00'], false);
        $pagina->assertSeeInOrder(['<td>Totale entrate</td>', '1.000,00'], false)->assertSeeText('Giroconti in entrata')->assertSeeText('Giroconti in uscita');
        $pagina->assertDontSeeText('1.300,00'); // i 300,00 del giroconto non gonfiano le entrate
    }

    public function test_il_denaro_non_ancora_classificato_compare_come_tale(): void
    {
        $this->esercizio();
        Movimento::factory()->create(['conto_id' => $this->cassa->id, 'verso' => 'entrata', 'importo' => 1200, 'data' => '2026-09-01', 'inserito_da' => $this->tesoriere->id, 'stato' => 'da_classificare']);

        $this->pagina()->assertSeeInOrder(['Cassa contanti</td>', '800,00', '350,00', '0,00', '0,00', '462,00', '12,00'], false);
    }

    public function test_l_avviso_per_un_conto_non_riconciliato_al_31_12_e_il_confronto_col_saldo_riconciliato(): void
    {
        $this->esercizio();
        $this->riconcilia($this->banca, '2026-05-31'); // dopo il 31/05 c'è la quota di luglio: 200,00 in più del saldo riconciliato

        $pagina = $this->pagina();

        $pagina->assertSeeText('«Cassa contanti» non è mai stato riconciliato.');
        $pagina->assertSeeText('«Conto corrente» è riconciliato solo fino al 31/05/2026: da allora il saldo è cambiato di 200,00 €.');
        // Il saldo riconciliato (1.000,00 al 31/05) accanto alla differenza dal saldo finale (1.200,00).
        $pagina->assertSeeInOrder(['Conto corrente</td>', '1.200,00', '0,00', '<td class="text-right">1.000,00</td>', 'fino al 31/05/2026 · differenza 200,00'], false);
    }

    public function test_un_conto_riconciliato_al_31_12_non_ha_avviso_e_un_conto_chiuso_conta_alla_sua_chiusura(): void
    {
        $this->esercizio();
        $this->riconcilia($this->banca, '2026-12-31');
        $this->riconcilia($this->cassa, '2026-12-31');
        $chiuso = Conto::factory()->create(['tipo' => 'banca', 'nome' => 'Vecchio conto', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'chiuso_il' => '2026-06-30', 'saldo_iniziale' => 5000]);
        $this->riconcilia($chiuso, '2026-06-30');

        $this->pagina()->assertDontSeeText('non è mai stato riconciliato')->assertDontSeeText('è riconciliato solo fino al')->assertSeeText('Vecchio conto');
    }

    public function test_un_conto_con_imputazioni_fuori_dal_suo_periodo_e_comunque_una_colonna(): void
    {
        // Un movimento datato prima dell'apertura del conto: il denaro non deve sparire dal prospetto.
        $this->imputa($this->cassa, 'entrata', 1000, '2025-12-30', 'Quote associative');
        $this->cassa->update(['aperto_il' => '2026-03-01']);
        $this->imputa($this->cassa, 'entrata', 2000, '2026-02-01', 'Quote associative');

        $r = Prospetto::rendiconto(2026);

        $this->assertSame(2000, $r['totali']['entrate']['totale']);
        $this->assertSame([$this->cassa->id => 'Cassa contanti', $this->banca->id => 'Conto corrente'], $r['conti']);
    }

    public function test_le_proposte_e_i_movimenti_eliminati_come_nel_modello_d(): void
    {
        $this->esercizio();
        $this->imputa($this->cassa, 'uscita', 4000, '2026-02-01', 'Utenze', 'E', null, ['stato' => 'proposto', 'confermato_il' => null, 'confermato_da' => null]);
        $this->imputa($this->cassa, 'uscita', 999, '2026-06-01', 'Utenze', 'E', null, ['eliminato_il' => now()]);

        // La proposta non è nel prospetto, ma il suo denaro è nel saldo: compare come non classificato (−40,00 in cassa).
        $this->pagina()->assertDontSeeText('Area E')->assertDontSeeText('9,99')->assertSeeText('-40,00');
        $this->pagina('?proposte=1')->assertSeeText('Con anche le imputazioni proposte')->assertSeeText('Area E')->assertSeeText('40,00')->assertDontSeeText('9,99')
            ->assertSeeInOrder(['Cassa contanti</td>', '800,00', '390,00', '0,00', '0,00', '410,00', '0,00'], false); // uscite 350 + 40, e ora niente di non classificato
    }

    public function test_la_destinazione_dell_avanzo_con_il_residuo_solo_nel_rendiconto(): void
    {
        $this->esercizio();
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/dati/destinazione', ['descrizione' => 'Fondo per il restauro del cancello', 'importo' => '400,00', 'nota' => 'Proposta del consiglio'])
            ->assertSessionHasNoErrors()->assertRedirect('/esercizi/2026/dati#destinazione');
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/dati/destinazione', ['descrizione' => 'Attività per i ragazzi', 'importo' => '100'])->assertSessionHasNoErrors();

        $pagina = $this->pagina();

        $pagina->assertSeeText('Fondo per il restauro del cancello')->assertSeeText('Proposta del consiglio')->assertSeeText('Attività per i ragazzi');
        $pagina->assertSeeInOrder(['destinazione-avanzo">650,00 €', 'destinazione-destinato">500,00 €', 'destinazione-residuo">150,00 €'], false);
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d')->assertOk()->assertDontSeeText('Fondo per il restauro del cancello')->assertDontSeeText('Destinazione dell’avanzo');
        $this->assertSame(['creata', 'creata'], RegistroModifica::where('oggetto_tipo', 'destinazione_avanzo')->orderBy('id')->pluck('azione')->all());
    }

    public function test_un_avanzo_superato_dalla_destinazione_da_un_residuo_negativo_senza_bloccare(): void
    {
        $this->esercizio();
        DestinazioneAvanzo::create(['esercizio' => 2026, 'descrizione' => 'Tutto e di più', 'importo' => 70000]);

        $this->pagina()->assertSeeInOrder(['destinazione-residuo">-50,00 €'], false);
    }

    public function test_senza_destinazione_il_residuo_e_l_avanzo(): void
    {
        $this->esercizio();

        $this->pagina()->assertSeeText('Nessuna destinazione inserita')->assertSeeInOrder(['destinazione-residuo">650,00 €'], false);
    }

    public function test_la_destinazione_si_elimina_e_una_riga_gia_eliminata_e_un_404(): void
    {
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/dati/destinazione', ['descrizione' => 'Fondo', 'importo' => '10,00']);
        $riga = DestinazioneAvanzo::firstOrFail();

        $this->actingAs($this->tesoriere)->delete('/esercizi/2026/dati/destinazione/'.$riga->id)->assertRedirect('/esercizi/2026/dati#destinazione');
        $this->assertSame(0, DestinazioneAvanzo::count());
        $this->actingAs($this->tesoriere)->delete('/esercizi/2026/dati/destinazione/'.$riga->id)->assertNotFound();
        // Una riga di un altro esercizio non si elimina da questo.
        $altra = DestinazioneAvanzo::create(['esercizio' => 2027, 'descrizione' => 'Altro', 'importo' => 100]);
        $this->actingAs($this->tesoriere)->delete('/esercizi/2026/dati/destinazione/'.$altra->id)->assertNotFound();
        $this->assertSame(['creata', 'eliminata'], RegistroModifica::where('oggetto_tipo', 'destinazione_avanzo')->orderBy('id')->pluck('azione')->all());
    }

    public function test_una_destinazione_non_valida_si_rifiuta_e_chi_non_gestisce_l_esercizio_non_scrive(): void
    {
        foreach ([
            ['descrizione' => '', 'importo' => '10'], ['descrizione' => 'x', 'importo' => ''], ['descrizione' => 'x', 'importo' => '0'],
            ['descrizione' => 'x', 'importo' => '-5'], ['descrizione' => 'x', 'importo' => 'dieci'], ['descrizione' => 'x', 'importo' => '1,234'], ['descrizione' => str_repeat('a', 201), 'importo' => '5'],
        ] as $caso) {
            $this->actingAs($this->tesoriere)->post('/esercizi/2026/dati/destinazione', $caso)->assertSessionHasErrors();
        }
        $this->assertSame(0, DestinazioneAvanzo::count());

        $lettore = $this->utenteCon('Lettore esterno', ['scade_il' => now()->addMonth()], ['email' => 'revisore@esterno.example']);
        $this->actingAs($lettore)->post('/esercizi/2026/dati/destinazione', ['descrizione' => 'x', 'importo' => '10'])->assertForbidden();
        DestinazioneAvanzo::create(['esercizio' => 2026, 'descrizione' => 'Fondo', 'importo' => 100]);
        $this->actingAs($lettore)->delete('/esercizi/2026/dati/destinazione/'.DestinazioneAvanzo::firstOrFail()->id)->assertForbidden();
        $this->assertSame(1, DestinazioneAvanzo::count());
        // Il lettore però legge il rendiconto, senza il link per scrivere.
        $this->actingAs($lettore)->get('/esercizi/2026/rendiconto')->assertOk()->assertSeeText('Fondo')->assertDontSeeText('Scrivi la destinazione');
    }

    public function test_il_rendiconto_non_ha_nomi_di_persone_fisiche(): void
    {
        $inseritore = User::factory()->create(['name' => 'Luigi Bianchi']);
        $this->imputa($this->cassa, 'uscita', 3000, '2026-06-02', 'Acquisti per la somministrazione', 'A', 'Festa della Torre', ['descrizione' => 'Rimborso spesa a Mario Rossi', 'inserito_da' => $inseritore->id]);
        RigaFigurativa::create(['esercizio' => 2026, 'tipo' => 'costo', 'area' => 'A', 'descrizione' => 'Gruppo cucina di Mario Rossi', 'importo' => 5000]);

        foreach (['', '?per=voce', '?proposte=1'] as $query) {
            $this->pagina($query)->assertDontSeeText('Mario Rossi')->assertDontSeeText('Luigi Bianchi')->assertDontSeeText('Rimborso spesa');
        }
    }

    public function test_un_esercizio_storico_non_ha_il_rendiconto_ma_rimanda_al_modello_d(): void
    {
        EsercizioStorico::create(['esercizio' => 2025, 'importi' => [], 'saldo_cassa' => 0, 'saldo_depositi' => 0, 'figurativi' => ['costi' => [], 'proventi' => []], 'differenze' => []]);

        $this->actingAs($this->tesoriere)->get('/esercizi/2025/rendiconto')->assertOk()
            ->assertSeeText('Esercizio storico: senza movimenti non c’è il rendiconto per l’assemblea')->assertDontSeeText('Riepilogo per conto');
    }

    public function test_senza_movimenti_la_pagina_c_e_con_i_conti_e_i_link(): void
    {
        $pagina = $this->pagina();

        $pagina->assertSeeText('Nessuna imputazione nell’esercizio 2026')->assertSeeText('Riepilogo per conto')->assertSeeText('Cassa contanti');
        $pagina->assertSee('/esercizi/2026/modello-d', false);
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d')->assertSee('/esercizi/2026/rendiconto', false);
    }

    public function test_solo_chi_e_autenticato_vede_il_rendiconto(): void
    {
        $this->get('/esercizi/2026/rendiconto')->assertRedirect();
    }
}
