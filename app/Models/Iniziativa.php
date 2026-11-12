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

    /** Nel registro anche area e attività predefinite, un esercizio per riga. */
    public function istantanea(): array
    {
        $predefiniti = $this->predefiniti()->with('attivita')->orderBy('esercizio')->get()
            ->map(fn ($p) => "$p->esercizio: ".$p->area->value.($p->attivita ? ' · '.$p->attivita->nome : ''))->join('; ');

        return ['nome' => $this->nome, 'predefiniti' => $predefiniti ?: null, 'archiviata_il' => $this->archiviata_il?->toIso8601String()];
    }

    public function usata(): bool
    {
        return Imputazione::where('iniziativa_id', $this->id)->exists()
            || PreventivoIniziativa::where('iniziativa_id', $this->id)->exists()
            || Regola::where('iniziativa_id', $this->id)->exists() || EsempioClassificazione::where('iniziativa_id', $this->id)->exists();
    }
}
