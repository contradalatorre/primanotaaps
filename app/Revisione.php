<?php

namespace App;

use App\Models\ImportEstratto;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cosa resta da fare su un import confermato (#41): i suoi movimenti divisi nei passi della schermata di revisione,
 * la catena del saldo e l'avanzamento. Qui stanno le regole dei passi, non nel controllore né nella vista: giroconti,
 * dubbi e regole (#45, #47, #42) aggiungeranno i loro qui.
 */
final class Revisione
{
    /** Le schede, nell'ordine in cui si lavora. */
    public const PASSI = ['giroconti' => 'Giroconti', 'pronte' => 'Proposte pronte', 'decidere' => 'Da decidere', 'tutti' => 'Tutti'];

    /** @var Collection<int, Movimento> in ordine di data, con quello che serve a versione, voce e fonte già caricato */
    public readonly Collection $movimenti;

    public function __construct(public readonly ImportEstratto $import)
    {
        $this->movimenti = $import->movimenti()
            ->with('proponente', 'giustificativi', 'imputazioni.attivita', 'imputazioni.iniziativa', 'imputazioni.tipologia', 'imputazioni.tag')
            ->orderBy('data')->orderBy('id')->get();
    }

    /**
     * I movimenti di un passo; tranne «Tutti» solo quelli ancora da confermare.
     * Le proposte ora sono tutte di chi può confermare (in un import classifica solo chi ha `importare_e_confermare`):
     * quando arriveranno regole, storico e AI (#42, #43), «Proposte pronte» lascerà fuori AI e consiglieri (#33, storia 72).
     *
     * @return Collection<int, Movimento>
     */
    public function passo(string $passo): Collection
    {
        $pronta = fn (Movimento $m) => $m->stato === 'proposto' && $m->confermabile();

        return match ($passo) {
            'giroconti' => collect(), // arrivano con #45
            'pronte' => $this->movimenti->filter($pronta)->values(),
            'decidere' => $this->movimenti->reject(fn (Movimento $m) => $m->confermato() || $pronta($m))->values(),
            'tutti' => $this->movimenti,
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

    public function conteggio(string $passo): int
    {
        return $this->passo($passo)->count();
    }

    public function passoIniziale(): string
    {
        return $this->conteggio('pronte') > 0 ? 'pronte' : ($this->conteggio('decidere') > 0 ? 'decidere' : 'tutti');
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
        $parti = array_filter([
            $da ? $da.($da === 1 ? ' movimento' : ' movimenti').' da decidere' : null,
            $pronte ? $pronte.($pronte === 1 ? ' proposta pronta' : ' proposte pronte') : null,
        ]);

        return $parti === [] ? null : implode(' e ', $parti);
    }

    /**
     * Saldo iniziale + entrate − uscite = saldo finale, calcolati sul conto nel periodo dell'estratto e confrontati con
     * quello che dice il file. I giroconti, quando ci saranno (#45), vanno tolti dalle entrate e dalle uscite.
     *
     * @return array{iniziale: int, entrate: int, uscite: int, finale: int, estratto: int, uguale: bool}
     */
    public function catena(): array
    {
        $conto = $this->import->conto;
        $somme = $conto->movimenti()->whereBetween('data', [$this->import->periodo_dal->toDateString(), $this->import->periodo_al->toDateString()])
            ->selectRaw('verso, cast(sum(importo) as signed) as totale')->groupBy('verso')->pluck('totale', 'verso');
        $iniziale = $conto->saldoAl($this->import->periodo_dal->toDateString(), false);
        $entrate = (int) ($somme['entrata'] ?? 0);
        $uscite = (int) ($somme['uscita'] ?? 0);
        $finale = $iniziale + $entrate - $uscite;

        return ['iniziale' => $iniziale, 'entrate' => $entrate, 'uscite' => $uscite, 'finale' => $finale,
            'estratto' => $this->import->saldo_finale, 'uguale' => $finale === $this->import->saldo_finale];
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
