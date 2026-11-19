<?php

namespace Tests\Unit;

use App\Esercizio\Calcolo;
use App\Esercizio\Differenze;
use App\Esercizio\Figurativo;
use App\Esercizio\Imputata;
use App\Esercizio\Saldo;
use App\Esercizio\Secondarieta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Le differenze dalla versione approvata (#59) sul modulo puro: importi inventati, in centesimi. */
class DifferenzeTest extends TestCase
{
    /**
     * @param  array<string, int>  $entrate  voce → importo
     * @param  array<string, int>  $uscite
     */
    private static function istantanea(array $entrate, array $uscite = [], string $esito2bis = 'rispettato'): array
    {
        $imputate = [];
        foreach ($entrate as $voce => $importo) {
            $imputate[] = new Imputata('entrate', $voce, $importo);
        }
        foreach ($uscite as $voce => $importo) {
            $imputate[] = new Imputata('uscite', $voce, $importo);
        }
        $modello = Calcolo::modelloD($imputate, [new Saldo('cassa', 0, 5000)], [new Figurativo('costo', 'A', 'x', 700)]);

        return [
            'modello_d' => $modello,
            'secondarieta' => Secondarieta::calcola($modello, true, 80, null),
            'catena' => ['areaA' => ['esito' => $esito2bis], 'ente' => ['esito' => 'rispettato'], 'forfettario' => ['esito' => 'rispettato']],
        ];
    }

    /** @return list<array{string, ?int, ?int}> */
    private static function voci(array $approvata, array $attuale): array
    {
        $d = Differenze::tra(Differenze::impronta($approvata), Differenze::impronta($attuale));

        return array_map(fn (array $r) => [$r['etichetta'], $r['approvato'], $r['attuale']], $d['voci']);
    }

    public function test_due_esercizi_uguali_non_hanno_differenze(): void
    {
        $i = self::istantanea(['A1' => 10000], ['A2' => 4000]);

        $this->assertSame(['voci' => [], 'esiti' => []], Differenze::tra(Differenze::impronta($i), Differenze::impronta($i)));
    }

    public function test_l_istantanea_salvata_come_json_si_confronta_uguale_a_se_stessa(): void
    {
        $i = self::istantanea(['A1' => 10000, 'B3' => 2500], ['A2' => 4000]);
        $riletta = json_decode(json_encode($i), true);

        $this->assertSame(['voci' => [], 'esiti' => []], Differenze::tra(Differenze::impronta($riletta), Differenze::impronta($i)));
    }

    public function test_una_voce_cambiata_porta_con_se_i_totali_che_cambiano(): void
    {
        $voci = self::voci(self::istantanea(['A1' => 10000], ['A2' => 4000]), self::istantanea(['A1' => 12000], ['A2' => 4000]));

        $this->assertSame([
            ['Entrate A1) Entrate da quote associative e apporti dei fondatori', 10000, 12000],
            ['Totale entrate della gestione', 10000, 12000],
            ['Avanzo/disavanzo complessivo', 6000, 8000],
        ], $voci);
    }

    public function test_la_stessa_voce_di_entrate_e_di_uscite_non_si_confonde(): void
    {
        $voci = self::voci(self::istantanea(['A1' => 100], ['A1' => 50]), self::istantanea(['A1' => 100], ['A1' => 80]));

        $this->assertSame('Uscite A1) Materie prime, sussidiarie, di consumo e di merci', $voci[0][0]);
        $this->assertSame([50, 80], [$voci[0][1], $voci[0][2]]);
    }

    public function test_una_voce_azzerata_o_nata_dopo_l_approvazione_si_vede(): void
    {
        $nata = self::voci(self::istantanea(['A1' => 10000]), self::istantanea(['A1' => 10000, 'B3' => 500]));
        $azzerata = self::voci(self::istantanea(['A1' => 10000, 'B3' => 500]), self::istantanea(['A1' => 10000]));

        $this->assertSame(['Entrate B3) Entrate per prestazioni e cessioni a terzi', 0, 500], $nata[0]);
        $this->assertSame(['Entrate B3) Entrate per prestazioni e cessioni a terzi', 500, 0], $azzerata[0]);
    }

    #[DataProvider('esiti')]
    public function test_un_esito_cambiato_si_vede(string $prima, string $dopo, bool $cambia): void
    {
        $d = Differenze::tra(Differenze::impronta(self::istantanea(['A1' => 100], [], $prima)), Differenze::impronta(self::istantanea(['A1' => 100], [], $dopo)));

        $this->assertSame($cambia ? [['Art. 79 c. 2-bis sull’area A', $prima, $dopo]] : [], array_map(fn (array $r) => [$r['etichetta'], $r['approvato'], $r['attuale']], $d['esiti']));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function esiti(): array
    {
        return ['da rispettato a non rispettato' => ['rispettato', 'non_rispettato', true], 'da vicino a rispettato' => ['vicino', 'rispettato', true], 'uguale' => ['vicino', 'vicino', false]];
    }
}
