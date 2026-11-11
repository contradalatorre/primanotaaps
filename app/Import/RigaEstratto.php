<?php

namespace App\Import;

/**
 * Una riga di movimento dell'estratto, già ripulita: solo le colonne ammesse, IBAN mascherati. Gli importi sono
 * in centesimi con il segno dell'estratto (negativo = uscita). Un incasso con commissione (Satispay) ha `importo` al
 * netto e la `commissione` a parte; un formato che identifica le transazioni (`idEsterno`) non ha il saldo parziale.
 */
final readonly class RigaEstratto
{
    public function __construct(
        public string $data,
        public ?string $dataValuta,
        public ?string $causale,
        public string $descrizione,
        public int $importo,
        public ?int $saldoParziale,
        public ?string $idEsterno = null,
        public int $commissione = 0,
    ) {}

    /** Contro i doppioni: l'id della transazione se il formato ne ha uno, altrimenti data operazione + importo + descrizione + saldo parziale (#14). */
    public function chiave(): string
    {
        if ($this->idEsterno !== null) {
            return sha1('id|'.$this->idEsterno);
        }

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
        return new self($dati['data'], $dati['dataValuta'], $dati['causale'], $dati['descrizione'], $dati['importo'], $dati['saldoParziale'], $dati['idEsterno'] ?? null, $dati['commissione'] ?? 0);
    }
}
