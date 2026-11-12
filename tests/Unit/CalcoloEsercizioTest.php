<?php

namespace Tests\Unit;

use App\Esercizio\Calcolo;
use App\Esercizio\Figurativo;
use App\Esercizio\Imputata;
use App\Esercizio\Saldo;
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

    public function test_quadratura_con_la_variazione_della_liquidita(): void
    {
        $m = Calcolo::modelloD([$this->e('A1', 1000), $this->u('A2', 300)], [new Saldo('depositi', 500, 1100)], []);

        $this->assertSame(700, $m['avanzoComplessivo']['importo']);
        $this->assertFalse($m['quadratura']['quadra']);
        $this->assertSame(-100, $m['quadratura']['differenza']); // la liquidità è cresciuta di 600 e non di 700
    }

    public function test_figurativi_a_parte_dagli_avanzi(): void
    {
        $m = Calcolo::modelloD([$this->e('A1', 1000)], [], [
            new Figurativo('costo', 'A', 'Volontari del Carnevale, 40 ore', 300),
            new Figurativo('costo', 'E', 'Volontari della segreteria', 100),
            new Figurativo('provento', 'A', 'Prestazione gratuita di un tecnico', 200),
        ]);

        $costi = array_column($m['figurativi']['costi']['righe'], 'importo', 'area');
        $this->assertSame(['A' => 300, 'B' => 0, 'E' => 100], $costi);
        $this->assertSame(400, $m['figurativi']['costi']['totale']);
        $proventi = array_column($m['figurativi']['proventi']['righe'], 'importo', 'area');
        $this->assertSame(['A' => 200, 'B' => 0], $proventi);
        $this->assertSame(200, $m['figurativi']['proventi']['totale']);
        $this->assertSame(1000, $m['avanzoComplessivo']['importo']);
        $this->assertSame('Volontari del Carnevale, 40 ore', $m['figurativi']['costi']['righe'][0]['dettaglio'][0]['descrizione']);
    }

    public function test_un_figurativo_in_un_area_senza_riga_e_un_anomalia_non_e_perso_in_silenzio(): void
    {
        $m = Calcolo::modelloD([], [], [new Figurativo('provento', 'C', 'Valore di una prestazione', 500)]);

        $this->assertSame(0, $m['figurativi']['proventi']['totale']);
        $this->assertCount(1, $m['anomalie']);
        $this->assertStringContainsString('figurativo', $m['anomalie'][0]);
    }

    public function test_una_voce_fuori_dallo_schema_o_mancante_e_un_anomalia_e_non_entra_nei_totali(): void
    {
        $m = Calcolo::modelloD([$this->e('A1', 1000), $this->e('X9', 70), new Imputata('uscite', null, 30)], [], []);

        $this->assertSame(1000, $m['gestione']['entrate']['importo']);
        $this->assertSame(0, $m['gestione']['uscite']['importo']);
        $this->assertCount(2, $m['anomalie']);
        $this->assertSame(70, $m['senzaRiga']['entrate']);
        $this->assertSame(30, $m['senzaRiga']['uscite']);
    }

    public function test_l_intestazione_riporta_le_impostazioni(): void
    {
        $impostazioni = ['denominazione' => 'Associazione di prova APS', 'codice_fiscale' => '00000000000', 'numero_runts' => null, 'affiliazione_rete' => false];

        $this->assertSame($impostazioni, Calcolo::modelloD([], [], [], $impostazioni)['intestazione']);
    }
}
