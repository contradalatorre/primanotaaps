<?php

namespace App\Import;

/** Gli IBAN nel testo di un estratto si mascherano già all'import, tenendo le ultime 4 cifre (#27). */
final class Iban
{
    /**
     * Si mascherano solo gli IBAN veri (cifre di controllo mod 97, maiuscole o minuscole): il resto del testo resta intatto.
     * shortcut: cambiare questa maschera cambia le chiavi dei movimenti già importati (la chiave contiene la
     * descrizione mascherata), e il primo import sovrapposto li duplicherebbe: se serve, prima si riscrivono le chiavi (#40).
     * Un IBAN con una cifra sbagliata o scritto con altre spaziature resta in chiaro.
     */
    public static function maschera(string $testo): string
    {
        // Compatto («IT60X05428...») oppure a gruppi di 4 («IT60 X054 ...»), con una coda di 1-3 caratteri.
        $schema = '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){2,9}(?: ?[A-Z0-9]{1,3})?\b/i';

        return preg_replace_callback($schema, function (array $m) {
            // Gruppi di altre parole o numeri, prima o dopo l'IBAN, possono essere finiti nel candidato: si provano tutti i tratti.
            $parti = explode(' ', $m[0]);
            for ($da = 0; $da < count($parti); $da++) {
                for ($a = count($parti); $a > $da; $a--) {
                    $iban = implode('', array_slice($parti, $da, $a - $da));
                    if (self::valido($iban)) {
                        $mascherato = str_repeat('*', strlen($iban) - 4).substr($iban, -4);

                        return implode(' ', [...array_slice($parti, 0, $da), $mascherato, ...array_slice($parti, $a)]);
                    }
                }
            }

            return $m[0];
        }, $testo);
    }

    private static function valido(string $iban): bool
    {
        // «RF» apre il riferimento del creditore, che ha le stesse cifre di controllo ma non è un IBAN.
        if (strlen($iban) < 15 || strlen($iban) > 34 || strcasecmp(substr($iban, 0, 2), 'RF') === 0 || ! preg_match('/^[A-Z]{2}\d{2}/i', $iban)) {
            return false;
        }
        $resto = 0;
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $c) {
            $v = ctype_digit($c) ? (int) $c : ord(strtoupper($c)) - 55;
            $resto = ($resto * ($v > 9 ? 100 : 10) + $v) % 97;
        }

        return $resto === 1;
    }
}
