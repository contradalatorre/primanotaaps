<?php

namespace App\Esercizio;

use App\Euro;
use App\Models\VersioneEsercizio;

/**
 * La bozza della segnalazione al RUNTS dopo una secondarietà non rispettata (art. 4 DM 107/2021, #17): scritta dai numeri della versione approvata,
 * da rivedere e firmare a cura dell'associazione. L'app non la invia.
 */
final class Segnalazione
{
    public static function bozza(VersioneEsercizio $versione): string
    {
        $istantanea = $versione->istantanea;
        $criterio = $versione->criterio_secondarieta;
        $c = $istantanea['secondarieta']['criteri'][$criterio];
        $intestazione = $istantanea['modello_d']['intestazione'];
        $punti = $c['rapporto'] === null ? 'l’intero valore' : number_format($c['rapporto'] - $c['soglia'], 2, ',', '.').' punti percentuali';
        $rapporto = $c['rapporto'] === null ? 'non calcolabile (nessun costo di riferimento)' : number_format($c['rapporto'], 2, ',', '.').'%';
        $di = $criterio === 'a' ? 'delle entrate complessive' : 'dei costi complessivi';

        return implode("\n", [
            'Oggetto: segnalazione ai sensi dell’art. 4 del D.M. 19 maggio 2021 n. 107 (attività diverse secondarie e strumentali).',
            '',
            ($intestazione['denominazione'] ?? '[denominazione]').', codice fiscale '.($intestazione['codice_fiscale'] ?? '[codice fiscale]').', iscritta al RUNTS con il numero '.($intestazione['numero_runts'] ?? '[numero RUNTS]').',',
            'segnala che dal rendiconto per cassa dell’esercizio '.$versione->esercizio.', approvato il '.$versione->approvata_il->format('d/m/Y').', risulta superato il limite del criterio '.$criterio
                .' dell’art. 3 del D.M. 107/2021: le entrate delle attività diverse sono '.Euro::formatta($c['numeratore']['importo']).' €, pari a '.$rapporto.' '.$di
                .' ('.Euro::formatta($c['denominatore']['importo']).' €), contro un limite del '.Secondarieta::formatta($c['soglia']).'%, con un superamento di '.$punti.'.',
            'L’associazione si impegna a rientrare nei limiti nell’esercizio '.($versione->esercizio + 1).', applicando lo stesso criterio con una soglia ridotta al '
                .Secondarieta::formatta($versione->soglia_rientro_centesimi_di_punto / 100).'%.',
            '',
            '[luogo e data] — [firma del legale rappresentante]',
        ]);
    }
}
