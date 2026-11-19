<?php

namespace App\Import;

use App\Euro;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Legge un foglio di calcolo `.xlsx` o `.ods` (il foglio 2025 di #63, poi la cassa di #64) con ZipArchive e DOM, senza dipendenze
 * nuove (l'estensione zip c'è già, la usa il backup). Restituisce, per ogni foglio, le righe come elenchi di celle: null per la
 * vuota, testo, numero (int o float) o la data come «AAAA-MM-GG». Le formule valgono per il loro ultimo valore salvato.
 *
 * Il file lo carica un utente: lo zip si apre da una copia in `storage` (`foglio-tmp`, ripulita; senza l'app, nella cartella temporanea di
 * sistema), si rifiutano gli XML con DTD o entità, si limitano fogli, byte, righe e colonne, le tabelle annidate e un valore oltre l'ultima
 * colonna ammessa; le righe vuote ripetute di un .ods (un milione, di norma) non si espandono.
 * shortcut: niente fogli nascosti o celle unite, niente date xlsx con formato personalizzato strano (si riconoscono d/m/y e i formati predefiniti);
 * da estendere con i file veri.
 */
final class Foglio
{
    private const MAX_XML = 30_000_000;

    private const MAX_RIGHE = 20_000;

    private const MAX_COLONNE = 60;

    /** Le righe vuote ripetute di un .ods non contano in `MAX_RIGHE` (un foglio formattato ne ha a blocchi), ma la memoria ha un suo limite. */
    private const MAX_RIGHE_VUOTE = 200_000;

    private const MAX_FOGLI = 50;

    /** La somma dei byte decompressi di tutte le parti lette, comprese quelle lette più volte (fogli che puntano alla stessa parte). */
    private const MAX_TOTALE = 60_000_000;

    /** I byte decompressi già letti dal file in corso. */
    private static int $letti = 0;

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
        self::$letti = 0;
        $percorso = tempnam(self::cartellaTemporanea(), 'foglio');
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

    /**
     * Dove si scrive il file caricato per aprirlo con lo zip. Nell'app, sotto `storage` (come `backup-tmp`: su Aruba `open_basedir` può vietare `/tmp`);
     * fuori dall'app (i test dei moduli puri) la cartella temporanea del sistema. I file che un processo morto ha lasciato (nomi di persona dentro) oltre l'ora si tolgono.
     */
    private static function cartellaTemporanea(): string
    {
        if (! Container::getInstance()->bound('filesystem')) {
            return sys_get_temp_dir();
        }
        $disco = Storage::disk('local');
        $disco->makeDirectory('foglio-tmp');
        foreach ($disco->files('foglio-tmp') as $file) {
            if ($disco->lastModified($file) < time() - 3600) {
                $disco->delete($file);
            }
        }

        return $disco->path('foglio-tmp');
    }

    /** Una cella senza valore: null o solo spazi (le vuote di LibreOffice e le stringhe condivise vuote). */
    private static function vuota(mixed $valore): bool
    {
        return $valore === null || (is_string($valore) && trim($valore) === '');
    }

    private static function troppeColonne(): FoglioNonValido
    {
        return new FoglioNonValido('Il foglio ha dei valori oltre la '.self::MAX_COLONNE.'ª colonna: non so leggerlo. Togli le colonne in più.');
    }

    private static function nonValido(): FoglioNonValido
    {
        return new FoglioNonValido('Il file non è un foglio di calcolo leggibile: carica un .xlsx o un .ods.');
    }

    private static function xml(ZipArchive $zip, string $nome): DOMDocument
    {
        $stat = $zip->statName($nome);
        if ($stat !== false && (self::$letti += $stat['size']) > self::MAX_TOTALE) {
            throw new FoglioNonValido('Il foglio è troppo grande.');
        }
        $testo = $stat === false || $stat['size'] > self::MAX_XML ? false : $zip->getFromName($nome);
        if ($testo === false) {
            throw self::nonValido();
        }
        $doc = new DOMDocument;
        $prima = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($testo, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prima);

        // Niente DTD né entità: l'XML di un foglio vero non ne ha, e l'espansione delle entità è un attacco noto. Si guarda l'albero e non i byte
        // (una parte in UTF-16 li nasconde); senza `LIBXML_NOENT` le entità non si espandono mai.
        return $ok && $doc->doctype === null ? $doc : throw self::nonValido();
    }

    // ---- ods --------------------------------------------------------------------------------------------------------------------

    private static function ods(ZipArchive $zip): array
    {
        $fogli = [];
        $tabelle = self::xml($zip, 'content.xml')->getElementsByTagNameNS(self::NS_ODS['table'], 'table');
        foreach ($tabelle as $tabella) {
            // Calc non scrive tabelle dentro le celle; con quelle, una riga si leggerebbe una volta per ogni tabella che la contiene (80 tabelle annidate occupano un worker).
            for ($p = $tabella->parentNode; $p !== null; $p = $p->parentNode) {
                if ($p instanceof DOMElement && $p->localName === 'table' && $p->namespaceURI === self::NS_ODS['table']) {
                    throw new FoglioNonValido('Il foglio ha tabelle dentro le tabelle: non so leggerlo.');
                }
            }
        }
        foreach ($tabelle as $tabella) {
            $righe = [];
            $piene = $vuote = 0;
            // Tutte le righe discendenti, in ordine: anche quelle in `table-row-group` (raggruppate) e `table-header-rows` (ripetute in stampa).
            foreach ($tabella->getElementsByTagNameNS(self::NS_ODS['table'], 'table-row') as $riga) {
                $celle = self::celleOds($riga);
                // Le righe ripetute (un milione di vuote, di norma, fino in fondo al foglio) si espandono al più di 100: le vuote in coda le toglie `pulisci`.
                $ripetute = min(100, max(1, (int) $riga->getAttributeNS(self::NS_ODS['table'], 'number-rows-repeated')));
                for ($i = 0; $i < $ripetute; $i++) {
                    $righe[] = $celle;
                }
                if ($celle === []) {
                    $vuote += $ripetute;
                } else {
                    $piene += $ripetute;
                }
                if ($piene > self::MAX_RIGHE || $vuote > self::MAX_RIGHE_VUOTE) {
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
            // Una cella con un valore oltre l'ultima colonna ammessa non si perde in silenzio (potrebbe essere il Saldo): si rifiuta il foglio.
            if (! self::vuota($valore) && count($celle) >= self::MAX_COLONNE) {
                throw self::troppeColonne();
            }
            $ripetuta = max(1, (int) $cella->getAttributeNS(self::NS_ODS['table'], 'number-columns-repeated'));
            // Si espande fino a MAX_COLONNE colonne in tutto: le ripetizioni oltre si troncano.
            for ($i = 0; $i < $ripetuta && count($celle) < self::MAX_COLONNE; $i++) {
                $celle[] = $valore;
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

        $schede = $cartella->getElementsByTagNameNS(self::NS_XLSX, 'sheet');
        if ($schede->length > self::MAX_FOGLI) {
            throw new FoglioNonValido('Il file ha troppi fogli: ne leggo al più '.self::MAX_FOGLI.'.');
        }
        $fogli = [];
        foreach ($schede as $foglio) {
            $destinazione = $relazioni[$foglio->getAttributeNS(self::NS_REL, 'id')] ?? throw self::nonValido();
            $destinazione = str_starts_with($destinazione, 'xl/') ? $destinazione : 'xl/'.$destinazione;
            $righe = [];
            $numero = 0;
            foreach (self::xml($zip, $destinazione)->getElementsByTagNameNS(self::NS_XLSX, 'row') as $riga) {
                $numero = (int) $riga->getAttribute('r') ?: $numero + 1;
                $celle = self::celleXlsx($riga, $condivise, $date);
                // Una riga senza valori (formattata, o in fondo al foglio) non pesa: il tetto vale per quelle con dei dati.
                if (array_filter($celle, fn ($v) => ! self::vuota($v)) === []) {
                    continue;
                }
                if ($numero > self::MAX_RIGHE) {
                    throw new FoglioNonValido('Il foglio ha troppe righe.');
                }
                $righe[$numero - 1] = $celle;
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
            if ($colonna > self::MAX_COLONNE) {
                // Una cella vuota (solo formattata) si ignora; una con un valore no: sarebbe una colonna persa in silenzio.
                if (! self::vuota($valore)) {
                    throw self::troppeColonne();
                }

                continue;
            }
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

    /** Un importo di una cella (numero, o testo come «1.234,56») in centesimi interi; null se la cella è vuota o non è un importo. Mai un cast dei float: si arrotonda. */
    public static function centesimi(mixed $cella): ?int
    {
        if (is_int($cella) || is_float($cella)) {
            return (int) round($cella * 100);
        }

        return is_string($cella) && preg_match('/^[-\d.,\s€]+$/', $cella) && preg_match('/\d/', $cella) ? Euro::daTesto($cella) : null;
    }

    /** «AAAA-MM-GG» da una data del foglio o da un testo «g/m/aaaa»; null se la cella non è una data. */
    public static function data(mixed $cella): ?string
    {
        $testo = trim((string) $cella);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $testo, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $testo : null;
        }
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $testo, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return null;
    }

    /** Il testo di una cella senza le email (e le parentesi rimaste vuote), a spazi ridotti. */
    public static function senzaEmail(mixed $cella): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace(['/[\w.+-]+@[\w-]+\.[\w.-]+/u', '/[(\[<]\s*[)\]>]/u'], '', (string) $cella)));
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
