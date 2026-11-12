<?php

namespace App\Esercizio;

/** Un'imputazione di un'iniziativa come la vede `ContiIniziativa`: esercizio, lato («entrate» o «uscite»), nome della tipologia e importo in centesimi, sempre positivo. */
final readonly class RigaIniziativa
{
    public function __construct(public int $esercizio, public string $lato, public string $tipologia, public int $importo) {}
}
