<?php

namespace Tests\Unit;

use App\Esercizio\Calcolo;
use App\Esercizio\Figurativo;
use App\Esercizio\Imputata;
use App\Esercizio\Saldo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Il modulo di calcolo dell'esercizio (seconda seam di test della spec #33): niente database né HTTP, solo valori in
 * ingresso e il Modello D in uscita. Importi inventati, in centesimi.
 */
class CalcoloEsercizioTest extends TestCase
{
    private function e(string $voce, int $importo): Imputata
    {
        return new Imputata('entrate', $voce, $importo);
    }

    private function u(string $voce, int $importo): Imputata
    {
        return new Imputata('uscite', $voce, $importo);
    }

    /** @return array<string, int> importo di ogni riga del Modello D, per «lato:voce» */
    private function righe(array $modello): array
    {
        $righe = [];
        foreach ($modello['aree'] as $area) {
            foreach (['uscite', 'entrate'] as $lato) {
                foreach ($area[$lato]['righe'] as $riga) {
                    $righe["$lato:{$riga['voce']}"] = $riga['importo'];
                }
            }
        }
        foreach (['uscite', 'entrate'] as $lato) {
            foreach ($modello['investimenti'][$lato]['righe'] as $riga) {
                $righe["$lato:{$riga['voce']}"] = $riga['importo'];
            }
        }

        return $righe;
    }

    public function test_lo_schema_e_completo_anche_senza_imputazioni(): void
    {
        $m = Calcolo::modelloD([], [], []);

        $conteggio = fn (string $lato) => array_map(fn (array $a) => count($a[$lato]['righe']), $m['aree']);
        $this->assertSame(['A' => 5, 'B' => 5, 'C' => 3, 'D' => 5, 'E' => 5], $conteggio('uscite'));
        $this->assertSame(['A' => 10, 'B' => 6, 'C' => 3, 'D' => 5, 'E' => 2], $conteggio('entrate'));
        $this->assertCount(4, $m['investimenti']['uscite']['righe']);
        $this->assertCount(4, $m['investimenti']['entrate']['righe']);
        $this->assertSame(0, array_sum($this->righe($m)));
        $this->assertSame(0, $m['avanzoComplessivo']['importo']);
        $this->assertTrue($m['quadratura']['quadra']);
        $this->assertSame([], $m['anomalie']);
    }

    public function test_la_stessa_voce_di_entrata_e_di_uscita_non_si_confonde(): void
    {
        $m = Calcolo::modelloD([$this->e('A1', 1000), $this->e('A1', 500), $this->u('A1', 300)], [], []);

        $righe = $this->righe($m);
        $this->assertSame(1500, $righe['entrate:A1']);
        $this->assertSame(300, $righe['uscite:A1']);
        $this->assertSame(1500, $m['aree']['A']['entrate']['totale']['importo']);
        $this->assertSame(300, $m['aree']['A']['uscite']['totale']['importo']);
        $this->assertSame(1200, $m['aree']['A']['avanzo']['importo']);
    }

    public function test_incasso_al_lordo_e_commissione_in_d1(): void
    {
        // Un incasso PayPal: netto 965, commissione 35, classificato al lordo (#48): entrata 1000 in A7 e uscita 35 in D1.
        $m = Calcolo::modelloD([$this->e('A7', 1000), $this->u('D1', 35)], [new Saldo('depositi', 0, 965)], []);

        $righe = $this->righe($m);
        $this->assertSame(1000, $righe['entrate:A7']);
        $this->assertSame(35, $righe['uscite:D1']);
        $this->assertSame(-35, $m['aree']['D']['avanzo']['importo']);
        $this->assertSame(965, $m['avanzoComplessivo']['importo']);
        $this->assertTrue($m['quadratura']['quadra']);
    }

    public function test_catena_degli_avanzi(): void
    {
        $m = Calcolo::modelloD([
            $this->e('A4', 1000), $this->u('B2', 400), $this->u('Imposte', 100),
            $this->u('I1', 200), $this->e('I3', 50), $this->e('D1', 20), $this->u('E5', 10),
        ], [], []);

        $this->assertSame(1020, $m['gestione']['entrate']['importo']);
        $this->assertSame(410, $m['gestione']['uscite']['importo']);
        $this->assertSame(610, $m['gestione']['avanzo']['importo']);
        $this->assertSame(100, $m['imposte']['importo']);
        $this->assertSame(510, $m['avanzoPrimaDegliInvestimenti']['importo']);
        $this->assertSame(200, $m['investimenti']['uscite']['totale']['importo']);
        $this->assertSame(50, $m['investimenti']['entrate']['totale']['importo']);
        $this->assertSame(-150, $m['investimenti']['avanzo']['importo']);
        $this->assertSame(360, $m['avanzoComplessivo']['importo']);
    }

