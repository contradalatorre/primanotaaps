<?php

namespace App\Esercizio;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Scrive un foglio di calcolo `.xlsx` in PHP puro (ZipArchive, come `Foglio` e il backup): nessuna dipendenza per quello che serve qui, cioè
 * testi, numeri, importi con due decimali e righe in grassetto. I testi sono «inline» (niente tabella delle stringhe condivise).
 * shortcut: niente larghezze di colonna, formule o bordi; si apre e si legge, e il commercialista riformatta; da rivedere se qualcuno lo chiede.
 */
final class Xlsx
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** Indici di stile in `styles.xml`: 0 normale, 1 importo, 2 grassetto, 3 importo in grassetto. */
    private const STILE_IMPORTO = 1;

    private const STILE_GRASSETTO = 2;

    /**
     * @param  array<string, list<array{celle: list<string|int|Importo|null>, grassetto?: bool}>>  $fogli  nome del foglio → righe
     * @return string il contenuto del file
     */
    public static function crea(array $fogli): string
    {
        // ZipArchive scrive su file: nello spazio dell'app (ADR 0001), non in una cartella di sistema; il file si toglie sempre.
        File::ensureDirectoryExists(storage_path('app/xlsx'));
        $percorso = tempnam(storage_path('app/xlsx'), 'xlsx');
        try {
            $zip = new ZipArchive;
            $zip->open($percorso, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true || throw new RuntimeException('Non riesco a creare il file temporaneo.');
            $nomi = array_map(fn (string $n) => htmlspecialchars(mb_substr(self::testo($n), 0, 31), ENT_XML1 | ENT_QUOTES), array_keys($fogli));

            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .implode('', array_map(fn (int $i) => '<Override PartName="/xl/worksheets/sheet'.($i + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys($nomi))).'</Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="'.self::NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
                .implode('', array_map(fn (int $i, string $n) => '<sheet name="'.$n.'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>', array_keys($nomi), $nomi)).'</sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .implode('', array_map(fn (int $i) => '<Relationship Id="rId'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i + 1).'.xml"/>', array_keys($nomi)))
                .'<Relationship Id="rId'.(count($nomi) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
            $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="'.self::NS.'"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4">'
                .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' // numFmtId 4 è «#,##0.00»
                .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="4" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/></cellXfs></styleSheet>');

            foreach (array_values($fogli) as $i => $righe) {
                $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', self::foglio($righe));
            }
            $zip->close() || throw new RuntimeException('Non riesco a scrivere il file.');

            return (string) file_get_contents($percorso);
        } finally {
            @unlink($percorso);
        }
    }

    private static function foglio(array $righe): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="'.self::NS.'"><sheetData>';
        foreach (array_values($righe) as $r => $riga) {
            $grassetto = ($riga['grassetto'] ?? false) ? self::STILE_GRASSETTO : 0;
            $xml .= '<row r="'.($r + 1).'">';
            foreach (array_values($riga['celle']) as $c => $cella) {
                $ref = self::colonna($c).($r + 1);
                $xml .= match (true) {
                    $cella === null || $cella === '' => '',
                    $cella instanceof Importo => '<c r="'.$ref.'" s="'.(self::STILE_IMPORTO + $grassetto).'"><v>'.$cella->decimale().'</v></c>',
                    is_int($cella) => '<c r="'.$ref.'" s="'.$grassetto.'"><v>'.$cella.'</v></c>',
                    default => '<c r="'.$ref.'" s="'.$grassetto.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars(self::testo((string) $cella), ENT_XML1 | ENT_QUOTES).'</t></is></c>',
                };
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    /** Le descrizioni bancarie possono portare caratteri di controllo, che in XML non sono ammessi e rompono il file. */
    private static function testo(string $testo): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $testo);
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    private static function colonna(int $indice): string
    {
        $lettere = '';
        for ($n = $indice + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $lettere = chr(65 + ($n - 1) % 26).$lettere;
        }

        return $lettere;
    }
}
