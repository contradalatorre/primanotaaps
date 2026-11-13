<?php

namespace Tests;

use DateTimeInterface;
use ZipArchive;

/**
 * Fogli di calcolo inventati per i test: un .xlsx o un .ods costruito qui da zero, con lo zip e l'XML minimi (nessun file binario nel
 * repo, nessun dato dell'associazione). Un foglio è un elenco di righe, una riga un elenco di celle: null (vuota), testo, numero o
 * DateTimeInterface (una data).
 */
trait FogliInventati
{
    /** @param array<string, list<list<mixed>>> $fogli nome → righe */
    protected static function xlsx(array $fogli): string
    {
        $condivise = [];
        $indice = function (string $testo) use (&$condivise) {
            return $condivise[$testo] ??= count($condivise);
        };
        $foglioXml = [];
        $n = 0;
        foreach ($fogli as $righe) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach ($righe as $r => $celle) {
                $xml .= '<row r="'.($r + 1).'">';
                foreach ($celle as $c => $valore) {
                    $rif = chr(65 + $c).($r + 1);
                    $xml .= match (true) {
                        $valore === null => '',
                        $valore instanceof DateTimeInterface => '<c r="'.$rif.'" s="1"><v>'.(int) floor((gmmktime(0, 0, 0, (int) $valore->format('n'), (int) $valore->format('j'), (int) $valore->format('Y')) + 2209161600) / 86400).'</v></c>',
                        is_string($valore) => '<c r="'.$rif.'" t="s"><v>'.$indice($valore).'</v></c>',
                        default => '<c r="'.$rif.'"><v>'.$valore.'</v></c>',
                    };
                }
                $xml .= '</row>';
            }
            $foglioXml[++$n] = $xml.'</sheetData></worksheet>';
        }

        $zip = new ZipArchive;
        $percorso = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip->open($percorso, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $sheets = '';
        $rels = '';
        foreach (array_keys($fogli) as $i => $nome) {
            $sheets .= '<sheet name="'.htmlspecialchars($nome, ENT_XML1).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
            $rels .= '<Relationship Id="rId'.($i + 1).'" Target="worksheets/sheet'.($i + 1).'.xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', $foglioXml[$i + 1]);
        }
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .implode('', array_map(fn ($t) => '<si><t xml:space="preserve">'.htmlspecialchars($t, ENT_XML1).'</t></si>', array_keys($condivise))).'</sst>');
        // Due stili: 0 predefinito, 1 data (formato predefinito 14).
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>');
        $zip->close();
        $byte = file_get_contents($percorso);
        unlink($percorso);

        return $byte;
    }

    /** @param array<string, list<list<mixed>>> $fogli nome → righe */
    protected static function ods(array $fogli): string
    {
        $tabelle = '';
        foreach ($fogli as $nome => $righe) {
            $tabelle .= '<table:table table:name="'.htmlspecialchars($nome, ENT_XML1).'">';
            foreach ($righe as $celle) {
                $tabelle .= '<table:table-row>';
                foreach ($celle as $valore) {
                    $tabelle .= match (true) {
                        $valore === null => '<table:table-cell/>',
                        $valore instanceof DateTimeInterface => '<table:table-cell office:value-type="date" office:date-value="'.$valore->format('Y-m-d').'"><text:p>'.$valore->format('d/m/Y').'</text:p></table:table-cell>',
                        is_string($valore) => '<table:table-cell office:value-type="string"><text:p>'.htmlspecialchars($valore, ENT_XML1).'</text:p></table:table-cell>',
                        default => '<table:table-cell office:value-type="float" office:value="'.$valore.'"><text:p>'.$valore.'</text:p></table:table-cell>',
                    };
                }
                $tabelle .= '</table:table-row>';
            }
            $tabelle .= '</table:table>';
        }

        $zip = new ZipArchive;
        $percorso = tempnam(sys_get_temp_dir(), 'ods');
        $zip->open($percorso, ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
        $zip->addFromString('content.xml', '<?xml version="1.0" encoding="UTF-8"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:spreadsheet>'.$tabelle.'</office:spreadsheet></office:body></office:document-content>');
        $zip->close();
        $byte = file_get_contents($percorso);
        unlink($percorso);

        return $byte;
    }
}
