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
        return DatiEsercizio::sottoBlocco($utente, $anno, function (DatiEsercizio $dati) use ($utente, $anno, $data) {
            if ($dati->stato !== 'aperto') {
                self::rifiuta('stato', "L'esercizio $anno è già approvato: per cambiarlo lo si riapre, e l'approvazione successiva è la versione ".($dati->versione + 1).'.');
            }
            if (EsercizioStorico::where('esercizio', $anno)->exists()) {
                self::rifiuta('stato', "L'esercizio $anno è storico: si carica dal foglio approvato, non si approva qui.");
            }
            if (! DB::table('voci_modello_d')->where('esercizio', $anno)->exists()) {
                self::rifiuta('stato', "Per il $anno la tabella delle voci del Modello D non è caricata: non c'è un prospetto da approvare.");
            }
            if ($data < ($anno + 1).'-01-01' || $data > today()->toDateString()) {
                self::rifiuta('data', 'La data della delibera va dal 1° gennaio '.($anno + 1).' a oggi.');
            }

            $istantanea = Verifiche::istantanea($anno);
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

    /** Riaprire un esercizio approvato (solo chi ha `riaprire_esercizio`, l'Amministratore): la versione precedente resta nello storico e la nuova approvazione sarà la successiva. */
    public static function riapri(User $utente, int $anno, string $motivazione): void
    {
        DatiEsercizio::sottoBlocco($utente, $anno, function (DatiEsercizio $dati) use ($utente, $anno, $motivazione) {
            if (! $dati->approvato()) {
                self::rifiuta('stato', "L'esercizio $anno non è approvato: non c'è niente da riaprire.");
            }
            $versione = VersioneEsercizio::where('esercizio', $anno)->where('numero', $dati->versione)->lockForUpdate()->firstOrFail();
            $statoPrima = $dati->stato;
            $versione->forceFill(['riaperta_il' => now(), 'riaperta_da' => $utente->id, 'motivazione_riapertura' => $motivazione])->save();
            $dati->forceFill(['stato' => 'aperto', 'versione' => $dati->versione + 1])->save();
            self::registra($utente, $anno, 'riaperto', ['stato' => $statoPrima, 'versione' => $versione->numero], ['stato' => 'aperto', 'versione' => $dati->versione], $motivazione);
        }, Permesso::RiaprireEsercizio);
    }

    /** L'invio della segnalazione al RUNTS (art. 4 DM 107/2021): la data e, se c'è, il protocollo. Solo per una versione vigente con la secondarietà non rispettata. */
    public static function segnala(User $utente, int $anno, string $data, ?string $protocollo): void
    {
        DatiEsercizio::sottoBlocco($utente, $anno, function (DatiEsercizio $dati) use ($utente, $anno, $data, $protocollo) {
            $versione = $dati->approvato() ? VersioneEsercizio::where('esercizio', $anno)->where('numero', $dati->versione)->lockForUpdate()->first() : null;
            if ($versione === null || $versione->esito_secondarieta !== 'non_rispettato') {
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
