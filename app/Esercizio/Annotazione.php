<?php

namespace App\Esercizio;

use App\Euro;

/**
 * L'annotazione in calce al rendiconto (art. 13 c. 6 CTS; #17): il testo che l'app precompila dagli esiti, con il criterio di secondarietà,
 * la strumentalità e il criterio di riparto dei costi promiscui. Il Tesoriere lo corregge nella pagina dei test; l'impaginazione è di #18.
 * La strumentalità non si ricava da nessun dato: è una dichiarazione dell'organo di amministrazione, e il testo lo dice.
 */
final class Annotazione
{
    /**
     * @param  array<string, mixed>  $secondarieta  il risultato di `Secondarieta::calcola`
     * @param  string  $riparto  «ricavi» o «costi_diretti» (dati dell'esercizio)
     */
    public static function testo(int $anno, array $secondarieta, string $riparto): string
    {
        $k = $secondarieta['confermato'] ?? $secondarieta['proposto'];
        $c = $secondarieta['criteri'][$k];
        $di = $k === 'a' ? 'delle entrate della gestione' : 'dei costi complessivi (uscite della gestione e costi figurativi)';
        $percentuale = $c['rapporto'] === null ? 'non calcolabile' : number_format($c['rapporto'], 2, ',', '.').'%';
        $scelta = $secondarieta['confermato'] === null ? 'proposto dall’app, da confermare' : 'confermato dal Tesoriere';
        $quota = $riparto === 'costi_diretti' ? 'quota dei costi diretti' : 'quota dei ricavi';

        return implode("\n", [
            "Secondarietà delle attività diverse (art. 6 CTS, DM 107/2021 art. 3): per l’esercizio $anno si applica il criterio $k ({$c['soglia']}%), $scelta. "
                .'Le entrate delle attività diverse sono '.Euro::formatta($c['numeratore']['importo'])." €, il $percentuale $di: esito «".Secondarieta::ETICHETTE[$c['esito']].'».',
            'Strumentalità: le attività diverse sono strumentali alle attività di interesse generale, che finanziano. [da confermare o correggere a cura dell’organo di amministrazione]',
            "Costi promiscui: le spese generali (uscite di supporto generale, oneri bancari e imposte) sono ripartite sull’area A in base alla $quota di ciascuna area; il criterio è mantenuto negli esercizi.",
        ]);
    }
}
