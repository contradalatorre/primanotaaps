<?php

namespace App\Esercizio;

use App\Models\Conto;
use App\Models\DatiEsercizio;
use App\Models\EsercizioStorico;
use App\Models\ImpostazioniAssociazione;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Models\VersioneEsercizio;
use Illuminate\Validation\ValidationException;

/**
 * Il deposito al RUNTS (#60, #12, #18): dall'esercizio approvato si scarica il PDF/A e si inserisce la data dell'invio, che porta l'esercizio a *depositato*:
 * l'istantanea di ciò che il PDF stampa si salva sulla versione e da allora i prospetti si leggono solo da lì; i movimenti non si toccano più.
 *
 * Il PDF di deposito è il rendiconto approvato: parte dalla versione approvata (quello che l'assemblea ha visto), mai dai dati di oggi, che dopo l'approvazione possono
 * essere stati corretti. Così il file scaricato prima della data dell'invio e l'istantanea salvata dopo dicono le stesse cose. Per correggere dopo l'approvazione
 * si riapre l'esercizio (#59) e si approva di nuovo.
 */
final class Deposito
{
    /**
     * Tutto ciò che il PDF stampa, oltre alla versione approvata: è l'istantanea del deposito. Le impostazioni e la colonna dell'esercizio precedente si leggono al
     * momento (non sono nella versione approvata); da quel momento nessun cambiamento le sposta.
     */
    public static function ingressi(int $anno, VersioneEsercizio $versione): array
    {
        return [
            'esercizio' => $anno,
            'versione' => $versione->numero,
            'approvata_il' => $versione->approvata_il->toDateString(),
            'impostazioni' => ImpostazioniAssociazione::per($anno)?->only(['denominazione', 'codice_fiscale', 'numero_runts']) ?? [],
            'precedente' => EsercizioStorico::modelloD($anno - 1),
            'prospetti' => $versione->istantanea,
        ];
    }

    /**
     * La data dell'invio al RUNTS fissa l'esercizio. Si blocca la riga dei dati (lo stato si legge lì, quindi un doppio clic non deposita due volte), poi i conti e i
     * movimenti dell'anno, nell'ordine di `eliminaLogicamente` (conto, poi movimento): chi sta scrivendo un movimento finisce prima, chi arriva dopo trova l'esercizio
     * depositato (`DatiEsercizio::controllaNonDepositato`). La riga dei dati si blocca per prima, come fa `SaldiApertura::inserisci`, che poi blocca i conti.
     *
     * @param  string  $data  il giorno dell'invio («2027-04-25»): dall'approvazione a oggi
     */
    public static function deposita(User $utente, int $anno, string $data): VersioneEsercizio
    {
        return DatiEsercizio::sottoBlocco($utente, $anno, function (DatiEsercizio $dati) use ($utente, $anno, $data) {
            if ($dati->stato === 'depositato') {
                self::rifiuta('stato', "L'esercizio $anno è già depositato: per cambiarlo lo si riapre, e la nuova approvazione è la versione ".($dati->versione + 1).'.');
            }
            if ($dati->stato !== 'approvato') {
                self::rifiuta('stato', "L'esercizio $anno non è approvato: si deposita il rendiconto approvato dall'assemblea.");
            }
            $versione = VersioneEsercizio::where('esercizio', $anno)->where('numero', $dati->versione)->lockForUpdate()->firstOrFail();
            if ($data < $versione->approvata_il->toDateString() || $data > today()->toDateString()) {
                self::rifiuta('data', "La data dell'invio va dall'approvazione ({$versione->approvata_il->format('d/m/Y')}) a oggi.");
            }

            Conto::orderBy('id')->lockForUpdate()->get(['id']);
            Movimento::whereBetween('data', ["$anno-01-01", "$anno-12-31"])->orderBy('id')->lockForUpdate()->get(['id']);

            $versione->forceFill(['depositata_il' => $data, 'depositata_da' => $utente->id, 'istantanea_deposito' => self::ingressi($anno, $versione)])->save();
            $dati->forceFill(['stato' => 'depositato'])->save();
            RegistroModifica::create([
                'user_id' => $utente->id, 'oggetto_tipo' => 'esercizio', 'oggetto_id' => $anno, 'azione' => 'depositato',
                'prima' => ['stato' => 'approvato', 'versione' => $versione->numero], 'dopo' => ['stato' => 'depositato', 'versione' => $versione->numero, 'depositata_il' => $data],
            ]);

            return $versione;
        });
    }

    private static function rifiuta(string $campo, string $messaggio): never
    {
        throw ValidationException::withMessages([$campo => $messaggio]);
    }
}
