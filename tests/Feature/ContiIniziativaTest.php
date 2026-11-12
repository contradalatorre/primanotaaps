<?php

namespace Tests\Feature;

use App\Esercizio\Preventivo;
use App\Models\Conto;
use App\Models\Giroconto;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\PreventivoIniziativa;
use App\Models\RegistroModifica;
use App\Models\Tipologia;
use App\Models\User;
use App\Permesso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** I conti per iniziativa e il preventivo (#57). Dati inventati. */
class ContiIniziativaTest extends TestCase
{
    use RefreshDatabase;

    private User $tesoriere;

    private Conto $cassa;

    private Conto $banca;

    private Iniziativa $festa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-20 09:30');
        $this->tesoriere = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa contanti', 'aperto_il' => '2025-01-01', 'saldo_iniziale' => 0]);
        $this->banca = Conto::factory()->create(['tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi', 'aperto_il' => '2025-01-01', 'saldo_iniziale' => 0]);
        $this->festa = Iniziativa::where('nome', 'Festa della Torre')->firstOrFail();
    }

    private function tipologia(string $nome): Tipologia
    {
        return Tipologia::where('nome', $nome)->firstOrFail();
    }

    private function imputa(Conto $conto, string $verso, int $importo, string $data, string $tipologia, ?Iniziativa $iniziativa = null, array $movimento = []): Imputazione
    {
        $m = Movimento::factory()->confermato()->create($movimento + ['conto_id' => $conto->id, 'verso' => $verso, 'importo' => $importo, 'data' => $data, 'inserito_da' => $this->tesoriere->id]);

        return Imputazione::create(['movimento_id' => $m->id, 'importo' => $importo, 'area' => 'A', 'tipologia_id' => $this->tipologia($tipologia)->id, 'iniziativa_id' => ($iniziativa ?? $this->festa)->id]);
    }

    private function preventivo(string $tipologia, int $importo, int $anno = 2026, ?Iniziativa $iniziativa = null): void
    {
        PreventivoIniziativa::create(['iniziativa_id' => ($iniziativa ?? $this->festa)->id, 'esercizio' => $anno, 'tipologia_id' => $this->tipologia($tipologia)->id, 'importo' => $importo]);
    }

    public function test_la_pagina_mostra_esercizio_precedente_totale_dall_avvio_e_il_confronto_con_il_preventivo(): void
    {
        $this->imputa($this->cassa, 'entrata', 50000, '2025-06-01', 'Somministrazione');
        $this->imputa($this->cassa, 'uscita', 20000, '2025-06-02', 'Acquisto di servizi');
        $this->imputa($this->cassa, 'entrata', 80000, '2026-06-01', 'Somministrazione');
        $this->imputa($this->cassa, 'uscita', 35000, '2026-06-02', 'Acquisto di servizi');
        $this->preventivo('Somministrazione', 70000);
        $this->preventivo('Acquisto di servizi', 40000);

        $pagina = $this->actingAs($this->tesoriere)->get('/esercizi/2026/iniziative')->assertOk();

        $pagina->assertSeeText('Festa della Torre');
        $pagina->assertSeeText('Esercizio 2026')->assertSeeText('Esercizio 2025')->assertSeeText('Dall’avvio');
        $pagina->assertSeeText('1.300,00')->assertSeeText('550,00'); // entrate e uscite dall'avvio: 500+800, 200+350
        $pagina->assertSeeText('Somministrazione')->assertSeeText('Acquisto di servizi');
        $pagina->assertSeeText('700,00')->assertSeeText('400,00'); // preventivi
        $pagina->assertSeeText('100,00')->assertSeeText('-50,00'); // scostamenti: 800−700 e 350−400
    }

    public function test_senza_preventivo_la_pagina_lo_dice_e_non_mostra_scostamenti(): void
    {
        $this->imputa($this->cassa, 'entrata', 80000, '2026-06-01', 'Somministrazione');

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/iniziative')->assertOk()
            ->assertSeeText('Nessun preventivo per il 2026')->assertDontSeeText('Scostamento');
    }

    public function test_giroconti_eliminati_e_proposte_restano_fuori_e_la_commissione_resta_in_d1(): void
    {
        // Un incasso PayPal con commissione: l'iniziativa ha il lordo, la commissione (area D, senza iniziativa) non la tocca.
        $incasso = $this->imputa($this->banca, 'entrata', 9650, '2026-04-01', 'Somministrazione', null, ['commissione' => 350]);
        $incasso->update(['importo' => 10000]);
        Imputazione::create(['movimento_id' => $incasso->movimento_id, 'importo' => -350, 'area' => 'D', 'tipologia_id' => $this->tipologia(Tipologia::COMMISSIONI)->id]);
        // Un giroconto con imputazioni sulle due metà, un movimento eliminato e uno solo proposto.
        $uscita = $this->imputa($this->cassa, 'uscita', 3000, '2026-05-01', 'Acquisto di servizi')->movimento;
        $entrata = $this->imputa($this->banca, 'entrata', 3000, '2026-05-01', 'Somministrazione')->movimento;
        Giroconto::create(['uscita_id' => $uscita->id, 'entrata_id' => $entrata->id, 'regola' => 'prova', 'confermato_da' => $this->tesoriere->id, 'confermato_il' => now()]);
        $this->imputa($this->cassa, 'uscita', 999, '2026-06-01', 'Acquisto di servizi', null, ['eliminato_il' => now()]);
        $this->imputa($this->cassa, 'uscita', 4000, '2026-02-01', 'Acquisto di servizi', null, ['stato' => 'proposto', 'confermato_il' => null, 'confermato_da' => null]);

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/iniziative')->assertOk()
            ->assertSeeText('100,00')->assertDontSeeText('3,50')->assertDontSeeText('30,00')->assertDontSeeText('9,99')->assertDontSeeText('40,00');
        // Con l'interruttore anche la proposta, come nel Modello D.
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/iniziative?proposte=1')->assertOk()->assertSeeText('40,00');
    }

    public function test_un_iniziativa_senza_imputazioni_e_senza_preventivo_non_compare_e_con_il_solo_preventivo_si(): void
    {
        $pagina = $this->actingAs($this->tesoriere)->get('/esercizi/2026/iniziative')->assertOk();
        $pagina->assertDontSeeText('Festa della Torre');

        $this->preventivo('Somministrazione', 70000);
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/iniziative')->assertSeeText('Festa della Torre')->assertSeeText('700,00');
    }

    public function test_il_link_al_pareggio_dell_area_a_c_e_solo_se_la_pagina_dei_test_esiste(): void
    {
        $this->imputa($this->cassa, 'entrata', 80000, '2026-06-01', 'Somministrazione');
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/iniziative')->assertOk()->assertDontSeeText('pareggio');

        // La pagina dei test (#54) non c'è ancora: quando ci sarà, la rotta si chiamerà così e la scheda la raggiunge.
        Route::get('/esercizi/{anno}/test', fn () => 'test')->name('esercizio.test');
        app('router')->getRoutes()->refreshNameLookups();

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/iniziative')->assertOk()
            ->assertSeeText('pareggio')->assertSee('/esercizi/2026/test#pareggio-area-a', false);
    }

    public function test_chi_ha_gestire_regole_salva_il_preventivo_e_resta_nel_registro(): void
    {
        $this->actingAs($this->tesoriere)->get("/esercizi/2026/iniziative/{$this->festa->id}/preventivo")->assertOk()->assertSeeText('Preventivo 2026');

        $this->actingAs($this->tesoriere)->put("/esercizi/2026/iniziative/{$this->festa->id}/preventivo", ['righe' => [
            ['tipologia_id' => $this->tipologia('Somministrazione')->id, 'importo' => '1.200,50'],
            ['tipologia_id' => $this->tipologia('Acquisto di servizi')->id, 'importo' => '400'],
            ['tipologia_id' => '', 'importo' => ''], // riga vuota del modulo: si ignora
        ]])->assertRedirect("/esercizi/2026/iniziative");

        $this->assertSame([120050, 40000], PreventivoIniziativa::where('iniziativa_id', $this->festa->id)->where('esercizio', 2026)->orderBy('id')->pluck('importo')->all());
        $registro = RegistroModifica::where('oggetto_tipo', 'iniziativa')->where('azione', 'preventivo')->latest('id')->firstOrFail();
        $this->assertSame($this->tesoriere->id, $registro->user_id);
        $this->assertNull($registro->prima);
        $this->assertStringContainsString('Somministrazione', $registro->dopo['2026']);

        // Salvare di nuovo sostituisce le righe; con nessuna riga il preventivo sparisce.
        $this->actingAs($this->tesoriere)->put("/esercizi/2026/iniziative/{$this->festa->id}/preventivo", ['righe' => [
            ['tipologia_id' => $this->tipologia('Somministrazione')->id, 'importo' => '10'],
        ]]);
        $this->assertSame([1000], PreventivoIniziativa::where('esercizio', 2026)->pluck('importo')->all());
        $this->actingAs($this->tesoriere)->put("/esercizi/2026/iniziative/{$this->festa->id}/preventivo", ['righe' => []]);
        $this->assertSame(0, PreventivoIniziativa::count());
    }

    public function test_il_preventivo_di_un_esercizio_non_tocca_gli_altri(): void
    {
        $this->preventivo('Somministrazione', 111, 2025);

        $this->actingAs($this->tesoriere)->put("/esercizi/2026/iniziative/{$this->festa->id}/preventivo", ['righe' => [['tipologia_id' => $this->tipologia('Somministrazione')->id, 'importo' => '5']]]);

        $this->assertSame(111, PreventivoIniziativa::where('esercizio', 2025)->value('importo'));
    }

    public function test_dati_non_validi_non_salvano_niente(): void
    {
        $url = "/esercizi/2026/iniziative/{$this->festa->id}/preventivo";
        $somministrazione = $this->tipologia('Somministrazione')->id;
        $this->preventivo('Somministrazione', 500);

        foreach ([
            'tipologia ripetuta' => [['tipologia_id' => $somministrazione, 'importo' => '1'], ['tipologia_id' => $somministrazione, 'importo' => '2']],
            'importo zero' => [['tipologia_id' => $somministrazione, 'importo' => '0']],
            'importo negativo' => [['tipologia_id' => $somministrazione, 'importo' => '-5']],
            'importo non numerico' => [['tipologia_id' => $somministrazione, 'importo' => 'tanto']],
            'importo senza tipologia' => [['tipologia_id' => '', 'importo' => '5']],
            'tipologia inesistente' => [['tipologia_id' => 999999, 'importo' => '5']],
        ] as $caso => $righe) {
            $this->actingAs($this->tesoriere)->put($url, ['righe' => $righe])->assertSessionHasErrors(null, '', $caso);
            $this->assertSame([500], PreventivoIniziativa::pluck('importo')->all(), $caso);
        }
    }

    public function test_chi_non_ha_gestire_regole_vede_i_conti_ma_non_scrive_il_preventivo(): void
    {
        $consigliere = $this->utenteCon('Consigliere');
        $this->preventivo('Somministrazione', 500);

        $this->actingAs($consigliere)->get('/esercizi/2026/iniziative')->assertOk()->assertDontSee('/preventivo');
        $this->actingAs($consigliere)->get("/esercizi/2026/iniziative/{$this->festa->id}/preventivo")->assertForbidden();
        $this->actingAs($consigliere)->put("/esercizi/2026/iniziative/{$this->festa->id}/preventivo", ['righe' => []])->assertForbidden();
        $this->assertSame(1, PreventivoIniziativa::count());
    }

    public function test_un_permesso_revocato_mentre_si_aspetta_il_blocco_non_vale(): void
    {
        $this->preventivo('Somministrazione', 500);
        // Il permesso era valido quando la richiesta è partita (la relazione è già caricata); poi l'assegnazione scade.
        $this->assertTrue($this->tesoriere->puo(Permesso::GestireRegole));
        $this->tesoriere->assegnazioni()->update(['scade_il' => '2026-12-19']);

        try {
            Preventivo::salva($this->tesoriere, $this->festa->id, 2026, []);
            $this->fail('Il permesso revocato doveva fermare il salvataggio.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(1, PreventivoIniziativa::count());
    }

    public function test_una_tipologia_o_un_iniziativa_nel_preventivo_non_si_cancellano(): void
    {
        $nuova = Tipologia::create(['nome' => 'Tipologia di prova', 'verso' => 'uscita', 'spiegazione' => 'prova', 'proprieta' => []]);
        $iniziativa = Iniziativa::create(['nome' => 'Iniziativa di prova']);
        PreventivoIniziativa::create(['iniziativa_id' => $iniziativa->id, 'esercizio' => 2026, 'tipologia_id' => $nuova->id, 'importo' => 100]);

        $this->actingAs($this->tesoriere)->delete("/classificazione/tipologie/{$nuova->id}")->assertSessionHasErrors('elimina');
        $this->actingAs($this->tesoriere)->delete("/classificazione/iniziative/{$iniziativa->id}")->assertSessionHasErrors('elimina');
        $this->assertTrue($nuova->fresh()->usata());
        $this->assertSame(1, PreventivoIniziativa::count());
    }
}
