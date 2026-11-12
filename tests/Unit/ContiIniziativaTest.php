<?php

namespace Tests\Unit;

use App\Esercizio\ContiIniziativa;
use App\Esercizio\PrevistoIniziativa;
use App\Esercizio\RigaIniziativa;
use PHPUnit\Framework\TestCase;

/** I conti di un'iniziativa (#57), senza database né HTTP: importi inventati, in centesimi. */
class ContiIniziativaTest extends TestCase
{
    private function r(int $esercizio, string $lato, string $tipologia, int $importo): RigaIniziativa
    {
        return new RigaIniziativa($esercizio, $lato, $tipologia, $importo);
    }

    private function riga(array $conti, string $lato, string $tipologia): array
    {
        return collect($conti['righe'][$lato])->firstWhere('tipologia', $tipologia);
    }

    /** La riga senza le colonne del precedente e dall'avvio: il solo confronto con il preventivo. */
    private function confronto(array $conti, string $lato, string $tipologia): array
    {
        return array_diff_key($this->riga($conti, $lato, $tipologia), ['precedente' => 0, 'avvio' => 0]);
    }

    public function test_esercizio_scelto_precedente_e_totale_dall_avvio(): void
    {
        $righe = [
            $this->r(2024, 'entrate', 'Somministrazione', 10000),
            $this->r(2025, 'entrate', 'Somministrazione', 50000), $this->r(2025, 'uscite', 'Acquisto di servizi', 20000),
            $this->r(2026, 'entrate', 'Somministrazione', 80000), $this->r(2026, 'uscite', 'Acquisto di servizi', 30000),
            $this->r(2027, 'entrate', 'Somministrazione', 99999), // dopo l'esercizio scelto: non conta
        ];

        $c = ContiIniziativa::calcola($righe, [], 2026)['colonne'];

        $this->assertSame(['entrate' => 80000, 'uscite' => 30000, 'risultato' => 50000], $c['scelto']);
        $this->assertSame(['entrate' => 50000, 'uscite' => 20000, 'risultato' => 30000], $c['precedente']);
        $this->assertSame(['entrate' => 140000, 'uscite' => 50000, 'risultato' => 90000], $c['avvio']);
    }

    public function test_ogni_tipologia_ha_effettivo_nell_esercizio_scelto_nel_precedente_e_dall_avvio(): void
    {
        $conti = ContiIniziativa::calcola([
            $this->r(2024, 'entrate', 'Somministrazione', 10000), $this->r(2024, 'uscite', 'Imposte', 300),
            $this->r(2025, 'entrate', 'Somministrazione', 50000),
            $this->r(2026, 'entrate', 'Somministrazione', 80000), $this->r(2026, 'entrate', 'Somministrazione', 1000),
            $this->r(2027, 'entrate', 'Somministrazione', 99999), // dopo l'esercizio scelto: non conta
        ], [], 2026);

        $s = $this->riga($conti, 'entrate', 'Somministrazione');
        $this->assertSame([81000, 50000, 141000], [$s['effettivo'], $s['precedente'], $s['avvio']]);
        // una tipologia mossa solo prima del precedente compare lo stesso, con zero nelle prime due colonne
        $i = $this->riga($conti, 'uscite', 'Imposte');
        $this->assertSame([0, 0, 300], [$i['effettivo'], $i['precedente'], $i['avvio']]);
    }

    public function test_il_risultato_puo_essere_negativo_e_un_esercizio_senza_righe_vale_zero(): void
    {
        $c = ContiIniziativa::calcola([$this->r(2026, 'uscite', 'Acquisto di servizi', 700)], [], 2026)['colonne'];

        $this->assertSame(-700, $c['scelto']['risultato']);
        $this->assertSame(['entrate' => 0, 'uscite' => 0, 'risultato' => 0], $c['precedente']);
    }

    public function test_per_tipologia_con_preventivo_lo_scostamento_e_effettivo_meno_previsto(): void
    {
        $conti = ContiIniziativa::calcola(
            [$this->r(2026, 'entrate', 'Somministrazione', 80000), $this->r(2026, 'uscite', 'Acquisto di servizi', 30000), $this->r(2026, 'uscite', 'Acquisto di servizi', 5000)],
            [new PrevistoIniziativa('entrate', 'Somministrazione', 70000), new PrevistoIniziativa('uscite', 'Acquisto di servizi', 40000)],
            2026,
        );

        $this->assertTrue($conti['conPreventivo']);
        $this->assertSame(['tipologia' => 'Somministrazione', 'effettivo' => 80000, 'previsto' => 70000, 'scostamento' => 10000], $this->confronto($conti, 'entrate', 'Somministrazione'));
        // 350,00 spesi contro 400,00 previsti: −50,00 (sotto il preventivo)
        $this->assertSame(['tipologia' => 'Acquisto di servizi', 'effettivo' => 35000, 'previsto' => 40000, 'scostamento' => -5000], $this->confronto($conti, 'uscite', 'Acquisto di servizi'));
        $this->assertSame(['effettivo' => 80000, 'previsto' => 70000, 'scostamento' => 10000], $conti['totali']['entrate']);
        $this->assertSame(['effettivo' => 35000, 'previsto' => 40000, 'scostamento' => -5000], $conti['totali']['uscite']);
        $this->assertSame(['effettivo' => 45000, 'previsto' => 30000, 'scostamento' => 15000], $conti['totali']['risultato']);
    }

    public function test_una_tipologia_solo_nel_preventivo_o_solo_nell_effettivo_compare_comunque(): void
    {
        $conti = ContiIniziativa::calcola(
            [$this->r(2026, 'uscite', 'Imposte', 1200)],
            [new PrevistoIniziativa('uscite', 'Affitto spazi', 25000)],
            2026,
        );

        $this->assertSame(['tipologia' => 'Affitto spazi', 'effettivo' => 0, 'previsto' => 25000, 'scostamento' => -25000], $this->confronto($conti, 'uscite', 'Affitto spazi'));
        // non prevista: il previsto è zero, tutto lo speso è scostamento
        $this->assertSame(['tipologia' => 'Imposte', 'effettivo' => 1200, 'previsto' => 0, 'scostamento' => 1200], $this->confronto($conti, 'uscite', 'Imposte'));
    }

    public function test_senza_preventivo_non_c_e_scostamento(): void
    {
        $conti = ContiIniziativa::calcola([$this->r(2026, 'entrate', 'Somministrazione', 80000)], [], 2026);

        $this->assertFalse($conti['conPreventivo']);
        $this->assertSame(['tipologia' => 'Somministrazione', 'effettivo' => 80000, 'previsto' => null, 'scostamento' => null], $this->confronto($conti, 'entrate', 'Somministrazione'));
        $this->assertSame(['effettivo' => 80000, 'previsto' => null, 'scostamento' => null], $conti['totali']['entrate']);
        $this->assertSame(['effettivo' => 80000, 'previsto' => null, 'scostamento' => null], $conti['totali']['risultato']);
    }

    public function test_le_righe_sono_in_ordine_di_nome_e_non_contano_gli_esercizi_dopo_lo_scelto(): void
    {
        $conti = ContiIniziativa::calcola([
            $this->r(2027, 'entrate', 'Contributi', 1), $this->r(2026, 'entrate', 'Somministrazione', 2), $this->r(2026, 'entrate', 'Erogazioni liberali', 3),
        ], [], 2026);

        $this->assertSame(['Erogazioni liberali', 'Somministrazione'], array_column($conti['righe']['entrate'], 'tipologia'));
        $this->assertSame([], $conti['righe']['uscite']);
    }
}
