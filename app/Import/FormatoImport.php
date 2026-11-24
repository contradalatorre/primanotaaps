<?php

namespace App\Import;

/** Il modulo di un formato di import (#33, storia 37): la lettura dell'estratto e le regole dei giroconti. Si registra in `ImportEstratto::LETTORI`. */
interface FormatoImport
{
    /** @throws EstrattoNonValido */
    public function leggi(string $contenuto): Estratto;

    /**
     * Le regole dei giroconti del formato, per chiave (vuoto se non ne ha).
     *
     * @return array<string, array{nome: string, verso: string, contiene: list<string>, con: string, giorni: int, meta?: string}>
     */
    public function regoleGiroconti(): array;
}
