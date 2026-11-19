<?php

namespace App\Esercizio;

/**
 * Le differenze di un esercizio dalla sua versione approvata (#59, #12, #18): un modulo puro, senza database, che confronta due «impronte» piatte, cioè le voci del
 * Modello D e i totali in centesimi, e gli esiti dei test. L'impronta si ricava da quello che l'approvazione salva (`Verifiche::istantanea`), così la versione approvata
 * e lo stato di oggi si confrontano con la stessa lente. Le cifre che non cambiano non compaiono.
 */
final class Differenze
{
    public const ESITI = ['rispettato' => 'Rispettato', 'vicino' => 'Vicino alla soglia', 'non_rispettato' => 'Non rispettato'];

    /**
     * @param  array{modello_d: array, catena: array, secondarieta: array}  $istantanea
     * @return array{voci: array<string, array{etichetta: string, importo: int}>, esiti: array<string, array{etichetta: string, esito: string}>}
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
        ]];
    }

    /**
     * Cosa è diverso: le voci con un importo diverso (o presenti da una parte sola) e i test con un esito diverso, nell'ordine del prospetto.
     *
     * @param  array{voci: array, esiti: array}  $approvata
     * @param  array{voci: array, esiti: array}  $attuale
     * @return array{voci: list<array{etichetta: string, approvato: ?int, attuale: ?int}>, esiti: list<array{etichetta: string, approvato: ?string, attuale: ?string}>}
     */
    public static function tra(array $approvata, array $attuale): array
    {
        $voci = [];
        foreach (array_unique([...array_keys($attuale['voci']), ...array_keys($approvata['voci'])]) as $chiave) {
            $prima = $approvata['voci'][$chiave]['importo'] ?? null;
            $dopo = $attuale['voci'][$chiave]['importo'] ?? null;
            if ($prima !== $dopo) {
                $voci[] = ['etichetta' => ($attuale['voci'][$chiave] ?? $approvata['voci'][$chiave])['etichetta'], 'approvato' => $prima, 'attuale' => $dopo];
            }
        }
        $esiti = [];
        foreach ($attuale['esiti'] as $chiave => $esito) {
            $prima = $approvata['esiti'][$chiave]['esito'] ?? null;
            if ($prima !== $esito['esito']) {
                $esiti[] = ['etichetta' => $esito['etichetta'], 'approvato' => $prima, 'attuale' => $esito['esito']];
            }
        }

        return ['voci' => $voci, 'esiti' => $esiti];
    }
}
