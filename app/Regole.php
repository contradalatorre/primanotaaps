<?php

namespace App;

use App\Models\Attivita;
use App\Models\Conto;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\Regola;
use App\Models\RegistroModifica;
use App\Models\Tag;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Le regole del tesoriere (#42): lettura e controllo del modulo, ordine, anteprima e applicazione ai movimenti.
 * Vince la prima regola della lista che corrisponde. Una regola propone solo su movimenti *da classificare* o già
 * proposti da una regola (`movimenti.regola_id`) o dallo storico (#43, `App\Storico`, che viene dopo le regole nella
 * cascata): mai sui confermati né su una proposta scritta da una persona, che è una scelta a mano. Le proposte si
 * rifanno da zero a ogni salvataggio o riordino, così una regola nuova, modificata o spostata toglie anche quello che
 * non è più suo, e dove non ce ne sono resta quella dello storico.
 *
 * Le transazioni bloccano prima i conti (come import e conferma: un import in corso aspetta, e ne vede le regole nuove),
 * poi tutti i candidati in ordine di id, e solo dopo leggono altro: su MySQL (REPEATABLE READ) la prima lettura semplice
 * fissa la fotografia, e a riga bloccata si decide sui dati correnti.
 */
final class Regole
{
    /** @return Collection<int, Regola> in ordine di priorità, con quello che serve a confrontarle e mostrarle */
    public static function elenco(): Collection
    {
        return Regola::with('conto', 'tipologia', 'iniziativa', 'attivita', 'tag')->orderBy('posizione')->orderBy('id')->get();
    }

    /** La prima regola della lista che corrisponde al movimento. */
    public static function vincitrice(Collection $regole, Movimento $movimento): ?Regola
    {
        return $regole->first(fn (Regola $r) => $r->corrisponde($movimento));
    }

    /**
     * Legge il modulo di una regola. `$regola` è quella che si modifica: le sue scelte già archiviate si possono tenere.
     *
     * @return array{0: array<string,mixed>, 1: list<int>} gli attributi della regola e i suoi tag
     *
     * @throws ValidationException
     */
    public static function leggi(array $input, ?Regola $regola = null): array
    {
        $dati = Validator::make($input, [
            'nome' => ['required', 'string', 'max:255', Rule::unique('regole', 'nome')->ignore($regola?->id)],
            'conto_id' => ['nullable', 'integer', 'exists:conti,id'],
            'verso' => ['nullable', Rule::in(array_keys(Movimento::VERSI))],
            'causale' => ['nullable', 'string', 'max:255'],
            'testo' => ['nullable', 'string', 'max:255'],
            'importo_min' => ['nullable', 'string', 'max:30'],
            'importo_max' => ['nullable', 'string', 'max:30'],
            'valida_dal' => ['nullable', 'date_format:Y-m-d'],
            'valida_al' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valida_dal'],
            'tipologia_id' => ['nullable', 'integer', 'exists:tipologie,id'],
            'iniziativa_id' => ['nullable', 'integer', 'exists:iniziative,id'],
            'area' => ['nullable', Rule::enum(Area::class)],
            'attivita_id' => ['nullable', 'integer', 'exists:attivita,id'],
            'tipo_controparte' => ['nullable', Rule::in(array_keys(Regola::TIPI_CONTROPARTE))],
            'tag' => ['nullable', 'array', 'max:50'],
            'tag.*' => ['integer', 'exists:tag,id'],
        ], [
            'nome.unique' => 'C’è già una regola con questo nome.',
            'valida_al.after_or_equal' => 'La fine del periodo di validità non può venire prima dell’inizio.',
        ])->validate();

        $dati += array_fill_keys(['conto_id', 'verso', 'causale', 'testo', 'importo_min', 'importo_max', 'valida_dal', 'valida_al', 'tipologia_id', 'iniziativa_id', 'area', 'attivita_id', 'tipo_controparte'], null);
        $errori = [];
        $tag = array_map('intval', $dati['tag'] ?? []);
        unset($dati['tag']);
        foreach (['importo_min', 'importo_max'] as $campo) {
            if ($dati[$campo] !== null) {
                $centesimi = Euro::daTesto($dati[$campo]);
                if ($centesimi === null || $centesimi < 0) {
                    $errori[$campo] = 'Scrivi l’importo in euro, con al più due decimali (es. 12,50).';
                } else {
                    $dati[$campo] = $centesimi;
                }
            }
        }
        if (is_int($dati['importo_min']) && is_int($dati['importo_max']) && $dati['importo_min'] > $dati['importo_max']) {
            $errori['importo_max'] = 'L’importo massimo non può essere meno del minimo.';
        }
        $condizioni = array_filter(Arr::only($dati, ['conto_id', 'verso', 'causale', 'testo', 'importo_min', 'importo_max', 'valida_dal', 'valida_al']), fn ($v) => $v !== null);
        if ($condizioni === []) {
            $errori['condizioni'] = 'Indica almeno una condizione: una regola senza condizioni prenderebbe ogni movimento.';
        }

        $tipologia = ($dati['tipologia_id'] ?? null) ? Tipologia::find($dati['tipologia_id']) : null;
        $iniziativa = ($dati['iniziativa_id'] ?? null) ? Iniziativa::find($dati['iniziativa_id']) : null;
        $attivita = ($dati['attivita_id'] ?? null) ? Attivita::find($dati['attivita_id']) : null;
        // L'attività porta la sua area, se non se ne sceglie un'altra.
        $dati['area'] ??= $attivita?->area->value;
        if ($iniziativa === null && ($dati['area'] ?? null) === null) {
            $errori['iniziativa_id'] = 'Scegli l’iniziativa o l’area: senza non si può proporre una classificazione.';
        }
        if ($tipologia === null && $iniziativa === null && $attivita === null && ($dati['tipo_controparte'] ?? null) === null && $tag === []) {
            $errori['tipologia_id'] = 'Scegli almeno un effetto oltre all’area: tipologia, iniziativa, attività, socio o terzo, tag.';
        }
        if ($tipologia && ($dati['verso'] ?? null) && $tipologia->verso !== $dati['verso']) {
            $errori['tipologia_id'] = "«{$tipologia->nome}» è una tipologia di ".Tipologia::VERSI[$tipologia->verso].', ma la regola guarda le '.($dati['verso'] === 'entrata' ? 'entrate' : 'uscite').'.';
        }
        if ($attivita && ($dati['area'] ?? null) !== $attivita->area->value) {
            $errori['attivita_id'] = "L’attività «{$attivita->nome}» non è dell’area {$dati['area']}.";
        }
        // Un elemento archiviato non si sceglie più, ma una regola che lo ha già può tenerlo (e viene segnalata).
        $scelte = ['tipologia_id' => $tipologia, 'iniziativa_id' => $iniziativa, 'attivita_id' => $attivita];
        foreach ($scelte as $campo => $elemento) {
            if ($elemento?->archiviata() && $regola?->$campo !== $elemento->id) {
                $errori[$campo] = "«{$elemento->nome}» è archiviata: non si sceglie più.";
            }
        }
        foreach (Tag::whereIn('id', $tag)->get() as $t) {
            if ($t->archiviata() && ! $regola?->tag->contains($t)) {
                $errori['tag'] = "Il tag «{$t->nome}» è archiviato: non si sceglie più.";
            }
        }
        if ($errori !== []) {
            throw ValidationException::withMessages($errori);
        }

        return [$dati, $tag];
    }

    /**
     * Crea o modifica una regola (una nuova va in fondo all'elenco) e rifà le proposte.
     *
     * @return array{0: Regola, 1: int} la regola e quanti movimenti hanno cambiato proposta
     *
     * @throws ValidationException
     */
    public static function salva(User $utente, ?Regola $regola, array $input): array
    {
        [$attributi, $tag] = self::leggi($input, $regola?->loadMissing('tag'));

        return DB::transaction(function () use ($utente, $regola, $attributi, $tag) {
            $candidati = self::blocca($utente);
            $regola = $regola ? Regola::with('tag')->findOrFail($regola->id) : new Regola(['posizione' => (int) Regola::max('posizione') + 1]);
            $prima = $regola->exists ? $regola->istantanea() : null;
            $regola->fill($attributi)->save();
            $regola->tag()->sync($tag);
            $dopo = $regola->load('tag')->istantanea();
            RegistroModifica::registra($utente, $regola, $prima ? 'modificata' : 'creata', $prima, $dopo);
            // Rinominarla non cambia nessuna proposta: le sue si riscrivono solo se cambiano condizioni o effetti.
            $cambiata = $prima === null || Arr::except($prima, 'nome') !== Arr::except($dopo, 'nome');

            return [$regola, self::assegna($utente, $candidati, self::elenco(), $cambiata ? $regola->id : null, $prima['tipo_controparte'] ?? null)];
        });
    }

    /**
     * Mette le regole nell'ordine dato (tutte, una volta ciascuna) e rifà le proposte.
     *
     * @param  list<int>  $ordine gli id delle regole, dalla prima
     * @return int quanti movimenti hanno cambiato proposta
     *
     * @throws ValidationException se l'elenco non è più quello che l'utente aveva davanti
     */
    public static function riordina(User $utente, array $ordine): int
    {
        return DB::transaction(function () use ($utente, $ordine) {
            $candidati = self::blocca($utente);
            $regole = Regola::orderBy('posizione')->orderBy('id')->get();
            $ids = $regole->modelKeys();
            if (count($ordine) !== count(array_unique($ordine)) || array_diff($ids, $ordine) !== [] || array_diff($ordine, $ids) !== []) {
                throw ValidationException::withMessages(['ordine' => 'L’elenco delle regole è cambiato dopo che l’hai aperto: ricaricalo e riprova.']);
            }
            foreach (array_values($ordine) as $i => $id) {
                $regola = $regole->firstWhere('id', $id);
                $prima = $regola->posizione;
                if ($prima !== $i + 1) {
                    $regola->update(['posizione' => $i + 1]);
                    RegistroModifica::registra($utente, $regola, 'riordinata', ['posizione' => $prima], ['posizione' => $i + 1]);
                }
            }

            return self::assegna($utente, $candidati, self::elenco());
        });
    }

    /**
     * Le regole sui movimenti appena entrati da un import. Per chi ha già bloccato il conto e fatto la sua prima lettura
     * dopo il lock: le regole si leggono qui, e un salvataggio di regole (che blocca tutti i conti) non può essere a metà.
     *
     * @param  Collection<int, Movimento>  $movimenti da classificare, appena inseriti
     */
    public static function applicaAiNuovi(User $utente, Collection $movimenti): int
    {
        return self::assegna($utente, $movimenti, self::elenco());
    }

    /**
     * Cosa farebbe una regola prima di salvarla: i movimenti che prenderebbe (e a quale regola li toglie) e quelli che,
     * se la regola esiste già, non sarebbero più suoi. Non scrive nulla.
     *
     * @return array{presi: Collection<int, array{movimento: Movimento, da: ?string}>, lasciati: Collection<int, array{movimento: Movimento, a: ?string, storico: bool}>}
     *
     * @throws ValidationException
     */
    public static function anteprima(array $input, ?Regola $regola): array
    {
        [$attributi, $tag] = self::leggi($input, $regola?->loadMissing('tag'));
        $bozza = (new Regola($attributi))->setRelation('tag', Tag::findMany($tag));
        $bozza->setRelation('tipologia', $bozza->tipologia_id ? Tipologia::find($bozza->tipologia_id) : null);

        $prima = self::elenco();
        $vecchia = $prima->firstWhere('id', $regola?->id);
        $dopo = $regola ? $prima->map(fn (Regola $r) => $r->is($regola) ? $bozza : $r) : $prima->concat([$bozza]);

        $presi = collect();
        $lasciati = collect();
        $archivio = null; // si legge solo se serve a qualcuno
        foreach (self::candidati()->get() as $movimento) {
            $era = self::efficace($prima, $movimento);
            $sara = self::efficace($dopo, $movimento);
            $eraSuo = $vecchia && $era === $vecchia;
            if ($sara === $bozza && ! $eraSuo) { // quelli che erano già suoi non sono «presi»
                $presi->push(['movimento' => $movimento, 'da' => $era?->nome]);
            } elseif ($eraSuo && $sara !== $bozza) {
                // Senza regola tocca allo storico, se ha qualcosa da proporre.
                $storico = $sara === null && ($p = Storico::migliore($archivio ??= Storico::archivio(), $movimento)) && Classificazione::imputazioniDelloStorico($movimento, $p) !== null;
                $lasciati->push(['movimento' => $movimento, 'a' => $sara?->nome, 'storico' => $storico]);
            }
        }

        return ['presi' => $presi, 'lasciati' => $lasciati];
    }

    /**
     * Blocca i conti e poi i candidati, ricontrolla il permesso di chi scrive (riletto: `puo` si ricorda le assegnazioni
     * già caricate) e restituisce i candidati com'erano a riga bloccata.
     *
     * @return Collection<int, Movimento>
     */
    private static function blocca(User $utente): Collection
    {
        Conto::orderBy('id')->lockForUpdate()->get(['id']);
        $candidati = self::candidati()->lockForUpdate()->get();
        abort_unless(User::find($utente->id)?->can('gestire_regole'), 403);

        return $candidati;
    }

    /**
     * Da classificare, o proposti da una regola o dallo storico: tutto il resto (confermati, scelte a mano) non si tocca.
     * shortcut: nessun controllo dell'esercizio, perché lo stato aperto o depositato arriva con l'esercizio (#33, storia 63); finora sono tutti aperti.
     */
    private static function candidati(): Builder
    {
        return Movimento::where(fn (Builder $q) => $q->where('stato', 'da_classificare')->orWhere(fn (Builder $q) => $q->where('stato', 'proposto')
            ->whereNull('proposto_da'))) // di regola o storico, o rimasta senza origine (esempio cancellato): quelle di una persona hanno `proposto_da`
            ->orderBy('id');
    }

    /** La regola che prende il movimento se la sua proposta si può fare davvero (tipologia attiva, voce ammessa, area ricavabile). */
    private static function efficace(Collection $regole, Movimento $movimento): ?Regola
    {
        $regola = self::vincitrice($regole, $movimento);

        return $regola && Classificazione::imputazioniDellaRegola($movimento, $regola) !== null ? $regola : null;
    }

    /**
     * Mette sui movimenti la proposta della regola che li prende, e toglie quella di una regola che non li prende più.
     * Si scrive solo dove qualcosa cambia: la proposta c'è già della stessa regola (e la regola non è stata appena
     * toccata), oppure non c'è né l'una né l'altra.
     *
     * @param  Collection<int, Movimento>  $movimenti già bloccati, o appena inseriti da chi chiama
     * @param  ?int  $toccata la regola salvata adesso (con condizioni o effetti cambiati): le sue proposte si rifanno anche se la regola è la stessa
     * @param  ?string  $tipoDellaToccata il socio o terzo che la regola aveva prima del salvataggio
     * @return int quanti movimenti hanno cambiato proposta
     */
    // shortcut: `risolvi` rifà alcune query per ogni movimento e regola, dentro la transazione; un estratto mensile ha qualche centinaio di righe, da ripensare solo se diventano migliaia.
    private static function assegna(User $utente, Collection $movimenti, Collection $regole, ?int $toccata = null, ?string $tipoDellaToccata = null): int
    {
        $cambiati = 0;
        $archivio = null; // letto solo per il primo movimento che nessuna regola prende, dopo i lock di chi chiama come le regole
        foreach ($movimenti as $movimento) {
            $regola = self::vincitrice($regole, $movimento);
            $imputazioni = $regola ? Classificazione::imputazioniDellaRegola($movimento, $regola) : null;
            $precedente = null;
            if ($imputazioni === null) { // nessuna regola, o la sua proposta non si può fare: vale come nessuna regola, e tocca allo storico
                $regola = null;
                $precedente = Storico::migliore($archivio ??= Storico::archivio(), $movimento);
                $imputazioni = $precedente ? Classificazione::imputazioniDelloStorico($movimento, $precedente) : null;
                if ($imputazioni === null) {
                    $precedente = null;
                }
            }
            $senzaOrigine = $movimento->stato === 'proposto' && $regola === null && $precedente === null; // proposta rimasta senza fonte: torna da classificare
            if (! $senzaOrigine && $regola?->id === $movimento->regola_id && ($regola === null || $regola->id !== $toccata)
                && $precedente?->movimento?->id === $movimento->storico_movimento_id && $precedente?->esempio?->id === $movimento->storico_esempio_id && ($precedente === null || Classificazione::haGia($movimento, $imputazioni))) {
                continue;
            }
            $tipoDiPrima = $movimento->regola_id === $toccata ? $tipoDellaToccata : $regole->firstWhere('id', $movimento->regola_id)?->tipo_controparte;
            Classificazione::proponi($utente, $movimento, $regola, $precedente, $imputazioni ?? [], $tipoDiPrima);
            $cambiati++;
        }

        return $cambiati;
    }
}
