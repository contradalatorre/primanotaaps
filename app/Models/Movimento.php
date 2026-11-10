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

    /** Conteggio delle note visibili (`note_count`) e dubbio aperto (`in_dubbio`) già nella query dell'elenco, senza una query per riga. */
    public function scopeConNote(Builder $query): void
    {
        $query->withCount(['note' => fn (Builder $n) => $n->whereNull('eliminata_il')])
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

    /**
     * Da dove viene la proposta in corso, o null se non ce n'è.
     * shortcut: finora le fonti sono persone e regole; storico e AI (#43) avranno la loro. Da caricare prima: `proponente` e `regola`.
     */
    public function fonteProposta(): ?string
    {
        return match (true) {
            $this->stato !== 'proposto' => null,
            $this->regola_id !== null => 'Regola: '.$this->regola->nome,
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
     * sul conto, solo sul proprio movimento non ancora confermato (#16: «da lì l'autore non lo modifica più»).
     */
    public function classificabileDa(User $utente): bool
    {
        return $utente->can('importare_e_confermare')
            || ($this->manuale && $this->inserito_da === $utente->id && ! $this->confermato() && $utente->can('proporre_classificazioni', $this->conto_id));
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
