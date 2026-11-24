<?php

namespace App\Esercizio;

/**
 * Un'imputazione come la vede il rendiconto per l'assemblea (#56): lato («entrate» o «uscite»), area, attività e iniziativa (null: nessuna),
 * nome della tipologia, voce Modello D (null se non si ricava), id del conto e importo in centesimi, sempre positivo.
 */
final readonly class RigaRendiconto
{
    public function __construct(
        public string $lato,
        public string $area,
        public ?string $attivita,
        public ?string $iniziativa,
        public string $tipologia,
        public ?string $voce,
        public int $conto,
        public int $importo,
    ) {}
}
