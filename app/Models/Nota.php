<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un messaggio breve di un utente su un oggetto (glossario: Nota), oggi solo un movimento. Non modifica mai
 * l'oggetto: niente effetti su classificazione, Modello D o test. Eliminata e oscurata restano nella tabella:
 * il testo sparisce dalla vista, non dal registro delle modifiche.
 */
class Nota extends Model
{
    protected $table = 'note';

    /** Per quante ore l'autore modifica o cancella la propria nota (#16). */
    public const FINESTRA_ORE = 24;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['dubbio' => 'boolean', 'chiuso_il' => 'datetime', 'modificata_il' => 'datetime', 'eliminata_il' => 'datetime', 'oscurata_il' => 'datetime'];
    }

    public function oggetto(): MorphTo
    {
        return $this->morphTo(null, 'oggetto_tipo', 'oggetto_id');
    }

    public function autore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function eliminataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'eliminata_da');
    }

    public function oscurataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'oscurata_da');
    }

    public function menzioni(): HasMany
    {
        return $this->hasMany(Menzione::class);
    }

    /** Una nota dubbio non eliminata e non ancora chiusa: finché ce n'è una, il movimento è in dubbio. */
    public function scopeDubbioAperto(Builder $query): void
    {
        $query->where('dubbio', true)->whereNull('chiuso_il')->whereNull('eliminata_il');
    }

    public function eliminata(): bool
    {
        return $this->eliminata_il !== null;
    }

    public function oscurata(): bool
    {
        return $this->oscurata_il !== null;
    }

    /** L'autore, entro 24 ore dalla scrittura, finché la nota non è stata eliminata né oscurata. Una regola sola per il controllo e per i pulsanti. */
    public function modificabileDa(User $utente): bool
    {
        return $this->user_id === $utente->id && ! $this->eliminata() && ! $this->oscurata()
            && now()->lessThanOrEqualTo($this->created_at->copy()->addHours(self::FINESTRA_ORE));
    }

    /** Le note di un oggetto in sequenza, con quello che serve a disegnarle. */
    public static function di(Model $oggetto)
    {
        return $oggetto->note()->with('autore', 'eliminataDa', 'oscurataDa', 'menzioni.utente', 'menzioni.ruolo', 'menzioni.vistaDa')->orderBy('id')->get();
    }

    /** Quello che il registro conserva a ogni cambiamento: il testo prima di tutto, perché non si perda con la modifica o l'oscuramento. */
    public function istantanea(): array
    {
        return ['testo' => $this->testo, 'dubbio' => $this->dubbio ? ($this->chiuso_il ? 'chiuso' : 'aperto') : null];
    }
}
