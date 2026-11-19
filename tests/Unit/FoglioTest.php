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
        $righe = Foglio::leggi(self::ods(['F' => [['a', [1, 100_000]], ['x', [null, 20_000_000]]]]))['F'];

        $this->assertSame(['a', ...array_fill(0, 59, 1)], $righe[0]); // si troncano le ripetizioni: oltre la sessantesima colonna non si legge
        $this->assertSame(['x'], $righe[1]);
    }

    public function test_un_valore_oltre_la_sessantesima_colonna_si_rifiuta_e_non_si_perde_in_silenzio(): void
    {
        $this->expectException(FoglioNonValido::class);
        $this->expectExceptionMessage('60');
        Foglio::leggi(self::ods(['F' => [['a', [null, 59], 'saldo']]])); // la 61ª cella ha un valore
    }

    public function test_l_errore_per_le_colonne_in_piu_dice_la_scheda_e_la_colonna(): void
    {
        foreach ([
            'ods' => fn () => Foglio::leggi(self::ods(['Saldi' => [['a', [null, 59], 'saldo']]])),
            'xlsx' => fn () => Foglio::leggi(self::xlsxGrezzo(['<worksheet xmlns="'.self::NS.'"><sheetData><row r="1"><c r="BI1" t="str"><v>saldo</v></c></row></sheetData></worksheet>'])),
        ] as $formato => $leggi) {
            try {
                $leggi();
                $this->fail($formato.': il foglio andava rifiutato');
            } catch (FoglioNonValido $e) {
                $this->assertStringContainsString($formato === 'ods' ? '«Saldi»' : '«F1»', $e->getMessage(), $formato);
                $this->assertStringContainsString('colonna BI', $e->getMessage(), $formato);
            }
        }
    }

    #[DataProvider('formati')]
    public function test_le_schede_che_l_import_non_legge_non_si_leggono_e_non_fanno_errore(string $formato): void
    {
        $largo = [['a', [null, 59], 'saldo']]; // un valore oltre la 60ª colonna
        $contenuto = $formato === 'ods'
            ? self::ods(['Cassa' => [['x']], 'Appunti' => $largo])
            : self::xlsxGrezzo(['<worksheet xmlns="'.self::NS.'"><sheetData><row r="1"><c r="A1" t="str"><v>x</v></c></row></sheetData></worksheet>', '<worksheet xmlns="'.self::NS.'"><sheetData><row r="1"><c r="BI1" t="str"><v>saldo</v></c></row></sheetData></worksheet>']);
        $lette = fn (string $nome) => in_array($nome, ['Cassa', 'F1'], true);

        $fogli = Foglio::leggi($contenuto, $lette);

        $this->assertSame([['x']], array_values($fogli)[0]);
        $this->assertCount(1, $fogli); // l'altra scheda non c'è nel risultato
    }

    public function test_un_valore_xlsx_oltre_la_sessantesima_colonna_si_rifiuta_ma_una_cella_vuota_no(): void
    {
        $riga = fn (string $celle) => self::xlsxGrezzo(['<worksheet xmlns="'.self::NS.'"><sheetData><row r="1"><c r="A1" t="str"><v>a</v></c>'.$celle.'</row></sheetData></worksheet>']);

        $this->assertSame([['a']], Foglio::leggi($riga('<c r="BI1"/>'))['F1']); // BI è la 61ª colonna, vuota
        $this->assertSame([['a']], Foglio::leggi($riga('<c r="BI1" t="str"><v>  </v></c>'))['F1']); // o con solo spazi
        Foglio::leggi(self::ods(['F' => [['a', [null, 59], '  ']]])); // lo stesso in un .ods
        $this->expectException(FoglioNonValido::class);
        Foglio::leggi($riga('<c r="BI1" t="str"><v>saldo</v></c>'));
    }

    public function test_un_xml_con_dtd_in_utf16_si_rifiuta_anche_se_le_stringhe_non_si_vedono_nei_byte(): void
    {
        // Un foglio per il resto valido: se il DTD passasse, si leggerebbe.
        $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE x [<!ENTITY e "boom">]>'.self::contenutoOds('<table:table table:name="F"><table:table-row><table:table-cell office:value-type="string"><text:p>&e;</text:p></table:table-cell></table:table-row></table:table>');
        $xml = str_replace('<?xml version="1.0"?>', '', $xml);

        $this->expectException(FoglioNonValido::class);
        Foglio::leggi(self::zip(['content.xml' => "\xFF\xFE".mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8')]));
    }

    public function test_un_xlsx_con_troppi_fogli_si_rifiuta_prima_di_leggerli(): void
    {
        $this->expectException(FoglioNonValido::class);
        Foglio::leggi(self::xlsxGrezzo(array_fill(0, 51, '<worksheet xmlns="'.self::NS.'"><sheetData/></worksheet>')));
    }

    public function test_un_xlsx_i_cui_fogli_puntano_alla_stessa_parte_grande_non_si_legge_piu_volte_senza_limite(): void
    {
        // 25 MB di commenti da 1 MB (libxml rifiuta un solo nodo di testo oltre i 10 MB): un XML valido, grande ma non enorme.
        $grande = '<worksheet xmlns="'.self::NS.'"><sheetData/>'.str_repeat('<!--'.str_repeat('x', 1_000_000).'-->', 25).'</worksheet>';

        $this->assertSame(['F1', 'F2'], array_keys(Foglio::leggi(self::xlsxGrezzo(['grande' => $grande], ['grande', 'grande'])))); // 50 MB in tutto: passa

        $this->expectException(FoglioNonValido::class);
        $this->expectExceptionMessage('troppo grande');
        Foglio::leggi(self::xlsxGrezzo(['grande' => $grande], array_fill(0, 3, 'grande'))); // 75 MB: i fogli puntano tre volte alla stessa parte
    }

    public function test_una_riga_xlsx_vuota_oltre_il_tetto_delle_righe_non_fa_rifiutare_il_file(): void
    {
        $byte = self::xlsxGrezzo(['<worksheet xmlns="'.self::NS.'"><sheetData><row r="1"><c r="A1" t="str"><v>a</v></c></row><row r="30000" ht="20"/><row r="30001"><c r="A30001"/></row></sheetData></worksheet>']);

        $this->assertSame([['a']], Foglio::leggi($byte)['F1']);
    }

    public function test_un_ods_con_tabelle_annidate_si_rifiuta(): void
    {
        $this->expectException(FoglioNonValido::class);
        Foglio::leggi(self::zip(['content.xml' => self::contenutoOds(
            '<table:table table:name="F"><table:table-row><table:table-cell><table:table table:name="dentro"><table:table-row><table:table-cell office:value-type="float" office:value="1"/></table:table-row></table:table></table:table-cell></table:table-row></table:table>'
        )]));
    }

    public function test_i_blocchi_vuoti_ripetuti_di_un_ods_non_contano_nel_tetto_delle_righe_ma_hanno_un_loro_limite(): void
    {
        $vuoti = fn (int $n) => str_repeat('<table:table-row table:number-rows-repeated="100"><table:table-cell table:number-columns-repeated="3"/></table:table-row>', $n);
        $dato = '<table:table-row><table:table-cell office:value-type="string"><text:p>dato</text:p></table:table-cell></table:table-row>';

        $righe = Foglio::leggi(self::zip(['content.xml' => self::contenutoOds('<table:table table:name="F">'.$vuoti(300).$dato.'</table:table>')]))['F']; // 30.000 righe vuote
        $this->assertSame(['dato'], $righe[count($righe) - 1]);

        $this->expectException(FoglioNonValido::class);
        Foglio::leggi(self::zip(['content.xml' => self::contenutoOds('<table:table table:name="F">'.$vuoti(2100).$dato.'</table:table>')])); // 210.000: la memoria non è infinita
    }

    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @param array<string, string> $parti nome → byte */
    private static function zip(array $parti): string
    {
        $zip = new \ZipArchive;
        $percorso = tempnam(sys_get_temp_dir(), 'zip');
        $zip->open($percorso, \ZipArchive::OVERWRITE);
        foreach ($parti as $nome => $byte) {
            $zip->addFromString($nome, $byte);
        }
        $zip->close();
        $byte = file_get_contents($percorso);
        unlink($percorso);

        return $byte;
    }

    private static function contenutoOds(string $tabelle): string
    {
        return '<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:spreadsheet>'.$tabelle.'</office:spreadsheet></office:body></office:document-content>';
    }

    /**
     * Un xlsx con le parti scritte a mano: i fogli «F1», «F2»… puntano ciascuno a una parte.
     *
     * @param  array<int|string, string>  $parti  XML dei fogli (la chiave è il nome della parte, se è una stringa)
     * @param  list<string>|null  $destinazioni  le parti a cui puntano i fogli, nell'ordine; se manca, una per parte
     */
    private static function xlsxGrezzo(array $parti, ?array $destinazioni = null): string
    {
        $destinazioni ??= array_keys($parti);
        $sheets = '';
        $rels = '';
        foreach ($destinazioni as $i => $parte) {
            $sheets .= '<sheet name="F'.($i + 1).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
            $rels .= '<Relationship Id="rId'.($i + 1).'" Target="worksheets/parte'.$parte.'.xml"/>';
        }
        $file = ['xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="'.self::NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>'];
        foreach ($parti as $parte => $xml) {
            $file['xl/worksheets/parte'.$parte.'.xml'] = $xml;
        }

        return self::zip($file);
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
