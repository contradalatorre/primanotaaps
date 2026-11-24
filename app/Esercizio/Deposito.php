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
use Illuminate\Support\Carbon;
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
            'precedente' => self::precedente($anno),
            'prospetti' => $versione->istantanea,
        ];
    }

    /**
     * Il Modello D dell'esercizio prima, per la sua colonna (#90, #18): dall'istantanea del suo deposito, altrimenti dalla sua versione approvata vigente (quella che
     * l'assemblea ha visto, non i dati di oggi), altrimenti dallo storico caricato dal foglio (#63); null se non c'è nessuna delle tre.
     */
    public static function precedente(int $anno): ?array
    {
        $prima = $anno - 1;

        return self::salvata($prima)['prospetti']['modello_d']
            ?? (DatiEsercizio::per($prima)->approvato() ? VersioneEsercizio::vigente($prima)?->istantanea['modello_d'] : null)
            ?? EsercizioStorico::modelloD($prima);
    }

    /**
     * Ciò che il PDF di deposito stampa: l'istantanea salvata se l'esercizio è depositato, altrimenti quella che si salverebbe adesso dalla versione approvata;
     * null se l'esercizio non è approvato (non c'è un rendiconto da depositare).
     */
    public static function istantanea(int $anno): ?array
    {
        $versione = DatiEsercizio::per($anno)->approvato() ? VersioneEsercizio::vigente($anno) : null;

        return $versione === null ? null : ($versione->istantanea_deposito ?? self::ingressi($anno, $versione));
    }

    /**
     * L'impronta di ciò che il PDF di deposito stampa adesso: otto cifre esadecimali, stampate nel piede del PDF (`piede`). Chi deposita la riscrive nel modulo della data
     * dell'invio e `deposita` la confronta con quella di adesso: se le impostazioni o la colonna dell'esercizio precedente sono cambiate dopo che il PDF è stato scaricato,
     * l'istantanea salvata sarebbe diversa dal file firmato e inviato, e il modulo si rifiuta (si riscarica il PDF).
     */
    public static function impronta(int $anno): ?string
    {
        $istantanea = self::istantanea($anno);

        return $istantanea === null ? null : self::improntaDi($istantanea);
    }

    /** Le chiavi si ordinano (le liste no): riletta dalla colonna JSON, l'istantanea salvata ha le chiavi in un altro ordine, e il piede non deve cambiare. */
    private static function improntaDi(array $istantanea): string
    {
        $ordina = function (array $a) use (&$ordina): array {
            array_is_list($a) || ksort($a, SORT_STRING);

            return array_map(fn ($v) => is_array($v) ? $ordina($v) : $v, $a);
        };

        return strtoupper(substr(sha1(json_encode($ordina($istantanea))), 0, 8));
    }

    /** Il piede del PDF di deposito: l'esercizio, l'approvazione e l'impronta da riscrivere nel modulo. */
    public static function piede(array $istantanea): string
    {
        $approvazione = 'approvato il '.Carbon::parse($istantanea['approvata_il'])->format('d/m/Y').' · versione '.$istantanea['versione'];

        return 'Esercizio '.$istantanea['esercizio'].' · '.$approvazione.' · impronta '.self::improntaDi($istantanea);
    }

    /** L'istantanea del deposito, solo se l'esercizio è depositato: da lì si leggono i prospetti (#12, #18). */
    public static function salvata(int $anno): ?array
    {
        return DatiEsercizio::per($anno)->stato === 'depositato' ? VersioneEsercizio::vigente($anno)?->istantanea_deposito : null;
    }

    /**
     * I documenti del PDF di deposito, nell'ordine in cui si stampano: il Modello D e un allegato per ogni raccolta fondi occasionale (rendiconto e relazione, #61).
     * Le istantanee di prima delle raccolte non hanno la chiave e stampano il solo Modello D.
     *
     * @return list<array{titolo: string, sezioni: list<array>}>
     */
    public static function documenti(array $istantanea): array
    {
        $anno = $istantanea['esercizio'];
        $documenti = [['titolo' => 'Modello D · Rendiconto per cassa '.$anno, 'sezioni' => Tabelle::modelloD($istantanea['prospetti']['modello_d'], $istantanea['precedente'], $anno)]];
        foreach ($istantanea['prospetti']['raccolte']['raccolte'] ?? [] as $raccolta) {
            $documenti[] = ['titolo' => 'Raccolta fondi occasionale · '.$raccolta['nome'].' · '.$anno, 'sezioni' => Tabelle::raccolta($raccolta, $anno)];
        }

        return $documenti;
    }

    /**
     * Il PDF/A-1b: indice in prima pagina, il Modello D e gli allegati delle raccolte occasionali. Né l'ora né nulla che cambi da un'esecuzione all'altra:
     * il file è funzione dell'istantanea, quindi quello scaricato prima dell'invio e quello di dopo dicono la stessa cosa. Nessun nome di persona (storia 143).
     * Con le raccolte che non quadrano con C2 o senza la motivazione richiesta non c'è un PDF da depositare (422): l'approvazione lo impedisce già, qui si difende il file.
     */
    public static function pdf(array $istantanea): string
    {
        abort_unless(($istantanea['prospetti']['raccolte']['bloccanti'] ?? []) === [], 422, 'Le raccolte fondi occasionali non sono pronte per il deposito: '.implode(' ', $istantanea['prospetti']['raccolte']['bloccanti'] ?? []));
        $anno = $istantanea['esercizio'];
        $impostazioni = $istantanea['impostazioni'];
        $approvazione = 'Approvato il '.Carbon::parse($istantanea['approvata_il'])->format('d/m/Y').' · versione '.$istantanea['versione'];
        $intestazione = [
            ($impostazioni['denominazione'] ?? '') ?: 'Denominazione da inserire',
            'Codice fiscale '.(($impostazioni['codice_fiscale'] ?? '') ?: 'da inserire').' · numero RUNTS '.(($impostazioni['numero_runts'] ?? '') ?: 'da inserire'),
            'Rendiconto per cassa '.$anno.' · deposito al RUNTS',
            $approvazione,
        ];

        return Pdf::deposito('Rendiconto per cassa '.$anno.' · deposito al RUNTS', $intestazione, self::documenti($istantanea), self::piede($istantanea));
    }

    /**
     * La data dell'invio al RUNTS fissa l'esercizio. Si blocca la riga dei dati (lo stato si legge lì, quindi un doppio clic non deposita due volte), poi i conti e i
     * movimenti dell'anno, nell'ordine di `eliminaLogicamente` (conto, poi movimento): chi sta scrivendo un movimento finisce prima, chi arriva dopo trova l'esercizio
     * depositato (`DatiEsercizio::controllaNonDepositato`). La riga dei dati si blocca per prima, come fa `SaldiApertura::inserisci`, che poi blocca i conti.
     *
     * @param  string  $data  il giorno dell'invio («2027-04-25»): dall'approvazione a oggi
     * @param  string  $impronta  quella stampata nel piede del PDF scaricato (`impronta`), riscritta da chi deposita: se non coincide con quella di adesso, si riscarica
     */
    public static function deposita(User $utente, int $anno, string $data, string $impronta): VersioneEsercizio
    {
        return DatiEsercizio::sottoBlocco($utente, $anno, function (DatiEsercizio $dati) use ($utente, $anno, $data, $impronta) {
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

            // Il PDF firmato e inviato porta «da inserire» al loro posto, e dopo il deposito non si rifà.
            $impostazioni = ImpostazioniAssociazione::per($anno);
            if (blank($impostazioni?->codice_fiscale) || blank($impostazioni?->numero_runts)) {
                self::rifiuta('impostazioni', "Mancano il codice fiscale o il numero RUNTS nelle impostazioni dell'associazione per il $anno: completali e riscarica il PDF di deposito.");
            }

            if (! hash_equals((string) self::impronta($anno), strtoupper(trim($impronta)))) {
                self::rifiuta('impronta', 'L’impronta non è quella del PDF di deposito di adesso: o l’hai scritta male, o le impostazioni sono cambiate dopo che hai scaricato il PDF. Riscarica il PDF (è quello da firmare e inviare) e riscrivi l’impronta del suo piede.');
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
