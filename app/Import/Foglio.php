<?php

namespace App\Import;

use DOMDocument;
use DOMElement;
use DOMNode;
use ZipArchive;

/**
 * Legge un foglio di calcolo `.xlsx` o `.ods` (il foglio 2025 di #63, poi la cassa di #64) in PHP puro: solo ZipArchive e DOM, nessuna
 * dipendenza (l'estensione zip c'è già, la usa il backup). Restituisce, per ogni foglio, le righe come elenchi di celle: null per la
 * vuota, testo, numero (int o float) o la data come «AAAA-MM-GG». Le formule valgono per il loro ultimo valore salvato.
 *
 * Il file lo carica un utente: si rifiutano gli XML con DTD o entità, si limitano le dimensioni, le righe e le colonne, e le righe vuote
 * ripetute di un .ods (un milione, di norma) non si espandono.
 * shortcut: niente fogli nascosti o celle unite, niente date xlsx con formato personalizzato strano (si riconoscono d/m/y e i formati predefiniti);
 * da estendere con i file veri.
 */
final class Foglio
{
    private const MAX_XML = 30_000_000;

    private const MAX_RIGHE = 20_000;

    private const MAX_COLONNE = 60;

    private const NS_ODS = ['office' => 'urn:oasis:names:tc:opendocument:xmlns:office:1.0', 'table' => 'urn:oasis:names:tc:opendocument:xmlns:table:1.0', 'text' => 'urn:oasis:names:tc:opendocument:xmlns:text:1.0'];

