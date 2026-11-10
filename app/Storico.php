<?php

namespace App;

use App\Models\EsempioClassificazione;
use App\Models\Movimento;
use Illuminate\Support\Collection;

/**
 * Lo storico (#43): propone la classificazione di un movimento simile già confermato, o di un esempio senza movimento.
 * Viene dopo le regole nella cascata (`App\Regole`). La controparte oggi sta nella descrizione dell'estratto, quindi
 * «stessa controparte e causale» vuol dire stessa causale e descrizione simile, e «molto simile» una descrizione quasi
 * uguale anche con un'altra causale; il verso deve sempre coincidere. Si legge dai movimenti confermati così come sono
 * (nessuna copia da tenere allineata): se uno viene riclassificato o eliminato, lo storico lo sa subito.
 */
final class Storico
{
    /** Somiglianza minima (0-1) delle descrizioni quando la causale è la stessa. */
    public const SOGLIA_STESSA_CAUSALE = 0.6;

    /** Somiglianza minima delle descrizioni quando la causale è un'altra o manca. */
    public const SOGLIA_MOLTO_SIMILE = 0.85;

    /**
     * Le parole che negli estratti Crédit Agricole non dicono chi è la controparte: senza, due negozi con la stessa
     * carta sembrerebbero simili. shortcut: l'elenco è quello dei formati visti finora, da allungare con i nuovi (PayPal, Satispay).
     */
    private const GENERICHE = ['carta', 'debit', 'visa', 'ord', 'descr', 'operazione', 'sct', 'sdd', 'bonifico', 'giroconto', 'del', 'dei', 'per', 'srl', 'spa', 'snc'];

    /**
     * I casi da cui imparare: i movimenti confermati con una sola imputazione e gli esempi senza movimento.
     * shortcut: un movimento diviso in più imputazioni non fa da precedente (la proposta ha una sola imputazione, per tutto l'importo).
     *
     * @return Collection<int, Precedente>
     */
    public static function archivio(): Collection
    {
        return Movimento::where('stato', 'confermato')->with('imputazioni.tag:id')->get()->map(fn (Movimento $m) => Precedente::daMovimento($m))->filter()
            ->concat(EsempioClassificazione::all()->map(fn (EsempioClassificazione $e) => Precedente::daEsempio($e)))->values();
    }

    /** Il precedente più simile (a parità il più recente) tra quelli abbastanza simili, o null. */
    public static function migliore(Collection $archivio, Movimento $movimento): ?Precedente
    {
        $parole = self::parole($movimento->descrizione);
        $causale = self::causale($movimento->causale);
        $migliore = null;
        $punteggio = 0.0;
        foreach ($archivio as $p) {
            if ($p->verso !== $movimento->verso || $p->movimento?->is($movimento) || $parole === []) {
                continue;
            }
            $s = self::somiglianza($parole, $p->parole);
            if ($s < self::SOGLIA_MOLTO_SIMILE && ! ($causale !== null && $causale === $p->causale && $s >= self::SOGLIA_STESSA_CAUSALE)) {
                continue;
            }
            if ($migliore === null || $s > $punteggio || ($s === $punteggio && $p->data->greaterThan($migliore->data))) {
                [$migliore, $punteggio] = [$p, $s];
            }
        }

        return $migliore;
    }

    /**
     * Le parole di una descrizione che contano per riconoscere la controparte: solo lettere, di almeno tre, senza quelle
     * generiche. Date, numeri, importi e IBAN spariscono, così le varianti mensili della stessa controparte coincidono.
     *
     * @return array<string,true>
     */
    public static function parole(string $descrizione): array
    {
        $parole = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($descrizione), -1, PREG_SPLIT_NO_EMPTY) as $parola) {
            if (mb_strlen($parola) >= 3 && preg_match('/^\p{L}+$/u', $parola) && ! in_array($parola, self::GENERICHE, true)) {
                $parole[$parola] = true;
            }
        }

        return $parole;
    }

    public static function causale(?string $causale): ?string
    {
        $causale = mb_strtolower(trim((string) $causale));

        return $causale === '' ? null : $causale;
    }

    /** Parole in comune su parole totali (0-1). */
    private static function somiglianza(array $a, array $b): float
    {
        $comuni = count(array_intersect_key($a, $b));

        return $comuni === 0 ? 0.0 : $comuni / (count($a) + count($b) - $comuni);
    }
}
