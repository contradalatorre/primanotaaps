<?php

namespace Tests\Unit;

use App\Esercizio\ContoRendiconto;
use App\Esercizio\RendicontoAssemblea;
use App\Esercizio\RigaRendiconto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Il rendiconto per l'assemblea (#56), senza database né HTTP: nomi e importi inventati, in centesimi. */
class RendicontoAssembleaTest extends TestCase
{
    private function r(string $lato, string $area, ?string $attivita, ?string $iniziativa, string $tipologia, ?string $voce, int $conto, int $importo): RigaRendiconto
    {
        return new RigaRendiconto($lato, $area, $attivita, $iniziativa, $tipologia, $voce, $conto, $importo);
    }

    private function conto(int $id, string $nome = 'Conto', int $iniziale = 0, int $finale = 0, int $giroIn = 0, int $giroOut = 0, ?string $riconciliatoAl = null, ?int $saldoRiconciliato = null, string $fino = '2026-12-31'): ContoRendiconto
    {
        return new ContoRendiconto($id, $nome, $iniziale, $finale, $giroIn, $giroOut, $riconciliatoAl, $saldoRiconciliato, $fino);
    }

    /** Un esercizio piccolo: due conti, un'iniziativa con entrate e uscite, un'imputazione senza iniziativa, una commissione senza attività. */
    private function esercizio(): array
    {
        $righe = [
            $this->r('entrate', 'A', 'Cultura', 'Festa della Torre', 'Somministrazione', 'A7', 1, 50000),
            $this->r('entrate', 'A', 'Cultura', 'Festa della Torre', 'Somministrazione', 'A7', 2, 20000),
            $this->r('uscite', 'A', 'Cultura', 'Festa della Torre', 'Acquisti per la somministrazione', 'A1', 1, 30000),
            $this->r('entrate', 'A', 'Cultura', null, 'Quote associative', 'A1', 2, 10000),
            $this->r('uscite', 'D', null, null, 'Commissioni e oneri bancari', 'D1', 2, 90),
        ];
        $conti = [$this->conto(1, 'Cassa', 1000, 21000), $this->conto(2, 'Banca', 5000, 34910)];

        return [$righe, $conti];
    }

    public function test_righe_area_attivita_iniziativa_tipologia_con_una_colonna_per_conto_e_il_totale(): void
    {
        [$righe, $conti] = $this->esercizio();

        $r = RendicontoAssemblea::calcola($righe, $conti, []);

        $this->assertSame([1 => 'Cassa', 2 => 'Banca'], $r['conti']);
        $cultura = $r['aree']['A']['attivita'][0];
        $this->assertSame('Cultura', $cultura['nome']);
        $festa = $cultura['iniziative'][0];
        $this->assertSame('Festa della Torre', $festa['nome']);
        $this->assertSame([['tipologia' => 'Somministrazione', 'conti' => [1 => 50000, 2 => 20000], 'totale' => 70000]], $festa['entrate']['righe']);
        $this->assertSame(['conti' => [1 => 50000, 2 => 20000], 'totale' => 70000], $festa['entrate']['totale']);
        $this->assertSame(['conti' => [1 => 30000, 2 => 0], 'totale' => 30000], $festa['uscite']['totale']);
        $this->assertSame(['conti' => [1 => 20000, 2 => 20000], 'totale' => 40000], $festa['risultato']);
    }

    public function test_un_conto_senza_imputazioni_ha_comunque_la_sua_colonna_a_zero(): void
    {
        [$righe, $conti] = $this->esercizio();

        $r = RendicontoAssemblea::calcola($righe, [...$conti, $this->conto(3, 'Satispay')], []);

        $this->assertSame([1 => 'Cassa', 2 => 'Banca', 3 => 'Satispay'], $r['conti']);
        $this->assertSame(0, $r['aree']['A']['attivita'][0]['iniziative'][0]['entrate']['righe'][0]['conti'][3]);
    }

    public function test_le_imputazioni_senza_iniziativa_stanno_sotto_altro_con_l_attivita_in_fondo(): void
    {
        [$righe, $conti] = $this->esercizio();

        $iniziative = RendicontoAssemblea::calcola($righe, $conti, [])['aree']['A']['attivita'][0]['iniziative'];

        $this->assertSame(['Festa della Torre', 'Altro – Cultura'], array_column($iniziative, 'nome'));
        $this->assertSame(10000, $iniziative[1]['entrate']['totale']['totale']);
    }

    public function test_senza_attivita_il_gruppo_e_l_altro_si_chiamano_senza_attivita_e_altro(): void
    {
        [$righe, $conti] = $this->esercizio();

        $d = RendicontoAssemblea::calcola($righe, $conti, [])['aree']['D'];

        $this->assertSame('Senza attività', $d['attivita'][0]['nome']);
        $this->assertSame('Altro', $d['attivita'][0]['iniziative'][0]['nome']);
        $this->assertSame(90, $d['attivita'][0]['iniziative'][0]['uscite']['totale']['totale']);
    }

