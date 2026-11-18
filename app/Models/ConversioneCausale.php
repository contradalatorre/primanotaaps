<?php

namespace App\Models;

use App\Import\EsempiDalFoglio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una riga della tabella di conversione delle vecchie causali del foglio (#19, docs/import-foglio): la «Nuova causale» diventa tipologia, area, attività,
 * iniziativa e tag. La leggono gli esempi del 2025 (#63) e l'import della cassa (#64), che la propone sui movimenti; la modifica il Tesoriere in
 * una pagina dell'app (`App\Conversioni`), finché il foglio è in uso.
 */
class ConversioneCausale extends Model
{
    protected $table = 'conversione_causali';

    protected $guarded = [];

    public const VERSI = ['entrata' => 'Entrata', 'uscita' => 'Uscita', 'giroconto' => 'Giroconto'];

    public function tipologia(): BelongsTo
    {
        return $this->belongsTo(Tipologia::class);
    }

    public function attivita(): BelongsTo
    {
        return $this->belongsTo(Attivita::class);
    }

    public function iniziativa(): BelongsTo
    {
        return $this->belongsTo(Iniziativa::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    /**
     * La tabella per `EsempiDalFoglio`, per chiave della causale.
     *
     * @return array<string, array{verso: string, tipologia_id: ?int, area: ?string, attivita_id: ?int, iniziativa_id: ?int}>
     */
    public static function perChiave(): array
    {
        return self::all()->mapWithKeys(fn (self $c) => [EsempiDalFoglio::chiave($c->causale) => [
            'verso' => $c->verso, 'tipologia_id' => $c->tipologia_id, 'area' => $c->area, 'attivita_id' => $c->attivita_id, 'iniziativa_id' => $c->iniziativa_id,
        ]])->all();
    }

    /** Una tipologia, un'iniziativa, un'attività o un tag archiviati non si propongono più (come per le regole). */
    public function archiviata(): bool
    {
        return collect([$this->tipologia, $this->iniziativa, $this->attivita, $this->tag])->filter()->contains(fn ($e) => $e->archiviata());
    }

    /** Quello che la riga cambia nei movimenti: per confrontare la riga di adesso con quella di prima. */
    public function istantanea(): array
    {
        return $this->only(['causale', 'verso', 'tipologia_id', 'area', 'attivita_id', 'iniziativa_id', 'tag_id']);
    }
}
