<?php

namespace App\Esercizio;

use App\Euro;

/**
 * Riconosce le voci del Modello D nel foglio `RUNTS_Rendiconto` del 2025 (#63, #18). Un modulo puro: legge le righe già lette da `Foglio`,
 * non conosce il database. Una riga è un'etichetta seguita dal suo importo (il primo numero dopo l'etichetta: le colonne a destra, come
 * l'anno precedente, si ignorano); due etichette sulla stessa riga (uscite a sinistra, entrate a destra) sono due «blocchi», ognuno con la sua sezione.
 *
 * Il riconoscimento è dalla sezione e dal numero («1) …» sotto «A) Uscite da…» è uscite:A1), non dal testo dell'etichetta, che negli
 * schemi di `SchemaModelloD` è scritto a memoria. Ciò che ha un importo e non si riconosce va all'abbinamento manuale: l'app non indovina.
 * shortcut: il foglio vero non è mai stato letto qui (#18 ne descrive la struttura a voce): intestazioni, totali e una colonna di etichette sono
 * un'ipotesi; il primo foglio vero dirà cosa aggiustare, e intanto l'abbinamento manuale copre ciò che sfugge.
 */
final class RendicontoFoglio
{
    /**
     * @param  list<list<mixed>>  $righe
     * @return array{
     *     voci: array<string, int>,
     *     nonRiconosciute: list<array{riga: int, etichetta: string, importo: int}>,
     *     saldi: array{cassa: ?int, depositi: ?int},
     *     totali: array<string, int>
     * } voci: «lato:codice» → centesimi; totali: i totali e gli avanzi scritti nel foglio («uscite:A», «gestione:entrate», «avanzo_complessivo»…)
     */
    public static function leggi(array $righe): array
    {
        $voci = [];
        $nonRiconosciute = [];
        $saldi = ['cassa' => null, 'depositi' => null];
        $totali = [];
        $contesto = []; // per blocco: lato e area («I» per gli investimenti)
        $macro = [];

        foreach ($righe as $n => $celle) {
            foreach (self::blocchi($celle) as $k => [$etichetta, $importo]) {
                $testo = self::normalizza($etichetta);
                $c = $contesto[$k] ?? null;

                if ($chiave = self::totaleGlobale($testo)) {
                    $importo === null || $totali[$chiave] = $importo;
                } elseif (preg_match('/^(cassa|depositi)\b/', $testo, $m)) {
                    $saldi[$m[1]] ??= $importo;
                } elseif (preg_match('/^(uscite|oneri e costi|costi e oneri)$/', $testo)) {
                    $macro[$k] = 'uscite';
                } elseif (preg_match('/^(entrate|proventi e ricavi|ricavi e proventi)$/', $testo)) {
                    $macro[$k] = 'entrate';
                } elseif (preg_match('/^([a-e])\)\s*(.*)$/', $testo, $m)) {
                    $lato = preg_match('/uscit|cost|oner/', $m[2]) ? 'uscite' : (preg_match('/entrat|ricav|provent/', $m[2]) ? 'entrate' : ($macro[$k] ?? null));
                    $contesto[$k] = $c = $lato === null ? null : ['lato' => $lato, 'area' => strtoupper($m[1])];
                    $importo === null || $c === null || $totali[$lato.':'.$c['area']] = $importo;
                } elseif (preg_match('/^(uscite|entrate)\b.*(investimenti|flussi di capitale)/', $testo, $m)) {
                    $contesto[$k] = ['lato' => $m[1], 'area' => 'I'];
                } elseif (str_starts_with($testo, 'totale')) {
                    $importo === null || $c === null || $totali[$c['area'] === 'I' ? 'investimenti:'.$c['lato'] : $c['lato'].':'.$c['area']] = $importo;
                } elseif (preg_match('/^(avanzo|disavanzo)/', $testo)) {
                    $importo === null || $c === null || $c['area'] === 'I' || $totali['avanzo:'.$c['area']] = $importo;
                } elseif (preg_match('/^imposte\b/', $testo)) {
                    $importo === null || $importo === 0 || $voci['uscite:Imposte'] = ($voci['uscite:Imposte'] ?? 0) + $importo;
                } elseif ($importo !== null && $importo !== 0) {
                    $codice = $c !== null && preg_match('/^(\d+)\)/', $testo, $m) ? $c['area'].$m[1] : null;
                    if ($codice !== null && SchemaModelloD::conosce($c['lato'], $codice)) {
                        $voci[$c['lato'].':'.$codice] = ($voci[$c['lato'].':'.$codice] ?? 0) + $importo;
                    } elseif ($c !== null || preg_match('/^\d+\)/', $testo)) { // fuori da ogni sezione le righe senza numero sono il contorno del foglio
                        $nonRiconosciute[] = ['riga' => $n + 1, 'etichetta' => trim($etichetta), 'importo' => $importo];
                    }
                }
            }
        }

        return ['voci' => $voci, 'nonRiconosciute' => $nonRiconosciute, 'saldi' => $saldi, 'totali' => $totali];
    }

    /** @return list<array{0: string, 1: ?int}> le etichette della riga, ognuna con il primo importo che la segue (centesimi) */
    private static function blocchi(array $celle): array
    {
        $blocchi = [];
        foreach ($celle as $cella) {
            $importo = self::importo($cella);
            if ($importo !== null) {
                if ($blocchi !== [] && end($blocchi)[1] === null) {
                    $blocchi[array_key_last($blocchi)][1] = $importo;
                }
            } elseif (is_string($cella) && trim($cella) !== '') {
                $blocchi[] = [$cella, null];
            }
        }

        return $blocchi;
    }

    private static function importo(mixed $cella): ?int
    {
        if (is_int($cella) || is_float($cella)) {
            return (int) round($cella * 100);
        }

        return is_string($cella) && preg_match('/^[-\d.,\s€]+$/', $cella) && preg_match('/\d/', $cella) ? Euro::daTesto($cella) : null;
    }

    private static function normalizza(string $etichetta): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace('’', "'", mb_strtolower($etichetta))));
    }

    /** I totali che non appartengono a una sezione: della gestione e gli avanzi complessivi. */
    private static function totaleGlobale(string $testo): ?string
    {
        return match (true) {
            (bool) preg_match('/^totale uscite.*gestione/', $testo) => 'gestione:uscite',
            (bool) preg_match('/^totale entrate.*gestione/', $testo) => 'gestione:entrate',
            str_contains($testo, 'prima delle imposte') => 'avanzo_prima_imposte',
            str_contains($testo, 'prima degli investimenti') => 'avanzo_prima_investimenti',
            (bool) preg_match('/^(avanzo|disavanzo).*investimenti/', $testo) => 'avanzo_investimenti',
            (bool) preg_match('/^(avanzo|disavanzo).*complessivo/', $testo) => 'avanzo_complessivo',
            default => null,
        };
    }
}
