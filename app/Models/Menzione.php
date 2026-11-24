<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il richiamo `@utente` o `@ruolo` in una nota (glossario: Menzione), da vedere finché qualcuno non lo segna visto.
 * Per un ruolo c'è una riga sola: la prima persona che agisce la chiude per tutte (#16).
 */
class Menzione extends Model
{
    protected $table = 'menzioni';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['vista_il' => 'datetime'];
    }

    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class);
    }

    public function utente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function ruolo(): BelongsTo
    {
        return $this->belongsTo(Ruolo::class);
    }

    public function vistaDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vista_da');
    }

    /** Rivolte all'utente: a lui, o a un ruolo che ha adesso in un'assegnazione attiva (`@ruolo` raggiunge chi lo ha alla lettura). */
    public function scopeRivolteA(Builder $query, User $utente): void
    {
        $query->where(fn (Builder $q) => $q->where('user_id', $utente->id)
            ->orWhereIn('ruolo_id', $utente->assegnazioniAttive()->select('ruolo_id')));
    }

    /** Il riquadro «Da vedere»: non viste, su note ancora leggibili di movimenti che esistono, non scritte dall'utente stesso. */
    public static function daVedere(User $utente)
    {
        return static::whereNull('vista_il')->rivolteA($utente)
            ->whereHas('nota', fn (Builder $n) => $n->whereNull('eliminata_il')->whereNull('oscurata_il')
                ->where('user_id', '!=', $utente->id)
                ->where('oggetto_tipo', 'movimento')->whereIn('oggetto_id', Movimento::select('id')))
            ->with('nota.autore', 'nota.oggetto.conto', 'ruolo')->orderBy('id')->get();
    }
}
