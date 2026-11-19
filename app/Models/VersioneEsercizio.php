<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Una versione approvata di un esercizio (glossario: Approvazione; #12, #59): i prospetti e i test com'erano il giorno dell'approvazione. Una riapertura
 * la marca come riaperta e la lascia nello storico; la nuova approvazione scrive la versione successiva.
 */
class VersioneEsercizio extends Model
{
    protected $table = 'versioni_esercizio';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'esercizio' => 'integer', 'numero' => 'integer', 'approvata_il' => 'date', 'soglia_rientro_centesimi_di_punto' => 'integer', 'istantanea' => 'array',
            'riaperta_il' => 'datetime', 'segnalazione_inviata_il' => 'date',
        ];
    }

    /** L'ultima versione approvata dell'esercizio, anche se poi riaperta: per il rientro dopo una secondarietà non rispettata un esito approvato non si dimentica. */
    public static function ultima(int $anno): ?self
    {
        return self::where('esercizio', $anno)->orderByDesc('numero')->first();
    }

    /** La versione che oggi vale per l'esercizio (approvato o depositato), se c'è. */
    public static function vigente(int $anno): ?self
    {
        return self::where('esercizio', $anno)->whereNull('riaperta_il')->first();
    }

    /** Entro quando va fatta la segnalazione al RUNTS (art. 4 DM 107/2021): 30 giorni dall'approvazione. */
    public function scadenzaSegnalazione(): CarbonInterface
    {
        return $this->approvata_il->copy()->addDays(30);
    }

    /**
     * Le segnalazioni al RUNTS ancora da registrare: la secondarietà non è rispettata nell'ultima versione dell'esercizio e l'invio non c'è. Anche se l'esercizio è stato
     * riaperto, il promemoria resta: l'obbligo nato con un esito approvato non si dimentica (come il rientro).
     *
     * @return Collection<int, self>
     */
    public static function segnalazioniDaFare(): Collection
    {
        return self::where('esito_secondarieta', 'non_rispettato')->whereNull('segnalazione_inviata_il')->orderBy('esercizio')->get()
            ->filter(fn (self $v) => $v->numero === self::ultima($v->esercizio)->numero)->values();
    }
}
