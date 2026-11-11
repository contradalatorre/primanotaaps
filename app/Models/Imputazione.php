<?php

namespace App\Models;

use App\Area;
use App\Euro;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * La parte di un movimento attribuita a un'area, un'attività, un'iniziativa e una tipologia (glossario:
 * Imputazione). La voce Modello D non si salva: si ricava da area e tipologia (`voce()`), così non si può forzare.
 */
class Imputazione extends Model
{
    protected $table = 'imputazioni';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['area' => Area::class, 'importo' => 'integer'];
    }

    public function movimento(): BelongsTo
    {
        return $this->belongsTo(Movimento::class);
    }

    public function attivita(): BelongsTo
    {
        return $this->belongsTo(Attivita::class);
    }

    public function iniziativa(): BelongsTo
    {
        return $this->belongsTo(Iniziativa::class);
    }

    public function tipologia(): BelongsTo
    {
        return $this->belongsTo(Tipologia::class);
    }

    public function tag(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'imputazione_tag', 'imputazione_id', 'tag_id');
    }

    /**
     * La commissione che il fornitore ha trattenuto su un incasso (#48): l'app la aggiunge da sola a ogni classificazione del
     * movimento (area D, «Commissioni e oneri bancari», importo negativo), nessuno la sceglie. Le altre imputazioni sono sempre positive.
     */
    public function commissione(): bool
    {
        return $this->importo < 0;
    }

    /** La voce del Modello D per l'esercizio del movimento («A1», «Imposte»), o null senza tipologia. */
    public function voce(): ?string
    {
        if ($this->tipologia_id === null) {
            return null;
        }

        return DB::table('voci_modello_d')->where('esercizio', $this->movimento->data->year)
            ->where('area', $this->area->value)->where('tipologia_id', $this->tipologia_id)->value('voce');
    }

    /** «Entrate A1»: la voce con il lato del Modello D, perché «A1» c'è sia tra le entrate sia tra le uscite. */
    public function etichettaVoce(): ?string
    {
        $voce = $this->voce();

        return $voce === null ? null : ($this->tipologia->verso === 'entrata' ? 'Entrate ' : 'Uscite ').$voce;
    }

    /** Una riga di testo per il registro delle modifiche. */
    public function riassunto(): string
    {
        $tag = $this->tag->pluck('nome');

        return collect([
            Euro::formatta($this->importo).' €', 'area '.$this->area->value, $this->attivita?->nome, $this->iniziativa?->nome,
            $this->tipologia?->nome ?? 'senza tipologia', $tag->isEmpty() ? null : 'tag: '.$tag->join(', '),
        ])->filter()->join(' · ');
    }
}