    public function test_totali_di_attivita_area_e_generali_con_il_risultato(): void
    {
        [$righe, $conti] = $this->esercizio();

        $r = RendicontoAssemblea::calcola($righe, $conti, []);

        $cultura = $r['aree']['A']['attivita'][0];
        $this->assertSame(80000, $cultura['entrate']['totale']);
        $this->assertSame(30000, $cultura['uscite']['totale']);
        $this->assertSame(50000, $cultura['risultato']['totale']);
        $this->assertSame(50000, $r['aree']['A']['risultato']['totale']);
        $this->assertSame(['conti' => [1 => 50000, 2 => 30000], 'totale' => 80000], $r['totali']['entrate']);
        $this->assertSame(['conti' => [1 => 30000, 2 => 90], 'totale' => 30090], $r['totali']['uscite']);
        $this->assertSame(['conti' => [1 => 20000, 2 => 29910], 'totale' => 49910], $r['totali']['risultato']);
    }

    public function test_le_aree_senza_imputazioni_non_compaiono_e_le_aree_seguono_l_ordine_del_modello(): void
    {
        $r = RendicontoAssemblea::calcola([
            $this->r('uscite', 'E', 'Segreteria', null, 'Utenze', 'E2', 1, 100),
            $this->r('entrate', 'B', 'Bar', null, 'Somministrazione', 'B3', 1, 200),
        ], [$this->conto(1)], []);

        $this->assertSame(['B', 'E'], array_keys($r['aree']));
    }

    public function test_vista_per_voce_modello_d_poi_tipologia_entrate_e_poi_uscite_nell_ordine_dello_schema(): void
    {
        [$righe, $conti] = $this->esercizio();
        $voci = RendicontoAssemblea::calcola($righe, $conti, [])['voci'];

        $this->assertSame(
            [['entrate', 'A1'], ['entrate', 'A7'], ['uscite', 'A1'], ['uscite', 'D1']],
            array_map(fn (array $v) => [$v['lato'], $v['voce']], $voci),
        );
        $a7 = $voci[1];
        $this->assertSame('Entrate per prestazioni e cessioni a terzi', $a7['etichetta']);
        $this->assertSame([['tipologia' => 'Somministrazione', 'conti' => [1 => 50000, 2 => 20000], 'totale' => 70000]], $a7['righe']);
        $this->assertSame(70000, $a7['totale']['totale']);
    }

    public function test_le_imputazioni_senza_riga_nel_modello_d_non_entrano_nei_totali_ma_si_dicono_a_parte(): void
    {
        [$righe, $conti] = $this->esercizio();
        $senzaRiga = [
            $this->r('uscite', 'E', 'Segreteria', null, 'Senza tipologia', null, 1, 5000),
            $this->r('entrate', 'A', 'Cultura', null, 'Incasso strano', 'Z9', 2, 700),
        ];

        $senza = RendicontoAssemblea::calcola([...$righe, ...$senzaRiga], $conti, []);
        $base = RendicontoAssemblea::calcola($righe, $conti, []);

        $this->assertSame($base['totali'], $senza['totali']);
        $this->assertSame($base['aree'], $senza['aree']);
        $this->assertSame($base['voci'], $senza['voci']);
        $this->assertSame($base['destinazione']['avanzo'], $senza['destinazione']['avanzo']);
        $this->assertSame(700, $senza['senzaRiga']['entrate']);
        $this->assertSame(5000, $senza['senzaRiga']['uscite']);
        $this->assertSame([
            '1 imputazioni in uscite per 50,00 € senza voce Modello D: non sono nel prospetto.',
            '1 imputazioni in entrate per 7,00 € con la voce «Z9», che non è nello schema: non sono nel prospetto.',
        ], $senza['senzaRiga']['avvisi']);
        $this->assertSame([], $base['senzaRiga']['avvisi']);
    }

    public function test_riepilogo_per_conto_con_giroconti_fuori_dai_totali_e_quadratura(): void
    {
        [$righe] = $this->esercizio();
        // Cassa: 10,00 + 500,00 entrate − 300,00 uscite + 100,00 giroconto in entrata − 200,00 in uscita = 110,00.
        // Banca: 50,00 + 300,00 − 0,90 + 200,00 in entrata − 100,00 in uscita = 449,10.
        $conti = [
            $this->conto(1, 'Cassa', iniziale: 1000, finale: 11000, giroIn: 10000, giroOut: 20000),
            $this->conto(2, 'Banca', iniziale: 5000, finale: 44910, giroIn: 20000, giroOut: 10000),
        ];

        $r = RendicontoAssemblea::calcola($righe, $conti, []);
        $c = $r['riepilogo']['conti'];

        $this->assertSame(['iniziale' => 1000, 'entrate' => 50000, 'uscite' => 30000, 'giroconti_entrata' => 10000, 'giroconti_uscita' => 20000, 'finale' => 11000, 'nonClassificato' => 0],
            array_intersect_key($c[1], array_flip(['iniziale', 'entrate', 'uscite', 'giroconti_entrata', 'giroconti_uscita', 'finale', 'nonClassificato'])));
        $this->assertSame(30000, $c[2]['entrate']);
        $this->assertSame(90, $c[2]['uscite']); // la commissione in D1 è un'uscita del conto
        $this->assertSame(0, $c[2]['nonClassificato']);
        // I giroconti non sono né entrate né uscite: restano fuori dai totali del prospetto.
        $this->assertSame(80000, $r['totali']['entrate']['totale']);
    }

