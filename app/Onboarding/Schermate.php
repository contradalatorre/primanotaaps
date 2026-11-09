<?php

namespace App\Onboarding;

use App\Models\Conto;
use App\Models\ImportEstratto;
use App\Models\Movimento;
use Illuminate\Routing\Exceptions\UrlGenerationException;

/** Dove portare l'utente per un passo: la rotta della schermata, con i parametri ricavati dallo stato dello spazio di prova. */
final class Schermate
{
    /** Con la connessione `prova` attiva. Null se la schermata non c'è ancora (per esempio l'import non è stato caricato). */
    public static function url(string $rotta): ?string
    {
        $parametri = match ($rotta) {
            'import.indice' => ['conto' => Conto::where('tipo', 'banca')->orderBy('id')->value('id')],
            'import.mostra', 'import.revisione' => ['import' => ImportEstratto::latest('id')->value('id')],
            // Il primo movimento da decidere, poi i proposti, poi gli altri.
            'movimenti.classificazione' => ['movimento' => Movimento::whereNotNull('import_estratto_id')
                ->orderByRaw("case stato when 'da_classificare' then 0 when 'proposto' then 1 else 2 end")->orderBy('id')->value('id')],
            default => [],
        };
        if (in_array(null, $parametri, true)) {
            return null;
        }

        try {
            return route($rotta, $parametri);
        } catch (UrlGenerationException) {
            return null;
        }
    }
}
