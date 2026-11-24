<?php

namespace App;

/** Gli importi sono solo in euro e si tengono in centesimi interi; qui si leggono e si scrivono all'italiana. */
final class Euro
{
    /** «12,50», «12.50», «1.234,56», «€ 12»: i centesimi, o null se non è un importo con al più due decimali (e dieci cifre intere: oltre, i centesimi non stanno in un intero). */
    public static function daTesto(string $testo): ?int
    {
        $testo = str_replace(['€', ' ', "\u{a0}"], '', $testo);
        // Con la virgola i punti sono migliaia; senza, il punto è la virgola decimale («12.50»).
        if (str_contains($testo, ',')) {
            $testo = str_replace(',', '.', str_replace('.', '', $testo));
        }
        if (! preg_match('/^(-?)(\d{1,10})(?:\.(\d{1,2}))?$/', $testo, $m)) {
            return null;
        }
        $centesimi = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$centesimi : $centesimi;
    }

    public static function formatta(int $centesimi): string
    {
        return ($centesimi < 0 ? '-' : '').number_format(abs($centesimi) / 100, 2, ',', '.');
    }
}
