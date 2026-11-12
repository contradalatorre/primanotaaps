<?php

namespace App;

use App\Models\Imputazione;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le forzature della natura commerciale (glossario: Forzatura; #52): chi può, la motivazione, il valore calcolato che resta
 * visibile, la fotografia dei dati da cui dipendeva il calcolo e il registro delle modifiche. Il calcolo è `App\Esercizio\Natura`.
 * Una forzatura sta sull'imputazione e `Classificazione` la riporta sulla riga nuova quando si riclassifica.
 */
final class Forzature
{
    public const MOTIVAZIONE_MASSIMO = 1000;

    /**
     * Forza (o, se c'è già, riconferma con i dati di oggi e la nuova motivazione) la natura di una o più imputazioni.
     * Tutto o niente: se una sola non si può forzare non si scrive nulla.
     *
     * @param  list<int>  $ids
     *
     * @throws ValidationException sotto `imputazioni`
     */
    public static function forza(User $utente, array $ids, bool $commerciale, string $motivazione): void
    {
        self::applica($utente, $ids, function (Imputazione $i) use ($utente, $commerciale, $motivazione) {
            $natura = $i->natura();
            $descrizione = $i->movimento->descrizione.' ('.Euro::formatta($i->importo).' €)';
            if ($natura->regola === 'uscita') {
                return "$descrizione: è un'uscita, la natura commerciale riguarda le entrate.";
            }
            // Una forzatura è una difformità dalle regole (glossario). Fa eccezione il corrispettivo dell'area A: il test 2-bis può cambiarne il calcolo, la forzatura lo tiene fermo.
            if ($natura->commerciale === $commerciale && ! $natura->dipendeDal2bis) {
                return "$descrizione: è già così per le regole, non serve forzarlo.";
            }
            $i->forceFill([
                'natura_forzata' => $commerciale, 'forzatura_motivazione' => $motivazione, 'forzatura_da' => $utente->id,
                'forzatura_il' => now(), 'forzatura_ingressi' => $i->ingressiNatura(),
            ])->save();

            return true;
        }, 'natura_forzata', $motivazione);
    }

    /**
     * Toglie la forzatura: torna il valore calcolato. Chi non è forzata si salta; se nessuna lo è, è un errore.
     *
     * @param  list<int>  $ids
     *
     * @throws ValidationException sotto `imputazioni`
     */
    public static function togli(User $utente, array $ids): void
    {
        self::applica($utente, $ids, function (Imputazione $i) {
            if (! $i->forzata()) {
                return false;
            }
            $i->forceFill(['natura_forzata' => null, 'forzatura_motivazione' => null, 'forzatura_da' => null, 'forzatura_il' => null, 'forzatura_ingressi' => null])->save();

            return true;
        }, 'forzatura_tolta', null);
    }

    /** Le imputazioni forzate di un esercizio, per l'elenco e per il conteggio da rivedere. @return Collection<int, Imputazione> */
    public static function dell(int $anno): Collection
    {
        return Imputazione::whereNotNull('natura_forzata')
            ->whereHas('movimento', fn ($m) => $m->whereBetween('data', ["$anno-01-01", "$anno-12-31"]))
            ->with('movimento.conto', 'tipologia', 'forzataDa')->orderBy('id')->get();
    }

    /**
     * Il passo comune: blocca i movimenti, rilegge permessi e imputazioni a riga bloccata, applica `$scrivi` a ognuna
     * e mette una riga nel registro per ogni movimento cambiato.
     *
     * @param  list<int>  $ids
     * @param  callable(Imputazione): (string|bool)  $scrivi  l'errore, oppure se ha scritto
     */
    private static function applica(User $utente, array $ids, callable $scrivi, string $azione, ?string $motivazione): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        // Prima della transazione: nessuna lettura sotto lo snapshot prima del blocco (MySQL REPEATABLE READ).
        $movimentiIds = Imputazione::whereIn('id', $ids)->pluck('movimento_id')->unique()->sort()->values()->all();

        DB::transaction(function () use ($utente, $ids, $movimentiIds, $scrivi, $azione, $motivazione) {
            // Il blocco prima di ogni lettura, in ordine di id: due forzature insieme non si incrociano.
            $movimenti = Movimento::whereIn('id', $movimentiIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            // A riga bloccata i permessi si rileggono: quelli caricati dal controllo della rotta possono essere di prima di una revoca.
            abort_unless(Classificazione::conPermessiRiletti($utente)->can('importare_e_confermare'), 403);
            $imputazioni = Imputazione::whereIn('id', $ids)->with('movimento', 'tipologia')->orderBy('id')->get();
            // Le imputazioni si riscrivono a ogni classificazione: un id che non c'è più vuol dire che la pagina era vecchia.
            if ($ids === [] || $imputazioni->count() !== count($ids) || $imputazioni->contains(fn (Imputazione $i) => ! $movimenti->has($i->movimento_id))) {
                throw ValidationException::withMessages(['imputazioni' => 'Le imputazioni sono cambiate dopo che hai aperto la pagina: rileggila e riprova.']);
            }

            $prima = $movimenti->map->istantanea();
            $errori = [];
            $cambiati = [];
            foreach ($imputazioni as $i) {
                $esito = $scrivi($i);
                if (is_string($esito)) {
                    $errori[] = $esito;
                } elseif ($esito) {
                    $cambiati[$i->movimento_id] = true;
                }
            }
            if ($errori === [] && $cambiati === []) {
                $errori[] = 'Nessuna delle imputazioni scelte ha una forzatura da togliere.';
            }
            if ($errori !== []) {
                throw ValidationException::withMessages(['imputazioni' => $errori]); // la transazione torna indietro: niente a metà
            }

            foreach (array_keys($cambiati) as $movimentoId) {
                $movimento = $movimenti[$movimentoId];
                RegistroModifica::registra($utente, $movimento, $azione, $prima[$movimentoId], $movimento->istantanea(), $motivazione);
            }
        });
    }
}
