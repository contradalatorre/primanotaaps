<?php

namespace Tests\Feature;

use App\Classificazione;
use App\Models\Conto;
use App\Models\ImportEstratto;
use App\Models\ImpostazioniAssociazione;
use App\Models\Imputazione;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Natura commerciale calcolata, sempre visibile, e forzatura motivata (#52). Dati inventati. */
class NaturaCommercialeTest extends TestCase
{
    use RefreshDatabase;

    private User $tesoriere;

    private Conto $cassa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-20 09:30');
        $this->tesoriere = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa contanti', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
    }

    private function entrata(string $tipologia, string $area, int $importo = 10000, array $movimento = []): Imputazione
    {
        $m = Movimento::factory()->confermato()->create($movimento + ['conto_id' => $this->cassa->id, 'verso' => 'entrata', 'importo' => $importo, 'data' => '2026-03-01', 'inserito_da' => $this->tesoriere->id]);

        return Imputazione::create(['movimento_id' => $m->id, 'importo' => $importo, 'area' => $area, 'tipologia_id' => Tipologia::where('nome', $tipologia)->value('id')]);
    }

    private function uscita(string $tipologia, string $area, int $importo = 5000): Imputazione
    {
        $m = Movimento::factory()->confermato()->create(['conto_id' => $this->cassa->id, 'verso' => 'uscita', 'importo' => $importo, 'data' => '2026-03-02', 'inserito_da' => $this->tesoriere->id]);

        return Imputazione::create(['movimento_id' => $m->id, 'importo' => $importo, 'area' => $area, 'tipologia_id' => Tipologia::where('nome', $tipologia)->value('id')]);
    }

    public function test_la_natura_calcolata_si_vede_su_ogni_imputazione_nel_modello_di_calcolo(): void
    {
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B');
        $quote = $this->entrata('Quote associative', 'A');
        $uscita = $this->uscita('Utenze', 'E');

        $this->assertTrue($sponsor->natura()->commerciale);
        $this->assertFalse($quote->natura()->commerciale);
        $this->assertNull($uscita->natura()->commerciale);
        $this->assertSame($sponsor->natura()->commerciale, $sponsor->naturaEffettiva());
        $this->assertFalse($sponsor->forzata());
    }

    public function test_l_affiliazione_dell_esercizio_decommercializza_la_somministrazione_ai_soci(): void
    {
        $cena = $this->entrata('Somministrazione ai soci', 'A', 4000, ['tipo_controparte' => 'socio']);
        $this->assertTrue($cena->natura()->commerciale);

        ImpostazioniAssociazione::create(['dal_esercizio' => 2026, 'denominazione' => 'Contrada La Torre APS', 'affiliazione_rete' => true]);

        $this->assertFalse($cena->refresh()->natura()->commerciale);
        $this->assertSame('somministrazione-ai-soci-affiliata', $cena->natura()->regola);
    }

    public function test_la_natura_e_sempre_visibile_nella_classificazione_nell_elenco_e_nelle_imputazioni(): void
    {
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B');
        $quote = $this->entrata('Quote associative', 'A');
        $this->actingAs($this->tesoriere);

        $this->get("/movimenti/{$sponsor->movimento_id}/classificazione")->assertOk()
            ->assertSee('Natura commerciale: commerciale', false)->assertSee('art. 85 c. 3 CTS', false);
        $this->get("/movimenti/{$quote->movimento_id}/classificazione")->assertSee('Natura commerciale: non commerciale', false);
        $this->get('/movimenti?conto='.$this->cassa->id)->assertOk()->assertSee('Natura commerciale: commerciale', false);
        $this->get('/esercizi/2026/imputazioni?chiavi=entrate:B3')->assertOk()->assertSee('Natura commerciale: commerciale', false);
    }

    public function test_la_natura_si_vede_nella_revisione_dell_import(): void
    {
        $import = ImportEstratto::create(['conto_id' => $this->cassa->id, 'user_id' => $this->tesoriere->id, 'stato' => 'confermato', 'periodo_dal' => '2026-03-01',
            'periodo_al' => '2026-03-31', 'saldo_iniziale' => 0, 'saldo_finale' => 10000, 'righe' => 1, 'confermato_il' => now()]);
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B', 10000, ['import_estratto_id' => $import->id, 'manuale' => false]);

        $this->actingAs($this->tesoriere)->get("/import/{$import->id}/revisione?movimento={$sponsor->movimento_id}")->assertOk()
            ->assertSee('Natura commerciale: commerciale', false)->assertDontSee('arriva con il calcolo dell’esercizio', false);
    }
}
