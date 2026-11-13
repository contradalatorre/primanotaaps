<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use App\Permesso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

    /**
     * Scrive le impostazioni «dal N» (crea la riga o la modifica) e la registra. La riga si crea FUORI dalla transazione, come in `DatiEsercizio::sottoBlocco`
     * (un `INSERT IGNORE` su una riga che c'è prende un blocco condiviso, poi due richieste che chiedono quello esclusivo vanno in stallo); poi la si blocca e
     * si rileggono i permessi (quelli della rotta possono essere di prima di una revoca). Il permesso si guarda anche prima di inserire: senza, chi è stato revocato creerebbe una riga.
     *
     * @param  array<string, mixed>  $dati  `dal_esercizio` e gli altri campi già validati
     */
    public static function scrivi(User $utente, array $dati): void
    {
        $abilitato = function () use ($utente) {
            $utente->unsetRelation('assegnazioniAttive');
            abort_unless($utente->puo(Permesso::GestireImpostazioni), 403);
        };
        $abilitato();
        $creata = DB::table('impostazioni_associazione')->insertOrIgnore($dati + ['created_at' => now(), 'updated_at' => now()]) === 1;

        DB::transaction(function () use ($dati, $utente, $abilitato, $creata) {
            $riga = self::where('dal_esercizio', $dati['dal_esercizio'])->lockForUpdate()->firstOrFail();
            $abilitato();
            $prima = $creata ? null : $riga->istantanea();
            $riga->fill($dati)->save();
            $riga->refresh(); // il valore di partenza della soglia lo dà il database
            RegistroModifica::registra($utente, $riga, $prima === null ? 'creato' : 'modificato', $prima, $riga->istantanea());
        });
    }

    public function istantanea(): array
    {
        return $this->only(['dal_esercizio', 'denominazione', 'codice_fiscale', 'numero_runts', 'soglia_vicino']) + ['affiliazione_rete' => $this->affiliazione_rete ? 'sì' : 'no'];
    }
}
