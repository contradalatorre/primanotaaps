<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una liberalità non monetaria di una raccolta (#61): descrizione, quantità, valore stimato in centesimi, criterio di stima (DM 28/11/2019) e documento. Entra solo nel rendiconto della raccolta. */
class LiberalitaNonMonetaria extends Model
{
    protected $table = 'liberalita_non_monetarie';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantita' => 'integer', 'valore' => 'integer'];
    }

    public function istantanea(): array
    {
        return $this->only(['descrizione', 'quantita', 'valore', 'criterio', 'documento']);
    }
}
