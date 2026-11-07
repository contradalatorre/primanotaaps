<?php

namespace App\Models;

use Database\Factories\MovimentoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    protected function casts(): array
    {
        return ['data' => 'date', 'importo' => 'integer', 'manuale' => 'boolean', 'confermato_il' => 'datetime'];
    }

    public function conto(): BelongsTo
    {
        return $this->belongsTo(Conto::class);
    }

    public function autore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inserito_da');
    }

    public function confermato(): bool
    {
        return $this->stato === 'confermato';
    }

    /** Con il segno, per mostrarlo e per i saldi. */
    public function importoConSegno(): int
    {
        return $this->verso === 'uscita' ? -$this->importo : $this->importo;
    }

    /** I campi che il registro delle modifiche conserva prima e dopo ogni cambiamento. */
    public function istantanea(): array
    {
        return [
            'conto_id' => $this->conto_id, 'data' => $this->data->toDateString(), 'importo' => $this->importo,
            'verso' => $this->verso, 'descrizione' => $this->descrizione, 'valuta_originale' => $this->valuta_originale,
            'stato' => $this->stato,
        ];
    }
}
