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

    /** I campi che il registro delle modifiche conserva prima e dopo ogni cambiamento. */
    public function istantanea(): array
    {
        return collect($this->attributesToArray())->only(['nome', 'area', 'raccolta', 'verso', 'spiegazione', 'proprieta', 'archiviata_il'])
            ->map(fn ($valore) => is_array($valore) ? implode(', ', $valore) : $valore)->all();
    }

    abstract public function usata(): bool;
}
