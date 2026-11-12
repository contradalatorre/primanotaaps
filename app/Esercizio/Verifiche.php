<?php

namespace App\Esercizio;

use App\Models\DatiEsercizio;
use App\Models\ImpostazioniAssociazione;

/**
 * Porta i dati dell'esercizio dal database ai test CTS (`Secondarieta`, che non li conosce). Le imputazioni sono scelte
 * da `Prospetto`, come per il Modello D: un test e il suo Modello D non possono divergere. Con `$proposte` includono
 * anche quelle proposte (scelta di chi guarda, mai salvata).
 */
final class Verifiche
{
    public const SOGLIA_VICINO_DI_PARTENZA = 80;

    /** Il test di secondarietà dell'esercizio, con i dati dell'esercizio e quel che attende ancora conferma. */
    public static function secondarieta(int $anno, bool $proposte = false): array
    {
        $dati = DatiEsercizio::per($anno);
        $vicino = ImpostazioniAssociazione::per($anno)?->soglia_vicino ?? self::SOGLIA_VICINO_DI_PARTENZA;

        return Secondarieta::calcola(Prospetto::modelloD($anno, $proposte), $dati->figurativi_dichiarati, $vicino, $dati->criterio_secondarieta)
            + ['dati' => $dati, 'inAttesa' => self::inAttesa($anno)];
    }

    /**
     * Le entrate e le uscite che attendono conferma (movimenti proposti): quante imputazioni e per quanti centesimi.
     *
     * @return array{entrate: array{numero: int, importo: int}, uscite: array{numero: int, importo: int}}
     */
    public static function inAttesa(int $anno): array
    {
        $attese = Prospetto::imputazioni($anno, true)->filter(fn (array $r) => $r['imputazione']->movimento->stato === 'proposto');
        $per = fn (string $lato) => ['numero' => $attese->where('lato', $lato)->count(), 'importo' => $attese->where('lato', $lato)->sum(fn (array $r) => abs($r['imputazione']->importo))];

        return ['entrate' => $per('entrate'), 'uscite' => $per('uscite')];
    }
}
