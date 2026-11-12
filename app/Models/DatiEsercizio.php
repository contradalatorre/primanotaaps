<?php

namespace App\Models;

use App\Permesso;
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
        return ['esercizio' => 'integer', 'dipendenti_medi' => 'integer', 'attivo' => 'integer', 'figurativi_dichiarati' => 'boolean'];
    }

    /** I dati dell'esercizio, o quelli di partenza (non salvati) se nessuno li ha ancora scritti. */
    public static function per(int $esercizio): self
    {
        return self::where('esercizio', $esercizio)->first() ?? new self(['esercizio' => $esercizio, 'criterio_riparto' => 'ricavi', 'figurativi_dichiarati' => false]);
    }

    /**
     * Da chiamare a inizio transazione prima di scrivere: crea la riga se manca (non si blocca una riga che non c'è: il blocco
     * sullo spazio vuoto lascia correre due scritture insieme), la blocca, e rilegge i permessi, perché quelli caricati dal
     * controllo della rotta possono essere di prima di una revoca. Anche le righe figurative si scrivono sotto questo blocco.
     */
    public static function bloccata(User $utente, int $esercizio): self
    {
        DB::table('dati_esercizio')->insertOrIgnore(['esercizio' => $esercizio, 'criterio_riparto' => 'ricavi', 'figurativi_dichiarati' => false, 'created_at' => now(), 'updated_at' => now()]);
        $dati = self::where('esercizio', $esercizio)->lockForUpdate()->firstOrFail();
        $utente->unsetRelation('assegnazioniAttive');
        abort_unless($utente->puo(Permesso::GestireEsercizio), 403);

        return $dati;
    }

    public function istantanea(): array
    {
        return $this->only(['esercizio', 'dipendenti_medi', 'attivo', 'criterio_riparto', 'criterio_secondarieta']) + ['figurativi_dichiarati' => $this->figurativi_dichiarati ? 'sì' : 'no'];
    }
}
