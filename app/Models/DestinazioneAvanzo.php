<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una riga della destinazione dell'avanzo di un esercizio (glossario, #56): descrizione, importo in centesimi e nota. */
class DestinazioneAvanzo extends Model
{
    protected $table = 'destinazioni_avanzo';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['esercizio' => 'integer', 'importo' => 'integer'];
    }

    /** Per il registro delle modifiche. */
    public function istantanea(): array
    {
        return $this->only(['esercizio', 'descrizione', 'importo', 'nota']);
    }
}
