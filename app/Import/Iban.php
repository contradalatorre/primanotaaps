<?php

namespace App\Import;

/** Gli IBAN nel testo di un estratto si mascherano già all'import, tenendo le ultime 4 cifre (#27). */
final class Iban
{
    public static function maschera(string $testo): string
    {
        // Compatto («IT60X05428...») oppure a gruppi di 4 («IT60 X054 ...»); la coda di 1-3 caratteri conta
        // solo se contiene una cifra, per non mangiare la parola che segue.
        // shortcut: un IBAN scritto con altre spaziature resta in chiaro, da rivedere se ne compaiono (#27).
        $schema = '/\b[A-Z]{2}\d{2}(?:[A-Z0-9]{11,30}|(?: ?[A-Z0-9]{4}){4,7}(?: ?(?=[A-Z0-9]{0,2}\d)[A-Z0-9]{1,3})?)\b/';

        return preg_replace_callback($schema, function (array $m) {
            $compatto = str_replace(' ', '', $m[0]);
            // una parola lunga in maiuscolo non è un IBAN: ci vogliono cifre
            if (preg_match_all('/\d/', $compatto) < 6) {
                return $m[0];
            }

            return str_repeat('*', strlen($compatto) - 4).substr($compatto, -4);
        }, $testo);
    }
}
