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
