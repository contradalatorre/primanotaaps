<?php

namespace App\Onboarding;

use App\Models\ImportEstratto;
use App\Models\Movimento;

/**
 * L'elenco chiuso dei controlli dei passi pratici: lo YAML ne cita il nome, mai codice. Si valutano sullo stato dello
 * spazio di prova (quindi con la connessione `prova` attiva), non sulla pagina in cui l'utente si trova.
 */
final class Verifiche
{
    public const NOMI = ['estratto_caricato', 'import_confermato', 'movimenti_confermati', 'nessun_da_classificare', 'tutto_confermato'];

    public static function passa(Passo $passo): bool
    {
        $importati = fn () => Movimento::whereNotNull('import_estratto_id');
        $importConfermato = fn () => ImportEstratto::where('stato', 'confermato')->exists();

        return match ($passo->verifica) {
            'estratto_caricato' => ImportEstratto::exists(),
            'import_confermato' => $importConfermato(),
            'movimenti_confermati' => $importati()->where('stato', 'confermato')->count() >= $passo->almeno,
            'nessun_da_classificare' => $importConfermato() && ! $importati()->where('stato', 'da_classificare')->exists(),
            'tutto_confermato' => $importConfermato() && ! $importati()->where('stato', '!=', 'confermato')->exists(),
        };
    }
}