    public function test_ogni_cifra_porta_le_sue_voci(): void
    {
        $m = Calcolo::modelloD([], [], []);

        $this->assertSame(['entrate:A1'], $m['aree']['A']['entrate']['righe'][0]['chiavi']);
        $this->assertContains('uscite:A5', $m['aree']['A']['avanzo']['chiavi']);
        $this->assertContains('entrate:A10', $m['aree']['A']['avanzo']['chiavi']);
        $this->assertNotContains('uscite:Imposte', $m['gestione']['avanzo']['chiavi']);
        $this->assertSame(['uscite:Imposte'], $m['imposte']['chiavi']);
        $this->assertContains('uscite:Imposte', $m['avanzoPrimaDegliInvestimenti']['chiavi']);
        $this->assertContains('entrate:I4', $m['avanzoComplessivo']['chiavi']);
    }

    public function test_cassa_e_depositi_dai_saldi_dei_conti(): void
    {
        $m = Calcolo::modelloD([], [new Saldo('cassa', 100, 250), new Saldo('depositi', 1000, 900), new Saldo('depositi', 0, 40)], []);

        $this->assertSame(250, $m['liquidita']['cassa']);
        $this->assertSame(940, $m['liquidita']['depositi']);
        $this->assertSame(1190, $m['liquidita']['totale']);
        $this->assertSame(1100, $m['liquidita']['iniziale']);
    }

    /** @return iterable<string, array{list<Imputata>, list<Saldo>, bool, int}> imputazioni, saldi, quadra?, differenza */
    public static function quadrature(): iterable
    {
        yield 'esercizio vuoto' => [[], [], true, 0];
        yield 'un incasso in cassa' => [[new Imputata('entrate', 'A1', 1000)], [new Saldo('cassa', 0, 1000)], true, 0];
        yield 'incasso al lordo e commissione in D1 (#48)' => [[new Imputata('entrate', 'A7', 1000), new Imputata('uscite', 'D1', 35)], [new Saldo('depositi', 0, 965)], true, 0];
        yield 'imposte e investimenti sono uscite di cassa' => [[new Imputata('entrate', 'A1', 1000), new Imputata('uscite', 'Imposte', 100), new Imputata('uscite', 'I1', 200)], [new Saldo('cassa', 0, 700)], true, 0];
        yield 'la liquidità cresce meno dell\'avanzo' => [[new Imputata('entrate', 'A1', 1000), new Imputata('uscite', 'A2', 300)], [new Saldo('depositi', 500, 1100)], false, -100];
        yield 'la liquidità cresce senza imputazioni' => [[], [new Saldo('cassa', 100, 250)], false, 150];
    }

    #[DataProvider('quadrature')]
    public function test_quadratura_con_la_variazione_della_liquidita(array $imputazioni, array $saldi, bool $quadra, int $differenza): void
    {
        $m = Calcolo::modelloD($imputazioni, $saldi, []);

        $this->assertSame($quadra, $m['quadratura']['quadra']);
        $this->assertSame($differenza, $m['quadratura']['differenza']);
    }

    /** @return iterable<string, array{list<Imputata>, array<string, int>, int}> imputazioni, righe attese per «lato:voce», anomalie */
    public static function voci(): iterable
    {
        yield 'la stessa voce di entrata e di uscita non si confonde' => [
            [new Imputata('entrate', 'A1', 1000), new Imputata('entrate', 'A1', 500), new Imputata('uscite', 'A1', 300)],
            ['entrate:A1' => 1500, 'uscite:A1' => 300], 0,
        ];
        yield 'Imposte è una riga solo delle uscite' => [
            [new Imputata('entrate', 'Imposte', 50)],
            ['entrate:A1' => 0], 1,
        ];
        yield 'le righe degli investimenti sono ai due lati' => [
            [new Imputata('uscite', 'I1', 200), new Imputata('entrate', 'I3', 50)],
            ['uscite:I1' => 200, 'entrate:I3' => 50], 0,
        ];
        yield 'una voce fuori dallo schema è un\'anomalia' => [
            [new Imputata('entrate', 'A1', 1000), new Imputata('entrate', 'X9', 70)],
            ['entrate:A1' => 1000], 1,
        ];
        yield 'una voce di un altro lato è un\'anomalia' => [
            [new Imputata('uscite', 'A10', 10)],
            ['uscite:A5' => 0], 1,
        ];
    }