    private const NS_XLSX = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** I formati predefiniti di xlsx che sono date o ore. */
    private const FORMATI_DATA = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58];

    /**
     * @param  string  $contenuto  i byte del file
     * @return array<string, list<list<string|int|float|null>>> nome del foglio → righe
     *
     * @throws FoglioNonValido
     */
    public static function leggi(string $contenuto): array
    {
        $percorso = tempnam(sys_get_temp_dir(), 'foglio');
        file_put_contents($percorso, $contenuto);
        try {
            $zip = new ZipArchive;
            if ($zip->open($percorso) !== true) {
                throw self::nonValido();
            }
            try {
                return $zip->locateName('content.xml') !== false ? self::ods($zip) : self::xlsx($zip);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($percorso);
        }
    }

    private static function nonValido(): FoglioNonValido
    {
        return new FoglioNonValido('Il file non è un foglio di calcolo leggibile: carica un .xlsx o un .ods.');
    }

    private static function xml(ZipArchive $zip, string $nome): DOMDocument
    {
        $stat = $zip->statName($nome);
        $testo = $stat === false || $stat['size'] > self::MAX_XML ? false : $zip->getFromName($nome);
        // Niente DTD né entità: l'XML di un foglio vero non ne ha, e l'espansione delle entità è un attacco noto.
        if ($testo === false || stripos($testo, '<!DOCTYPE') !== false || stripos($testo, '<!ENTITY') !== false) {
            throw self::nonValido();
        }
        $doc = new DOMDocument;
        $prima = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($testo, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prima);

        return $ok ? $doc : throw self::nonValido();
    }

    // ---- ods --------------------------------------------------------------------------------------------------------------------

    private static function ods(ZipArchive $zip): array
    {
        $fogli = [];
        foreach (self::xml($zip, 'content.xml')->getElementsByTagNameNS(self::NS_ODS['table'], 'table') as $tabella) {
            $righe = [];
            foreach ($tabella->childNodes as $riga) {
                if (! $riga instanceof DOMElement || $riga->localName !== 'table-row') {
                    continue;
                }
                $celle = self::celleOds($riga);
                // Le righe ripetute (un milione di vuote, di norma, fino in fondo al foglio) si espandono al più di 100: le vuote in coda le toglie `pulisci`.
                for ($i = 0; $i < min(100, max(1, (int) $riga->getAttributeNS(self::NS_ODS['table'], 'number-rows-repeated'))); $i++) {
                    $righe[] = $celle;
                }
                if (count($righe) > self::MAX_RIGHE) {
                    throw new FoglioNonValido('Il foglio ha troppe righe.');
                }
            }
            $fogli[$tabella->getAttributeNS(self::NS_ODS['table'], 'name')] = self::pulisci($righe);
        }

        return $fogli ?: throw self::nonValido();
    }

    /** @return list<string|int|float|null> la riga senza le celle vuote in coda; [] se è tutta vuota */
    private static function celleOds(DOMElement $riga): array
    {
        $celle = [];
        foreach ($riga->childNodes as $cella) {
            if (! $cella instanceof DOMElement || ! in_array($cella->localName, ['table-cell', 'covered-table-cell'], true)) {
                continue;
            }
            $tipo = $cella->getAttributeNS(self::NS_ODS['office'], 'value-type');
            $valore = match ($tipo) {
                'float', 'percentage', 'currency' => self::numero($cella->getAttributeNS(self::NS_ODS['office'], 'value')),
                'date' => substr($cella->getAttributeNS(self::NS_ODS['office'], 'date-value'), 0, 10),
                default => ($t = self::testoOds($cella)) === '' ? null : $t,
            };
            $ripetuta = max(1, (int) $cella->getAttributeNS(self::NS_ODS['table'], 'number-columns-repeated'));
            for ($i = 0; $i < ($valore === null ? $ripetuta : min($ripetuta, 10)); $i++) {
                $celle[] = $valore;
                if (count($celle) > self::MAX_COLONNE && $valore !== null) {
                    throw new FoglioNonValido('Il foglio ha troppe colonne.');
                }
            }
        }
        while ($celle !== [] && end($celle) === null) {
            array_pop($celle);
        }

        return $celle;
    }

    private static function testoOds(DOMNode $nodo): string
    {
        $paragrafi = [];
        foreach ($nodo->childNodes as $p) {
            if ($p instanceof DOMElement && $p->localName === 'p') {
                $paragrafi[] = self::contenutoOds($p);
            }
        }

        return implode("\n", $paragrafi);
    }

    private static function contenutoOds(DOMNode $nodo): string
    {
        $testo = '';
        foreach ($nodo->childNodes as $n) {
            $testo .= match (true) {
                $n instanceof DOMElement && $n->localName === 's' => str_repeat(' ', min(100, max(1, (int) $n->getAttributeNS(self::NS_ODS['text'], 'c')))),
                $n instanceof DOMElement && $n->localName === 'tab' => "\t",
                $n instanceof DOMElement && $n->localName === 'line-break' => "\n",
                $n instanceof DOMElement => self::contenutoOds($n),
                default => $n->nodeValue,
            };
        }

        return $testo;
    }

    // ---- xlsx -------------------------------------------------------------------------------------------------------------------

    private static function xlsx(ZipArchive $zip): array
    {
        $cartella = self::xml($zip, 'xl/workbook.xml');
        $relazioni = [];
        foreach (self::xml($zip, 'xl/_rels/workbook.xml.rels')->getElementsByTagName('Relationship') as $r) {
            $relazioni[$r->getAttribute('Id')] = ltrim($r->getAttribute('Target'), '/');
        }
        $condivise = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            foreach (self::xml($zip, 'xl/sharedStrings.xml')->getElementsByTagNameNS(self::NS_XLSX, 'si') as $si) {
                $condivise[] = self::testoXlsx($si);
            }
        }
        $date = self::stiliData($zip);

        $fogli = [];
        foreach ($cartella->getElementsByTagNameNS(self::NS_XLSX, 'sheet') as $foglio) {
            $destinazione = $relazioni[$foglio->getAttributeNS(self::NS_REL, 'id')] ?? throw self::nonValido();
            $destinazione = str_starts_with($destinazione, 'xl/') ? $destinazione : 'xl/'.$destinazione;
            $righe = [];
            foreach (self::xml($zip, $destinazione)->getElementsByTagNameNS(self::NS_XLSX, 'row') as $riga) {
                $numero = (int) $riga->getAttribute('r') ?: count($righe) + 1;
                if ($numero > self::MAX_RIGHE) {
                    throw new FoglioNonValido('Il foglio ha troppe righe.');
                }
                $righe[$numero - 1] = self::celleXlsx($riga, $condivise, $date);
            }
            $fogli[$foglio->getAttribute('name')] = self::pulisci($righe === [] ? [] : array_replace(array_fill(0, max(array_keys($righe)) + 1, []), $righe));
        }

        return $fogli ?: throw self::nonValido();
    }

    /** @return list<string|int|float|null> */
    private static function celleXlsx(DOMElement $riga, array $condivise, array $date): array
    {
        $celle = [];
        foreach ($riga->childNodes as $c) {
            if (! $c instanceof DOMElement || $c->localName !== 'c') {
                continue;
            }
            preg_match('/^([A-Z]+)/', $c->getAttribute('r'), $lettere);
            $colonna = 0;
            foreach (str_split($lettere[1] ?? 'A') as $l) {
                $colonna = $colonna * 26 + ord($l) - 64;
            }
            if ($colonna > self::MAX_COLONNE) {
                continue;
            }
            $v = null;
            foreach ($c->childNodes as $figlio) {
                if ($figlio instanceof DOMElement && $figlio->localName === 'v') {
                    $v = $figlio->textContent;
                } elseif ($figlio instanceof DOMElement && $figlio->localName === 'is') {
                    $v = self::testoXlsx($figlio);
                }
            }
            $tipo = $c->getAttribute('t');
            $valore = match (true) {
                $v === null || $v === '' => null,
                $tipo === 's' => $condivise[(int) $v] ?? null,
                in_array($tipo, ['str', 'inlineStr'], true) => $v,
                in_array($tipo, ['b', 'e'], true) => null,
                isset($date[(int) $c->getAttribute('s')]) => gmdate('Y-m-d', (int) (floor((float) $v) - 25569) * 86400),
                default => self::numero($v),
            };
            $celle = array_replace(array_fill(0, $colonna, null), $celle);
            $celle[$colonna - 1] = $valore;
        }

        return array_values($celle);
    }

    private static function testoXlsx(DOMNode $nodo): string
    {
        $testo = '';
        foreach ($nodo->getElementsByTagNameNS(self::NS_XLSX, 't') as $t) {
            if ($t->parentNode?->localName !== 'rPh') { // il testo fonetico non è parte della cella
                $testo .= $t->textContent;
            }
        }

        return $testo;
    }

    /** @return array<int, true> gli indici di stile (`s` delle celle) che sono date */
    private static function stiliData(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/styles.xml') === false) {
            return [];
        }
        $stili = self::xml($zip, 'xl/styles.xml');
        $personalizzati = [];
        foreach ($stili->getElementsByTagNameNS(self::NS_XLSX, 'numFmt') as $f) {
            // Senza le parti tra virgolette e tra parentesi quadre, un formato con d, m o y è una data.
            $codice = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $f->getAttribute('formatCode'));
            $personalizzati[(int) $f->getAttribute('numFmtId')] = (bool) preg_match('/[dmy]/i', $codice);
        }
        $date = [];
        foreach ($stili->getElementsByTagNameNS(self::NS_XLSX, 'cellXfs') as $xfs) {
            foreach ($xfs->getElementsByTagNameNS(self::NS_XLSX, 'xf') as $i => $xf) {
                $id = (int) $xf->getAttribute('numFmtId');
                if (in_array($id, self::FORMATI_DATA, true) || ($personalizzati[$id] ?? false)) {
                    $date[$i] = true;
                }
            }
        }

        return $date;
    }

    // ---- comune -----------------------------------------------------------------------------------------------------------------

    private static function numero(string $testo): int|float|null
    {
        if (! is_numeric($testo)) {
            return null;
        }

        return preg_match('/^-?\d+$/', $testo) ? (int) $testo : (float) $testo;
    }

    /** Toglie le righe vuote in fondo al foglio: i riempimenti di un foglio ripulito non devono pesare. */
    private static function pulisci(array $righe): array
    {
        while ($righe !== [] && end($righe) === []) {
            array_pop($righe);
        }

        return array_values($righe);
    }
}
