<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una conta di cassa (glossario): i contanti contati a una data, confrontati con il saldo dell'app. È «senza differenze»
 * se i due coincidono o se la differenza è stata corretta con un movimento; una nota la spiega ma non la toglie.
 */
class ContaDiCassa extends Model
{
    protected $table = 'conte_di_cassa';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'date', 'importo_contato' => 'integer', 'saldo_app' => 'integer', 'differenza' => 'integer'];
    }

    public function conto(): BelongsTo
    {
        return $this->belongsTo(Conto::class);
    }

    public function contataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contata_da');
    }

    public function contataCon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contata_con');
    }

    public function correzione(): BelongsTo
    {
        return $this->belongsTo(Movimento::class, 'correzione_id')->withTrashed();
    }

    public function scopeSenzaDifferenze(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('differenza', 0)->orWhereNotNull('correzione_id'));
    }

    public function senzaDifferenze(): bool
    {
        return $this->differenza === 0 || $this->correzione_id !== null;
    }
}
