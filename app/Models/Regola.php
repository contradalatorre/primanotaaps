<?php

namespace App\Models;

use App\Euro;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Una regola del tesoriere (#42): se un movimento soddisfa tutte le condizioni indicate, ne propone la classificazione.
 * Nessuna espressione regolare: «contiene» e «uguale a» sono confronti di testo semplici. Le regole non impostano mai
 * la natura commerciale. Dell'ordine e dell'applicazione si occupa `App\Regole`.
 */
class Regola extends Model
{
    protected $table = 'regole';

    protected $guarded = [];

    public const TIPI_CONTROPARTE = ['socio' => 'Socio', 'terzo' => 'Terzo'];

    protected function casts(): array
    {
        return ['importo_min' => 'integer', 'importo_max' => 'integer', 'valida_dal' => 'date', 'valida_al' => 'date', 'posizione' => 'integer'];
    }

    public function conto(): BelongsTo
    {
        return $this->belongsTo(Conto::class);
    }

    public function tipologia(): BelongsTo
    {
        return $this->belongsTo(Tipologia::class);
    }

    public function iniziativa(): BelongsTo
    {
        return $this->belongsTo(Iniziativa::class);
    }

    public function attivita(): BelongsTo
    {
        return $this->belongsTo(Attivita::class);
    }

    public function tag(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'regola_tag', 'regola_id', 'tag_id');
    }

    /**
     * Il movimento soddisfa tutte le condizioni. Il verso della tipologia scelta conta come condizione: una regola
     * che propone un'uscita non guarda le entrate. Legge `tipologia`: da caricare prima, in un elenco.
     */
    public function corrisponde(Movimento $movimento): bool
    {
        $verso = $this->verso ?? $this->tipologia?->verso;
        $data = $movimento->data->toDateString();

        return ($this->conto_id === null || $this->conto_id === $movimento->conto_id)
            && ($verso === null || $verso === $movimento->verso)
            && ($this->causale === null || mb_strtolower(trim($this->causale)) === mb_strtolower(trim((string) $movimento->causale)))
            && ($this->testo === null || mb_stripos($movimento->descrizione, $this->testo) !== false)
            && ($this->importo_min === null || $movimento->importo >= $this->importo_min)
            && ($this->importo_max === null || $movimento->importo <= $this->importo_max)
            && ($this->valida_dal === null || $data >= $this->valida_dal->toDateString())
            && ($this->valida_al === null || $data <= $this->valida_al->toDateString());
    }

    /** Una tipologia, iniziativa, attività o tag archiviati non si propongono più: la regola va corretta (e intanto non propone). Legge le relazioni: da caricare prima. */
    public function archiviata(): bool
    {
        return collect([$this->tipologia, $this->iniziativa, $this->attivita, ...$this->tag])->filter()->contains(fn ($e) => $e->archiviata());
    }

    /** Le condizioni a parole, per l'elenco («descrizione contiene «ENEL» · importo 10,00–20,00 €»). */
    public function condizioniATesto(): string
    {
        return collect([
            $this->conto?->nome ? 'conto '.$this->conto->nome : null,
            ($this->verso ?? $this->tipologia?->verso) ? Movimento::VERSI[$this->verso ?? $this->tipologia->verso] : null, // il verso della tipologia conta come condizione
            $this->causale !== null ? 'causale «'.$this->causale.'»' : null,
            $this->testo !== null ? 'descrizione contiene «'.$this->testo.'»' : null,
            match (true) {
                $this->importo_min !== null && $this->importo_min === $this->importo_max => 'importo '.Euro::formatta($this->importo_min).' €',
                $this->importo_min !== null && $this->importo_max !== null => 'importo da '.Euro::formatta($this->importo_min).' a '.Euro::formatta($this->importo_max).' €',
                $this->importo_min !== null => 'importo da '.Euro::formatta($this->importo_min).' €',
                $this->importo_max !== null => 'importo fino a '.Euro::formatta($this->importo_max).' €',
                default => null,
            },
            $this->valida_dal ? 'dal '.$this->valida_dal->format('d/m/Y') : null,
            $this->valida_al ? 'al '.$this->valida_al->format('d/m/Y') : null,
        ])->filter()->join(' · ');
    }

    /** Gli effetti a parole. */
    public function effettiATesto(): string
    {
        return collect([
            $this->tipologia?->nome, $this->iniziativa?->nome, $this->attivita?->nome, $this->area && ! $this->iniziativa ? 'area '.$this->area : null,
            $this->tipo_controparte ? self::TIPI_CONTROPARTE[$this->tipo_controparte] : null,
            $this->tag->isEmpty() ? null : 'tag: '.$this->tag->pluck('nome')->join(', '),
        ])->filter()->join(' · ');
    }

    /** I campi che il registro delle modifiche conserva prima e dopo ogni cambiamento. */
    public function istantanea(): array
    {
        $this->loadMissing('tag');

        return $this->only(['nome', 'conto_id', 'verso', 'causale', 'testo', 'importo_min', 'importo_max', 'tipologia_id', 'iniziativa_id', 'area', 'attivita_id', 'tipo_controparte'])
            + ['valida_dal' => $this->valida_dal?->toDateString(), 'valida_al' => $this->valida_al?->toDateString(), 'tag' => $this->tag->pluck('nome')->sort()->join(', ') ?: null];
    }
}
