<?php

namespace App;

use App\Models\Conto;
use App\Models\Giroconto;
use App\Models\ImportEstratto;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cosa resta da fare su un import confermato (#41): i suoi movimenti divisi nei passi della schermata di revisione,
 * la catena del saldo e l'avanzamento. Qui stanno le regole dei passi, non nel controllore né nella vista: i dubbi (#45)
 * e i giroconti (#47) ci sono già. Il giroconto precede la classificazione (cascata di #33): un movimento con una proposta
 * di giroconto non è «da decidere» né «pronto», finché l'operatore non lo abbina o lo classifica a mano.
 */
final class Revisione
{
    /** Le schede, nell'ordine in cui si lavora. */
    public const PASSI = ['giroconti' => 'Giroconti', 'pronte' => 'Proposte pronte', 'decidere' => 'Da decidere', 'tutti' => 'Tutti', 'dubbi' => 'In dubbio'];

    /** @var Collection<int, Movimento> in ordine di data, con quello che serve a versione, voce e fonte già caricato */
    public readonly Collection $movimenti;

    /** @var ?Collection<int, array<string,mixed>> le proposte di giroconto che toccano questo import, calcolate una volta */
    private ?Collection $proposteGiroconti = null;

    /** @var ?Collection<int, Giroconto> i giroconti confermati con una metà in questo import */
    private ?Collection $girocontiConfermati = null;

    public function __construct(public readonly ImportEstratto $import)
    {
        $this->movimenti = $import->movimenti()->conNote()
            ->with('proponente', 'regola', 'storicoMovimento', 'storicoEsempio', 'giustificativi', 'imputazioni.attivita', 'imputazioni.iniziativa', 'imputazioni.tipologia', 'imputazioni.tag')
            ->orderBy('data')->orderBy('id')->get();
    }

    /**
     * I movimenti di un passo; tranne «Tutti» solo quelli ancora da confermare.
     * Le proposte ora sono tutte di chi può confermare (in un import classifica solo chi ha `importare_e_confermare`):
     * quando arriverà l'AI (#66), «Proposte pronte» lascerà fuori AI e consiglieri (#33, storia 72).
     *
     * @return Collection<int, Movimento>
     */
    public function passo(string $passo): Collection
    {
        // Un dubbio resta fuori da «Conferma le proposte pronte» e dai gruppi: la conferma lo chiude (#16) e non deve succedere in silenzio. Lo si conferma con la ✓ o spuntandolo.
        $pronta = fn (Movimento $m) => $m->stato === 'proposto' && $m->fonteProposta() !== null && $m->confermabile() && ! $m->inDubbio();

        $inGiroconto = $this->movimentiInProposta();

        return match ($passo) {
            'giroconti' => $this->movimenti->filter(fn (Movimento $m) => $inGiroconto->has($m->id))->values(),
            'pronte' => $this->movimenti->filter(fn (Movimento $m) => $pronta($m) && ! $inGiroconto->has($m->id))->values(),
            'decidere' => $this->movimenti->reject(fn (Movimento $m) => $m->confermato() || $pronta($m) || $inGiroconto->has($m->id))->values(),
            'tutti' => $this->movimenti,
            'dubbi' => $this->movimenti->filter->inDubbio()->values(),
        };
    }

    /**
     * Le proposte pronte raggruppate per tipologia e iniziativa («Utenze · Carnevale»), il gruppo si conferma in un colpo.
     *
     * @param  Collection<int, Movimento>  $movimenti
     * @return Collection<string, Collection<int, Movimento>>
     */
    public function gruppi(Collection $movimenti): Collection
    {
        $nomi = fn (Movimento $m, string $relazione) => $m->imputazioni->map(fn ($i) => $i->$relazione?->nome)->filter()->unique()->sort()->join(', ');

        return $movimenti->groupBy(fn (Movimento $m) => ($nomi($m, 'tipologia') ?: 'Senza tipologia').' · '.($nomi($m, 'iniziativa') ?: 'senza iniziativa'))->sortKeys();
    }

    /**
     * Le proposte di giroconto che toccano i movimenti di questo import (una metà dentro, l'altra magari su un altro conto
     * o in un altro import), in ordine di data. Il passo Giroconti le mostra una per una.
     *
     * @return Collection<int, array<string,mixed>> come `Giroconti::proposte`
     */
    public function giroconti(): Collection
    {
        $mie = $this->movimenti->modelKeys();

        return $this->proposteGiroconti ??= Giroconti::proposte()
            ->filter(fn (array $p) => in_array($p['movimento']->id, $mie, true) || in_array($p['controparte']?->id, $mie, true))->values();
    }

    /** @return Collection<int, true> gli id dei movimenti di questo import che sono metà di una proposta di giroconto */
    private function movimentiInProposta(): Collection
    {
        $mie = array_flip($this->movimenti->modelKeys());

        return $this->giroconti()->flatMap(fn (array $p) => [$p['movimento']->id, $p['controparte']?->id])
            ->filter(fn ($id) => isset($mie[$id]))->flip()->map(fn () => true);
    }

    /** Il giroconto confermato di cui il movimento è una metà, con le due metà e i loro conti già caricati. */
    public function girocontoDi(Movimento $movimento): ?Giroconto
    {
        $this->girocontiConfermati ??= Giroconto::delle($this->movimenti->modelKeys())->with('uscita.conto', 'entrata.conto')->get();

        return $this->girocontiConfermati->first(fn (Giroconto $g) => $g->uscita_id === $movimento->id || $g->entrata_id === $movimento->id);
    }

    public function conteggio(string $passo): int
    {
        return $this->passo($passo)->count();
    }

    public function passoIniziale(): string
    {
        $primo = collect(['giroconti', 'pronte', 'decidere'])->first(fn (string $passo) => $this->conteggio($passo) > 0);

        return $primo ?? 'tutti';
    }

    public function confermati(): int
    {
        return $this->movimenti->filter->confermato()->count();
    }

    /** «1 movimento da decidere e 2 proposte pronte», o null se è tutto confermato. */
    public function cosaResta(): ?string
    {
        $da = $this->conteggio('decidere');
        $pronte = $this->conteggio('pronte');
        $giroconti = $this->conteggio('giroconti');
        $parti = array_filter([
            $giroconti ? $giroconti.($giroconti === 1 ? ' giroconto' : ' giroconti').' da controllare' : null,
            $da ? $da.($da === 1 ? ' movimento' : ' movimenti').' da decidere' : null,
            $pronte ? $pronte.($pronte === 1 ? ' proposta pronta' : ' proposte pronte') : null,
        ]);

        $parti = array_values($parti);

        return $parti === [] ? null : implode(', ', array_slice($parti, 0, -1)).(count($parti) > 1 ? ' e ' : '').end($parti);
    }

    /**
     * Saldo iniziale + entrate − uscite ± giroconti = saldo finale, calcolati sul conto nel periodo dell'estratto e
     * confrontati con quello che dice il file. I giroconti (#47) non sono né entrate né uscite: stanno in una riga a
     * parte, col saldo netto che portano al conto, così il saldo continua a tornare con la banca.
     *
     * @return array{iniziale: int, entrate: int, uscite: int, giroconti: int, nGiroconti: int, finale: int, estratto: int, uguale: bool}
     */
    public function catena(): array
    {
        $conto = $this->import->conto;
        $periodo = fn () => $conto->movimenti()->whereBetween('data', [$this->import->periodo_dal->toDateString(), $this->import->periodo_al->toDateString()]);
        $somme = $periodo()->senzaGiroconto()->selectRaw('verso, cast(sum(importo) as signed) as totale')->groupBy('verso')->pluck('totale', 'verso');
        $giroconti = $periodo()->inGiroconto()->selectRaw('verso, cast(sum(importo) as signed) as totale, count(*) as n')->groupBy('verso')->get()->keyBy('verso');
        $iniziale = $conto->saldoAl($this->import->periodo_dal->toDateString(), false);
        $entrate = (int) ($somme['entrata'] ?? 0);
        $uscite = (int) ($somme['uscita'] ?? 0);
        $nettoGiroconti = (int) ($giroconti['entrata']->totale ?? 0) - (int) ($giroconti['uscita']->totale ?? 0);
        $finale = $iniziale + $entrate - $uscite + $nettoGiroconti;

        return ['iniziale' => $iniziale, 'entrate' => $entrate, 'uscite' => $uscite, 'giroconti' => $nettoGiroconti, 'nGiroconti' => (int) $giroconti->sum('n'),
            'finale' => $finale, 'estratto' => $this->import->saldo_finale, 'uguale' => $finale === $this->import->saldo_finale];
    }

    /**
     * Conferma i movimenti scelti, ognuno per la versione che l'utente aveva davanti: o tutti o nessuno, così un
     * gruppo non resta a metà. In ordine di id, perché due conferme insieme non si blocchino a vicenda.
     *
     * @param  array<int, string>  $versioni  id del movimento → versione vista
     *
     * @throws ValidationException sotto `conferma`, con la descrizione del movimento che non si conferma
     */
    public function conferma(User $utente, array $versioni): void
    {
        $this->inTransazione($versioni, fn (Movimento $movimento, string $versione) => Classificazione::conferma($utente, $movimento, $versione));
    }

    /**
     * L'impronta delle proposte pronte come le ha viste l'utente (id e versione di ognuna): l'azione primaria della pagina
     * manda solo questa, non un campo per proposta, che oltre `max_input_vars` PHP taglierebbe in silenzio.
     */
    public function improntaPronte(): string
    {
        return hash('sha256', $this->passo('pronte')->map(fn (Movimento $m) => $m->id.':'.Classificazione::versione($m, true))->implode(','));
    }

    /**
     * Conferma tutte le proposte pronte, se sono ancora quelle che l'utente aveva davanti. Le versioni si rileggono qui,
     * e `conferma` le ricontrolla a riga bloccata: una modifica arrivata in mezzo fa rifiutare tutto.
     *
     * @return int quante ne ha confermate
     *
     * @throws ValidationException sotto `conferma`
     */
    public function confermaPronte(User $utente, string $impronta): int
    {
        $pronte = $this->passo('pronte');
        if ($pronte->isEmpty() || ! hash_equals($this->improntaPronte(), $impronta)) {
            throw ValidationException::withMessages(['conferma' => 'Le proposte pronte sono cambiate dopo che le hai viste: rileggile prima di confermarle.']);
        }
        $this->conferma($utente, $pronte->mapWithKeys(fn (Movimento $m) => [$m->id => Classificazione::versione($m, true)])->all());

        return $pronte->count();
    }

    /**
     * Imposta l'iniziativa su tutte le imputazioni dei movimenti scelti (area e attività si ereditano da lei),
     * lasciando il resto della classificazione com'è. Anche qui: tutti o nessuno, e solo sulla versione vista.
     *
     * @param  array<int, string>  $versioni  id del movimento → versione vista
     *
     * @throws ValidationException
     */
    public function impostaIniziativa(User $utente, array $versioni, Iniziativa $iniziativa): void
    {
        $this->inTransazione($versioni, function (Movimento $movimento, string $versione) use ($utente, $iniziativa) {
            Classificazione::bloccaEControlla($utente, $movimento);
            if (! hash_equals(Classificazione::versione($movimento), $versione)) {
                throw ValidationException::withMessages(['conferma' => 'Il movimento è cambiato dopo che lo hai aperto: rileggilo prima di cambiarlo.']);
            }
            $imputazioni = $movimento->imputazioni()->with('tag:id')->orderBy('id')->get();
            // Con una sola riga l'importo è quello del movimento; con più, in euro come lo scrive l'utente.
            $righe = $imputazioni->map(fn ($i) => [
                'importo' => $imputazioni->count() > 1 ? Euro::formatta($i->importo) : '', 'tipologia_id' => $i->tipologia_id,
                'iniziativa_id' => $iniziativa->id, 'tag' => $i->tag->pluck('id')->all(),
            ])->all() ?: [['iniziativa_id' => $iniziativa->id]];
            Classificazione::salva($utente, $movimento, $movimento->tipo_controparte, $righe);
        });
    }

    /** @param callable(Movimento, string): void $azione */
    private function inTransazione(array $versioni, callable $azione): void
    {
        // Solo movimenti di questo import: un id di un altro non entra mai.
        $movimenti = $this->movimenti->whereIn('id', array_keys($versioni))->sortBy('id');
        abort_if($movimenti->count() !== count($versioni), 404);

        DB::transaction(function () use ($movimenti, $versioni, $azione) {
            // Stesso ordine di conferma e annullamento dell'import: prima il conto, poi l'import, poi i movimenti.
            // Un import annullato nel frattempo non si rivede: i suoi movimenti sono usciti.
            Conto::lockForUpdate()->findOrFail($this->import->conto_id);
            abort_unless(ImportEstratto::lockForUpdate()->findOrFail($this->import->id)->stato === 'confermato', 404);
            // Tutti i movimenti insieme, prima di qualunque lettura semplice: MySQL (REPEATABLE READ) fotografa alla prima
            // lettura semplice, e se si bloccasse un movimento alla volta dal secondo in poi si deciderebbe su dati vecchi
            // (conferma di un movimento appena privato della tipologia, classificazione altrui cancellata).
            // shortcut: circa 28 query per movimento sotto lock; un estratto mensile ha qualche centinaio di righe, da ripensare solo se diventano migliaia.
            Movimento::whereIn('id', $movimenti->modelKeys())->orderBy('id')->lockForUpdate()->get(['id']);
            foreach ($movimenti as $movimento) {
                try {
                    $azione($movimento, $versioni[$movimento->id]);
                } catch (ValidationException $e) {
                    // Il messaggio dice di quale movimento parla: in un gruppo non sarebbe chiaro.
                    throw ValidationException::withMessages(['conferma' => collect($e->errors())->flatten()->map(fn ($t) => $movimento->descrizione.': '.$t)->all()]);
                }
            }
        });
    }
}
