<?php

namespace App;

use App\Models\ImportEstratto;
use App\Models\Movimento;
use Illuminate\Support\Collection;

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
}
