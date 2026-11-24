<?php

namespace App\Esercizio;

use App\Models\DatiEsercizio;
use App\Models\EsercizioStorico;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Models\VersioneEsercizio;
use App\Permesso;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * L'approvazione dell'esercizio e la sua riapertura (#59, #12): aperto → approvato (salva la versione approvata dei prospetti e dei test e fissa il criterio
 * di secondarietà) → riaperto, con una nuova versione. Il deposito (#60) è un passaggio a parte.
 * Ogni scrittura passa dal blocco della riga dei dati dell'esercizio (`DatiEsercizio::sottoBlocco`): lo stato si legge sulla riga bloccata, così un doppio clic
 * trova l'esercizio già approvato e non ne scrive una seconda versione.
 */
final class Approvazione
{
    /**
     * @param  string  $data  il giorno della delibera dell'assemblea («2027-04-20»): dopo la fine dell'esercizio, non nel futuro
     */
    public static function approva(User $utente, int $anno, string $data): VersioneEsercizio
    {
        // Si bloccano anche i due esercizi prima, in ordine di anno: il rientro, la tolleranza del 6% e il confronto si leggono dalle loro versioni, e una riapertura
        // concorrente non deve cambiarle sotto la lettura (su MySQL, REPEATABLE READ, si legge dopo i blocchi).
        return DatiEsercizio::sottoBlocchi($utente, [$anno - 2, $anno - 1, $anno], function (array $tutti) use ($utente, $anno, $data) {
            $dati = $tutti[$anno];
            if ($dati->stato !== 'aperto') {
                self::rifiuta('stato', "L'esercizio $anno è già approvato: per cambiarlo lo si riapre, e l'approvazione successiva è la versione ".($dati->versione + 1).'.');
            }
            if (EsercizioStorico::where('esercizio', $anno)->exists()) {
                self::rifiuta('stato', "L'esercizio $anno è storico: si carica dal foglio approvato, non si approva qui.");
            }
            if (! DB::table('voci_modello_d')->where('esercizio', $anno)->exists()) {
                self::rifiuta('stato', "Per il $anno la tabella delle voci del Modello D non è caricata: non c'è un prospetto da approvare.");
            }
            // Il rientro e la tolleranza del 6% si leggono dall'esito approvato dell'esercizio prima: approvare questo con l'altro ancora aperto farebbe saltare il rientro
            // (il precedente approvato dopo non cambierebbe più la versione già misurata al 30% pieno). Un esercizio prima che non c'è (nessuna tabella delle voci) non si aspetta.
            if (! $tutti[$anno - 1]->approvato() && DB::table('voci_modello_d')->where('esercizio', $anno - 1)->exists() && ! EsercizioStorico::where('esercizio', $anno - 1)->exists()) {
                self::rifiuta('stato', 'Prima di approvare il '.$anno.' va approvato il '.($anno - 1).': il rientro dopo una secondarietà non rispettata e la tolleranza del 6% si misurano sul suo esito approvato.');
            }
            if ($data < ($anno + 1).'-01-01' || $data > today()->toDateString()) {
                self::rifiuta('data', 'La data della delibera va dal 1° gennaio '.($anno + 1).' a oggi.');
            }

            $istantanea = Verifiche::istantanea($anno);
            // Il PDF di deposito è il rendiconto approvato (#60): se le raccolte fondi non quadrano con C2 o manca una motivazione, non si potrebbe produrre, e correggere dopo vuol dire riaprire.
            if ($istantanea['raccolte']['bloccanti'] !== []) {
                self::rifiuta('raccolte', 'Le raccolte fondi occasionali non sono pronte per il deposito: '.implode(' ', $istantanea['raccolte']['bloccanti']));
            }
            $s = $istantanea['secondarieta'];
            $criterio = $s['confermato'] ?? $s['proposto']; // il confermato, altrimenti quello che l'app propone: da qui è fissato
            $prima = $dati->istantanea();
            $dati->forceFill(['criterio_secondarieta' => $criterio, 'stato' => 'approvato'])->save();
            $istantanea['secondarieta']['confermato'] = $criterio;

            $esito = $s['criteri'][$criterio]['esito'];
            $versione = VersioneEsercizio::create([
                'esercizio' => $anno, 'numero' => $dati->versione, 'approvata_il' => $data, 'approvata_da' => $utente->id,
                'criterio_secondarieta' => $criterio, 'esito_secondarieta' => $esito,
                'soglia_rientro_centesimi_di_punto' => $esito === 'non_rispettato' ? Secondarieta::sogliaDiRientro($s['criteri'][$criterio]) : null,
                'stato_2bis' => $istantanea['catena']['areaA']['stato'], 'istantanea' => $istantanea,
            ]);
            self::registra($utente, $anno, 'approvato', ['stato' => 'aperto', 'versione' => $dati->versione, 'criterio_secondarieta' => $prima['criterio_secondarieta']],
                ['stato' => 'approvato', 'versione' => $dati->versione, 'approvata_il' => $data, 'criterio_secondarieta' => $criterio]);

            return $versione;
        });
    }

    /**
     * Com'è l'esercizio per chi guarda un prospetto: aperto (provvisorio) oppure approvato, con la versione vigente e le differenze dalla versione approvata
     * (solo le imputazioni confermate, come l'approvazione). Per un esercizio aperto non si calcola nulla.
     *
     * @return array{versione: ?VersioneEsercizio, numero: int, differenze: ?array{voci: list<array>, esiti: list<array>, rendiconto: list<array>}}
     */
    public static function situazione(int $anno): array
    {
        $dati = DatiEsercizio::per($anno);
        $versione = $dati->approvato() ? VersioneEsercizio::vigente($anno) : null;
        // Un esercizio depositato si legge dall'istantanea (#60): non c'è niente da confrontare.
        $differenze = $versione === null || $versione->depositata_il !== null ? null : Differenze::tra(Differenze::impronta($versione->istantanea), Differenze::impronta(Verifiche::istantanea($anno)));

        return ['versione' => $versione, 'numero' => $dati->versione, 'differenze' => $differenze];
    }

    /**
     * Riaprire un esercizio approvato (solo chi ha `riaprire_esercizio`, l'Amministratore): la versione precedente resta nello storico e la nuova approvazione sarà la successiva.
     * Si riapre in ordine inverso: con l'anno dopo ancora approvato, correggere questo cambierebbe l'esito da cui quello ha misurato rientro e tolleranza, senza che nessuno se ne accorga.
     */
    public static function riapri(User $utente, int $anno, string $motivazione): void
    {
        // Si bloccano anche l'anno dopo, in ordine di anno (come `approva`): non deve venire approvato mentre si riapre questo.
        DatiEsercizio::sottoBlocchi($utente, [$anno, $anno + 1], function (array $tutti) use ($utente, $anno, $motivazione) {
            $dati = $tutti[$anno];
            if (! $dati->approvato()) {
                self::rifiuta('stato', "L'esercizio $anno non è approvato: non c'è niente da riaprire.");
            }
            if ($tutti[$anno + 1]->approvato()) {
                self::rifiuta('stato', 'Prima di riaprire il '.$anno.' va riaperto il '.($anno + 1).': il suo rientro e la sua tolleranza del 6% si misurano sull\'esito approvato del '.$anno.'.');
            }
            $versione = VersioneEsercizio::where('esercizio', $anno)->where('numero', $dati->versione)->lockForUpdate()->firstOrFail();
            $statoPrima = $dati->stato;
            $versione->forceFill(['riaperta_il' => now(), 'riaperta_da' => $utente->id, 'motivazione_riapertura' => $motivazione])->save();
            $dati->forceFill(['stato' => 'aperto', 'versione' => $dati->versione + 1])->save();
            self::registra($utente, $anno, 'riaperto', ['stato' => $statoPrima, 'versione' => $versione->numero], ['stato' => 'aperto', 'versione' => $dati->versione], $motivazione);
        }, Permesso::RiaprireEsercizio);
    }

    /** L'invio della segnalazione al RUNTS (art. 4 DM 107/2021): la data e, se c'è, il protocollo. Per l'ultima versione approvata con la secondarietà non rispettata:
     * anche se poi riaperta e approvata di nuovo con un altro esito, perché l'obbligo nato con un esito approvato non si dimentica (come il rientro, `Verifiche::rientro`). */
    public static function segnala(User $utente, int $anno, string $data, ?string $protocollo): void
    {
        DatiEsercizio::sottoBlocco($utente, $anno, function () use ($utente, $anno, $data, $protocollo) {
            $versione = VersioneEsercizio::where('esercizio', $anno)->where('esito_secondarieta', 'non_rispettato')->orderByDesc('numero')->lockForUpdate()->first();
            if ($versione === null) {
                self::rifiuta('stato', "Per l'esercizio $anno non c'è una segnalazione da registrare.");
            }
            if ($data < $versione->approvata_il->toDateString() || $data > today()->toDateString()) {
                self::rifiuta('data', "La data dell'invio va dall'approvazione ({$versione->approvata_il->format('d/m/Y')}) a oggi.");
            }
            $prima = ['segnalazione_inviata_il' => $versione->segnalazione_inviata_il?->toDateString(), 'segnalazione_protocollo' => $versione->segnalazione_protocollo];
            $versione->forceFill(['segnalazione_inviata_il' => $data, 'segnalazione_protocollo' => $protocollo])->save();
            self::registra($utente, $anno, 'segnalazione_runts', $prima, ['segnalazione_inviata_il' => $data, 'segnalazione_protocollo' => $protocollo]);
        });
    }

    private static function registra(User $utente, int $anno, string $azione, array $prima, array $dopo, ?string $motivazione = null): void
    {
        RegistroModifica::create([
            'user_id' => $utente->id, 'oggetto_tipo' => 'esercizio', 'oggetto_id' => $anno, 'azione' => $azione, 'prima' => $prima, 'dopo' => $dopo, 'motivazione' => $motivazione,
        ]);
    }

    private static function rifiuta(string $campo, string $messaggio): never
    {
        throw ValidationException::withMessages([$campo => $messaggio]);
    }
}
