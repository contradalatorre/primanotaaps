<?php

namespace App\Esercizio;

use App\Euro;
use App\Giroconti;
use App\Models\Conto;
use App\Models\ImpostazioniAssociazione;
use App\Models\Imputazione;
use App\Models\Movimento;
use App\Models\RigaFigurativa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Porta i dati dell'esercizio dal database al calcolo (`Calcolo`, che non li conosce) e il Modello D alla pagina. È l'unico
 * punto che sceglie le imputazioni: il prospetto e l'elenco filtrato dietro ogni cifra partono dalle stesse righe, quindi
 * una cifra e il suo elenco non possono divergere.
 *
 * Contano le imputazioni dei movimenti con data nell'anno, non eliminati e fuori dai giroconti (glossario: non sono né
 * entrate né uscite). Per impostazione solo le confermate; `$proposte` include anche quelle proposte (scelta di chi guarda).
 */
final class Prospetto
{
    /** Chiave dell'elenco per le imputazioni che il Modello D non sa collocare (senza voce, o con una voce fuori schema). */
    public const SENZA_RIGA = 'senza-riga';

    /**
     * Le imputazioni dell'esercizio, ognuna con il lato del Modello D, la voce e la chiave («entrate:A1»).
     * Il lato è quello della tipologia, non del movimento: la commissione su un incasso è un'uscita (importo negativo) in un movimento in entrata.
     *
     * @return Collection<int, array{imputazione: Imputazione, lato: string, voce: ?string, chiave: string}>
     */
    public static function imputazioni(int $anno, bool $proposte = false): Collection
    {
        $voci = DB::table('voci_modello_d')->where('esercizio', $anno)->get()->mapWithKeys(fn ($v) => [$v->area.'|'.$v->tipologia_id => $v->voce]);

        return Imputazione::whereHas('movimento', fn (Builder $m) => $m->senzaGiroconto()
            ->whereBetween('data', ["$anno-01-01", "$anno-12-31"])->whereIn('stato', $proposte ? ['confermato', 'proposto'] : ['confermato']))
            ->with('movimento.conto', 'movimento.giustificativi', 'tipologia', 'attivita', 'iniziativa')
            ->get()->sortBy(fn (Imputazione $i) => [$i->movimento->data->toDateString(), $i->id])->values()
            ->map(function (Imputazione $i) use ($voci) {
                $lato = ($i->tipologia?->verso ?? $i->movimento->verso) === 'entrata' ? 'entrate' : 'uscite';
                $voce = $i->tipologia_id === null ? null : $voci->get($i->area->value.'|'.$i->tipologia_id);

                return ['imputazione' => $i, 'lato' => $lato, 'voce' => $voce, 'chiave' => SchemaModelloD::conosce($lato, $voce) ? "$lato:$voce" : self::SENZA_RIGA];
            });
    }

    /**
     * Il Modello D dell'esercizio, calcolato dal vivo. Chi ha già letto le imputazioni (la pagina dei test, che ne vuole anche la catena)
     * passa in `$imputate` quelle di `imputate()`: una lettura sola, e Modello D e test non possono divergere.
     *
     * @param  ?list<Imputata>  $imputate
     */
    public static function modelloD(int $anno, bool $proposte = false, ?array $imputate = null): array
    {
        $impostazioni = ImpostazioniAssociazione::per($anno);
        $imputate ??= self::imputate(self::imputazioni($anno, $proposte), (bool) $impostazioni?->affiliazione_rete);

        return Calcolo::modelloD($imputate, self::saldi($anno), self::figurativi($anno), $impostazioni?->only(['denominazione', 'codice_fiscale', 'numero_runts', 'affiliazione_rete']) ?? []);
    }

    /**
     * Le imputazioni come le vede il calcolo: per le entrate anche la natura commerciale calcolata (`Natura`), la forzatura e se la tipologia è neutra
     * nel test dell'ente; la catena dei test (`TestNatura`, #54) parte da qui. La voce è già in `$righe`: nessuna query per riga.
     *
     * @param  Collection<int, array{imputazione: Imputazione, lato: string, voce: ?string, chiave: string}>  $righe
     * @return list<Imputata>
     */
    public static function imputate(Collection $righe, bool $affiliazioneRete): array
    {
        return $righe->map(function (array $r) use ($affiliazioneRete) {
            $i = $r['imputazione'];

            return new Imputata(
                $r['lato'], $r['voce'], abs($i->importo), $i->area->value, $i->iniziativa_id,
                $r['lato'] === 'entrate' ? Natura::calcola($i->ingressiCon($r['voce'], $affiliazioneRete)) : null,
                $i->natura_forzata, in_array('neutra_nel_test_dell_ente', $i->tipologia?->proprieta ?? [], true),
            );
        })->all();
    }

