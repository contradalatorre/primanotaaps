<?php

namespace Tests\Unit;

use App\Backup\Rotazione;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** La rotazione dei backup (#68): gli ultimi 12 mensili più uno per anno, gli annuali per 10 anni. Date inventate. */
class RotazioneBackupTest extends TestCase
{
    /** @return array<string, CarbonImmutable> un backup il primo di ogni mese, da $da a $a (AAAA-MM), con l'id uguale al mese */
    private function mensili(string $da, string $a): array
    {
        $backup = [];
        for ($mese = CarbonImmutable::parse("$da-01"); $mese <= CarbonImmutable::parse("$a-01"); $mese = $mese->addMonth()) {
            $backup[$mese->format('Y-m')] = $mese;
        }

        return $backup;
    }

    private function daEliminare(array $backup, string $adesso): array
    {
        $ids = Rotazione::daEliminare($backup, CarbonImmutable::parse($adesso));
        sort($ids);

        return $ids;
    }

    public function test_con_meno_di_dodici_backup_non_si_elimina_niente(): void
    {
        $this->assertSame([], $this->daEliminare($this->mensili('2026-01', '2026-06'), '2026-06-15'));
    }

    public function test_si_tengono_gli_ultimi_dodici_mesi(): void
    {
        // gen 2025 .. mar 2026: 15 backup; i 12 più recenti vanno da apr 2025 a mar 2026, l'ultimo del 2025 (dic) è tra questi.
        $this->assertSame(['2025-01', '2025-02', '2025-03'], $this->daEliminare($this->mensili('2025-01', '2026-03'), '2026-03-15'));
    }

    public function test_l_ultimo_backup_di_un_anno_vecchio_resta_come_annuale(): void
    {
        $backup = $this->mensili('2022-01', '2026-03');

        $eliminati = $this->daEliminare($backup, '2026-03-15');

        // restano i dodici da apr 2025 e gli annuali: dic 2022, dic 2023, dic 2024
        $this->assertNotContains('2022-12', $eliminati);
        $this->assertNotContains('2023-12', $eliminati);
        $this->assertNotContains('2024-12', $eliminati);
        $this->assertContains('2024-11', $eliminati);
        $this->assertContains('2025-03', $eliminati);
        $this->assertNotContains('2025-04', $eliminati);
        $this->assertCount(count($backup) - 12 - 3, $eliminati);
    }

    public function test_gli_annuali_si_tengono_al_massimo_per_dieci_anni(): void
    {
        $eliminati = $this->daEliminare($this->mensili('2014-11', '2026-03'), '2026-03-15');

        // gli anni tenuti sono 2017..2026: il dic 2016 e prima non sono più annuali
        $this->assertContains('2016-12', $eliminati);
        $this->assertNotContains('2017-12', $eliminati);
    }

    public function test_nello_stesso_mese_resta_il_piu_recente_compreso_quello_su_richiesta(): void
    {
        $backup = ['mensile' => CarbonImmutable::parse('2026-03-01 03:00'), 'richiesta' => CarbonImmutable::parse('2026-03-20 11:00')];

        $this->assertSame(['mensile'], $this->daEliminare($backup, '2026-03-25'));
    }

    public function test_i_mesi_senza_backup_non_contano_tra_i_dodici(): void
    {
        // un backup ogni due mesi: sono 12 e nessuno è fuori dai dodici più recenti
        $backup = [];
        for ($i = 0; $i < 12; $i++) {
            $backup["b$i"] = CarbonImmutable::parse('2024-01-01')->addMonths($i * 2);
        }

        $this->assertSame([], $this->daEliminare($backup, '2026-01-01'));
    }
}
