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
        $schema = '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){2,7}(?: ?[A-Z0-9]{1,3})?\b/i';

        return preg_replace_callback($schema, function (array $m) {
            // La parola o il numero dopo un IBAN a gruppi possono essere finiti nel candidato: si prova a toglierli.
            $parti = explode(' ', $m[0]);
            for ($n = count($parti); $n > 0; $n--) {
                $iban = implode('', array_slice($parti, 0, $n));
                if (self::valido($iban)) {
                    $resto = implode(' ', array_slice($parti, $n));

                    return str_repeat('*', strlen($iban) - 4).substr($iban, -4).($resto === '' ? '' : ' '.$resto);
                }
            }

            return $m[0];
        }, $testo);
    }

    private static function valido(string $iban): bool
    {
        // «RF» apre il riferimento del creditore, che ha le stesse cifre di controllo ma non è un IBAN.
        if (strlen($iban) < 15 || strlen($iban) > 34 || strcasecmp(substr($iban, 0, 2), 'RF') === 0) {
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
