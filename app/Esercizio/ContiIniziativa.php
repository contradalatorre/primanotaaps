<?php

namespace App\Esercizio;

/**
 * I conti di un'iniziativa (#57), un modulo puro come `Calcolo`: dalle imputazioni dell'iniziativa e dal suo preventivo ricava
 * entrate, uscite e risultato dell'esercizio scelto, del precedente e dall'avvio (fino all'esercizio scelto compreso), poi per
 * tipologia l'effettivo, il previsto e lo scostamento. Lo scostamento è sempre effettivo meno previsto: nelle uscite un valore
 * positivo è una spesa oltre il preventivo. Senza preventivo previsto e scostamento sono null, mai «meno l'effettivo».
 */
final class ContiIniziativa
{
    /**
     * @param  list<RigaIniziativa>  $righe  le imputazioni dell'iniziativa, di qualunque esercizio
     * @param  list<PrevistoIniziativa>  $preventivo  le righe del preventivo dell'esercizio scelto; vuoto se non c'è
     */
    public static function calcola(array $righe, array $preventivo, int $anno): array
    {
        $conPreventivo = $preventivo !== [];
        $effettivo = ['entrate' => [], 'uscite' => []];
        $previsto = ['entrate' => [], 'uscite' => []];
        foreach ($righe as $r) {
            if ($r->esercizio === $anno) {
                $effettivo[$r->lato][$r->tipologia] = ($effettivo[$r->lato][$r->tipologia] ?? 0) + $r->importo;
            }
        }
        foreach ($preventivo as $p) {
            $previsto[$p->lato][$p->tipologia] = ($previsto[$p->lato][$p->tipologia] ?? 0) + $p->importo;
        }

        $dettaglio = [];
        $totali = [];
        foreach (['entrate', 'uscite'] as $lato) {
            $nomi = array_unique([...array_keys($effettivo[$lato]), ...array_keys($previsto[$lato])]);
            sort($nomi, SORT_STRING);
            $dettaglio[$lato] = array_map(fn (string $nome) => ['tipologia' => $nome] + self::confronto($effettivo[$lato][$nome] ?? 0, $conPreventivo ? ($previsto[$lato][$nome] ?? 0) : null), $nomi);
            $totali[$lato] = self::confronto(array_sum($effettivo[$lato]), $conPreventivo ? array_sum($previsto[$lato]) : null);
        }
        $totali['risultato'] = self::confronto(
            $totali['entrate']['effettivo'] - $totali['uscite']['effettivo'],
            $conPreventivo ? $totali['entrate']['previsto'] - $totali['uscite']['previsto'] : null,
        );

        return [
            'colonne' => [
                'scelto' => self::colonna($righe, fn (int $e) => $e === $anno),
                'precedente' => self::colonna($righe, fn (int $e) => $e === $anno - 1),
                'avvio' => self::colonna($righe, fn (int $e) => $e <= $anno),
            ],
            'conPreventivo' => $conPreventivo,
            'righe' => $dettaglio,
            'totali' => $totali,
        ];
    }

    /** @return array{effettivo: int, previsto: ?int, scostamento: ?int} */
    private static function confronto(int $effettivo, ?int $previsto): array
    {
        return ['effettivo' => $effettivo, 'previsto' => $previsto, 'scostamento' => $previsto === null ? null : $effettivo - $previsto];
    }

    /** @return array{entrate: int, uscite: int, risultato: int} */
    private static function colonna(array $righe, callable $inclusa): array
    {
        $somma = fn (string $lato) => array_sum(array_map(fn (RigaIniziativa $r) => $r->lato === $lato && $inclusa($r->esercizio) ? $r->importo : 0, $righe));

        return ['entrate' => $somma('entrate'), 'uscite' => $somma('uscite'), 'risultato' => $somma('entrate') - $somma('uscite')];
    }
}
