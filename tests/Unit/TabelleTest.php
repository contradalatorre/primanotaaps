<?php

namespace Tests\Unit;

use App\Esercizio\Calcolo;
use App\Esercizio\ContoRendiconto;
use App\Esercizio\Importo;
use App\Esercizio\Imputata;
use App\Esercizio\RendicontoAssemblea;
use App\Esercizio\RigaRendiconto;
use App\Esercizio\Saldo;
use App\Esercizio\Tabelle;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Le tabelle dei prospetti per PDF e XLSX (#58), senza database né HTTP. Nomi e importi inventati, in centesimi. */
class TabelleTest extends TestCase
{
    /** @return array<string, list<string|Importo>> titolo della sezione → riga per etichetta */
    private function righe(array $sezioni): array
    {
        $per = [];
        foreach ($sezioni as $s) {
            foreach ($s['righe'] as $r) {
                $per[$s['titolo']][$r['celle'][0]] = array_slice($r['celle'], 1);
            }
        }

        return $per;
    }

    private function modelloD(int $entrate): array
    {
        return Calcolo::modelloD([new Imputata('entrate', 'A1', $entrate), new Imputata('uscite', 'A1', 30000)], [new Saldo('cassa', 0, $entrate - 30000)], []);
    }

    public function test_il_modello_d_ha_sempre_tutte_le_voci_anche_a_zero_e_i_totali_come_la_pagina(): void
    {
        $sezioni = Tabelle::modelloD($this->modelloD(100000), null, 2026);

        $titoli = array_column($sezioni, 'titolo');
        $this->assertSame(['A)', 'B)', 'C)', 'D)', 'E)'], array_map(fn (string $t) => substr($t, 0, 2), array_slice($titoli, 0, 5)));
        $this->assertContains('Cassa e banca', $titoli);
        $a = $this->righe($sezioni)[$titoli[0]];
        $a1 = array_values(array_filter($a, fn (array $c, string $k) => str_starts_with($k, 'A1)'), ARRAY_FILTER_USE_BOTH));
        $this->assertEquals([[new Importo(30000)], [new Importo(100000)]], $a1); // uscite poi entrate; le altre voci ci sono, a zero
        $this->assertGreaterThan(10, count($a));
        $this->assertEquals([new Importo(70000)], $a['Avanzo/disavanzo '.lcfirst(explode(') ', $titoli[0], 2)[1])]);
        $this->assertEquals([new Importo(70000)], $this->righe($sezioni)['Totali e avanzi']['Avanzo/disavanzo complessivo']);
        $this->assertEquals([new Importo(70000)], $this->righe($sezioni)['Cassa e banca']['Cassa']);
        $this->assertSame(['Voce', 'Importo (€)'], $sezioni[0]['colonne']);
    }

    public function test_con_il_precedente_c_e_una_colonna_in_piu_e_una_riga_senza_la_sua_voce_conta_zero(): void
    {
        $sezioni = Tabelle::modelloD($this->modelloD(100000), $this->modelloD(40000), 2026);

        $this->assertSame(['Voce', 'Importo (€)', 'Esercizio 2025 (€)'], $sezioni[0]['colonne']);
        $this->assertEquals([new Importo(70000), new Importo(10000)], $this->righe($sezioni)['Totali e avanzi']['Avanzo/disavanzo complessivo']);
    }

    public function test_un_modello_d_che_non_quadra_lo_dice_e_lo_storico_no(): void
    {
        $m = Calcolo::modelloD([new Imputata('entrate', 'A1', 5000)], [new Saldo('cassa', 0, 100)], []);

        $this->assertContains('Avvisi', array_column(Tabelle::modelloD($m, null, 2026), 'titolo'));
        $this->assertNotContains('Avvisi', array_column(Tabelle::modelloD($m, null, 2026, storico: true), 'titolo'));
    }

    private function rendiconto(): array
    {
        $righe = [
            new RigaRendiconto('entrate', 'A', 'Cultura', 'Festa della Torre', 'Somministrazione', 'A7', 1, 50000),
            new RigaRendiconto('uscite', 'A', 'Cultura', 'Festa della Torre', 'Acquisti per la somministrazione', 'A1', 1, 30000),
        ];
        $conti = [new ContoRendiconto(1, 'Cassa', 1000, 21000, 0, 0, '2026-12-31', 21000, '2026-12-31'), new ContoRendiconto(2, 'Banca', 0, 0, 0, 0, null, null, '2026-12-31')];

        return RendicontoAssemblea::calcola($righe, $conti, [['descrizione' => 'Fondo per la sede', 'importo' => 5000, 'nota' => 'Delibera del direttivo']]);
    }

    public function test_il_rendiconto_ha_una_colonna_per_conto_il_riepilogo_gli_avvisi_e_la_destinazione(): void
    {
        $sezioni = Tabelle::rendiconto($this->rendiconto(), true);

        $this->assertSame(['Area, attività, iniziativa e tipologia', 'Cassa', 'Banca', 'Totale'], $sezioni[0]['colonne']);
        $this->assertEquals([new Importo(50000), new Importo(0), new Importo(50000)], $this->righe($sezioni)['Per area, attività, iniziativa e tipologia']['– Somministrazione']);
        $this->assertEquals([new Importo(20000), new Importo(0), new Importo(20000)], $this->righe($sezioni)['Per area, attività, iniziativa e tipologia']['Avanzo (disavanzo) dell’esercizio']);
        $riepilogo = $this->righe($sezioni)['Riepilogo per conto (i giroconti restano fuori dai totali del prospetto)'];
        $this->assertSame('mai', $riepilogo['Banca'][8]);
        $this->assertSame('sì', $riepilogo['Cassa'][8]);
        $this->assertSame(['«Banca» non è mai stato riconciliato'], array_map(fn (array $r) => $r['celle'][0], $sezioni[2]['righe']));
    }

    public function test_la_nota_della_destinazione_c_e_solo_a_chi_gestisce_l_esercizio(): void
    {
        $con = $this->righe(Tabelle::rendiconto($this->rendiconto(), true))['Destinazione dell’avanzo'];
        $senza = Tabelle::rendiconto($this->rendiconto(), false);

        $this->assertSame('Delibera del direttivo', $con['Fondo per la sede'][1]);
        $this->assertSame(['Destinazione', 'Importo (€)'], end($senza)['colonne']);
        $this->assertStringNotContainsString('Delibera', json_encode($senza));
        $this->assertEquals([new Importo(15000)], $this->righe($senza)['Destinazione dell’avanzo']['Residuo']);
    }

    public function test_intestazione_dalle_impostazioni_con_provvisorio_in_chiaro_e_piede_con_data_e_versione(): void
    {
        $ora = CarbonImmutable::parse('2026-12-20 09:30');
        $impostazioni = ['denominazione' => 'Contrada La Torre APS', 'codice_fiscale' => '00000000000', 'numero_runts' => null];

        $i = Tabelle::intestazione($impostazioni, 'Modello D · 2026', false, true, $ora);

        $this->assertSame('Contrada La Torre APS', $i[0]);
        $this->assertSame('Codice fiscale 00000000000 · numero RUNTS da inserire', $i[1]);
        $this->assertSame('Provvisorio – aggiornato al 20/12/2026 09:30. Con anche le imputazioni proposte, ancora da confermare.', $i[3]);
        $this->assertSame('Denominazione da inserire', Tabelle::intestazione([], 'x', false, false, $ora)[0]);
        $this->assertStringStartsNotWith('Provvisorio', Tabelle::intestazione($impostazioni, 'x', true, false, $ora)[3]);
        $this->assertSame('Generato il 20/12/2026 09:30 · Esercizio 2026 · versione 1 (provvisoria)', Tabelle::piede(2026, $ora, 1, false));
        $this->assertSame('Generato il 20/12/2026 09:30 · Esercizio 2025 · versione 1', Tabelle::piede(2025, $ora, 1, true));
    }

    public function test_come_righe_mette_l_intestazione_in_testa_e_una_riga_vuota_tra_le_sezioni(): void
    {
        $righe = Tabelle::comeRighe([['titolo' => 'S1', 'colonne' => ['a', 'b'], 'righe' => [['celle' => ['x', new Importo(1)], 'grassetto' => false]]]], ['Ente', 'Provvisorio']);

        $this->assertSame([['Ente'], ['Provvisorio'], [], ['S1'], ['a', 'b']], array_map(fn (array $r) => array_slice($r['celle'], 0, 2), array_slice($righe, 0, 5)));
        $this->assertTrue($righe[0]['grassetto']);
        $this->assertFalse($righe[1]['grassetto']);
    }
}
