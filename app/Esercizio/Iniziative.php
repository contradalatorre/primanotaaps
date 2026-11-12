<?php

namespace App\Esercizio;

use App\Area;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\PreventivoIniziativa;

/**
 * Porta le imputazioni e i preventivi delle iniziative dal database a `ContiIniziativa` (che non li conosce). Le imputazioni sono
 * quelle di `Prospetto::imputazioni`, esercizio per esercizio: stessa scelta del Modello D (confermate, o anche proposte; mai i giroconti
 * né i movimenti eliminati). Le commissioni di PayPal e Satispay non hanno iniziativa: restano in D1.
 */
final class Iniziative
{
    /**
     * Una scheda per ogni iniziativa che fino all'esercizio scelto ha imputazioni o che per quell'esercizio ha un preventivo, in ordine di nome.
     *
     * @return list<array{iniziativa: Iniziativa, conti: array, inAreaA: bool}> `inAreaA`: nell'esercizio scelto ha imputazioni in area A
     */
    public static function conti(int $anno, bool $proposte = false): array
    {
        $righe = [];
        $inAreaA = [];
        // Solo gli esercizi in cui ci sono movimenti: un anno lontano nel futuro non deve costare una query per anno.
        $primo = (int) substr((string) Movimento::min('data'), 0, 4);
        for ($e = $primo; $primo > 0 && $e <= min($anno, (int) substr((string) Movimento::max('data'), 0, 4)); $e++) {
            foreach (Prospetto::imputazioni($e, $proposte) as $r) {
                $i = $r['imputazione'];
                if ($i->iniziativa_id === null) {
                    continue;
                }
                $righe[$i->iniziativa_id][] = new RigaIniziativa($e, $r['lato'], $i->tipologia?->nome ?? 'Senza tipologia', abs($i->importo));
                if ($e === $anno && $i->area === Area::A) {
                    $inAreaA[$i->iniziativa_id] = true;
                }
            }
        }
        $previsti = PreventivoIniziativa::with('tipologia')->where('esercizio', $anno)->get()->groupBy('iniziativa_id')
            ->map(fn ($righe) => $righe->map(fn (PreventivoIniziativa $p) => new PrevistoIniziativa($p->tipologia->verso === 'entrata' ? 'entrate' : 'uscite', $p->tipologia->nome, $p->importo))->all());

        return Iniziativa::whereIn('id', array_unique([...array_keys($righe), ...$previsti->keys()->all()]))->orderBy('nome')->get()
            ->map(fn (Iniziativa $i) => [
                'iniziativa' => $i,
                'conti' => ContiIniziativa::calcola($righe[$i->id] ?? [], $previsti[$i->id] ?? [], $anno),
                'inAreaA' => isset($inAreaA[$i->id]),
            ])->all();
    }
}
