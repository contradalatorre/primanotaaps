<?php

namespace App\Models;

use App\Models\Concerns\NelDatabaseVero;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Lo spazio di prova di un utente (ADR 0003): le tabelle stanno nel database di prova, qui solo che esiste. */
#[Fillable(['user_id', 'missione', 'con_assegnazione', 'ultimo_uso'])]
class SpazioProva extends Model
{
    use NelDatabaseVero;

    protected $table = 'spazi_prova';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['ultimo_uso' => 'datetime'];
    }
}
