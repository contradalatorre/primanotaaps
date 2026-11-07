<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Tipologie, attività, iniziative e tag non si cancellano se usati: si archiviano. Un elemento archiviato non si
 * sceglie più ma resta leggibile dove è già stato usato. Chi lo usa implementa `usata()`.
 */
trait Archiviabile
{
    protected function initializeArchiviabile(): void
    {
        $this->mergeCasts(['archiviata_il' => 'datetime']);
    }

    public function scopeAttive(Builder $query): void
    {
        $query->whereNull('archiviata_il');
    }

    public function archiviata(): bool
    {
        return $this->archiviata_il !== null;
    }

    abstract public function usata(): bool;
}
