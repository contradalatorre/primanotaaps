<?php

namespace App\Esercizio;

use App\Models\Movimento;
use App\Models\Nota;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Gli elenchi dell'esercizio per i fogli e i CSV (#58): le imputazioni (anche il foglio «Dettaglio» dell'XLSX, per il commercialista) e i movimenti.
 * Le imputazioni partono da `Prospetto::imputazioni`, le stesse righe del Modello D e dell'elenco dietro ogni cifra: una cifra, il suo elenco, il
 * Dettaglio e il CSV non possono divergere. Importi in centesimi (`Importo`); uscite col segno meno, così la colonna si somma.
 *
 * Le colonne delle note (testo, numero, dubbio aperto) ci sono solo per chi le legge sulle pagine (`$conNote`, permesso `note`). Le note
 * eliminate e quelle oscurate non escono né nel testo né nel numero; un dubbio oscurato però resta un dubbio aperto (come per `Movimento::inDubbio`).
 * Niente autori né chi ha classificato o confermato: nessun nome di persona viene dall'app.
 * shortcut: la descrizione del movimento è quella dell'estratto, e può nominare una persona (la stessa che si vede sulle pagine); la versione senza nomi è del mascheratore (#65).
 */
final class Elenchi
{
    private const NOTE = ['Note', 'Numero note', 'Dubbio aperto'];

    private const STATI = ['da_classificare' => 'Da classificare', 'proposto' => 'Proposto', 'confermato' => 'Confermato'];

    /** @return array{colonne: list<string>, righe: list<list<string|int|Importo|null>>} */
    public static function imputazioni(int $anno, bool $proposte, bool $conNote): array
    {
        $righe = Prospetto::imputazioni($anno, $proposte);
        $note = self::note($righe->map(fn (array $r) => $r['imputazione']->movimento), $conNote);

        return [
            'colonne' => ['Data', 'Conto', 'Entrata/uscita', 'Importo (€)', 'Area', 'Attività', 'Iniziativa', 'Tipologia', 'Voce Modello D', 'Stato', 'Descrizione', 'Giustificativi', 'Id movimento', ...($conNote ? self::NOTE : [])],
            'righe' => $righe->map(function (array $r) use ($note, $conNote) {
                $i = $r['imputazione'];
                $m = $i->movimento;

                return [
                    $m->data->format('d/m/Y'), $m->conto->nome, $r['lato'] === 'uscite' ? 'Uscita' : 'Entrata', self::firmato($r['lato'], $i->importo),
                    $i->area->value, $i->attivita?->nome, $i->iniziativa?->nome, $i->tipologia?->nome, $r['voce'], self::STATI[$m->stato] ?? $m->stato,
                    $m->descrizione, $m->giustificativi->pluck('riferimento')->join('; '), $m->id, ...($conNote ? $note[$m->id] : []),
                ];
            })->all(),
        ];
    }

    /** I movimenti dell'esercizio, ogni stato (c'è la colonna), esclusi gli eliminati; i giroconti ci sono, con la loro colonna. */
    public static function movimenti(int $anno, bool $conNote): array
    {
        $movimenti = Movimento::with('conto', 'giustificativi')->whereBetween('data', ["$anno-01-01", "$anno-12-31"])->orderBy('data')->orderBy('id')->get();
        $note = self::note($movimenti, $conNote);
        $giroconti = Movimento::inGiroconto()->whereIn('movimenti.id', $movimenti->modelKeys())->pluck('movimenti.id')->flip();

        return [
            'colonne' => ['Data', 'Conto', 'Entrata/uscita', 'Importo (€)', 'Stato', 'Giroconto', 'Descrizione', 'Giustificativi', 'Id movimento', ...($conNote ? self::NOTE : [])],
            'righe' => $movimenti->map(fn (Movimento $m) => [
                $m->data->format('d/m/Y'), $m->conto->nome, $m->verso === 'uscita' ? 'Uscita' : 'Entrata', self::firmato($m->verso === 'uscita' ? 'uscite' : 'entrate', $m->importo),
                self::STATI[$m->stato] ?? $m->stato, $giroconti->has($m->id) ? 'sì' : 'no', $m->descrizione, $m->giustificativi->pluck('riferimento')->join('; '), $m->id, ...($conNote ? $note[$m->id] : []),
            ])->all(),
        ];
    }

    /** Il CSV come Excel italiano lo apre: punto e virgola, BOM, importi con la virgola e senza migliaia. */
    public static function csv(array $elenco): string
    {
        $csv = fopen('php://temp', 'w+');
        fwrite($csv, "\xEF\xBB\xBF");
        fputcsv($csv, $elenco['colonne'], ';', '"', ''); // senza il carattere di escape: Excel legge il CSV come l'RFC 4180
        foreach ($elenco['righe'] as $riga) {
            fputcsv($csv, array_map(fn ($c) => match (true) {
                $c instanceof Importo => str_replace('.', ',', $c->decimale()),
                // Un testo che comincia come una formula (una descrizione bancaria `=...`) in Excel si eseguirebbe: lo si apre con un apice.
                is_string($c) && ! is_numeric($c) && preg_match('/^[=+\-@\t\r]/', $c) => "'".$c,
                default => $c ?? '',
            }, $riga), ';', '"', '');
        }
        rewind($csv);

        return (string) stream_get_contents($csv);
    }

    private static function firmato(string $lato, int $importo): Importo
    {
        return new Importo($lato === 'uscite' ? -abs($importo) : abs($importo));
    }

    /**
     * Le tre colonne delle note per ogni movimento, in una query sola.
     *
     * @param  iterable<Movimento>  $movimenti
     * @return array<int, array{string, int, string}> id del movimento → testo, numero, «sì»/«no»
     */
    private static function note(iterable $movimenti, bool $conNote): array
    {
        if (! $conNote) {
            return [];
        }
        $movimenti = (new EloquentCollection($movimenti))->unique('id');
        $movimenti->load(['note' => fn ($q) => $q->whereNull('eliminata_il')->orderBy('id')]);

        $per = [];
        foreach ($movimenti as $m) {
            $visibili = $m->note->reject(fn (Nota $n) => $n->oscurata());
            $per[$m->id] = [$visibili->pluck('testo')->join(' | '), $visibili->count(), $m->note->contains(fn (Nota $n) => $n->dubbio && $n->chiuso_il === null) ? 'sì' : 'no'];
        }

        return $per;
    }
}
