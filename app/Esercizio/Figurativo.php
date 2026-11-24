<?php

namespace App\Esercizio;

/** Una riga dei dati dell'esercizio: costo o provento figurativo («costo», «provento») con area, descrizione e importo in centesimi. */
final readonly class Figurativo
{
    public function __construct(public string $tipo, public string $area, public string $descrizione, public int $importo) {}
}