    /**
     * Le imputazioni dietro una cifra: quelle delle sue chiavi, con i totali per lato.
     *
     * @param  list<string>  $chiavi
     * @return array{imputazioni: Collection<int, array{imputazione: Imputazione, lato: string, voce: ?string, chiave: string}>, entrate: int, uscite: int}
     */
    public static function elenco(int $anno, bool $proposte, array $chiavi): array
    {
        $imputazioni = self::imputazioni($anno, $proposte)->filter(fn (array $r) => in_array($r['chiave'], $chiavi, true))->values();
        $totale = fn (string $lato) => $imputazioni->where('lato', $lato)->sum(fn (array $r) => abs($r['imputazione']->importo));

        return ['imputazioni' => $imputazioni, 'entrate' => $totale('entrate'), 'uscite' => $totale('uscite')];
    }

    /** @return list<Saldo> i conti attivi nell'esercizio: saldo al 1/1 e al 31/12, tutti i movimenti (il denaro c'è comunque, confermato o no) */
    public static function saldi(int $anno): array
    {
        return self::conti($anno)->map(fn (Conto $c) => new Saldo($c->riga_modello_d, $c->saldoAl("$anno-01-01", false), $c->saldoAl("$anno-12-31")))->all();
    }

    /** @return Collection<int, Conto> */
    private static function conti(int $anno): Collection
    {
        return Conto::where('aperto_il', '<=', "$anno-12-31")->where(fn (Builder $c) => $c->whereNull('chiuso_il')->orWhere('chiuso_il', '>=', "$anno-01-01"))->orderBy('nome')->get();
    }

    /** @return list<Figurativo> */
    public static function figurativi(int $anno): array
    {
        return RigaFigurativa::where('esercizio', $anno)->orderBy('id')->get()->map(fn (RigaFigurativa $r) => new Figurativo($r->tipo, $r->area, $r->descrizione, $r->importo))->all();
    }

    /**
     * Cosa impedisce di approvare l'esercizio, in parole: movimenti da classificare o da confermare, conti non riconciliati, dubbi aperti, giroconti senza la seconda metà.
     * Un conto è non riconciliato se non lo è fino all'ultimo movimento dell'esercizio (per l'anno in corso non aspetta il 31/12).
     *
     * @return list<array{testo: string, link: ?string}>
     */
    public static function impedimenti(int $anno): array
    {
        $impedimenti = [];
        $dellAnno = fn () => Movimento::whereBetween('data', ["$anno-01-01", "$anno-12-31"]);

        $daClassificare = $dellAnno()->where('stato', 'da_classificare')->count();
        $proposti = $dellAnno()->where('stato', 'proposto')->count();
        if ($daClassificare > 0) {
            $impedimenti[] = ['testo' => $daClassificare.($daClassificare === 1 ? ' movimento è da classificare' : ' movimenti sono da classificare'), 'link' => route('movimenti')];
        }
        if ($proposti > 0) {
            $impedimenti[] = ['testo' => $proposti.($proposti === 1 ? ' movimento ha una proposta da confermare' : ' movimenti hanno una proposta da confermare'), 'link' => route('movimenti')];
        }

        foreach (self::conti($anno) as $conto) {
            $ultimo = $conto->movimenti()->whereBetween('data', ["$anno-01-01", "$anno-12-31"])->max('data');
            if ($ultimo === null) {
                continue;
            }
            $riconciliato = $conto->riconciliatoFinoAl();
            if ($riconciliato === null || $riconciliato->toDateString() < $ultimo) {
                $impedimenti[] = ['testo' => '«'.$conto->nome.'» '.($riconciliato === null ? 'non è mai stato riconciliato' : 'è riconciliato solo fino al '.$riconciliato->format('d/m/Y')).' (ultimo movimento del '.date('d/m/Y', strtotime($ultimo)).')', 'link' => route('conti')];
            }
        }

        $dubbi = $dellAnno()->whereHas('note', fn (Builder $n) => $n->dubbioAperto())->count();
        if ($dubbi > 0) {
            $impedimenti[] = ['testo' => $dubbi.($dubbi === 1 ? ' movimento è in dubbio' : ' movimenti sono in dubbio'), 'link' => route('movimenti')];
        }

        $senzaMeta = Giroconti::proposte()->where('tipo', '!=', 'coppia')->filter(fn (array $p) => $p['movimento']->data->year === $anno);
        foreach ($senzaMeta as $p) {
            $m = $p['movimento'];
            $impedimenti[] = ['testo' => 'Giroconto senza la seconda metà: '.($m->verso === 'uscita' ? 'uscita' : 'entrata').' di '.Euro::formatta($m->importo).' € del '.$m->data->format('d/m/Y').' su «'.$m->conto->nome.'»', 'link' => route('movimenti', ['conto' => $m->conto_id])];
        }

        return $impedimenti;
    }
}
