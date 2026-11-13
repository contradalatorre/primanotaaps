<?php

namespace App\Esercizio;

use App\Models\DatiEsercizio;
use App\Models\ImpostazioniAssociazione;
use App\Models\Iniziativa;

/**
 * Porta i dati dell'esercizio dal database ai test CTS (`Secondarieta` e `TestNatura`, che non li conoscono). Le imputazioni sono scelte
 * da `Prospetto`, come per il Modello D: un test e il suo Modello D non possono divergere. Con `$proposte` includono
 * anche quelle proposte (scelta di chi guarda, mai salvata).
 */
final class Verifiche
{
    public const SOGLIA_VICINO_DI_PARTENZA = 80;

    /** Il test di secondarietà dell'esercizio, con i dati dell'esercizio e quel che attende ancora conferma. */
    public static function secondarieta(int $anno, bool $proposte = false): array
    {
        return self::secondarietaDa($anno, Prospetto::modelloD($anno, $proposte));
    }

    /**
     * La pagina dei test: secondarietà (`s`) e catena dell'area A e dell'ente (`catena`), dallo stesso Modello D e da una sola lettura
     * delle imputazioni. Il pareggio per iniziativa porta anche il nome dell'iniziativa.
     *
     * @return array{s: array, catena: array}
     */
    public static function pagina(int $anno, bool $proposte = false): array
    {
        [$modelloD, $catena] = self::calcolaCatena($anno, $proposte);

        return ['s' => self::secondarietaDa($anno, $modelloD), 'catena' => $catena];
    }

    /**
     * Il 2-bis dell'esercizio non è rispettato: i corrispettivi dei terzi in area A sono commerciali (`Imputazione::natura`). Con le sole imputazioni
     * confermate. Si calcola una volta per richiesta (negli attributi della richiesta, non in una statica: le richieste dei test condividono il processo).
     */
    public static function areaACommerciale(int $anno): bool
    {
        $attributi = request()->attributes;
        $chiave = "area-a-commerciale.$anno";
        if (! $attributi->has($chiave)) {
            $attributi->set($chiave, self::calcolaCatena($anno, false)[1]['areaA']['stato'] === 'non_rispettato');
        }

        return $attributi->get($chiave);
    }

    /** @return array{0: array, 1: array} il Modello D e la catena, con il nome delle iniziative del pareggio */
    private static function calcolaCatena(int $anno, bool $proposte): array
    {
        $impostazioni = ImpostazioniAssociazione::per($anno);
        $imputate = Prospetto::imputate(Prospetto::imputazioni($anno, $proposte), (bool) $impostazioni?->affiliazione_rete);
        $modelloD = Prospetto::modelloD($anno, $proposte, $imputate);
        $dati = DatiEsercizio::per($anno);

        $catena = TestNatura::calcola(
            $anno, $modelloD, $imputate, Prospetto::figurativi($anno), $dati->criterio_riparto,
            self::storico2bis($anno), $impostazioni?->soglia_vicino ?? self::SOGLIA_VICINO_DI_PARTENZA, $dati->figurativi_dichiarati,
        );
        $nomi = Iniziativa::whereIn('id', array_keys($catena['pareggi']))->pluck('nome', 'id');
        $catena['pareggi'] = array_map(fn (array $p) => $p + ['nome' => $nomi[$p['iniziativa']] ?? '—'], $catena['pareggi']);

        return [$modelloD, $catena];
    }

    /**
     * Lo stato del 2-bis degli esercizi già approvati (anno → «pareggio», «tolleranza», «non_rispettato»), da cui si conta la tolleranza del 6%.
     * shortcut: l'approvazione (#59) non c'è ancora e nessun esito è approvato, quindi dal 2027 la tolleranza non si applica (`TestNatura` lo dice);
     * #59 lo legge dalla versione approvata dei test (`catena.areaA.stato`).
     *
     * @return array<int, string>
     */
    private static function storico2bis(int $anno): array
    {
        return [];
    }

    private static function secondarietaDa(int $anno, array $modelloD): array
    {
        $dati = DatiEsercizio::per($anno);
        $vicino = ImpostazioniAssociazione::per($anno)?->soglia_vicino ?? self::SOGLIA_VICINO_DI_PARTENZA;

        return Secondarieta::calcola($modelloD, $dati->figurativi_dichiarati, $vicino, $dati->criterio_secondarieta) + ['dati' => $dati];
    }

    /**
     * Le entrate e le uscite che attendono conferma (movimenti proposti): quanti movimenti e per quanti centesimi. Lo chiede
     * solo la pagina dei test, non la home.
     *
     * @return array{entrate: array{numero: int, importo: int}, uscite: array{numero: int, importo: int}}
     */
    public static function inAttesa(int $anno): array
    {
        $attese = Prospetto::imputazioni($anno, true)->filter(fn (array $r) => $r['imputazione']->movimento->stato === 'proposto');
        $per = fn (string $lato) => ['numero' => $attese->where('lato', $lato)->pluck('imputazione.movimento_id')->unique()->count(), 'importo' => $attese->where('lato', $lato)->sum(fn (array $r) => abs($r['imputazione']->importo))];

        return ['entrate' => $per('entrate'), 'uscite' => $per('uscite')];
    }
}
