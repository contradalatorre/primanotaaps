<?php

namespace App\Esercizio;

/**
 * Le differenze di un esercizio dalla sua versione approvata (#59, #12, #18): un modulo puro, senza database, che confronta due «impronte» piatte, cioè le voci del
 * Modello D e i totali in centesimi, gli esiti dei test e le cifre del rendiconto per l'assemblea (che con lo stesso Modello D può cambiare: iniziativa, attività, conto). L'impronta si ricava da quello che l'approvazione salva (`Verifiche::istantanea`), così la versione approvata
 * e lo stato di oggi si confrontano con la stessa lente. Le cifre che non cambiano non compaiono.
 * shortcut: le raccolte fondi occasionali (#61) non sono nell'impronta, ma le loro cifre in denaro stanno nella voce C2; i dati scritti a mano (relazione, liberalità non monetarie) non si confrontano. Da rivedere se serve.
 */
final class Differenze
{
    /**
     * @param  array{modello_d: array, catena: array, secondarieta: array, rendiconto?: array}  $istantanea
     * @return array{voci: array<string, array{etichetta: string, importo: int}>, esiti: array<string, array{etichetta: string, esito: string}>, rendiconto: array<string, array{etichetta: string, importo: int}>}
     */
    public static function impronta(array $istantanea): array
    {
        $m = $istantanea['modello_d'];
        $voci = [];
        $blocchi = [];
        foreach ($m['aree'] as $area) {
            array_push($blocchi, $area['entrate'], $area['uscite']);
        }
        array_push($blocchi, $m['investimenti']['entrate'], $m['investimenti']['uscite']);
        foreach ($blocchi as $blocco) {
            foreach ($blocco['righe'] as $riga) {
                $lato = explode(':', $riga['chiavi'][0])[0]; // «entrate» o «uscite»: la stessa voce c'è da tutte e due le parti
                $voci["$lato:{$riga['voce']}"] = ['etichetta' => ucfirst($lato).' '.$riga['voce'].') '.$riga['etichetta'], 'importo' => $riga['importo']];
            }
        }
        $totali = [
            'tot:entrate' => ['Totale entrate della gestione', $m['gestione']['entrate']['importo']],
            'tot:uscite' => ['Totale uscite della gestione', $m['gestione']['uscite']['importo']],
            'tot:imposte' => ['Imposte', $m['imposte']['importo']],
            'tot:avanzo' => ['Avanzo/disavanzo complessivo', $m['avanzoComplessivo']['importo']],
            'tot:cassa' => ['Cassa a fine esercizio', $m['liquidita']['cassa']],
            'tot:depositi' => ['Depositi bancari e postali a fine esercizio', $m['liquidita']['depositi']],
            'tot:costi-figurativi' => ['Costi figurativi', $m['figurativi']['costi']['totale']],
            'tot:proventi-figurativi' => ['Proventi figurativi', $m['figurativi']['proventi']['totale']],
        ];
        foreach ($totali as $chiave => [$etichetta, $importo]) {
            $voci[$chiave] = ['etichetta' => $etichetta, 'importo' => $importo];
        }

        $s = $istantanea['secondarieta'];
        $criterio = $s['confermato'] ?? $s['proposto'];
        $catena = $istantanea['catena'];

        return ['voci' => $voci, 'esiti' => [
            'secondarieta' => ['etichetta' => "Secondarietà delle attività diverse (criterio $criterio)", 'esito' => $s['criteri'][$criterio]['esito']],
            'areaA' => ['etichetta' => 'Art. 79 c. 2-bis sull’area A', 'esito' => $catena['areaA']['esito']],
            'ente' => ['etichetta' => 'Art. 79 c. 5, natura dell’ente', 'esito' => $catena['ente']['esito']],
            'forfettario' => ['etichetta' => 'Regime forfettario art. 86', 'esito' => $catena['forfettario']['esito']],
        ], 'rendiconto' => self::improntaRendiconto($istantanea['rendiconto'] ?? null)];
    }

