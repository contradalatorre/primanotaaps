<?php

namespace App\Esercizio;

use RuntimeException;

/**
 * Il pezzo di PDF/A-1b che mPDF 8.3.1 non scrive (ISO 19005-1, 6.3.5): ogni font incorporato come sottoinsieme (tutti, in questa versione di mPDF) deve avere nel suo
 * descrittore un `CIDSet`, il flusso di bit dei CID presenti. veraPDF lo boccia senza (rilevato in #60); tutto il resto della conformità è di mPDF con `PDFA`.
 *
 * mPDF scrive un PDF 1.4 con la tabella `xref` classica e senza flussi di oggetti: si leggono le posizioni dalla tabella, si aggiunge un `CIDSet` a ogni descrittore
 * e si riscrive la tabella. I CID presenti sono quelli che la `CIDToGIDMap` del font manda a un glifo.
 *
 * shortcut: capisce solo il PDF come lo scrive mPDF (una sola tabella xref, senza aggiornamenti incrementali) e si ferma con un errore su qualunque altro; se una versione
 * di mPDF scrive il `CIDSet` da sé, questa classe e il suo test si tolgono.
 */
final class PdfA
{
    public static function conCidSet(string $pdf): string
    {
        if (preg_match('/startxref\n(\d+)\n%%EOF\s*$/', $pdf, $m) !== 1 || preg_match('/\Gxref\n0 (\d+)\n/', $pdf, $x, 0, (int) $m[1]) !== 1) {
            throw new RuntimeException('PDF/A: tabella xref non riconosciuta.');
        }
        $xref = (int) $m[1];
        $quanti = (int) $x[1];
        $dallaTabella = $xref + strlen($x[0]);

        // Gli oggetti nell'ordine in cui stanno nel file: ognuno arriva fino all'inizio del successivo (l'ultimo, fino alla tabella).
        $posizioni = [];
        for ($i = 1; $i < $quanti; $i++) {
            $posizioni[$i] = (int) substr($pdf, $dallaTabella + 20 * $i, 10);
        }
        asort($posizioni);
        $inizio = array_values($posizioni);
        $oggetti = [];
        foreach (array_keys($posizioni) as $k => $numero) {
            $oggetti[$numero] = substr($pdf, $inizio[$k], ($inizio[$k + 1] ?? $xref) - $inizio[$k]);
        }

        $nuovi = [];
        $prossimo = $quanti;
        foreach ($oggetti as $testo) {
            if (! str_contains($testo, '/Subtype /CIDFontType2') || preg_match('/\/FontDescriptor (\d+) 0 R/', $testo, $d) !== 1 || preg_match('/\/CIDToGIDMap (\d+) 0 R/', $testo, $g) !== 1) {
                continue;
            }
            if (preg_match('/stream\n(.*)\nendstream/s', $oggetti[(int) $g[1]], $flusso) !== 1 || ($mappa = @gzuncompress($flusso[1])) === false) {
                throw new RuntimeException('PDF/A: CIDToGIDMap non leggibile.');
            }
            $cidSet = gzcompress(self::bitDeiCid($mappa));
            $nuovi[$prossimo] = "$prossimo 0 obj\n<</Filter /FlateDecode /Length ".strlen($cidSet)."\n>>\nstream\n$cidSet\nendstream\nendobj\n";
            $descrittore = preg_replace('/>>\s*endobj\s*$/', " /CIDSet $prossimo 0 R>>\nendobj\n", $oggetti[(int) $d[1]], 1, $sostituiti);
            if ($sostituiti !== 1) {
                throw new RuntimeException('PDF/A: descrittore del font non riconosciuto.');
            }
            $oggetti[(int) $d[1]] = $descrittore;
            $prossimo++;
        }

        $corpo = substr($pdf, 0, $inizio[0]);
        $offset = [];
        foreach ($oggetti + $nuovi as $numero => $testo) {
            $offset[$numero] = strlen($corpo);
            $corpo .= $testo;
        }
        $tabella = "xref\n0 $prossimo\n0000000000 65535 f \n";
        for ($i = 1; $i < $prossimo; $i++) {
            $tabella .= sprintf("%010d 00000 n \n", $offset[$i]);
        }
        $trailer = preg_replace('/\/Size \d+/', "/Size $prossimo", substr($pdf, $dallaTabella + 20 * $quanti, strpos($pdf, 'startxref', $xref) - $dallaTabella - 20 * $quanti), 1);

        return $corpo.$tabella.$trailer.'startxref'."\n".strlen($corpo)."\n%%EOF\n";
    }

    /** Il bit di ogni CID che la mappa manda a un glifo (il primo bit del primo byte è il CID 0); si tolgono i byte a zero in fondo. */
    private static function bitDeiCid(string $mappa): string
    {
        $bit = str_repeat("\0", intdiv(strlen($mappa), 2 * 8));
        for ($cid = 0, $n = intdiv(strlen($mappa), 2); $cid < $n; $cid++) {
            if ($mappa[2 * $cid] !== "\0" || $mappa[2 * $cid + 1] !== "\0") {
                $bit[$cid >> 3] = chr(ord($bit[$cid >> 3]) | (0x80 >> ($cid & 7)));
            }
        }

        return rtrim($bit, "\0");
    }
}
