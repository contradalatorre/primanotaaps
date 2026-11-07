<?php

namespace App\Models;

use App\Models\Concerns\Archiviabile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** L'evento o il progetto a cui un movimento si riferisce, attraverso più esercizi (glossario: Iniziativa). */
#[Fillable(['nome'])]
class Iniziativa extends Model
{
    use Archiviabile;

    protected $table = 'iniziative';

    public function predefiniti(): HasMany
    {
        return $this->hasMany(IniziativaEsercizio::class);
    }

    /** Area e attività che l'iniziativa propone ai movimenti di un esercizio, se ne ha. */
    public function predefinitiPer(int $esercizio): ?IniziativaEsercizio
    {
        return $this->predefiniti->firstWhere('esercizio', $esercizio);
    }

    public function usata(): bool
    {
        return Imputazione::where('iniziativa_id', $this->id)->exists();
    }
}
