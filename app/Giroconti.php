<?php

namespace App;

use App\Mail\GirocontiOrfani;
use App\Models\Conto;
use App\Models\Giroconto;
use App\Models\ImportEstratto;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * I giroconti (#47; glossario: Giroconto): le regole del modulo di ogni formato (`regoleGiroconti`) riconoscono i movimenti che sono metà
 * di un trasferimento tra due conti dell'associazione, e l'app ne propone l'abbinamento (o la metà che manca); lo
 * conferma l'operatore nel passo Giroconti della revisione. Le proposte non si salvano: si ricalcolano ogni volta
 * dalle regole e dai movimenti, così una regola ritirata o un conto chiuso le fa sparire senza altro da fare.
 * Solo i giroconti confermati sono una riga (`Giroconto`).
 *
 * Un movimento è candidato finché non è confermato né già in un giroconto: la conferma di un giroconto toglie la
 * sua classificazione, quindi uno già confermato come entrata o uscita non si riconverte.
 */
final class Giroconti
{
    /**
     * La prima regola del formato del suo conto che riconosce il movimento, o null: nessuna se il conto è chiuso
     * (chiudere un conto spegne le sue regole). Con `conto` caricato.
     *
     * @return ?array<string,mixed> la regola con la sua `chiave`
     */
    public static function regolaDi(Movimento $movimento): ?array
    {
        return self::regolaPer($movimento->conto, $movimento->verso, $movimento->causale.' '.$movimento->descrizione);
    }

    /** Come `regolaDi`, per una riga che non è ancora un movimento (l'anteprima dell'import): causale e descrizione in un testo solo. */
    public static function regolaPer(Conto $conto, string $verso, string $testo): ?array
    {
        if ($conto->chiuso()) {
            return null;
        }
        foreach (ImportEstratto::lettorePer($conto)?->regoleGiroconti() ?? [] as $chiave => $regola) {
            if ($regola['verso'] === $verso && collect($regola['contiene'])->contains(fn (string $parola) => mb_stripos($testo, $parola) !== false)) {
                return $regola + ['chiave' => $chiave];
            }
        }

        return null;
    }

    /**
     * Le metà che `$regola` accetta per `$movimento`: un altro conto del tipo `con`, verso opposto, stesso importo, data
     * entro la finestra, ancora candidate, su un conto non chiuso. Il più vicino per data per primo.
     *
     * @return Collection<int, Movimento>
     */
    public static function controparti(Movimento $movimento, array $regola): Collection
    {
        return self::candidati()->with('conto')
            ->where('conto_id', '!=', $movimento->conto_id)->where('verso', $movimento->verso === 'uscita' ? 'entrata' : 'uscita')
            ->where('importo', $movimento->importo)
            ->whereBetween('data', [$movimento->data->copy()->subDays($regola['giorni'])->toDateString(), $movimento->data->copy()->addDays($regola['giorni'])->toDateString()])
            ->whereHas('conto', fn (Builder $c) => $c->where('tipo', $regola['con'])->whereNull('chiuso_il'))
            ->get()->sortBy(fn (Movimento $m) => [abs((int) $m->data->diffInDays($movimento->data)), $m->id])->values();
    }

    /**
     * Le metà già confermate come entrata o uscita che somigliano a quella che manca: non si abbinano (un confermato non si
     * riconverte) ma scriverne un'altra raddoppierebbe il conto.
     *
     * @return Collection<int, Movimento>
     */
    private static function confermatiSimili(Movimento $movimento, array $regola): Collection
    {
        return Movimento::senzaGiroconto()->where('stato', 'confermato')
            ->where('conto_id', '!=', $movimento->conto_id)->where('verso', $movimento->verso === 'uscita' ? 'entrata' : 'uscita')->where('importo', $movimento->importo)
            ->whereBetween('data', [$movimento->data->copy()->subDays($regola['giorni'])->toDateString(), $movimento->data->copy()->addDays($regola['giorni'])->toDateString()])
            ->whereHas('conto', fn (Builder $c) => $c->where('tipo', $regola['con']))->get();
    }

    /** I conti su cui si può scrivere la metà che manca: del tipo giusto, già aperti alla data del movimento e non chiusi (su un conto chiuso non si scrive, come a mano). */
    public static function contiPerLaMeta(Movimento $movimento, array $regola): Collection
    {
        // La cassa che si importa dal foglio non si completa da qui finché il foglio è in uso: la metà c'è già nel foglio, e scriverla due volte raddoppierebbe la cassa (#64).
        return Conto::where('tipo', $regola['con'])->where('aperto_il', '<=', $movimento->data->toDateString())->whereNull('chiuso_il')->orderBy('nome')->get()
            ->reject(fn (Conto $c) => $c->importaDalFoglio() && ! $c->foglioChiuso())->values();
    }

    /**
     * Le proposte di adesso, in ordine di data. Ogni movimento riconosciuto da una regola ha la sua, di tre tipi:
     * `coppia` (c'è già la metà, da confermare), `meta` (la regola sa scrivere quella che manca) o `attesa` (la metà
     * deve arrivare da un altro estratto). Un movimento già preso come metà di una proposta non ne apre un'altra.
     * `orfana`: senza metà da più di `giorni_per_orfana` giorni.
     *
     * shortcut: una query per movimento riconosciuto; sono quelli di cui nessuno ha ancora deciso, qualche decina, da ripensare se diventano migliaia.
     *
     * @return Collection<int, array{tipo: string, movimento: Movimento, controparte: ?Movimento, regola: array<string,mixed>, orfana: bool, giorni: int, conti: Collection<int, Conto>}>
     */
    public static function proposte(): Collection
    {
        $formati = array_keys(array_filter(ImportEstratto::LETTORI, fn (string $modulo) => app($modulo)->regoleGiroconti() !== []));
        $proposte = collect();
        $presi = [];
        $limite = today()->subDays(config('giroconti.giorni_per_orfana'));

        $riconosciuti = self::candidati()->with('conto')
            ->whereHas('conto', fn (Builder $c) => $c->whereNull('chiuso_il')->whereIn('formato_import', $formati))
            ->orderBy('data')->orderBy('id')->get();
        foreach ($riconosciuti as $movimento) {
            if (isset($presi[$movimento->id]) || ($regola = self::regolaDi($movimento)) === null) {
                continue;
            }
            $controparte = self::controparti($movimento, $regola)->first(fn (Movimento $m) => ! isset($presi[$m->id]));
            $presi[$movimento->id] = true;
            if ($controparte) {
                $presi[$controparte->id] = true;
            }
            $proposte->push([
                'tipo' => $controparte ? 'coppia' : (isset($regola['meta']) ? 'meta' : 'attesa'),
                'movimento' => $movimento, 'controparte' => $controparte, 'regola' => $regola,
                'orfana' => $controparte === null && $movimento->data->lte($limite),
                'giorni' => (int) $movimento->data->diffInDays(today()),
                'conti' => $controparte === null && isset($regola['meta']) ? self::contiPerLaMeta($movimento, $regola) : collect(),
            ]);
        }

        return $proposte;
    }

    /** Le metà senza corrispondenza da più di qualche giorno: quelle da segnalare. */
    public static function orfane(): Collection
    {
        return self::proposte()->where('orfana', true)->values();
    }

    /**
     * Segnala le metà orfane per email a chi può confermare i giroconti (`importare_e_confermare`, senza ambito), ogni
     * giorno finché ce ne sono. La posta giù non ferma niente: l'errore va nel log e gli altri destinatari restano.
     *
     * @return int quante email ha mandato
     */
    public static function segnalaOrfane(): int
    {
        $orfane = self::orfane();
        if ($orfane->isEmpty()) {
            return 0;
        }
        $inviate = 0;
        foreach (User::where('emergenza', false)->get()->filter(fn (User $u) => $u->puo(Permesso::ImportareEConfermare)) as $utente) {
            try {
                Mail::to($utente->email)->send(new GirocontiOrfani($orfane));
                $inviate++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $inviate;
    }

    /** Dove si abbina una metà: il passo Giroconti del suo import, o i movimenti del suo conto se l'ha scritta qualcuno a mano. */
    public static function urlPerAbbinare(Movimento $movimento): string
    {
        return $movimento->import_estratto_id
            ? route('import.revisione', [$movimento->import_estratto_id, 'passo' => 'giroconti'])
            : route('movimenti', ['conto' => $movimento->conto_id]);
    }

    /**
     * Conferma l'abbinamento di due movimenti: lo accetta solo se una regola lo propone ancora (rifatto a riga bloccata,
     * non quello che la pagina mostrava). Le due metà diventano confermate e perdono ogni classificazione. Chiude i dubbi
     * aperti che la scheda mostrava (`$dubbiVisti`, id delle note): uno nato dopo non lo ha visto nessuno e resta aperto.
     *
     * @param  list<int>  $dubbiVisti
     *
     * @throws ValidationException sotto `conferma`
     */
    public static function conferma(User $utente, int $idA, int $idB, array $dubbiVisti): Giroconto
    {
        return DB::transaction(function () use ($utente, $idA, $idB, $dubbiVisti) {
            [$a, $b] = self::blocca($utente, [$idA, $idB]);
            $regola = self::regolaDellaCoppia($a, $b);
            if ($regola === null) {
                throw ValidationException::withMessages(['conferma' => 'Questi due movimenti non si abbinano più: uno è già confermato o abbinato, oppure non c’è più una regola che li propone. Rileggi il passo Giroconti.']);
            }

            return self::abbina($utente, $a, $b, $regola, $dubbiVisti);
        });
    }

    /**
     * «Registra e conferma»: scrive sul conto scelto la metà che manca, a mano come la scriverebbe l'operatore, e
     * conferma il giroconto. Vale l'ambito di chi scrive sulla cassa. Se la metà esiste già non si scrive un'altra: la cassa non si raddoppia.
     * Per i dubbi vale come `conferma`.
     *
     * @param  list<int>  $dubbiVisti
     *
     * @throws ValidationException sotto `conferma`
     */
    public static function registraMeta(User $utente, int $movimentoId, int $contoId, array $dubbiVisti): Giroconto
    {
        return DB::transaction(function () use ($utente, $movimentoId, $contoId, $dubbiVisti) {
            [$movimento] = self::blocca($utente, [$movimentoId]);
            abort_unless($utente->puo(Permesso::InserireMovimentiCassa, $contoId), 403);
            // La proposta di adesso decide, non le sole controparti: una metà già presa da un altro prelievo non conta (#47).
            $proposta = self::proposte()->first(fn (array $p) => $p['movimento']->id === $movimento->id);
            $regola = $proposta['regola'] ?? null;
            $errore = match (true) {
                $proposta === null || $proposta['tipo'] === 'attesa' => 'Questo movimento non ha più una metà da registrare: è già confermato o abbinato, oppure la regola non c’è più. Rileggi il passo Giroconti.',
                $proposta['tipo'] === 'coppia' => 'La metà c’è già: abbinala invece di registrarne un’altra. Rileggi il passo Giroconti.',
                self::confermatiSimili($movimento, $regola)->isNotEmpty() => 'Sul conto c’è già un movimento confermato che sembra la metà (stesso importo, verso opposto, date vicine): la cassa si raddoppierebbe. Se è proprio la metà che manca, eliminalo (se l’hai scritto a mano) e riprova; se è solo una somiglianza, scrivi tu la metà a mano nella cassa e poi abbinala qui.',
                ! ($conto = $proposta['conti']->firstWhere('id', $contoId)) => 'Il conto scelto non va bene: dev’essere del tipo giusto, già aperto alla data del movimento e non chiuso.',
                default => null,
            };
            if ($errore !== null) {
                throw ValidationException::withMessages(['conferma' => $errore]);
            }

            $meta = (new Movimento)->forceFill([
                'conto_id' => $conto->id, 'data' => $movimento->data, 'importo' => $movimento->importo, 'verso' => $movimento->verso === 'uscita' ? 'entrata' : 'uscita',
                'descrizione' => str_replace('{conto}', $movimento->conto->nome, $regola['meta']), 'stato' => 'da_classificare', 'manuale' => true, 'inserito_da' => $utente->id,
            ]);
            $meta->save();
            $meta->setRelation('conto', $conto);
            RegistroModifica::registra($utente, $meta, 'inserito', null, $meta->istantanea(), 'Metà del giroconto con «'.$movimento->conto->nome.'», movimento n. '.$movimento->id);

            return self::abbina($utente, $movimento, $meta, $regola, $dubbiVisti);
        });
    }

    /**
     * Scioglie un giroconto confermato per errore: le due metà tornano da classificare (e, se una regola le propone
     * ancora, al passo Giroconti). Una metà scritta con «Registra e conferma» resta: si elimina come ogni movimento manuale.
     */
    public static function scioglie(User $utente, int $girocontoId): void
    {
        DB::transaction(function () use ($utente, $girocontoId) {
            Conto::orderBy('id')->lockForUpdate()->get(['id']);
            $giroconto = Giroconto::lockForUpdate()->findOrFail($girocontoId);
            $movimenti = self::bloccaMovimenti($utente, [$giroconto->uscita_id, $giroconto->entrata_id]);
            if ($movimenti->contains(fn (Movimento $m) => $m->conto->chiuso())) {
                throw ValidationException::withMessages(['conferma' => 'Una delle due metà è su un conto chiuso: il giroconto non si scioglie, perché la metà tornerebbe da classificare dove non si scrive più.']);
            }
            $giroconto->delete();
            foreach ($movimenti as $movimento) {
                $prima = $movimento->istantanea();
                $movimento->forceFill(['stato' => 'da_classificare', 'confermato_da' => null, 'confermato_il' => null])->save();
                RegistroModifica::registra($utente, $movimento, 'giroconto_sciolto', $prima, $movimento->istantanea(), 'Giroconto n. '.$giroconto->id.' sciolto');
            }
        });
    }

    /** @param list<int> $ids */
    private static function blocca(User $utente, array $ids): Collection
    {
        Conto::orderBy('id')->lockForUpdate()->get(['id']);

        return self::bloccaMovimenti($utente, $ids);
    }

    /**
     * Tutti i lock prima di qualunque lettura semplice (MySQL, REPEATABLE READ, fotografa alla prima): i conti in ordine
     * di id (come import, conferma e regole: tutte le scritture si mettono in fila), poi i movimenti in ordine di id. Solo
     * dopo si rilegge il permesso di chi scrive (le assegnazioni caricate dalla rotta possono essere di prima).
     * shortcut: si bloccano tutti i conti, non solo i due coinvolti, perché prima di leggere i movimenti non si sa quali sono; sono una decina.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Movimento> in ordine di id, con il conto
     */
    private static function bloccaMovimenti(User $utente, array $ids): Collection
    {
        $movimenti = Movimento::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        abort_unless($movimenti->count() === count(array_unique($ids)), 404);
        $utente->unsetRelation('assegnazioniAttive');
        abort_unless($utente->puo(Permesso::ImportareEConfermare), 403);

        return $movimenti->load('conto');
    }

    /** La regola con cui uno dei due propone l'altro, o null se non c'è più o uno dei due non è più libero. */
    private static function regolaDellaCoppia(Movimento $a, Movimento $b): ?array
    {
        if (self::candidati()->whereKey([$a->id, $b->id])->count() !== 2) {
            return null;
        }
        foreach ([[$a, $b], [$b, $a]] as [$movimento, $altro]) {
            $regola = self::regolaDi($movimento);
            if ($regola && self::controparti($movimento, $regola)->contains('id', $altro->id)) {
                return $regola;
            }
        }

        return null;
    }

    /** @param  list<int>  $dubbiVisti */
    private static function abbina(User $utente, Movimento $a, Movimento $b, array $regola, array $dubbiVisti): Giroconto
    {
        [$uscita, $entrata] = $a->verso === 'uscita' ? [$a, $b] : [$b, $a];
        $giroconto = Giroconto::create(['uscita_id' => $uscita->id, 'entrata_id' => $entrata->id, 'regola' => $regola['chiave'], 'confermato_da' => $utente->id, 'confermato_il' => now()]);
        foreach ([[$uscita, $entrata], [$entrata, $uscita]] as [$movimento, $altro]) {
            $prima = $movimento->istantanea();
            // Il giroconto non è classificato: la proposta di una persona, di una regola o dello storico non ha più senso.
            $movimento->imputazioni()->delete();
            $movimento->forceFill([
                'stato' => 'confermato', 'confermato_da' => $utente->id, 'confermato_il' => now(),
                'proposto_da' => null, 'regola_id' => null, 'storico_movimento_id' => null, 'storico_esempio_id' => null, 'conversione_causale_id' => null,
            ])->save();
            RegistroModifica::registra($utente, $movimento, 'giroconto_confermato', $prima, $movimento->istantanea(), 'Giroconto n. '.$giroconto->id.' con «'.$altro->conto->nome.'», movimento n. '.$altro->id);
            Note::chiudiDubbi($utente, $movimento, $dubbiVisti); // confermare scioglie il dubbio (#16), ma solo quello che la scheda mostrava
        }

        return $giroconto;
    }

    /** Movimenti ancora liberi: né confermati né in un giroconto, non eliminati. */
    private static function candidati(): Builder
    {
        return Movimento::senzaGiroconto()->where('stato', '!=', 'confermato');
    }
}
