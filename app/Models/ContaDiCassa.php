<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una conta di cassa (glossario): i contanti contati a una data, confrontati con il saldo dell'app. Quadra se i due
 * coincidono o se la differenza è stata corretta con un movimento; una nota la spiega ma non la toglie. `saldo_app` e
 * `differenza` sono la fotografia del momento, per la storia: se quadra si rilegge sempre dal saldo.
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

    /** Senza differenze oggi: i contanti contati sono ancora il saldo dell'app di allora (una correzione eliminata o un movimento antecedente arrivato dopo la toglie). */
    public function quadra(): bool
    {
        return $this->conto->saldoDellaConta($this) === $this->importo_contato;
    }
}
