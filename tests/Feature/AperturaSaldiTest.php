<?php

namespace Tests\Feature;

use App\Models\Conto;
use App\Models\ContaDiCassa;
use App\Models\DatiEsercizio;
use App\Models\EsercizioStorico;
use App\Models\ImportEstratto;
use App\Models\RegistroModifica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** La procedura di apertura (#63, #19): i saldi dei conti al 1/1 inseriti una sola volta, precompilati dallo storico e controllati contro la sua chiusura. Dati inventati. */
class AperturaSaldiTest extends TestCase
{
    use RefreshDatabase;

    private User $tesoriere;

    private Conto $cassa;

    private Conto $banca;

    private Conto $paypal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-01-05 10:00');
        $this->tesoriere = $this->utenteCon('Tesoriere');
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa contanti']);
        $this->banca = Conto::factory()->create(['tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi']);
        $this->paypal = Conto::factory()->create(['tipo' => 'paypal', 'nome' => 'Conto PayPal', 'formato_import' => 'paypal_csv', 'riga_modello_d' => 'depositi']);
    }

    private function storico2025(int $cassa = 12000, int $depositi = 53000): void
    {
        EsercizioStorico::create(['esercizio' => 2025, 'importi' => [], 'saldo_cassa' => $cassa, 'saldo_depositi' => $depositi, 'figurativi' => ['costi' => [], 'proventi' => []], 'differenze' => []]);
    }

    private function saldi(string $cassa = '120,00', string $banca = '400,00', string $paypal = '130,00'): array
    {
        return ['saldi' => [$this->cassa->id => $cassa, $this->banca->id => $banca, $this->paypal->id => $paypal]];
    }

    public function test_la_pagina_precompila_dallo_storico_dove_la_riga_ha_un_solo_conto_e_mostra_estratto_e_conta(): void
    {
        $this->storico2025();
        ImportEstratto::create(['conto_id' => $this->banca->id, 'user_id' => $this->tesoriere->id, 'stato' => 'confermato', 'periodo_dal' => '2026-01-02', 'periodo_al' => '2026-01-31', 'saldo_iniziale' => 40000, 'saldo_finale' => 40000, 'righe' => 0, 'confermato_il' => now()]);
        ContaDiCassa::create(['conto_id' => $this->cassa->id, 'data' => '2026-01-01', 'importo_contato' => 11950, 'saldo_app' => 0, 'ultimo_movimento_id' => 0, 'differenza' => 11950, 'contata_da' => $this->tesoriere->id]);

        $pagina = $this->actingAs($this->tesoriere)->get('/esercizi/2026/saldi-apertura')->assertOk();

        $pagina->assertSee('name="saldi['.$this->cassa->id.']" inputmode="decimal" maxlength="30" value="120,00"', false) // un solo conto nella riga cassa: precompilato
            ->assertSee('name="saldi['.$this->banca->id.']" inputmode="decimal" maxlength="30" value="0,00"', false) // i depositi sono divisi tra due conti: non si indovina
            ->assertSeeText('Chiusura 2025 nel foglio: cassa 120,00 €, depositi 530,00 €')
            ->assertSeeText('Estratto: saldo iniziale 400,00 € (dal 02/01/2026)')->assertSeeText('Conta di cassa al 01/01/2026: 119,50 €');
    }

    public function test_i_saldi_si_inseriscono_una_sola_volta_e_finiscono_nei_conti_e_nel_registro(): void
    {
        $this->storico2025();

        $this->actingAs($this->tesoriere)->post('/esercizi/2026/saldi-apertura', $this->saldi())->assertRedirect('/esercizi/2026/saldi-apertura')->assertSessionHas('ok');

        $this->assertSame([12000, 40000, 13000], [$this->cassa->fresh()->saldo_iniziale, $this->banca->fresh()->saldo_iniziale, $this->paypal->fresh()->saldo_iniziale]);
        $this->assertNotNull(DatiEsercizio::per(2026)->saldi_apertura_il);
        $r = RegistroModifica::where('oggetto_tipo', 'conto')->where('oggetto_id', $this->banca->id)->firstOrFail();
        $this->assertSame([0, 40000, 'modificato'], [$r->prima['saldo_iniziale'], $r->dopo['saldo_iniziale'], $r->azione]);

        // Una seconda volta no: né dalla procedura né cambiando il saldo di partenza dalla pagina del conto.
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/saldi-apertura', $this->saldi(banca: '999,00'))->assertSessionHasErrors('apertura');
        $this->assertSame(40000, $this->banca->fresh()->saldo_iniziale);
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/saldi-apertura')->assertOk()->assertSeeText('già inseriti')->assertDontSee('<button type="submit" class="bottone">Inserisci', false);
        $this->actingAs($this->tesoriere)->put('/conti/'.$this->banca->id, ['nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => '1,00'])
            ->assertSessionHasErrors('saldo_iniziale');
        $this->assertSame(40000, $this->banca->fresh()->saldo_iniziale);
    }

    public function test_i_saldi_finali_del_2025_devono_coincidere_con_i_saldi_iniziali_del_2026(): void
    {
        $this->storico2025();

        $this->actingAs($this->tesoriere)->post('/esercizi/2026/saldi-apertura', $this->saldi(banca: '350,00'))
            ->assertSessionHasErrors('apertura'); // depositi 350 + 130 = 480, non 530
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/saldi-apertura', $this->saldi(cassa: '100,00'))->assertSessionHasErrors('apertura');

        $this->assertSame([0, 0, 0], [$this->cassa->fresh()->saldo_iniziale, $this->banca->fresh()->saldo_iniziale, $this->paypal->fresh()->saldo_iniziale]);
        $this->assertNull(DatiEsercizio::per(2026)->saldi_apertura_il);
    }

    public function test_senza_esercizio_storico_si_inserisce_lo_stesso_e_la_pagina_dice_che_manca_il_controllo(): void
    {
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/saldi-apertura')->assertOk()->assertSeeText('Esercizio storico 2025 non caricato: nessun controllo');

        $this->actingAs($this->tesoriere)->post('/esercizi/2026/saldi-apertura', $this->saldi('10,00', '20,00', '30,00'))->assertSessionHas('ok');

        $this->assertSame(1000, $this->cassa->fresh()->saldo_iniziale);
    }

    public function test_solo_i_conti_aperti_al_primo_gennaio_ci_sono_e_ognuno_va_compilato(): void
    {
        $dopo = Conto::factory()->create(['nome' => 'Cassa Festa', 'aperto_il' => '2026-06-01']);
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/saldi-apertura')->assertDontSeeText('Cassa Festa');

        $this->actingAs($this->tesoriere)->post('/esercizi/2026/saldi-apertura', ['saldi' => [$this->cassa->id => '1', $this->banca->id => '2']])->assertSessionHasErrors('saldi'); // manca PayPal
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/saldi-apertura', ['saldi' => [$this->cassa->id => '1', $this->banca->id => '2', $this->paypal->id => 'tanti']])->assertSessionHasErrors();
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/saldi-apertura', ['saldi' => [$this->cassa->id => '1', $this->banca->id => '2', $this->paypal->id => '3', $dopo->id => '4']])->assertSessionHasErrors('saldi'); // un conto non dell'apertura
        $this->assertSame(0, $this->cassa->fresh()->saldo_iniziale);
    }

    public function test_solo_chi_gestisce_i_dati_dell_esercizio_e_il_permesso_si_rilegge_a_riga_bloccata(): void
    {
        $this->actingAs($this->utenteCon('Consigliere'))->get('/esercizi/2026/saldi-apertura')->assertForbidden();
        $this->actingAs($this->utenteCon('Consigliere'))->post('/esercizi/2026/saldi-apertura', $this->saldi())->assertForbidden();

        $this->tesoriere->assegnazioni()->delete();
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/saldi-apertura', $this->saldi())->assertForbidden();
        $this->assertSame(0, $this->cassa->fresh()->saldo_iniziale);
    }
}