    /**
     * Le cifre del rendiconto per l'assemblea, una per riga: area, attività, iniziativa, lato, tipologia e conto (solo gli importi diversi da zero, così una riga che manca
     * da una parte e a zero dall'altra non è una differenza), poi i figurativi non collocati, la destinazione dell'avanzo e i saldi dei conti. Non le date di riconciliazione,
     * che si muovono da sole.
     *
     * @return array<string, array{etichetta: string, importo: int}>
     */
    private static function improntaRendiconto(?array $r): array
    {
        if ($r === null) {
            return [];
        }
        $righe = [];
        foreach ($r['aree'] as $codice => $area) {
            foreach ($area['attivita'] as $attivita) {
                foreach ($attivita['iniziative'] as $iniziativa) {
                    foreach (['entrate', 'uscite'] as $lato) {
                        foreach ($iniziativa[$lato]['righe'] as $riga) {
                            foreach ($riga['conti'] as $conto => $importo) {
                                if ($importo !== 0) {
                                    $righe["$codice|{$attivita['nome']}|{$iniziativa['nome']}|$lato|{$riga['tipologia']}|$conto"] = [
                                        'etichetta' => "Area $codice · {$attivita['nome']} · {$iniziativa['nome']} · ".ucfirst($lato)." · {$riga['tipologia']} · {$r['conti'][$conto]}", 'importo' => $importo,
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }
        foreach (['entrate', 'uscite'] as $lato) {
            $righe["senza-riga:$lato"] = ['etichetta' => 'Senza una riga del Modello D · '.ucfirst($lato), 'importo' => $r['senzaRiga'][$lato]];
        }
        foreach ($r['destinazione']['righe'] as $i => $destinazione) {
            $righe["destinazione:$i"] = ['etichetta' => 'Destinazione dell’avanzo · '.$destinazione['descrizione'], 'importo' => $destinazione['importo']];
        }
        foreach ($r['riepilogo']['conti'] as $id => $conto) {
            foreach (['iniziale' => 'saldo iniziale', 'giroconti_entrata' => 'giroconti in entrata', 'giroconti_uscita' => 'giroconti in uscita', 'finale' => 'saldo finale'] as $campo => $nome) {
                $righe["conto:$id:$campo"] = ['etichetta' => "Conto «{$conto['nome']}» · $nome", 'importo' => $conto[$campo]];
            }
        }

        return $righe;
    }

    /**
     * Cosa è diverso: le voci del Modello D e le cifre del rendiconto con un importo diverso (o presenti da una parte sola) e i test con un esito diverso, nell'ordine del prospetto.
     *
     * @param  array{voci: array, esiti: array, rendiconto: array}  $approvata
     * @param  array{voci: array, esiti: array, rendiconto: array}  $attuale
     * @return array{voci: list<array{etichetta: string, approvato: ?int, attuale: ?int}>, esiti: list<array{etichetta: string, approvato: ?string, attuale: ?string}>, rendiconto: list<array{etichetta: string, approvato: ?int, attuale: ?int}>}
     */
    public static function tra(array $approvata, array $attuale): array
    {
        $righe = function (string $gruppo) use ($approvata, $attuale) {
            $diverse = [];
            foreach (array_unique([...array_keys($attuale[$gruppo]), ...array_keys($approvata[$gruppo])]) as $chiave) {
                $prima = $approvata[$gruppo][$chiave]['importo'] ?? null;
                $dopo = $attuale[$gruppo][$chiave]['importo'] ?? null;
                if ($prima !== $dopo) {
                    $diverse[] = ['etichetta' => ($attuale[$gruppo][$chiave] ?? $approvata[$gruppo][$chiave])['etichetta'], 'approvato' => $prima, 'attuale' => $dopo];
                }
            }

            return $diverse;
        };
        $esiti = [];
        foreach ($attuale['esiti'] as $chiave => $esito) {
            $prima = $approvata['esiti'][$chiave]['esito'] ?? null;
            if ($prima !== $esito['esito']) {
                $esiti[] = ['etichetta' => $esito['etichetta'], 'approvato' => $prima, 'attuale' => $esito['esito']];
            }
        }

        return ['voci' => $righe('voci'), 'esiti' => $esiti, 'rendiconto' => $righe('rendiconto')];
    }

    /**
     * Ci sono differenze nei gruppi che un prospetto mostra? Il Modello D e i test guardano `voci` ed `esiti`, il rendiconto per l'assemblea `rendiconto`: la frase
     * «nessuna differenza» di una pagina vale per quel che la pagina mostra.
     *
     * @param  array{voci: array, esiti: array, rendiconto: array}  $differenze
     * @param  list<string>  $gruppi
     */
    public static function presenti(array $differenze, array $gruppi): bool
    {
        return array_filter(array_intersect_key($differenze, array_flip($gruppi))) !== [];
    }
}
