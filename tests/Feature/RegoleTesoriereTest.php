<?php

namespace Tests\Feature;

use App\Models\Conto;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\Regola;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Le regole del tesoriere (#42): condizioni, priorità, anteprima e protezione di confermati e scelte a mano. Dati inventati. */
class RegoleTesoriereTest extends TestCase
{
    use RefreshDatabase;

    private Conto $banca;

    private User $tesoriere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-01 10:00');
        $this->tesoriere = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->banca = Conto::factory()->create([
            'tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv',
            'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 100000,
        ]);
    }

    private function tipologia(string $nome): int
    {
        return Tipologia::where('nome', $nome)->value('id');
    }

    private function iniziativa(string $nome): int
    {
        return Iniziativa::where('nome', $nome)->value('id');
    }

    private function movimento(string $descrizione, int $importo = 1250, string $verso = 'uscita', array $altri = []): Movimento
    {
        return Movimento::factory()->create($altri + [
            'conto_id' => $this->banca->id, 'manuale' => false, 'inserito_da' => $this->tesoriere->id,
            'descrizione' => $descrizione, 'importo' => $importo, 'verso' => $verso, 'causale' => 'PAGAMENTO TRAMITE POS',
        ]);
    }

    /** I campi del modulo di una regola: di base «descrizione contiene ENEL» → Utenze · Carnevale. */
    private function dati(array $altri = []): array
    {
        return $altri + [
            'nome' => 'Bollette', 'testo' => 'ENEL', 'tipologia_id' => $this->tipologia('Utenze'), 'iniziativa_id' => $this->iniziativa('Carnevale'),
        ];
    }

    private function creaRegola(array $altri = []): Regola
    {
        $this->actingAs($this->tesoriere)->post('/regole', $this->dati($altri))->assertSessionHasNoErrors();

        return Regola::where('nome', $altri['nome'] ?? 'Bollette')->firstOrFail();
    }

    public function test_la_prima_regola_che_corrisponde_vince_e_riordinare_cambia_il_vincitore(): void
    {
        $movimento = $this->movimento('Bolletta ENEL maggio');
        $utenze = $this->creaRegola(['nome' => 'Utenze luce']);
        $materiali = $this->creaRegola(['nome' => 'Spese generiche', 'tipologia_id' => $this->tipologia('Materiali e beni di consumo')]);

        // Il salvataggio applica la regola ai movimenti che c'erano già: la prima della lista prende il movimento.
        $this->assertSame('Regola: Utenze luce', $movimento->refresh()->fonteProposta());
        $this->assertSame($utenze->id, $movimento->regola_id);
        $this->assertSame('proposto', $movimento->stato);

        $this->actingAs($this->tesoriere)->post('/regole/ordine', ['ordine' => [$materiali->id, $utenze->id]])->assertSessionHasNoErrors();

        $this->assertSame($materiali->id, $movimento->refresh()->regola_id);
        $this->assertSame('Materiali e beni di consumo', $movimento->imputazioni()->with('tipologia')->first()->tipologia->nome);
    }
}
