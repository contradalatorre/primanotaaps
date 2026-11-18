<?php

namespace Tests\Feature;

use App\Esercizio\SaldiApertura;
use App\Models\ContaDiCassa;
use App\Models\Conto;
use App\Models\DatiEsercizio;
use App\Models\EsercizioStorico;
use App\Models\ImportEstratto;
use App\Models\RegistroModifica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    private function nuovoConto(string $nome, string $saldo, string $apertoIl): array
    {
        return ['tipo' => 'cassa', 'nome' => $nome, 'formato_import' => 'manuale', 'riga_modello_d' => 'cassa', 'aperto_il' => $apertoIl, 'saldo_iniziale' => $saldo];
    }

    public function test_dopo_l_apertura_un_conto_al_primo_gennaio_nasce_o_arriva_solo_se_la_chiusura_del_2025_torna(): void
    {
        $this->storico2025();
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/saldi-apertura', $this->saldi())->assertSessionHas('ok');
        $dopo = Conto::factory()->create(['nome' => 'Cassa Festa', 'aperto_il' => '2026-06-01']);

        // Creato al 1/1 con un saldo che sposta la cassa da 120,00 a 125,00: la chiusura 2025 non torna.
        $this->actingAs($this->tesoriere)->post('/conti', $this->nuovoConto('Cassa nuova', '5,00', '2026-01-01'))->assertSessionHasErrors('saldo_iniziale');
        $this->assertSame(0, Conto::where('nome', 'Cassa nuova')->count());
        // Spostato al 1/1 con un saldo: lo stesso.
        $this->actingAs($this->tesoriere)->put('/conti/'.$dopo->id, $this->nuovoConto('Cassa Festa', '5,00', '2026-01-01'))->assertSessionHasErrors('saldo_iniziale');
        $this->assertSame('2026-06-01', $dopo->fresh()->aperto_il->toDateString());
        // A zero la chiusura torna, e un conto aperto dopo si crea come sempre.
        $this->actingAs($this->tesoriere)->post('/conti', $this->nuovoConto('Cassa a zero', '0', '2026-01-01'))->assertSessionHasNoErrors();
        $this->actingAs($this->tesoriere)->post('/conti', $this->nuovoConto('Cassa estate', '5,00', '2026-06-02'))->assertSessionHasNoErrors();
        // Uno dell'apertura non cambia riga: sarebbe un altro modo di spostare il saldo.
        $this->actingAs($this->tesoriere)->put('/conti/'.$this->banca->id, ['nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'cassa', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => '400,00'])->assertSessionHasErrors('saldo_iniziale');
        $this->assertSame('depositi', $this->banca->fresh()->riga_modello_d);
    }

    public function test_senza_storico_un_conto_nuovo_al_primo_gennaio_dopo_l_apertura_ha_saldo_zero(): void
    {
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/saldi-apertura', $this->saldi())->assertSessionHas('ok');

        $this->actingAs($this->tesoriere)->post('/conti', $this->nuovoConto('Cassa nuova', '5,00', '2026-01-01'))->assertSessionHasErrors('saldo_iniziale');
        $this->actingAs($this->tesoriere)->post('/conti', $this->nuovoConto('Cassa nuova', '0', '2026-01-01'))->assertSessionHasNoErrors();
    }

    public function test_creare_o_modificare_un_conto_al_primo_gennaio_blocca_prima_i_dati_dell_esercizio_e_poi_il_conto(): void
    {
        $query = [];
        DB::listen(function ($q) use (&$query) {
            $query[] = $q->sql;
        });
        $bloccanti = function () use (&$query) {
            return array_values(array_filter($query, fn (string $sql) => str_contains($sql, 'for update')));
        };

        // Come la procedura di apertura (`inserisci`): prima `dati_esercizio`, poi i conti. Altrimenti, in REPEATABLE READ, i due si leggono a vicenda un'apertura vecchia.
        $this->actingAs($this->tesoriere)->put('/conti/'.$this->cassa->id, ['nome' => 'Cassa contanti', 'formato_import' => 'manuale', 'riga_modello_d' => 'cassa', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => '1,00'])->assertSessionHasNoErrors();
        $this->assertStringContainsString('dati_esercizio', $bloccanti()[0]);
        $this->assertStringContainsString('`conti`', $bloccanti()[1]);

        $query = [];
        $this->actingAs($this->tesoriere)->post('/conti', $this->nuovoConto('Cassa nuova', '0', '2026-01-01'))->assertSessionHasNoErrors();
        $this->assertStringContainsString('dati_esercizio', $bloccanti()[0]);

        // Il permesso si rilegge a riga bloccata, come nelle altre scritture.
        $this->tesoriere->assegnazioni()->delete();
        $this->actingAs($this->tesoriere)->post('/conti', $this->nuovoConto('Cassa altra', '0', '2026-01-01'))->assertForbidden();
    }

    public function test_dopo_l_apertura_una_data_di_apertura_anteriore_al_primo_gennaio_non_aggira_il_controllo(): void
    {
        $this->storico2025();
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/saldi-apertura', $this->saldi())->assertSessionHas('ok');
        $dopo = Conto::factory()->create(['nome' => 'Cassa Festa', 'aperto_il' => '2026-06-01']);
        $iniziali = SaldiApertura::iniziali(2026);

        // Il Modello D somma il saldo di un conto aperto il 31/12/2025 nei saldi iniziali del 2026: per la procedura di apertura è un secondo inserimento.
        $this->actingAs($this->tesoriere)->post('/conti', $this->nuovoConto('Cassa nuova', '5,00', '2025-12-31'))->assertSessionHasErrors('aperto_il');
        $this->assertSame(0, Conto::where('nome', 'Cassa nuova')->count());
        $this->actingAs($this->tesoriere)->put('/conti/'.$dopo->id, $this->nuovoConto('Cassa Festa', '5,00', '2025-12-31'))->assertSessionHasErrors('aperto_il');
        $this->assertSame('2026-06-01', $dopo->fresh()->aperto_il->toDateString());
        $this->assertSame($iniziali, SaldiApertura::iniziali(2026));
        // Un conto già più vecchio si rinomina ancora: la data non cambia.
        $vecchio = Conto::factory()->create(['nome' => 'Cassa vecchia', 'aperto_il' => '2025-06-01']);
        $this->actingAs($this->tesoriere)->put('/conti/'.$vecchio->id, $this->nuovoConto('Cassa vecchia 2', '0', '2025-06-01'))->assertSessionHasNoErrors();
    }

    public function test_un_conto_aperto_prima_del_primo_gennaio_ferma_l_apertura(): void
    {
        Conto::factory()->create(['nome' => 'Cassa 2025', 'aperto_il' => '2025-12-31']);

        $this->actingAs($this->tesoriere)->post('/esercizi/2026/saldi-apertura', $this->saldi())->assertSessionHasErrors('apertura');
        $this->assertNull(DatiEsercizio::per(2026)->saldi_apertura_il);
    }
}
