<?php

namespace App\Esercizio;

/** Il saldo di un conto all'inizio e alla fine dell'esercizio, nella riga del Modello D del conto («cassa» o «depositi»). */
final readonly class Saldo
{
    public function __construct(public string $riga, public int $iniziale, public int $finale) {}
}
