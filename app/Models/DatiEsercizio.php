<?php

namespace App\Models;

use App\Permesso;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** I dati dell'esercizio che non derivano dai movimenti (glossario: Dati dell'esercizio, #12, #53). Una riga per esercizio, creata alla prima scrittura. */
class DatiEsercizio extends Model
{
    /** @var array<string, string> costi promiscui: come si ripartiscono (predefinito: la quota dei ricavi, Circolare 1/E) */
    public const CRITERI_RIPARTO = ['ricavi' => 'Quota dei ricavi dell’attività (Circolare 1/E)', 'costi_diretti' => 'Quota dei costi diretti'];

    protected $table = 'dati_esercizio';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['esercizio' => 'integer', 'dipendenti_medi' => 'integer', 'attivo' => 'integer', 'figurativi_dichiarati' => 'boolean', 'saldi_apertura_il' => 'datetime'];
    }

    /** I dati dell'esercizio, o quelli di partenza (non salvati) se nessuno li ha ancora scritti. */
    public static function per(int $esercizio): self
    {
        return self::where('esercizio', $esercizio)->first() ?? new self(['esercizio' => $esercizio, 'criterio_riparto' => 'ricavi', 'figurativi_dichiarati' => false]);
    }

    /**
     * Ogni scrittura dei dati dell'esercizio passa di qui: crea la riga se manca, la blocca, rilegge i permessi (quelli caricati
     * dal controllo della rotta possono essere di prima di una revoca) ed esegue `$scrittura($dati)` nella stessa transazione.
     * La riga si crea FUORI dalla transazione: un `INSERT IGNORE` su una riga che c'è già prende un blocco condiviso, e due
     * richieste che poi chiedono quello esclusivo si bloccano a vicenda (deadlock su MySQL). Non si blocca una riga che non c'è:
     * il blocco sullo spazio vuoto lascia correre due scritture insieme. Anche le righe figurative si scrivono sotto questo blocco.
     */
    public static function sottoBlocco(User $utente, int $esercizio, Closure $scrittura, Permesso $permesso = Permesso::GestireEsercizio): mixed
    {
        return self::sottoBlocchi($utente, [$esercizio], fn (array $dati) => $scrittura($dati[$esercizio]), $permesso);
    }

    /**
     * Come `sottoBlocco` per più esercizi insieme (anche nessuno: solo la transazione e il permesso): le righe si bloccano in un'unica
     * lettura, in ordine di anno, e `$scrittura` riceve quelle bloccate per esercizio.
     *
     * @param  list<int>  $esercizi
     * @param  Closure(array<int, DatiEsercizio>): mixed  $scrittura
     */
    public static function sottoBlocchi(User $utente, array $esercizi, Closure $scrittura, Permesso $permesso = Permesso::GestireEsercizio): mixed
    {
        foreach ($esercizi as $esercizio) {
            DB::table('dati_esercizio')->insertOrIgnore(['esercizio' => $esercizio, 'criterio_riparto' => 'ricavi', 'figurativi_dichiarati' => false, 'created_at' => now(), 'updated_at' => now()]);
        }

        return DB::transaction(function () use ($utente, $esercizi, $scrittura, $permesso) {
            $dati = $esercizi === [] ? [] : self::whereIn('esercizio', $esercizi)->orderBy('esercizio')->lockForUpdate()->get()->keyBy('esercizio')->all();
            $utente->unsetRelation('assegnazioniAttive');
            abort_unless($utente->puo($permesso), 403);

            return $scrittura($dati);
        });
    }

    public function istantanea(): array
    {
        return $this->only(['esercizio', 'dipendenti_medi', 'attivo', 'criterio_riparto', 'criterio_secondarieta', 'annotazione_in_calce']) + ['figurativi_dichiarati' => $this->figurativi_dichiarati ? 'sì' : 'no'];
    }
}
