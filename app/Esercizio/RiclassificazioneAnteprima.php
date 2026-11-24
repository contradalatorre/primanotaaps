<?php

namespace App\Esercizio;

use RuntimeException;

/** L'anteprima di una riclassificazione (#62): il cambio si fa davvero, dentro la transazione, e si annulla lanciando questo con l'esito (`Riclassificazione::anteprima`). */
final class RiclassificazioneAnteprima extends RuntimeException
{
    public function __construct(public readonly array $esito)
    {
        parent::__construct('Anteprima della riclassificazione: la transazione si annulla.');
    }
}
