<?php

namespace App;

/**
 * Codici a tempo (RFC 6238: HMAC-SHA1, 30 secondi, 6 cifre) per l'account di emergenza.
 * Una ventina di righe al posto di una dipendenza: serve solo a verificare, e il segreto è uno.
 */
class Totp
{
    private const PASSO = 30;

    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Un segreto base32 casuale di 160 bit, come lo vogliono le app di autenticazione. */
    public static function nuovoSegreto(): string
    {
        $bit = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $bit .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($gruppo) => self::ALFABETO[bindec($gruppo)], str_split($bit, 5)));
    }

    /** Il codice del passo di tempo indicato (secondi Unix / 30). */
    public static function codice(string $segretoBase32, int $passo): string
    {
        $chiave = self::decodifica($segretoBase32);
        $hash = hash_hmac('sha1', pack('J', $passo), $chiave, true);
        $scarto = ord($hash[19]) & 0xF;
        $numero = unpack('N', substr($hash, $scarto, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($numero % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Il passo di tempo a cui il codice corrisponde (da 30 secondi prima a 30 dopo `$adesso`, per gli orologi
     * non allineati), oppure null. Chi lo usa ricorda l'ultimo passo accettato: un codice non vale due volte.
     */
    public static function verifica(string $segretoBase32, string $codice, int $adesso): ?int
    {
        if ($segretoBase32 === '' || ! preg_match('/^\d{6}$/', $codice)) {
            return null;
        }

        $corrente = intdiv($adesso, self::PASSO);
        foreach ([$corrente, $corrente - 1, $corrente + 1] as $passo) {
            if (hash_equals(self::codice($segretoBase32, $passo), $codice)) {
                return $passo;
            }
        }

        return null;
    }

    private static function decodifica(string $base32): string
    {
        $bit = '';
        foreach (str_split(strtoupper(rtrim($base32, '='))) as $carattere) {
            $bit .= str_pad(decbin((int) strpos(self::ALFABETO, $carattere)), 5, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($otto) => chr(bindec($otto)), str_split(substr($bit, 0, intdiv(strlen($bit), 8) * 8), 8)));
    }
}
