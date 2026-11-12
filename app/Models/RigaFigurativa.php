<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una riga di costo o provento figurativo di un esercizio (dati dell'esercizio, #12). */
class RigaFigurativa extends Model
{
    protected $table = 'righe_figurative';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['esercizio' => 'integer', 'importo' => 'integer'];
    }
}
