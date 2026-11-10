<?php

namespace App;

/**
 * Chi è chiamato in causa da un testo con `@`. I nomi hanno spazi («Tina Verdi», «Lettore esterno»), quindi non si
 * cerca una parola dopo la chiocciola: si cerca quale tra i nomi che si conoscono segue la chiocciola, il più lungo
 * per primo, senza distinguere le maiuscole. Un `@` dentro una parola (un indirizzo email) non chiama nessuno.
 */
final class Menzioni
{
    /**
     * @param  array<string, string>  $nomi  chiave => nome
     * @return list<string> le chiavi dei nomi menzionati, una volta sola
     */
    public static function trova(string $testo, array $nomi): array
    {
        uasort($nomi, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        $trovati = [];
        foreach ($nomi as $chiave => $nome) {
            $modello = '/(?<![\p{L}\p{N}_.])@'.preg_quote($nome, '/').'(?![\p{L}\p{N}_])/iu';
            if (preg_match($modello, $testo)) {
                $trovati[] = $chiave;
                // Il tratto trovato non conta per i nomi più corti che ne sono l'inizio («Maria» dentro «Maria Rossi»).
                $testo = preg_replace($modello, ' ', $testo);
            }
        }

        return $trovati;
    }
}
