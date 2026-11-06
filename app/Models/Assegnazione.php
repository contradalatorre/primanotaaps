<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['user_id', 'ruolo_id', 'tutti_i_conti', 'scade_il', 'nomina_firmata_il'])]
class Assegnazione extends Model
{
    protected $table = 'assegnazioni';

    protected function casts(): array
    {
        return ['tutti_i_conti' => 'boolean', 'scade_il' => 'date', 'nomina_firmata_il' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ruolo(): BelongsTo
    {
        return $this->belongsTo(Ruolo::class);
    }

    /** `scade_il` è l'ultimo giorno di validità: dal giorno dopo l'assegnazione non dà più permessi. */
    public function scopeAttive(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('scade_il')->orWhere('scade_il', '>=', today()));
    }

    public function ambito(): string
    {
        if ($this->tutti_i_conti) {
            return 'Tutti i conti';
        }
        $n = DB::table('assegnazione_conto')->where('assegnazione_id', $this->id)->count();

        return $n === 1 ? '1 conto' : $n.' conti';
    }

    /** Su un conto preciso, o (senza conto) su almeno uno: un'assegnazione rimasta senza conti non vale su niente. */
    public function valeSuConto(?int $contoId = null): bool
    {
        return $this->tutti_i_conti
            || DB::table('assegnazione_conto')->where('assegnazione_id', $this->id)
                ->when($contoId !== null, fn ($q) => $q->where('conto_id', $contoId))->exists();
    }
}
