<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Le impostazioni dell'associazione con validità per esercizio (#12, storia 135): valgono dall'esercizio indicato in poi. */
#[Fillable(['dal_esercizio', 'denominazione', 'codice_fiscale', 'numero_runts', 'affiliazione_rete', 'soglia_vicino'])]
class ImpostazioniAssociazione extends Model
{
    protected $table = 'impostazioni_associazione';

    protected function casts(): array
    {
        return ['dal_esercizio' => 'integer', 'affiliazione_rete' => 'boolean', 'soglia_vicino' => 'integer'];
    }

    /** Le impostazioni che valgono per l'esercizio: la riga con il più grande `dal_esercizio` non oltre l'anno, o null se nessuna. */
    public static function per(int $esercizio): ?self
    {
        return self::where('dal_esercizio', '<=', $esercizio)->orderByDesc('dal_esercizio')->first();
    }

    public function istantanea(): array
    {
        return $this->only(['dal_esercizio', 'denominazione', 'codice_fiscale', 'numero_runts', 'soglia_vicino']) + ['affiliazione_rete' => $this->affiliazione_rete ? 'sì' : 'no'];
    }
}
