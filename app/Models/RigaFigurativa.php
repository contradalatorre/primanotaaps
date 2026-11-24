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

    /** Per il registro delle modifiche: senza la descrizione, che può nominare una persona (storia 143) e il registro lo leggono più persone di chi gestisce i dati dell'esercizio. */
    public function istantanea(): array
    {
        return $this->only(['esercizio', 'tipo', 'area', 'importo']);
    }
}
