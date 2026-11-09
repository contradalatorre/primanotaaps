<?php

namespace App\Onboarding;

/** L'elenco chiuso dei controlli dei passi pratici: lo YAML ne cita il nome, mai codice. Si valutano sullo spazio di prova. */
final class Verifiche
{
    public const NOMI = ['estratto_caricato', 'import_confermato', 'movimenti_confermati', 'nessun_da_classificare', 'tutto_confermato'];
}
