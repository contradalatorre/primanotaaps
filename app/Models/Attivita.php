<?php

namespace App\Models;

use App\Area;
use App\Models\Concerns\Archiviabile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Dentro un'area, l'attività in senso normativo (glossario: Attività). */
#[Fillable(['area', 'nome', 'raccolta'])]
class Attivita extends Model
{
    use Archiviabile;

    /** Nell'area C l'attività è una raccolta fondi abituale o occasionale (glossario): da lì la voce è C1 o C2 (`SchemaModelloD::voceDi`). */
    public const RACCOLTE = ['abituale' => 'Abituale', 'occasionale' => 'Occasionale'];

    protected $table = 'attivita';

    protected function casts(): array
    {
        return ['area' => Area::class];
    }

    public function usata(): bool
    {
        return Imputazione::where('attivita_id', $this->id)->exists()
            || DB::table('iniziativa_esercizio')->where('attivita_id', $this->id)->exists()
            || Regola::where('attivita_id', $this->id)->exists() || EsempioClassificazione::where('attivita_id', $this->id)->exists()
            || ConversioneCausale::where('attivita_id', $this->id)->exists(); // una riga della tabella di conversione (#64) la tiene: cancellandola, la chiave `nullOnDelete` la perderebbe in silenzio
    }
}
