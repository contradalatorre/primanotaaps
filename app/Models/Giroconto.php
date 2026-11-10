<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un giroconto confermato (glossario: Giroconto): un movimento in uscita da un conto e uno in entrata su un altro.
 * Non è né un'entrata né un'uscita dell'ente: le sue metà restano fuori dai totali (`Movimento::senzaGiroconto`).
 */
class Giroconto extends Model
{
    protected $table = 'giroconti';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['confermato_il' => 'datetime'];
    }

    public function uscita(): BelongsTo
    {
        return $this->belongsTo(Movimento::class, 'uscita_id')->withTrashed();
    }

    public function entrata(): BelongsTo
    {
        return $this->belongsTo(Movimento::class, 'entrata_id')->withTrashed();
    }

    /** Il giroconto di cui il movimento è una metà, se c'è. */
    public static function di(Movimento $movimento): ?self
    {
        return self::where($movimento->verso === 'uscita' ? 'uscita_id' : 'entrata_id', $movimento->id)->first();
    }

    /** L'altra metà rispetto a `$movimento`. */
    public function altraMeta(Movimento $movimento): Movimento
    {
        return $movimento->verso === 'uscita' ? $this->entrata : $this->uscita;
    }

    public function scopeDelle(Builder $query, iterable $idMovimenti): void
    {
        $id = collect($idMovimenti);
        $query->where(fn (Builder $q) => $q->whereIn('uscita_id', $id)->orWhereIn('entrata_id', $id));
    }
}
