<?php

namespace App\Models;

use Database\Factories\MovimentoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una singola entrata o uscita di un conto, al netto (glossario: Movimento). L'importo è in centesimi, sempre
 * positivo; il verso dice se entra o esce. L'eliminazione è logica: la riga resta e sparisce dagli elenchi.
 */
#[Fillable(['conto_id', 'data', 'importo', 'verso', 'descrizione', 'valuta_originale'])]
class Movimento extends Model
{
    /** @use HasFactory<MovimentoFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'movimenti';

    public const DELETED_AT = 'eliminato_il';

    public const VERSI = ['entrata' => 'Entrata', 'uscita' => 'Uscita'];

    public const TIPI_CONTROPARTE = ['socio' => 'Socio', 'terzo' => 'Terzo', 'non_pertinente' => 'Non pertinente'];

    protected function casts(): array
    {
        return ['data' => 'date', 'data_valuta' => 'date', 'importo' => 'integer', 'saldo_parziale' => 'integer', 'manuale' => 'boolean', 'confermato_il' => 'datetime'];
    }

    public function conto(): BelongsTo
    {
        return $this->belongsTo(Conto::class);
    }

    public function autore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inserito_da');
    }

    public function imputazioni(): HasMany
    {
        return $this->hasMany(Imputazione::class)->chaperone();
    }

    public function giustificativi(): HasMany
    {
        return $this->hasMany(Giustificativo::class);
    }

    public function note(): MorphMany
    {
        return $this->morphMany(Nota::class, 'oggetto', 'oggetto_tipo', 'oggetto_id');
    }

    /** Fuori dai totali di entrate e uscite: le metà di un giroconto non sono né l'una né l'altra (glossario). */
    public function scopeSenzaGiroconto(Builder $query): void
    {
        $query->whereNotIn('movimenti.id', Giroconto::select('uscita_id'))->whereNotIn('movimenti.id', Giroconto::select('entrata_id'));
    }

