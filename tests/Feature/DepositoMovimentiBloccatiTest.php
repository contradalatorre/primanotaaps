<?php

namespace Tests\Feature;

use App\Classificazione;
use App\Models\Conto;
use App\Models\DatiEsercizio;
use App\Models\Giroconto;
use App\Models\ImportEstratto;
use App\Models\Imputazione;
use App\Models\Movimento;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dopo il deposito i movimenti dell'esercizio non si toccano più (#60, #12, storie 43 e 73): né scrivere, né classificare, né confermare, né forzare, né importare,
 * né eliminare. Lo stato si imposta direttamente: qui conta il blocco, il passaggio a depositato è in `DepositoRuntsTest`. Dati inventati.
 */
class DepositoMovimentiBloccatiTest extends TestCase
{
    use RefreshDatabase;

    private User $tesoriere;

    private Conto $cassa;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2027-04-20 10:00');
        $this->tesoriere = $this->utenteCon('Tesoriere');
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa contanti', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
    }

    private function deposita(int $anno = 2026): void
    {
        DatiEsercizio::updateOrCreate(['esercizio' => $anno], ['criterio_riparto' => 'ricavi', 'figurativi_dichiarati' => false, 'stato' => 'depositato']);
    }

    /** Un movimento a mano del tesoriere con una sola imputazione. */
    private function movimento(string $data = '2026-03-01', string $stato = 'proposto'): Movimento
    {
        $m = Movimento::factory()->create(['conto_id' => $this->cassa->id, 'verso' => 'uscita', 'importo' => 5000, 'data' => $data, 'manuale' => true, 'inserito_da' => $this->tesoriere->id, 'stato' => $stato]);
        Imputazione::create(['movimento_id' => $m->id, 'importo' => 5000, 'area' => 'E', 'tipologia_id' => Tipologia::where('nome', 'Utenze')->firstOrFail()->id]);

        return $m;
    }

    public function test_un_movimento_di_un_esercizio_depositato_non_si_scrive_ne_si_elimina(): void
    {
        $m = $this->movimento();
        $this->deposita();
        $this->actingAs($this->tesoriere);
        $nuovo = ['data' => '2026-04-01', 'verso' => 'uscita', 'importo' => '10,00', 'descrizione' => 'Doppione'];

        $this->post("/conti/{$this->cassa->id}/movimenti", $nuovo)->assertSessionHasErrors('movimento');
        $this->put("/movimenti/{$m->id}", ['descrizione' => 'Cambiata'] + $nuovo)->assertSessionHasErrors('movimento');
        $this->put("/movimenti/{$m->id}", ['descrizione' => 'Spostata al 2027', 'data' => '2027-02-01'] + $nuovo)->assertSessionHasErrors('movimento');
        $this->post("/movimenti/{$m->id}/ritiro")->assertSessionHasErrors('movimento');
        $this->post("/movimenti/{$m->id}/eliminazione", ['motivazione' => 'Doppione'])->assertSessionHasErrors('movimento');
        $this->put("/movimenti/{$m->id}/classificazione", ['imputazioni' => [['importo' => '50,00', 'tipologia_id' => Tipologia::where('nome', 'Utenze')->firstOrFail()->id, 'area' => 'E']]])->assertSessionHasErrors('movimento');
        $this->post("/movimenti/{$m->id}/giustificativi", ['riferimento' => 'Fattura 12'])->assertSessionHasErrors('movimento');
        $this->post("/movimenti/{$m->id}/conferma", ['versione' => Classificazione::versione($m)])->assertSessionHasErrors('movimento');

        $this->assertSame(1, Movimento::count());
        $m->refresh();
        $this->assertSame('Ghiaccio per la festa', $m->descrizione);
        $this->assertSame('2026-03-01', $m->data->toDateString());
        $this->assertSame('proposto', $m->stato);
        $this->assertSame(0, $m->giustificativi()->count());
        $this->assertSame(5000, $m->imputazioni()->sole()->importo);
    }

    public function test_la_natura_commerciale_non_si_forza_su_un_esercizio_depositato(): void
    {
        $m = Movimento::factory()->confermato()->create(['conto_id' => $this->cassa->id, 'verso' => 'entrata', 'importo' => 9000, 'data' => '2026-03-01']);
        $imputazione = Imputazione::create(['movimento_id' => $m->id, 'importo' => 9000, 'area' => 'B', 'tipologia_id' => Tipologia::where('nome', 'Sponsorizzazioni e pubblicità')->firstOrFail()->id]);
        $this->deposita();

        $this->actingAs($this->tesoriere)->post('/imputazioni/natura', ['imputazioni' => [$imputazione->id], 'natura' => 'commerciale', 'motivazione' => 'Per prudenza'])->assertSessionHasErrors('movimento');

        $this->assertNull($imputazione->refresh()->natura_forzata);
    }

    public function test_la_correzione_di_una_conta_di_cassa_non_entra_in_un_esercizio_depositato(): void
    {
        $this->deposita();
        $this->actingAs($this->tesoriere);

        // La cassa ha saldo zero e si contano 10,00: la correzione sarebbe un movimento del 31/12/2026.
        $this->post("/conti/{$this->cassa->id}/conte", ['data' => '2026-12-31', 'importo' => '10,00', 'correggi' => '1'])->assertSessionHasErrors('data');
        $this->assertSame(0, Movimento::count());

        $this->post("/conti/{$this->cassa->id}/conte", ['data' => '2027-04-10', 'importo' => '10,00', 'correggi' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(1, Movimento::count());
    }

    public function test_l_annotazione_in_calce_di_un_esercizio_depositato_non_si_cambia(): void
    {
        $this->deposita();

        $this->actingAs($this->tesoriere)->put('/esercizi/2026/test/annotazione', ['testo' => 'Una dichiarazione in più'])->assertSessionHasErrors('stato');

        $this->assertNull(DatiEsercizio::per(2026)->annotazione_in_calce);
    }

    public function test_un_esercizio_non_depositato_si_scrive_come_prima(): void
    {
        $this->deposita(2026);
        $this->actingAs($this->tesoriere);

        $this->post("/conti/{$this->cassa->id}/movimenti", ['data' => '2027-04-10', 'verso' => 'uscita', 'importo' => '10,00', 'descrizione' => 'Ghiaccio'])->assertSessionHasNoErrors();

        $this->assertSame(1, Movimento::count());
    }

    public function test_un_giroconto_di_un_esercizio_depositato_non_si_scioglie(): void
    {
        $banca = Conto::factory()->create(['tipo' => 'banca', 'nome' => 'Conto corrente', 'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 100000]);
        $uscita = Movimento::factory()->confermato()->create(['conto_id' => $banca->id, 'verso' => 'uscita', 'importo' => 10000, 'data' => '2026-05-10', 'manuale' => false]);
        $entrata = Movimento::factory()->confermato()->create(['conto_id' => $this->cassa->id, 'verso' => 'entrata', 'importo' => 10000, 'data' => '2026-05-10']);
        $giroconto = Giroconto::create(['uscita_id' => $uscita->id, 'entrata_id' => $entrata->id, 'regola' => 'prelievo', 'confermato_da' => $this->tesoriere->id, 'confermato_il' => now()]);
        $import = ImportEstratto::create(['conto_id' => $banca->id, 'user_id' => $this->tesoriere->id, 'stato' => 'confermato', 'periodo_dal' => '2026-05-01', 'periodo_al' => '2026-05-31', 'saldo_iniziale' => 100000, 'saldo_finale' => 90000, 'righe' => 1, 'confermato_il' => now()]);
        $this->deposita();

        $this->actingAs($this->tesoriere)->post("/import/{$import->id}/giroconti/{$giroconto->id}/scioglimento")->assertSessionHasErrors('conferma');

        $this->assertModelExists($giroconto);
    }

    /** L'estratto di aprile 2026 caricato sul conto di banca: resta in anteprima. */
    private function anteprimaDiAprile(): ImportEstratto
    {
        $banca = Conto::factory()->create(['tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 120000]);
        $csv = file_get_contents(base_path('tests/fixtures/credit-agricole/aprile-2026.csv'));
        $this->actingAs($this->tesoriere)->post("/conti/{$banca->id}/import", ['estratto' => UploadedFile::fake()->createWithContent('aprile.csv', $csv)])->assertSessionHasNoErrors();

        return ImportEstratto::latest('id')->firstOrFail();
    }

    public function test_un_import_non_aggiunge_movimenti_a_un_esercizio_depositato(): void
    {
        $import = $this->anteprimaDiAprile();
        $this->deposita();

        $this->post("/import/{$import->id}/conferma")->assertSessionHasErrors('estratto');

        $this->assertSame(0, Movimento::count());
        $this->assertSame('anteprima', $import->refresh()->stato);
    }

    public function test_un_import_non_toglie_movimenti_a_un_esercizio_depositato(): void
    {
        $import = $this->anteprimaDiAprile();
        $this->post("/import/{$import->id}/conferma")->assertSessionHasNoErrors();
        $importati = Movimento::where('import_estratto_id', $import->id)->count();
        $this->assertGreaterThan(0, $importati);
        $this->deposita();

        $this->post("/import/{$import->id}/annullamento")->assertSessionHasErrors('estratto');

        $this->assertSame($importati, Movimento::count());
        $this->assertSame('confermato', $import->refresh()->stato);
    }
}
