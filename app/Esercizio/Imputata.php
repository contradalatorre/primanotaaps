<?php

namespace App\Esercizio;

/** Un'imputazione come la vede il calcolo: lato («entrate» o «uscite»), voce Modello D (null se non si ricava) e importo in centesimi, sempre positivo. */
final readonly class Imputata
{
    public function __construct(public string $lato, public ?string $voce, public int $importo) {}
}