    public function scopeInGiroconto(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereIn('movimenti.id', Giroconto::select('uscita_id'))->orWhereIn('movimenti.id', Giroconto::select('entrata_id')));
    }

    /** Conteggio delle note visibili (`note_count`), dubbio aperto (`in_dubbio`) e giroconto confermato (`in_giroconto`, #47) già nella query dell'elenco, senza una query per riga. */
    public function scopeConNote(Builder $query): void
    {
        // Con il builder e non in SQL grezzo: nello spazio di prova le tabelle hanno il prefisso (ADR 0003).
        $query->addSelect('movimenti.*')->selectSub(Giroconto::selectRaw('1')->where(fn (Builder $g) => $g->whereColumn('uscita_id', 'movimenti.id')->orWhereColumn('entrata_id', 'movimenti.id'))->limit(1), 'in_giroconto')
            ->withCount(['note' => fn (Builder $n) => $n->whereNull('eliminata_il')])
            ->withExists(['note as in_dubbio' => fn (Builder $n) => $n->dubbioAperto()]);
    }

    /** In dubbio finché ha una nota dubbio aperta (glossario). Negli elenchi legge `in_dubbio` da `conNote`. */
    public function inDubbio(): bool
    {
        return (bool) ($this->attributes['in_dubbio'] ?? $this->note()->dubbioAperto()->exists());
    }

    public function proponente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposto_da');
    }

    public function regola(): BelongsTo
    {
        return $this->belongsTo(Regola::class);
    }

    /** Il movimento da cui lo storico ha preso la proposta (anche eliminato: la fonte ne dice la data, il collegamento no). */
    public function storicoMovimento(): BelongsTo
    {
        return $this->belongsTo(self::class, 'storico_movimento_id')->withTrashed();
    }

    public function storicoEsempio(): BelongsTo
    {
        return $this->belongsTo(EsempioClassificazione::class, 'storico_esempio_id');
    }

    /** Il movimento di origine della proposta dallo storico, se esiste ancora (a cui si può collegare). Da caricare prima: `storicoMovimento`. */
    public function movimentoDiOrigine(): ?self
    {
        return $this->storicoMovimento?->trashed() === false ? $this->storicoMovimento : null;
    }

    /**
     * Da dove viene la proposta in corso, o null se non ce n'è.
     * shortcut: finora le fonti sono persone, regole e storico; l'AI (#66) avrà la sua. Da caricare prima: `proponente`, `regola`, `storicoMovimento` e `storicoEsempio`.
     */
    public function fonteProposta(): ?string
    {
        return match (true) {
            $this->stato !== 'proposto' => null,
            $this->regola_id !== null => 'Regola: '.$this->regola->nome,
            $this->storico_movimento_id !== null => 'Storico: come il movimento del '.$this->storicoMovimento->data->format('d/m/Y'),
            $this->storico_esempio_id !== null => 'Storico: come l’esempio del '.$this->storicoEsempio->data->format('d/m/Y'),
            $this->proponente !== null => 'Proposta da '.$this->proponente->name,
            default => null,
        };
    }

    public function confermato(): bool
    {
        return $this->stato === 'confermato';
    }

    /**
     * Si può confermare così com'è: ha imputazioni e ognuna ha la tipologia (gli altri controlli li rifà
     * `Classificazione::conferma`). Legge le imputazioni: da caricare prima, in un elenco.
     */
    public function confermabile(): bool
    {
        return ! $this->confermato() && $this->imputazioni->isNotEmpty() && $this->imputazioni->every(fn (Imputazione $i) => $i->tipologia_id !== null);
    }

    /**
     * L'autore modifica e ritira il proprio movimento manuale, sui conti del suo ambito, finché nessuno lo ha
     * confermato e il conto è aperto. Una sola regola per il controllore e per i pulsanti della pagina.
     */
    public function modificabileDa(User $utente): bool
    {
        return $this->manuale && $this->inserito_da === $utente->id && ! $this->confermato()
            && ! $this->conto->chiuso() && $utente->can('modificare_movimenti_propri', $this->conto_id);
    }

    /**
     * Classifica (e carica giustificativi) chi ha `importare_e_confermare`, sempre; l'autore con `proporre_classificazioni`
     * sul conto, solo sul proprio movimento non ancora confermato e a conto aperto, come `modificabileDa` (#16: «da lì
     * l'autore non lo modifica più»). Chi ha `importare_e_confermare` classifica anche a conto chiuso: l'ultimo estratto si rivede dopo.
     */
    public function classificabileDa(User $utente): bool
    {
        // Una metà di giroconto non si classifica: non è un'entrata né un'uscita. Prima si scioglie il giroconto.
        if ($this->confermato() && (array_key_exists('in_giroconto', $this->attributes) ? (bool) $this->attributes['in_giroconto'] : Giroconto::di($this) !== null)) { // una metà di giroconto è sempre confermata
            return false;
        }

        return $utente->can('importare_e_confermare')
            || ($this->manuale && $this->inserito_da === $utente->id && ! $this->confermato() && ! $this->conto->chiuso() && $utente->can('proporre_classificazioni', $this->conto_id));
    }

    /**
     * I campi che il registro delle modifiche conserva prima e dopo ogni cambiamento. Con `$relazioniCaricate` legge
     * imputazioni (con attività, iniziativa, tipologia e tag) e giustificativi già caricati, senza query: solo per
     * chi non scrive dopo (la lista); le relazioni caricate non seguono le scritture.
     */
    public function istantanea(bool $relazioniCaricate = false): array
    {
        $imputazioni = $relazioniCaricate ? $this->imputazioni->sortBy('id') : $this->imputazioni()->with('attivita', 'iniziativa', 'tipologia', 'tag')->orderBy('id')->get();
        $giustificativi = $relazioniCaricate ? $this->giustificativi->sortBy('id')->pluck('riferimento') : $this->giustificativi()->orderBy('id')->pluck('riferimento');

        return [
            'conto_id' => $this->conto_id, 'data' => $this->data->toDateString(), 'importo' => $this->importo,
            'verso' => $this->verso, 'descrizione' => $this->descrizione, 'valuta_originale' => $this->valuta_originale,
            'stato' => $this->stato, 'tipo_controparte' => $this->tipo_controparte,
            'imputazioni' => $imputazioni->map->riassunto()->join('; ') ?: null,
            'giustificativi' => $giustificativi->join('; ') ?: null,
        ];
    }
}