    #[DataProvider('voci')]
    public function test_le_voci_vanno_alla_loro_riga(array $imputazioni, array $attese, int $anomalie): void
    {
        $m = Calcolo::modelloD($imputazioni, [], []);

        $righe = $this->righe($m);
        foreach ($attese as $chiave => $importo) {
            $this->assertSame($importo, $righe[$chiave], $chiave);
        }
        $this->assertCount($anomalie, $m['anomalie']);
    }

    /** @return iterable<string, array{list<Figurativo>, array<string, int>, array<string, int>, int}> figurativi, costi per area, proventi per area, anomalie */
    public static function figurativi(): iterable
    {
        yield 'nessun figurativo' => [[], ['A' => 0, 'B' => 0], ['A' => 0, 'B' => 0], 0];
        yield 'costi e proventi nelle aree A e B' => [
            [new Figurativo('costo', 'A', 'Volontari del Carnevale, 40 ore', 300), new Figurativo('costo', 'A', 'Altre ore', 50), new Figurativo('provento', 'B', 'Prestazione gratuita di un tecnico', 200)],
            ['A' => 350, 'B' => 0], ['A' => 0, 'B' => 200], 0,
        ];
        // Il DM 5/3/2020 ha solo le righe 1) attività di interesse generale e 2) attività diverse: gli altri non sono persi in silenzio.
        foreach (['costo', 'provento'] as $tipo) {
            foreach (['C', 'D', 'E'] as $area) {
                yield "un $tipo figurativo in area $area è un'anomalia" => [[new Figurativo($tipo, $area, 'Ore di un volontario', 500)], ['A' => 0, 'B' => 0], ['A' => 0, 'B' => 0], 1];
            }
        }
    }

    #[DataProvider('figurativi')]
    public function test_figurativi_a_parte_dagli_avanzi(array $figurativi, array $costi, array $proventi, int $anomalie): void
    {
        $m = Calcolo::modelloD([new Imputata('entrate', 'A1', 1000)], [], $figurativi);

        $this->assertSame($costi, array_column($m['figurativi']['costi']['righe'], 'importo', 'area'));
        $this->assertSame($proventi, array_column($m['figurativi']['proventi']['righe'], 'importo', 'area'));
        $this->assertSame(array_sum($costi), $m['figurativi']['costi']['totale']);
        $this->assertSame(array_sum($proventi), $m['figurativi']['proventi']['totale']);
        $this->assertSame(1000, $m['avanzoComplessivo']['importo']);
        $this->assertCount($anomalie, $m['anomalie']);
    }

    public function test_la_descrizione_libera_di_un_figurativo_non_entra_nel_modello_d(): void
    {
        $m = Calcolo::modelloD([], [], [new Figurativo('costo', 'A', 'Volontari del Carnevale, Mario Rossi, 40 ore', 300)]);

        $this->assertSame(300, $m['figurativi']['costi']['righe'][0]['importo']);
        $this->assertStringNotContainsString('Mario Rossi', json_encode($m)); // può nominare una persona (storia 143)
    }

    public function test_senza_riga_si_raccolgono_le_voci_mancanti_o_sconosciute(): void
    {
        $m = Calcolo::modelloD([new Imputata('entrate', 'A1', 1000), new Imputata('entrate', 'X9', 70), new Imputata('uscite', null, 30)], [], []);

        $this->assertSame(1000, $m['gestione']['entrate']['importo']);
        $this->assertSame(0, $m['gestione']['uscite']['importo']);
        $this->assertSame(70, $m['senzaRiga']['entrate']);
        $this->assertSame(30, $m['senzaRiga']['uscite']);
    }

    public function test_l_intestazione_riporta_le_impostazioni(): void
    {
        $impostazioni = ['denominazione' => 'Associazione di prova APS', 'codice_fiscale' => '00000000000', 'numero_runts' => null, 'affiliazione_rete' => false];

        $this->assertSame($impostazioni, Calcolo::modelloD([], [], [], $impostazioni)['intestazione']);
    }
}
