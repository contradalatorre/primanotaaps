<?php

namespace App\Esercizio;

/**
 * Un conto come lo vede il riepilogo del rendiconto per l'assemblea (#56): i saldi (centesimi) all'inizio e alla fine dell'esercizio, i giroconti
 * dell'esercizio in entrata e in uscita, e la riconciliazione: fino a quando il conto concorda con l'estratto o la conta (null: mai) e il saldo
 * dell'app a quella data. `$fino` è l'ultimo giorno del conto nell'esercizio: il 31/12, o la chiusura se il conto si è chiuso prima.
 */
final readonly class ContoRendiconto
{
    public function __construct(
        public int $id,
        public string $nome,
        public int $iniziale,
        public int $finale,
        public int $giroconti_entrata,
        public int $giroconti_uscita,
        public ?string $riconciliatoAl,
        public ?int $saldoRiconciliato,
        public string $fino,
    ) {}
}
