<?php

namespace App\Import;

use App\Euro;

/**
 * Riepilogo mensile di Satispay, caricato come file di controllo (#49, #33 storia 22): una riga per mese con il numero di
 * transazioni, il lordo, i resi, il netto e le commissioni. Non entra nei movimenti: si confronta con quello che l'app ha già.
 *
 * shortcut: le intestazioni sono quelle di un riepilogo inventato (come per `SatispayCsv`, da confermare su un export vero, fuori dal
 * repo): si correggono in `COLONNE`. Le altre colonne (negozio, codici) si scartano.
 */
final class SatispayRiepilogo
{
    private const COLONNE = ['data', 'numero transazioni', 'totale lordo', 'resi', 'totale netto', 'commissioni'];

    /**
     * @return list<array{mese: string, transazioni: int, lordo: int, resi: int, netto: int, commissioni: int}> `mese` è «aaaa-mm»
     *
     * @throws EstrattoNonValido
     */
    public static function leggi(string $contenuto): array
    {
        $contenuto = preg_replace('/^\xEF\xBB\xBF/', '', $contenuto);
        if (! mb_check_encoding($contenuto, 'UTF-8')) {
            $contenuto = mb_convert_encoding($contenuto, 'UTF-8', 'Windows-1252');
        }
        $righe = array_values(array_filter(array_map(fn (string $r) => str_getcsv($r, ',', '"', ''), preg_split('/\R/', $contenuto)), fn (array $r) => trim(implode('', $r)) !== ''));
        $posizioni = array_flip(array_map(fn ($c) => mb_strtolower(trim((string) $c)), array_shift($righe) ?? []));
        if ($mancanti = array_diff(self::COLONNE, array_keys($posizioni))) {
            throw new EstrattoNonValido('Il file non è il riepilogo mensile di Satispay: nella prima riga mancano le colonne '.implode(', ', $mancanti).'.');
        }
        if ($righe === []) {
            throw new EstrattoNonValido('Il riepilogo non contiene nessun mese.');
        }

        $mesi = [];
        foreach ($righe as $i => $celle) {
            $n = $i + 2;
            $cella = fn (string $nome) => trim($celle[$posizioni[$nome]] ?? '');
            if (! preg_match('/^(?:(\d{2})-(\d{4})|(\d{4})-(\d{2}))$/', $cella('data'), $m)) {
                throw new EstrattoNonValido("Riga $n: il mese («{$cella('data')}») non è nella forma mm-aaaa.");
            }
            $mese = ($m[3] ?? '') !== '' ? "$m[3]-$m[4]" : "$m[2]-$m[1]";
            $importo = function (string $nome) use ($cella, $n) {
                $centesimi = Euro::daTesto($cella($nome));
                if ($centesimi === null) {
                    throw new EstrattoNonValido("Riga $n: «{$cella($nome)}» ($nome) non è un importo in euro.");
                }

                return $centesimi;
            };
            if (! ctype_digit($cella('numero transazioni'))) {
                throw new EstrattoNonValido("Riga $n: il numero di transazioni («{$cella('numero transazioni')}») non è un numero.");
            }
            if (isset($mesi[$mese])) {
                throw new EstrattoNonValido("Riga $n: il mese $mese compare due volte (un riepilogo per negozio non si somma qui: si carica un file per volta).");
            }
            $mesi[$mese] = ['mese' => $mese, 'transazioni' => (int) $cella('numero transazioni'), 'lordo' => $importo('totale lordo'), 'resi' => $importo('resi'),
                'netto' => $importo('totale netto'), 'commissioni' => $importo('commissioni')];
        }

        return array_values($mesi);
    }
}
