<?php

namespace App\Models;

use App\Import\RigaEstratto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una riga dell'estratto con la stessa chiave di un movimento già presente ma dati diversi (glossario: conflitto). */
class ImportConflitto extends Model
{
    protected $table = 'import_conflitti';

    protected $guarded = [];

    public const DECISIONI = ['tieni_esistente' => 'Tieni il movimento già presente', 'importa_a_parte' => 'Importa anche questa riga'];

    protected function casts(): array
    {
        return ['riga' => 'array', 'deciso_il' => 'datetime'];
    }

    public function importEstratto(): BelongsTo
    {
        return $this->belongsTo(ImportEstratto::class);
    }

    public function movimento(): BelongsTo
    {
        // anche se nel frattempo l'import che l'aveva portato è stato annullato
        return $this->belongsTo(Movimento::class)->withTrashed();
    }

    public function rigaEstratto(): RigaEstratto
    {
        return RigaEstratto::daArray($this->riga);
    }
}
