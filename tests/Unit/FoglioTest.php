<?php

namespace Tests\Unit;

use App\Import\Foglio;
use App\Import\FoglioNonValido;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\FogliInventati;

/** Il lettore dei fogli di calcolo (#63, poi #64): xlsx e ods, su file inventati. */
class FoglioTest extends TestCase
{
    use FogliInventati;

    /** @return array<string, array{0: string}> */
    public static function formati(): array
    {
        return ['xlsx' => ['xlsx'], 'ods' => ['ods']];
    }

    private static function fogli(): array
    {
        return [
            'Cassa' => [
                ['Data', 'Descrizione', 'Entrate'],
                [new DateTimeImmutable('2025-03-01'), 'Quota di prova & «test»', 12.5],
                [null, 'dopo una cella vuota', null, 7],
            ],
            'RUNTS_Rendiconto' => [['A) Uscite'], [], ['1) Materie prime', 1234.56]],
        ];
    }

    private function leggi(string $formato, array $fogli): array
    {
        return Foglio::leggi(self::$formato($fogli));
    }

    #[DataProvider('formati')]
    public function test_legge_i_fogli_per_nome_con_testi_numeri_date_e_celle_vuote(string $formato): void
    {
        $fogli = $this->leggi($formato, self::fogli());

        $this->assertSame(['Cassa', 'RUNTS_Rendiconto'], array_keys($fogli));
        $this->assertSame(['Data', 'Descrizione', 'Entrate'], $fogli['Cassa'][0]);
        $this->assertSame(['2025-03-01', 'Quota di prova & «test»', 12.5], $fogli['Cassa'][1]);
        $this->assertSame([null, 'dopo una cella vuota', null, 7], $fogli['Cassa'][2]);
        $this->assertSame([], $fogli['RUNTS_Rendiconto'][1]); // una riga vuota resta al suo posto
        $this->assertSame(['1) Materie prime', 1234.56], $fogli['RUNTS_Rendiconto'][2]);
    }

    #[DataProvider('formati')]
    public function test_la_data_del_foglio_non_dipende_dal_fuso_orario_della_macchina(string $formato): void
    {
        $fuso = date_default_timezone_get();
        date_default_timezone_set('Europe/Rome'); // a mezzanotte di Roma, in UTC è ancora il giorno prima
        try {
            $fogli = $this->leggi($formato, ['F' => [[new DateTimeImmutable('2025-03-01 00:00')]]]);
        } finally {
            date_default_timezone_set($fuso);
        }

        $this->assertSame([['2025-03-01']], $fogli['F']);
    }

    public function test_un_ods_con_celle_e_righe_ripetute_non_le_espande_senza_limite(): void
    {
        $xml = '<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:spreadsheet>'
            .'<table:table table:name="F"><table:table-row><table:table-cell office:value-type="string"><text:p>a<text:s text:c="2"/>b</text:p></table:table-cell><table:table-cell table:number-columns-repeated="3"/><table:table-cell office:value-type="float" office:value="5"/></table:table-row>'
            .'<table:table-row table:number-rows-repeated="1048575"><table:table-cell table:number-columns-repeated="1024"/></table:table-row></table:table></office:spreadsheet></office:body></office:document-content>';
        $zip = new \ZipArchive;
        $percorso = tempnam(sys_get_temp_dir(), 'ods');
        $zip->open($percorso, \ZipArchive::OVERWRITE);
        $zip->addFromString('content.xml', $xml);
        $zip->close();
        $byte = file_get_contents($percorso);
        unlink($percorso);

        $righe = Foglio::leggi($byte)['F'];

        $this->assertSame(['a  b', null, null, null, 5], $righe[0]);
        $this->assertLessThanOrEqual(2, count($righe)); // le righe vuote ripetute sono sparite
    }

    public function test_una_cella_non_vuota_ripetuta_oltre_dieci_volte_non_fa_slittare_le_colonne_dopo(): void
    {
        $righe = Foglio::leggi(self::ods(['F' => [['Data', [0, 12], 'Quote', 10, null, 110]]]))['F'];

        $this->assertSame(['Data', ...array_fill(0, 12, 0), 'Quote', 10, null, 110], $righe[0]);
    }

    public function test_le_celle_ripetute_si_espandono_fino_al_tetto_delle_colonne_e_il_resto_si_ignora(): void
    {
        $righe = Foglio::leggi(self::ods(['F' => [['a', [1, 100_000], 'b'], ['x', [null, 20_000_000], 'y']]]))['F'];

        $this->assertSame(['a', ...array_fill(0, 59, 1)], $righe[0]); // come l'xlsx: oltre la sessantesima colonna non si legge
        $this->assertSame(['x', ...array_fill(0, 59, null)], array_pad($righe[1], 60, null));
    }

    public function test_le_righe_raggruppate_o_ripetute_in_stampa_si_leggono_in_ordine(): void
    {
        $righe = Foglio::leggi(self::ods(
            ['F' => [['Data', 'Importo'], ['r1', 1], ['r2', 2], ['r3', 3], ['r4', 4], ['r5', 5]]],
            [['table-header-rows', 0, 0], ['table-row-group', 2, 3], ['table-row-group', 5, 5]],
        ))['F'];

        $this->assertSame([['Data', 'Importo'], ['r1', 1], ['r2', 2], ['r3', 3], ['r4', 4], ['r5', 5]], $righe);
    }

    public function test_un_file_che_non_e_un_foglio_si_rifiuta_con_un_messaggio(): void
    {
        foreach (['testo semplice', "PK\x03\x04non uno zip vero"] as $byte) {
            try {
                Foglio::leggi($byte);
                $this->fail('Doveva rifiutarlo');
            } catch (FoglioNonValido $e) {
                $this->assertStringContainsString('un .xlsx o un .ods', $e->getMessage());
            }
        }
    }

    public function test_un_xml_con_entita_si_rifiuta(): void
    {
        $zip = new \ZipArchive;
        $percorso = tempnam(sys_get_temp_dir(), 'ods');
        $zip->open($percorso, \ZipArchive::OVERWRITE);
        $zip->addFromString('content.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "boom">]><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"/>');
        $zip->close();
        $byte = file_get_contents($percorso);
        unlink($percorso);

        $this->expectException(FoglioNonValido::class);
        Foglio::leggi($byte);
    }
}
