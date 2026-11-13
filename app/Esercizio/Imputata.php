<?php

namespace App\Esercizio;

/**
 * Un'imputazione come la vede il calcolo: lato («entrate» o «uscite»), voce Modello D (null se non si ricava) e importo in
 * centesimi, sempre positivo. Il Modello D non ha bisogno d'altro; la catena dei test dell'area A e dell'ente (`TestNatura`, #54)
 * vuole anche l'area, l'iniziativa, la natura calcolata (`Natura`), la forzatura (null: nessuna) e se la tipologia è neutra nel test dell'ente.
 */
final readonly class Imputata
{
    public function __construct(
        public string $lato,
        public ?string $voce,
        public int $importo,
        public string $area = '',
        public ?int $iniziativa = null,
        public ?Natura $natura = null,
        public ?bool $forzata = null,
        public bool $neutra = false,
    ) {}
}
