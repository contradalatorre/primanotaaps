<?php

namespace App\Models;

use App\Models\Concerns\Archiviabile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Un'etichetta libera da ogni effetto su Modello D e test (glossario: Tag). */
#[Fillable(['nome'])]
class Tag extends Model
{
    use Archiviabile;

    protected $table = 'tag';

    public function usata(): bool
    {
        return DB::table('imputazione_tag')->where('tag_id', $this->id)->exists();
    }
}
