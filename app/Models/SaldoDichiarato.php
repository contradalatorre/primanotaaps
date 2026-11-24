<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il saldo che l'operatore legge dalla dashboard di un conto senza saldo nell'estratto (Satispay, #49), a una data. Con quello dell'app
 * non coincide sempre: la differenza si calcola a ogni lettura (l'app può ricevere altri import) e si spiega con la `spiegazione`.
 */
class SaldoDichiarato extends Model
{
    protected $table = 'saldi_dichiarati';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'date', 'importo' => 'integer'];
    }

    public function utente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Quanto il saldo dell'app alla data è diverso da quello dichiarato (positivo: l'app ha di più). */
    public function differenza(Conto $conto): int
    {
        return $conto->saldoAl($this->data->toDateString()) - $this->importo;
    }
}
