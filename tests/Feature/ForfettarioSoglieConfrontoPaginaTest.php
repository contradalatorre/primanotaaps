<?php

namespace Tests\Feature;

use App\Esercizio\Verifiche;
use App\Models\Conto;
use App\Models\DatiEsercizio;
use App\Models\Imputazione;
use App\Models\Movimento;
use App\Models\RigaFigurativa;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Il forfettario dell'art. 86, le soglie degli artt. 13, 30 e 31 e il confronto con gli altri esercizi, a schermo (#55). Dati inventati. */
class ForfettarioSoglieConfrontoPaginaTest extends TestCase
{
    use RefreshDatabase;

    private User $tesoriere;

    private Conto $cassa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-20 09:30');
        $this->tesoriere = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa contanti', 'aperto_il' => '2024-01-01', 'saldo_iniziale' => 0]);
        // Le voci Modello D ci sono solo per il 2026 (migrazione di #39): senza, un movimento di un altro anno non ha riga. Gli esercizi vicini le hanno copiate.
        foreach ([2023, 2024, 2025] as $anno) {
            DB::table('voci_modello_d')->insert(DB::table('voci_modello_d')->where('esercizio', 2026)->get(['area', 'tipologia_id', 'voce'])->map(fn ($v) => ['esercizio' => $anno] + (array) $v)->all());
        }
    }

    private function imputa(string $verso, int $importo, string $tipologia, string $area, string $data = '2026-03-01'): Imputazione
    {
        $movimento = Movimento::factory()->create([
            'conto_id' => $this->cassa->id, 'verso' => $verso, 'importo' => $importo, 'data' => $data, 'inserito_da' => $this->tesoriere->id,
            'stato' => 'confermato', 'confermato_il' => now(), 'confermato_da' => $this->tesoriere->id,
        ]);

        return Imputazione::create(['movimento_id' => $movimento->id, 'importo' => $importo, 'area' => $area, 'tipologia_id' => Tipologia::where('nome', $tipologia)->firstOrFail()->id]);
    }

    private function pagina(int $anno = 2026)
    {
        return $this->actingAs($this->tesoriere)->get("/esercizi/$anno/test")->assertOk();
    }

    public function test_il_forfettario_mostra_ricavi_soglia_e_imponibile_senza_calcolare_l_imposta(): void
    {
        $this->imputa('entrata', 14000, 'Corrispettivi da terzi', 'B');
        $this->imputa('entrata', 6000, 'Sponsorizzazioni e pubblicità', 'B');
        $this->imputa('entrata', 9000, 'Quote associative', 'A');

        $this->pagina()
            ->assertSeeText('Regime forfettario (art. 86)')
            ->assertSeeText('ricavi commerciali 200,00 € contro una soglia di 85.000,00 €')
            ->assertSeeText('Reddito imponibile stimato al 3%: 6,00 €')
            ->assertSeeText('l’uscita dal regime vale dall’esercizio successivo')
            ->assertDontSeeText('IRES');
    }

    public function test_sopra_gli_85_000_euro_l_avviso_dice_da_quando_si_esce(): void
    {
        $this->imputa('entrata', 8_500_100, 'Corrispettivi da terzi', 'B');

        $this->pagina()
            ->assertSeeText('La soglia è superata di 1,00 €')
            ->assertSeeText('dall’esercizio 2027 l’associazione esce dal regime forfettario');
    }

    public function test_senza_l_esercizio_precedente_le_soglie_dicono_dato_mancante_e_non_annunciano_il_modello_e(): void
    {
        $this->imputa('entrata', 5000, 'Quote associative', 'A');

        $this->pagina()
            ->assertSeeText('L’esercizio 2025 non ha movimenti confermati nell’app')
            ->assertDontSeeText('potete redigere il Modello E');
    }

    public function test_un_esercizio_precedente_sotto_i_60_000_euro_annuncia_il_modello_e_ma_solo_dal_2026(): void
    {
        $this->imputa('entrata', 500_000, 'Quote associative', 'A', '2025-04-01');
        $this->imputa('entrata', 200_000, 'Quote associative', 'A', '2024-04-01');

        $this->pagina()->assertSeeText('L’esercizio 2025 è sotto i 60.000 euro (entrate 5.000,00 €): potete redigere il Modello E');
        $this->pagina(2025)->assertDontSeeText('potete redigere il Modello E');
    }

    public function test_sopra_i_60_000_euro_niente_modello_e_e_la_cassa_resta_ammessa(): void
    {
        $this->imputa('entrata', 7_000_000, 'Quote associative', 'A', '2025-04-01');

        $this->pagina()->assertDontSeeText('potete redigere il Modello E')->assertSeeText('70.000,00 €')->assertSeeText('ancora 230.000,00 €');
    }

    public function test_attivo_e_dipendenti_dell_esercizio_precedente_pesano_sugli_artt_30_e_31(): void
    {
        foreach (['2025-04-01', '2024-04-01'] as $data) {
            $this->imputa('entrata', 10_000_000, 'Quote associative', 'A', $data);
        }
        foreach ([2025, 2024] as $anno) {
            DatiEsercizio::create(['esercizio' => $anno, 'criterio_riparto' => 'ricavi', 'figurativi_dichiarati' => false, 'attivo' => 20_000_000, 'dipendenti_medi' => 9]);
        }

        $this->pagina()
            ->assertSeeText('Totale dell’attivo: 200.000,00 € (limite superato)')
            ->assertSeeText('Dipendenti medi: 9 (limite superato)')
            ->assertSeeText('2 limiti superati su 3')->assertSeeText('Superati anche nell’esercizio prima: l’obbligo c’è.')
            ->assertDontSeeText('Dato mancante: l’attivo');
    }

    public function test_con_un_solo_esercizio_sotto_i_limiti_l_obbligo_non_e_ancora_cessato(): void
    {
        foreach (['2025-04-01', '2024-04-01'] as $data) {
            $this->imputa('entrata', 1_000_000, 'Quote associative', 'A', $data);
        }
        DatiEsercizio::create(['esercizio' => 2025, 'criterio_riparto' => 'ricavi', 'figurativi_dichiarati' => false, 'attivo' => 1_000_000, 'dipendenti_medi' => 2]);
        DatiEsercizio::create(['esercizio' => 2024, 'criterio_riparto' => 'ricavi', 'figurativi_dichiarati' => false, 'attivo' => 20_000_000, 'dipendenti_medi' => 9]);

        $this->pagina()->assertSeeText('cessa solo dopo due esercizi consecutivi sotto i limiti');
    }

    public function test_un_attivo_non_scritto_conta_come_superato_e_si_segnala_col_link_ai_dati(): void
    {
        $this->imputa('entrata', 10_000_000, 'Quote associative', 'A', '2025-04-01');

        $this->pagina()->assertSeeText('Totale dell’attivo: — (dato mancante: conta come superato)')
            ->assertSeeText('Dato mancante: l’attivo o i dipendenti medi dell’esercizio 2025')
            ->assertSee('/esercizi/2025/dati');
    }

    public function test_chi_non_gestisce_i_dati_dell_esercizio_non_vede_il_link_ma_il_perche(): void
    {
        $this->imputa('entrata', 10_000_000, 'Quote associative', 'A', '2025-04-01');
        $lettore = $this->utenteCon('Lettore esterno', ['scade_il' => now()->addMonth()], ['email' => 'revisore@esterno.example']);

        $this->actingAs($lettore)->get('/esercizi/2026/test')->assertOk()
            ->assertSeeText('Chi gestisce i dati dell’esercizio può inserirli.')->assertDontSee('/esercizi/2025/dati');
    }

    public function test_il_confronto_affianca_tre_esercizi_e_durante_l_anno_lo_stesso_periodo_dell_anno_prima(): void
    {
        $this->imputa('entrata', 1000, 'Quote associative', 'A', '2024-05-01');
        $this->imputa('entrata', 2000, 'Quote associative', 'A', '2025-05-01');
        $this->imputa('entrata', 4000, 'Quote associative', 'A', '2025-12-30'); // dopo il 20/12: fuori dallo stesso periodo
        $this->imputa('entrata', 8000, 'Quote associative', 'A', '2026-05-01');

        $colonne = Verifiche::pagina(2026)['confronto'];
        $this->assertSame(['2024', '2025', '2025, stesso periodo (dal 1/1 al 20/12)', '2026 (in corso)'], array_column($colonne, 'etichetta'));
        $this->assertSame([1000, 6000, 2000, 8000], array_column($colonne, 'entrate'));

        $this->pagina()->assertSeeText('Confronto con gli altri esercizi')->assertSeeText('2025, stesso periodo (dal 1/1 al 20/12)')->assertSeeText('Forfettario (art. 86)');
    }

    public function test_un_esercizio_chiuso_si_confronta_con_i_due_precedenti_senza_periodo(): void
    {
        $colonne = Verifiche::pagina(2025)['confronto'];

        $this->assertSame(['2023', '2024', '2025'], array_column($colonne, 'etichetta'));
        $this->assertSame([true, true, true], array_column($colonne, 'vuota'));
    }

    public function test_il_periodo_parziale_non_conta_i_figurativi_dell_anno_intero(): void
    {
        $this->imputa('entrata', 3000, 'Quote associative', 'A', '2025-02-01');
        $this->imputa('entrata', 1000, 'Corrispettivi da terzi', 'B', '2025-02-01');
        $this->imputa('uscita', 1000, 'Utenze', 'A', '2025-02-01');
        RigaFigurativa::create(['esercizio' => 2025, 'tipo' => 'costo', 'area' => 'A', 'descrizione' => 'Ore dei volontari', 'importo' => 90000]);

        [, $intero, $periodo] = Verifiche::pagina(2026)['confronto'];

        // Anno intero il criterio b regge (le entrate B sono l'1,1% di uscite e figurativi); nel periodo, senza figurativi, b darebbe il 100% e resta il criterio a (25%).
        $this->assertSame('b', $intero['secondarieta']['criterio']);
        $this->assertSame('a', $periodo['secondarieta']['criterio']);
        $this->assertEqualsWithDelta(25.0, $periodo['secondarieta']['rapporto'], 0.0001);
    }
}
