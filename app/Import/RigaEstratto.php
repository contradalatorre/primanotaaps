<?php

namespace App\Import;

/**
 * Una riga di movimento dell'estratto, già ripulita: solo le colonne ammesse, IBAN mascherati. Gli importi sono
 * in centesimi con il segno dell'estratto (negativo = uscita). Un formato che dà un identificativo per riga (PayPal:
 * il codice transazione) lo mette in `codice`, che fa da chiave; uno che trattiene una commissione su un incasso
 * (PayPal, Satispay) la mette in `commissione` (centesimi, positiva): `importo` resta quello netto, che entra nel conto.
 */
final readonly class RigaEstratto
{
    public function __construct(
        public string $data,
        public ?string $dataValuta,
        public ?string $causale,
        public string $descrizione,
        public int $importo,
        public int $saldoParziale,
        public ?string $codice = null,
        public int $commissione = 0,
    ) {}

    /** Contro i doppioni: il codice del formato, se ne ha uno; sennò data operazione + importo + descrizione + saldo parziale (#14). */
    public function chiave(): string
    {
        return $this->codice ?? sha1(implode('|', [$this->data, $this->importo, $this->descrizione, $this->saldoParziale]));
    }

    /** Tutto ciò che la riga dice: due righe con la stessa chiave e la stessa impronta sono la stessa riga, con la stessa chiave e un'impronta diversa un conflitto. */
    public function impronta(): string
    {
        return sha1(json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    public function verso(): string
    {
        return $this->importo < 0 ? 'uscita' : 'entrata';
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function daArray(array $dati): self
    {
        // `codice` e `commissione` mancano nelle righe salvate prima di #48 (anteprime e conflitti aperti).
        return new self($dati['data'], $dati['dataValuta'], $dati['causale'], $dati['descrizione'], $dati['importo'], $dati['saldoParziale'], $dati['codice'] ?? null, $dati['commissione'] ?? 0);
    }
}
