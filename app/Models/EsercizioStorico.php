<?php

namespace App\Models;

use App\Esercizio\Calcolo;
use App\Esercizio\Figurativo;
use App\Esercizio\Imputata;
use App\Esercizio\Saldo;
use Illuminate\Database\Eloquent\Model;

/**
 * Un esercizio storico (glossario): importi per voce Modello D e saldi finali, senza movimenti, per la colonna dell'esercizio precedente
 * (#63). Totali e avanzi non si salvano: li ricalcola `Calcolo` dagli importi, come per un esercizio vero.
 */
class EsercizioStorico extends Model
{
    protected $table = 'esercizi_storici';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['esercizio' => 'integer', 'importi' => 'array', 'saldo_cassa' => 'integer', 'saldo_depositi' => 'integer', 'figurativi' => 'array', 'differenze' => 'array'];
    }

    /**
     * Si carica come storico solo un anno precedente al primo esercizio con movimenti nell'app (senza movimenti: precedente all'anno in corso):
     * un anno dopo il primo movimento ha i suoi movimenti, e l'esercizio in corso non è «storia».
     */
    public static function caricabile(int $anno): bool
    {
        $primo = Movimento::min('data');

        return $anno < ($primo ? (int) substr($primo, 0, 4) : today()->year);
    }

    /** Il Modello D di quell'esercizio storico, o null se non è stato caricato. La `quadratura` non vale: non ci sono i saldi iniziali. */
    public static function modelloD(int $anno): ?array
    {
        return self::where('esercizio', $anno)->first()?->prospetto();
    }

    public function prospetto(): array
    {
        return self::calcola($this->importi, $this->saldo_cassa, $this->saldo_depositi, $this->figurativi);
    }

    /**
     * @param  array<string, int>  $importi  «lato:codice» → centesimi
     * @param  array{costi?: array<string, int>, proventi?: array<string, int>}  $figurativi  area → centesimi
     */
    public static function calcola(array $importi, int $cassa, int $depositi, array $figurativi): array
    {
        $imputate = [];
        foreach ($importi as $chiave => $importo) {
            [$lato, $voce] = explode(':', $chiave);
            $imputate[] = new Imputata($lato, $voce, $importo, $voce[0]);
        }
        $righe = [];
        foreach (['costi' => 'costo', 'proventi' => 'provento'] as $nome => $tipo) {
            foreach ($figurativi[$nome] ?? [] as $area => $importo) {
                $righe[] = new Figurativo($tipo, $area, '', $importo);
            }
        }

        return Calcolo::modelloD($imputate, [new Saldo('cassa', 0, $cassa), new Saldo('depositi', 0, $depositi)], $righe);
    }

    /** Per il registro, piatta come le altre istantanee: una chiave per voce («entrate:A1») e per figurativo («costi:A»), in centesimi. */
    public function istantanea(): array
    {
        $figurativi = [];
        foreach ($this->figurativi as $tipo => $aree) {
            foreach ($aree as $area => $importo) {
                $figurativi["$tipo:$area"] = $importo;
            }
        }

        return ['esercizio' => $this->esercizio, 'saldo_cassa' => $this->saldo_cassa, 'saldo_depositi' => $this->saldo_depositi] + $this->importi + $figurativi;
    }
}
