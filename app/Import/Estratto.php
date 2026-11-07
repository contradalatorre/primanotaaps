<?php

namespace App\Import;

/** Un estratto letto da un formato: le righe di movimento nell'ordine del file e i saldi dichiarati. */
final readonly class Estratto
{
    /** @param list<RigaEstratto> $righe */
    public function __construct(public array $righe, public int $saldoIniziale, public int $saldoFinale) {}

    public function dal(): string
    {
        return min(array_map(fn (RigaEstratto $r) => $r->data, $this->righe));
    }

    public function al(): string
    {
        return max(array_map(fn (RigaEstratto $r) => $r->data, $this->righe));
    }

    public function toJson(): string
    {
        return json_encode([
            'righe' => array_map(fn (RigaEstratto $r) => $r->toArray(), $this->righe),
            'saldoIniziale' => $this->saldoIniziale, 'saldoFinale' => $this->saldoFinale,
        ], JSON_THROW_ON_ERROR);
    }

    public static function daJson(string $json): self
    {
        $dati = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return new self(array_map(RigaEstratto::daArray(...), $dati['righe']), $dati['saldoIniziale'], $dati['saldoFinale']);
    }
}
