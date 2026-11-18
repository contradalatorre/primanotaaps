<?php

namespace App\Import;

/**
 * Un estratto letto da un formato: le righe di movimento nell'ordine del file e i saldi che l'estratto dice (nulli se il formato non ne ha, come Satispay).
 * `saldiMensili` (solo il foglio della cassa, #64): il saldo dell'ultima riga di ogni mese («AAAA-MM» → centesimi), per i controlli mese per mese.
 */
final readonly class Estratto
{
    /**
     * @param  list<RigaEstratto>  $righe
     * @param  array<string, int>  $saldiMensili
     */
    public function __construct(public array $righe, public ?int $saldoIniziale, public ?int $saldoFinale, public array $saldiMensili = []) {}

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
            'saldoIniziale' => $this->saldoIniziale, 'saldoFinale' => $this->saldoFinale, 'saldiMensili' => $this->saldiMensili,
        ], JSON_THROW_ON_ERROR);
    }

    public static function daJson(string $json): self
    {
        $dati = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return new self(array_map(RigaEstratto::daArray(...), $dati['righe']), $dati['saldoIniziale'], $dati['saldoFinale'], $dati['saldiMensili'] ?? []);
    }
}
