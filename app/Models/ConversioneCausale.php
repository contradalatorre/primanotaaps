<?php

namespace App\Models;

use App\Import\EsempiDalFoglio;
use Illuminate\Database\Eloquent\Model;

/**
 * Una riga della tabella di conversione delle vecchie causali del foglio (#19, docs/import-foglio): la «Nuova causale» diventa tipologia, area, attività,
 * iniziativa e tag. Per ora la legge solo il caricamento degli esempi del 2025 (#63); #64 la rende modificabile.
 */
class ConversioneCausale extends Model
{
    protected $table = 'conversione_causali';

    protected $guarded = [];

    /**
     * La tabella per `EsempiDalFoglio`, per chiave della causale.
     *
     * @return array<string, array{verso: string, tipologia_id: ?int, area: ?string, attivita_id: ?int, iniziativa_id: ?int}>
     */
    public static function perChiave(): array
    {
        return self::all()->mapWithKeys(fn (self $c) => [EsempiDalFoglio::chiave($c->causale) => [
            'verso' => $c->verso, 'tipologia_id' => $c->tipologia_id, 'area' => $c->area, 'attivita_id' => $c->attivita_id, 'iniziativa_id' => $c->iniziativa_id,
        ]])->all();
    }
}
