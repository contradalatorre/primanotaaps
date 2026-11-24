<?php

namespace App\Esercizio;

/** Una riga del preventivo di un'iniziativa per l'esercizio scelto: il lato è quello della tipologia, l'importo in centesimi. */
final readonly class PrevistoIniziativa
{
    public function __construct(public string $lato, public string $tipologia, public int $importo) {}
}
