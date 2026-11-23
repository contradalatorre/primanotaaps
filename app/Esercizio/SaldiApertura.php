<?php

namespace App\Esercizio;

use App\Euro;
use App\Models\ContaDiCassa;
use App\Models\Conto;
use App\Models\DatiEsercizio;
use App\Models\EsercizioStorico;
use App\Models\ImportEstratto;
use App\Models\RegistroModifica;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * La procedura di apertura (#63, #19): i saldi dei conti al 1/1 dell'esercizio, inseriti una sola volta. Precompilati dalla chiusura dell'esercizio
 * storico precedente, confrontati con il primo estratto e con la conta di cassa, e controllati: la somma dei saldi iniziali di ogni riga del
 * Modello D (cassa, depositi) deve coincidere con il saldo finale dell'esercizio storico. Dagli esercizi dopo l'apertura sarà la chiusura dell'anno prima.
 *
 * Sono dell'apertura i conti aperti proprio il 1/1; un conto aperto in seguito si crea con il suo saldo di partenza. Un conto aperto prima del 1/1 non ha posto qui
 * (il Modello D ne conterebbe il saldo lo stesso): ferma l'apertura, e dopo l'apertura non si crea né ci si sposta (`ContiController`).
 * shortcut: il foglio dà solo cassa e depositi aggregati, quindi i depositi con più conti (banca, PayPal, Satispay) non si precompilano e si
 * controllano in somma; se il foglio 2025 avrà il saldo finale per conto (le schede), si potrà precompilare e controllare conto per conto.
 */
final class SaldiApertura
{
    /** @return Collection<int, Conto> */
    public static function conti(int $anno): Collection
    {
        return Conto::where('aperto_il', "$anno-01-01")->orderBy('nome')->get();
    }

    public static function fatta(int $anno): bool
    {
        return DatiEsercizio::per($anno)->saldi_apertura_il !== null;
    }

    /** @return ?array{cassa: int, depositi: int} la chiusura dell'esercizio storico precedente, o null se non è stato caricato */
    public static function chiusura(int $anno): ?array
    {
        $storico = EsercizioStorico::where('esercizio', $anno - 1)->first();

        return $storico ? ['cassa' => $storico->saldo_cassa, 'depositi' => $storico->saldo_depositi] : null;
    }

    /**
     * I saldi iniziali dei conti dell'apertura, sommati per riga del Modello D.
     *
     * @return array<string, int> riga → centesimi
     */
    public static function iniziali(int $anno, ?int $escludi = null): array
    {
        $somme = array_fill_keys(array_keys(Conto::RIGHE_MODELLO_D), 0);
        foreach (self::conti($anno) as $conto) {
            $conto->id === $escludi || $somme[$conto->riga_modello_d] = ($somme[$conto->riga_modello_d] ?? 0) + $conto->saldo_iniziale;
        }

        return $somme;
    }

    /**
     * Dove i saldi iniziali non coincidono con i saldi finali dell'esercizio storico precedente, riga per riga. Vuoto se coincidono o se lo storico non c'è.
     *
     * @param  array<string, int>  $iniziali  riga → centesimi
     * @return list<string>
     */
    public static function scostamenti(int $anno, array $iniziali): array
    {
        $errori = [];
        foreach (self::chiusura($anno) ?? [] as $riga => $finale) {
            if (($iniziali[$riga] ?? 0) !== $finale) {
                $errori[] = Conto::RIGHE_MODELLO_D[$riga].': saldi iniziali '.Euro::formatta($iniziali[$riga] ?? 0).' €, chiusura '.($anno - 1).' '.Euro::formatta($finale).' €';
            }
        }

        return $errori;
    }

    /** Il controllo con la chiusura rifatto sui saldi di oggi, per la pagina di apertura e per quando lo storico arriva o cambia. Vuoto prima dell'apertura. */
    public static function scostamentiAttuali(int $anno): array
    {
        return self::fatta($anno) ? self::scostamenti($anno, self::iniziali($anno)) : [];
    }

    /**
     * Un conto che nasce o arriva al 1/1 dopo l'apertura è un secondo inserimento di saldi: passa dal controllo con la chiusura come se fosse nell'apertura
     * (senza storico, dove non c'è nulla con cui controllarlo, solo a saldo zero). Da chiamare a riga dei dati dell'esercizio bloccata.
     *
     * @throws ValidationException
     */
    public static function controllaConto(int $anno, string $riga, int $saldo, ?int $escludi = null): void
    {
        if (! self::fatta($anno)) {
            return;
        }
        $iniziali = self::iniziali($anno, $escludi);
        $iniziali[$riga] = ($iniziali[$riga] ?? 0) + $saldo;
        $errori = self::chiusura($anno) === null ? ($saldo !== 0 ? ['senza l’esercizio storico '.($anno - 1).' un saldo diverso da zero non si può controllare'] : []) : self::scostamenti($anno, $iniziali);
        if ($errori !== []) {
            throw ValidationException::withMessages(['saldo_iniziale' => "I saldi di apertura del $anno sono già inseriti: un conto al 1/1 si aggiunge solo se i saldi tornano con la chiusura (".implode('; ', $errori).').']);
        }
    }

    /**
     * Il saldo da proporre per ogni conto: quello dello storico se è l'unico conto della sua riga, altrimenti null (il saldo di oggi).
     *
     * @param  Collection<int, Conto>  $conti
     * @return array<int, ?int> conto → centesimi
     */
    public static function precompilati(Collection $conti, ?array $chiusura): array
    {
        $perRiga = $conti->countBy('riga_modello_d');

        return $conti->mapWithKeys(fn (Conto $c) => [$c->id => $chiusura !== null && $perRiga[$c->riga_modello_d] === 1 ? $chiusura[$c->riga_modello_d] : null])->all();
    }

    /**
     * Con che cosa confrontare il saldo: il saldo iniziale del primo estratto confermato di gennaio (banca, PayPal), la conta di cassa del 1/1 (contanti).
     *
     * @return array{estratto: ?array{saldo: int, dal: string}, conta: ?int}
     */
    public static function riscontri(Conto $conto, int $anno): array
    {
        $import = $conto->tipo === 'cassa' ? null : ImportEstratto::where('conto_id', $conto->id)->where('stato', 'confermato')->whereNotNull('saldo_iniziale')
            ->whereBetween('periodo_dal', ["$anno-01-01", "$anno-01-31"])->orderBy('periodo_dal')->first();
        $conta = $conto->tipo === 'cassa' ? ContaDiCassa::where('conto_id', $conto->id)->where('data', "$anno-01-01")->orderByDesc('id')->first() : null;

        return ['estratto' => $import ? ['saldo' => $import->saldo_iniziale, 'dal' => $import->periodo_dal->format('d/m/Y')] : null, 'conta' => $conta?->importo_contato];
    }

    /**
     * Scrive i saldi iniziali dei conti dell'apertura, una sola volta. A riga dei dati dell'esercizio bloccata (con il permesso riletto) e con i conti bloccati:
     * un secondo invio aspetta e trova l'apertura già fatta. Ogni conto cambia nel registro.
     *
     * @param  array<int, int>  $saldi  conto → centesimi: tutti e soli i conti dell'apertura
     */
    public static function inserisci(User $utente, int $anno, array $saldi): void
    {
        DatiEsercizio::sottoBlocco($utente, $anno, function (DatiEsercizio $dati) use ($utente, $anno, $saldi) {
            if ($dati->stato === 'depositato') { // prima dei conti: l'ordine dei blocchi è dati, poi conti
                throw ValidationException::withMessages(['stato' => "L'esercizio $anno è depositato: i suoi dati non si cambiano più. Per correggere un errore l'Amministratore lo riapre."]);
            }
            if ($dati->saldi_apertura_il !== null) {
                throw ValidationException::withMessages(['apertura' => "I saldi di apertura del $anno sono già inseriti: non si inseriscono due volte."]);
            }
            $conti = Conto::where('aperto_il', "$anno-01-01")->orderBy('id')->lockForUpdate()->get();
            if ($conti->pluck('id')->sort()->values()->all() !== collect(array_keys($saldi))->sort()->values()->all()) {
                throw ValidationException::withMessages(['saldi' => 'I conti da compilare sono cambiati: ricarica la pagina e riprova.']);
            }

            // Il Modello D somma nei saldi iniziali anche i conti aperti prima del 1/1: senza una data corretta sfuggirebbero a questa procedura.
            if (Conto::where('aperto_il', '<', "$anno-01-01")->where(fn ($c) => $c->whereNull('chiuso_il')->orWhere('chiuso_il', '>=', "$anno-01-01"))->exists()) {
                throw ValidationException::withMessages(['apertura' => "Ci sono conti aperti prima del 1/1/$anno: la procedura vale per i conti aperti il 1/1, correggi la loro data di apertura."]);
            }

            $errori = self::scostamenti($anno, $conti->groupBy('riga_modello_d')->map(fn (Collection $c) => $c->sum(fn (Conto $conto) => $saldi[$conto->id]))->all());
            if ($errori !== []) {
                throw ValidationException::withMessages(['apertura' => 'I saldi iniziali del '.$anno.' devono coincidere con i saldi finali del '.($anno - 1).' ('.implode('; ', $errori).').']);
            }

            foreach ($conti as $conto) {
                $prima = $conto->istantanea();
                $conto->update(['saldo_iniziale' => $saldi[$conto->id]]);
                RegistroModifica::registra($utente, $conto, 'modificato', $prima, $conto->istantanea(), "Apertura dell'esercizio $anno");
            }
            $dati->update(['saldi_apertura_il' => now()]);
        });
    }
}
