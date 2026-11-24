<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** I dati di una raccolta fondi occasionale per un esercizio, a mano (glossario: Raccolta fondi occasionale, #61): date, occasione e campi della relazione illustrativa. Gli importi sono in centesimi. */
class RaccoltaOccasionale extends Model
{
    /** I campi che l'operatore scrive nel modulo: testi, date e importi. */
    public const CAMPI = ['dal', 'al', 'occasione', 'beni_servizi', 'descrizione', 'luogo', 'modalita', 'finalita', 'dettaglio_costi', 'attivita_destinazione_id', 'beni_modico_numero', 'beni_modico_prezzo', 'elargizioni_persone_fisiche', 'elargizioni_persone_giuridiche', 'motivazione'];

    protected $table = 'raccolte_occasionali';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['esercizio' => 'integer', 'dal' => 'date', 'al' => 'date', 'beni_modico_numero' => 'integer', 'beni_modico_prezzo' => 'integer', 'elargizioni_persone_fisiche' => 'integer', 'elargizioni_persone_giuridiche' => 'integer'];
    }

    public function iniziativa(): BelongsTo
    {
        return $this->belongsTo(Iniziativa::class);
    }

    public function attivitaDestinazione(): BelongsTo
    {
        return $this->belongsTo(Attivita::class, 'attivita_destinazione_id');
    }

    public function liberalita(): HasMany
    {
        return $this->hasMany(LiberalitaNonMonetaria::class, 'raccolta_id')->orderBy('id');
    }

    /** Per il registro delle modifiche. */
    public function istantanea(): array
    {
        return collect($this->attributesToArray())->only(['esercizio', ...self::CAMPI])->all();
    }
}
