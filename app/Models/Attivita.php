<?php

namespace App\Models;

use App\Area;
use App\Models\Concerns\Archiviabile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Dentro un'area, l'attività in senso normativo (glossario: Attività). */
#[Fillable(['area', 'nome'])]
class Attivita extends Model
{
    use Archiviabile;

    protected $table = 'attivita';

    protected function casts(): array
    {
        return ['area' => Area::class];
    }

    public function usata(): bool
    {
        return Imputazione::where('attivita_id', $this->id)->exists()
            || DB::table('iniziativa_esercizio')->where('attivita_id', $this->id)->exists();
    }
}
