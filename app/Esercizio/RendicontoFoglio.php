<?php

namespace App\Esercizio;

use App\Import\Foglio;

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
     *     totali: array<string, int>,
     *     figurativi: array{costi: array<string, int>, proventi: array<string, int>}
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
        $figurativi = ['costi' => [], 'proventi' => []];

        foreach ($righe as $n => $celle) {
            foreach (self::blocchi($celle) as $k => [$etichetta, $importo]) {
                $testo = self::normalizza($etichetta);
                $c = $contesto[$k] ?? null;

                if ($chiave = self::totaleGlobale($testo)) {
                    $importo === null || $totali[$chiave] = $importo;
                } elseif (preg_match('/^(cassa|depositi)\b/', $testo, $m)) {
                    $saldi[$m[1]] ??= $importo;
                    $contesto[$k] = null; // «Cassa e banca» chiude gli investimenti: ciò che segue non ne eredita il contesto
                } elseif (str_contains($testo, 'figurativ') && ! preg_match('/^(\d+\)|totale)/', $testo)) { // il prospetto dei figurativi: shortcut: «1)» è A e «2)» è B come in SchemaModelloD::figurativi(), da riscontrare sul foglio vero
                    $contesto[$k] = ['lato' => 'figurativi', 'area' => 'F', 'tipo' => str_contains($testo, 'provent') ? (str_contains($testo, 'cost') ? null : 'proventi') : 'costi'];
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
                    $importo === null || $c === null || $c['area'] === 'F' || $totali[$c['area'] === 'I' ? 'investimenti:'.$c['lato'] : $c['lato'].':'.$c['area']] = $importo;
                } elseif (preg_match('/^(avanzo|disavanzo)/', $testo)) {
                    $importo === null || $c === null || in_array($c['area'], ['I', 'F'], true) || $totali['avanzo:'.$c['area']] = $importo;
                } elseif (preg_match('/^imposte\b/', $testo)) {
                    $importo === null || $importo === 0 || $voci['uscite:Imposte'] = ($voci['uscite:Imposte'] ?? 0) + $importo;
                } elseif ($importo === null) {
                    if (($c['lato'] ?? null) === 'figurativi' && preg_match('/^(costi|proventi)\b/', $testo, $m)) {
                        $contesto[$k]['tipo'] = $m[1]; // il sottotitolo sotto «Costi e proventi figurativi»
                    } elseif (! preg_match('/^\d+\)/', $testo)) {
                        $contesto[$k] = null; // un titolo che non si riconosce chiude la sezione: la prossima non ne eredita il contesto
                    }
                } elseif (($c['lato'] ?? null) === 'figurativi') { // mai tra le voci: si contano una volta sola, nei campi dei figurativi; il resto lo corregge la persona
                    $area = preg_match('/^(\d+)\)/', $testo, $m) ? [1 => 'A', 2 => 'B'][(int) $m[1]] ?? null : null;
                    if ($importo > 0 && $area !== null && $c['tipo'] !== null) {
                        $figurativi[$c['tipo']][$area] = ($figurativi[$c['tipo']][$area] ?? 0) + $importo;
                    }
                } elseif ($importo !== 0) {
                    $codice = $c !== null && preg_match('/^(\d+)\)/', $testo, $m) ? $c['area'].$m[1] : null;
                    if ($importo > 0 && $codice !== null && SchemaModelloD::conosce($c['lato'], $codice)) { // un importo negativo lo guarda una persona
                        $voci[$c['lato'].':'.$codice] = ($voci[$c['lato'].':'.$codice] ?? 0) + $importo;
                    } elseif ($c !== null || preg_match('/^\d+\)/', $testo)) { // fuori da ogni sezione le righe senza numero sono il contorno del foglio
                        $nonRiconosciute[] = ['riga' => $n + 1, 'etichetta' => trim($etichetta), 'importo' => $importo];
                    }
                }
            }
        }

        return ['voci' => $voci, 'nonRiconosciute' => $nonRiconosciute, 'saldi' => $saldi, 'totali' => $totali, 'figurativi' => $figurativi];
    }

    /**
     * @return list<array{0: string, 1: ?int}> le etichette della riga, ognuna con l'importo della cella che la segue (centesimi): se la cella è vuota
     *                                         vale 0 quando più avanti, prima della prossima etichetta, c'è un numero (l'anno precedente), mai quello della colonna accanto.
     *                                         shortcut: l'importo è nella cella subito dopo l'etichetta; con una colonna vuota in mezzo nel foglio vero i 2025 si leggerebbero come zeri (lo mostra il confronto coi totali).
     */
    private static function blocchi(array $celle): array
    {
        $eEtichetta = fn ($c) => Foglio::centesimi($c) === null && is_string($c) && trim($c) !== '';
        $celle = array_values($celle);
        $blocchi = [];
        foreach ($celle as $i => $cella) {
            if (! $eEtichetta($cella)) {
                continue;
            }
            $dopo = [];
            for ($j = $i + 1; $j < count($celle) && ! $eEtichetta($celle[$j]); $j++) {
                $dopo[] = Foglio::centesimi($celle[$j]);
            }
            $blocchi[] = [$cella, $dopo[0] ?? (array_filter($dopo, fn ($v) => $v !== null) !== [] ? 0 : null)];
        }

        return $blocchi;
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