    public function test_il_denaro_non_spiegato_dalle_imputazioni_compare_come_non_classificato(): void
    {
        $righe = [$this->r('entrate', 'A', 'Cultura', null, 'Quote associative', 'A1', 1, 10000)];

        $c = RendicontoAssemblea::calcola($righe, [$this->conto(1, iniziale: 0, finale: 12500)], [])['riepilogo']['conti'][1];

        $this->assertSame(2500, $c['nonClassificato']); // un movimento ancora da classificare, o la metà di un giroconto
    }

    /** @return array<string, array{?string, ?int, string, ?string, ?int}> */
    public static function riconciliazioni(): array
    {
        return [
            'riconciliato proprio al 31/12' => ['2026-12-31', 50000, '2026-12-31', null, 0],
            'riconciliato oltre il 31/12' => ['2027-02-15', 99999, '2026-12-31', null, 0],
            'riconciliato prima: avviso con la differenza' => ['2026-11-30', 40000, '2026-12-31', 'riconciliato solo fino al 30/11/2026', 10000],
            'mai riconciliato' => [null, null, '2026-12-31', 'non è mai stato riconciliato', null],
            'conto chiuso a giugno, riconciliato alla chiusura' => ['2026-06-30', 50000, '2026-06-30', null, 0],
            'conto chiuso a giugno, riconciliato prima' => ['2026-05-31', 45000, '2026-06-30', 'riconciliato solo fino al 31/05/2026', 5000],
        ];
    }

    #[DataProvider('riconciliazioni')]
    public function test_riconciliazione_al_31_12_e_confronto_con_il_saldo_riconciliato(?string $al, ?int $saldo, string $fino, ?string $avviso, ?int $differenza): void
    {
        $conto = $this->conto(1, 'Banca', finale: 50000, riconciliatoAl: $al, saldoRiconciliato: $saldo, fino: $fino);

        $c = RendicontoAssemblea::calcola([], [$conto], [])['riepilogo']['conti'][1];

        $this->assertSame($avviso === null, $c['riconciliato']);
        $this->assertSame($differenza, $c['differenzaRiconciliato']);
        if ($avviso === null) {
            $this->assertNull($c['avviso']);
        } else {
            $this->assertStringContainsString($avviso, $c['avviso']);
        }
    }

    public function test_la_riga_dei_totali_del_riepilogo_somma_i_conti(): void
    {
        [$righe, $conti] = $this->esercizio();

        $t = RendicontoAssemblea::calcola($righe, $conti, [])['riepilogo']['totale'];

        $this->assertSame(6000, $t['iniziale']);
        $this->assertSame(80000, $t['entrate']);
        $this->assertSame(30090, $t['uscite']);
        $this->assertSame(21000 + 34910, $t['finale']);
    }

    public function test_destinazione_dell_avanzo_con_il_residuo(): void
    {
        [$righe, $conti] = $this->esercizio();
        $destinazioni = [
            ['descrizione' => 'Fondo per il restauro del cancello', 'importo' => 30000, 'nota' => 'Proposta del consiglio'],
            ['descrizione' => 'Attività per i ragazzi', 'importo' => 10000, 'nota' => null],
        ];

        $d = RendicontoAssemblea::calcola($righe, $conti, $destinazioni)['destinazione'];

        $this->assertSame(49910, $d['avanzo']);
        $this->assertSame(40000, $d['destinato']);
        $this->assertSame(9910, $d['residuo']);
        $this->assertSame($destinazioni, $d['righe']);
    }

    public function test_un_avanzo_superato_dalla_destinazione_da_un_residuo_negativo_e_non_si_blocca(): void
    {
        [$righe, $conti] = $this->esercizio();

        $d = RendicontoAssemblea::calcola($righe, $conti, [['descrizione' => 'Tutto', 'importo' => 60000, 'nota' => null]])['destinazione'];

        $this->assertSame(-10090, $d['residuo']);
    }

    public function test_senza_imputazioni_il_rendiconto_e_vuoto_ma_il_riepilogo_dei_conti_c_e(): void
    {
        $r = RendicontoAssemblea::calcola([], [$this->conto(1, 'Cassa', iniziale: 700, finale: 700)], []);

        $this->assertSame([], $r['aree']);
        $this->assertSame([], $r['voci']);
        $this->assertSame(0, $r['totali']['risultato']['totale']);
        $this->assertSame(700, $r['riepilogo']['conti'][1]['finale']);
    }
}
