<?php

namespace Tests\Feature;

use App\Classificazione;
use App\Models\Conto;
use App\Models\EsempioClassificazione;
use App\Models\ImportEstratto;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\Regola;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Le proposte dallo storico (#43): cascata dopo le regole, fonte, protezione delle scelte e suggerimento di regola. Dati inventati. */
class StoricoClassificazioneTest extends TestCase
{
    use RefreshDatabase;

    private Conto $banca;

    private Conto $cassa;

    private User $tesoriere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-01 10:00');
        $this->tesoriere = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->banca = Conto::factory()->create([
            'tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv',
            'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 120000,
        ]);
        // I precedenti stanno su un altro conto, così il saldo dell'estratto di aprile resta quello del file.
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa storica']);
    }

    private function tipologia(string $nome): int
    {
        return Tipologia::where('nome', $nome)->value('id');
    }

    private function iniziativa(string $nome): int
    {
        return Iniziativa::where('nome', $nome)->value('id');
    }

    private function movimento(string $descrizione, array $altri = []): Movimento
    {
        return Movimento::factory()->create($altri + [
            'conto_id' => $this->cassa->id, 'manuale' => false, 'inserito_da' => $this->tesoriere->id,
            'descrizione' => $descrizione, 'importo' => 5830, 'verso' => 'uscita', 'causale' => 'PAGAMENTO UTENZE', 'data' => '2026-03-22',
        ]);
    }

    /** Un movimento già classificato e confermato: il precedente da cui lo storico impara. */
    private function precedente(string $descrizione, array $altri = [], string $tipologia = 'Utenze', string $iniziativa = 'Carnevale'): Movimento
    {
        $movimento = $this->movimento($descrizione, $altri);
        Classificazione::salva($this->tesoriere, $movimento, null, [['tipologia_id' => $this->tipologia($tipologia), 'iniziativa_id' => $this->iniziativa($iniziativa)]]);
        Classificazione::conferma($this->tesoriere, $movimento, Classificazione::versione($movimento->refresh()));

        return $movimento->refresh();
    }

    private function importaAprile(): ImportEstratto
    {
        Storage::fake('local');
        $this->actingAs($this->tesoriere)->post("/conti/{$this->banca->id}/import", ['estratto' => UploadedFile::fake()->createWithContent('aprile.csv', file_get_contents(base_path('tests/fixtures/credit-agricole/aprile-2026.csv')))]);
        $import = ImportEstratto::firstOrFail();
        $this->post("/import/{$import->id}/conferma")->assertRedirect();

        return $import;
    }

    private function bolletta(): Movimento
    {
        return Movimento::where('descrizione', 'like', '%FORNITORE ENERGIA%')->where('conto_id', $this->banca->id)->firstOrFail();
    }

    private function nomeTipologia(Movimento $movimento): ?string
    {
        return $movimento->imputazioni()->with('tipologia')->first()?->tipologia->nome;
    }

    public function test_dopo_un_import_lo_storico_propone_come_il_movimento_confermato_simile_e_ne_dice_la_fonte(): void
    {
        $origine = $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000987654');

        $this->importaAprile();

        $bolletta = $this->bolletta();
        $this->assertSame('proposto', $bolletta->stato);
        $this->assertNull($bolletta->regola_id);
        $this->assertSame($origine->id, $bolletta->storico_movimento_id);
        $this->assertSame('Storico: come il movimento del 22/03/2026', $bolletta->load('proponente', 'regola', 'storicoMovimento', 'storicoEsempio')->fonteProposta());
        $this->assertSame('Utenze', $this->nomeTipologia($bolletta));
        $this->assertSame('Carnevale', $bolletta->imputazioni()->with('iniziativa')->first()->iniziativa->nome);
        // Gli altri movimenti dell'estratto non somigliano a nessun precedente.
        $this->assertSame(4, Movimento::where('stato', 'da_classificare')->count());
        // La pagina di revisione mostra la fonte e il collegamento al movimento di origine.
        $import = ImportEstratto::firstOrFail();
        $this->get("/import/{$import->id}/revisione?passo=pronte&movimento={$bolletta->id}")->assertOk()
            ->assertSee('Storico: come il movimento del 22/03/2026')->assertSee(route('movimenti.classificazione', $origine), false);
    }
}
