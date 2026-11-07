<?php

namespace App\Import;

/**
 * Una riga di movimento dell'estratto, già ripulita: solo le colonne ammesse, IBAN mascherati. Gli importi sono
 * in centesimi con il segno dell'estratto (negativo = uscita).
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
    ) {}

    /** Contro i doppioni: data operazione + importo + descrizione + saldo parziale (#14). */
    public function chiave(): string
    {
        return sha1(implode('|', [$this->data, $this->importo, $this->descrizione, $this->saldoParziale]));
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
        return new self($dati['data'], $dati['dataValuta'], $dati['causale'], $dati['descrizione'], $dati['importo'], $dati['saldoParziale']);
    }
}
