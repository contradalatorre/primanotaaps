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
     * La pagina dei test: secondarietà (`s`), catena dell'area A, dell'ente e forfettario (`catena`), soglie degli artt. 13, 30 e 31 sull'esercizio
     * precedente (`soglie`) e il confronto con gli esercizi vicini (`confronto`), dallo stesso Modello D e da una sola lettura delle imputazioni
     * di ogni esercizio. Il pareggio per iniziativa porta anche il nome dell'iniziativa.
     *
     * Le soglie guardano anche il terzo e il quarto esercizio prima (la persistenza dell'obbligo degli artt. 30 e 31), che bastano come misure: senza catena.
     * `inAttesa` è quel che attende conferma nell'esercizio precedente: le soglie si misurano sui soli confermati.
     *
     * @return array{s: array, catena: array, soglie: array, confronto: array, inAttesa: array}
     */
    public static function pagina(int $anno, bool $proposte = false): array
    {
        $esercizi = [$anno => self::calcolaCatena($anno, $proposte)];
        foreach ([$anno - 1, $anno - 2] as $y) {
            $esercizi[$y] = self::calcolaCatena($y, $proposte);
        }
        [$modelloD, $catena] = $esercizi[$anno];

        $misure = [];
        foreach ([$anno - 1, $anno - 2] as $y) {
            $misure[$y] = self::misura($y, $esercizi[$y][0]);
        }
        foreach ([$anno - 3, $anno - 4] as $y) {
            $misure[$y] = self::misura($y, Prospetto::modelloD($y));
        }
        $inAttesa = self::inAttesa($anno - 1);
        if ($misure[$anno - 1] !== null) {
            $misure[$anno - 1]['inAttesa'] = $inAttesa['entrate']['numero'];
        }
        $vicino = ImpostazioniAssociazione::per($anno)?->soglia_vicino ?? self::SOGLIA_VICINO_DI_PARTENZA;

        return [
            's' => self::secondarietaDa($anno, $modelloD),
            'catena' => $catena,
            'soglie' => Soglie::calcola($anno, $misure, $vicino),
            'confronto' => self::confronto($anno, $proposte, $esercizi),
            'inAttesa' => $inAttesa,
        ];
    }

    /**
     * I valori e gli esiti degli ultimi tre esercizi affiancati e, durante l'anno, lo stesso periodo dell'anno precedente (#17). Sono sempre
     * calcolati dal vivo dai movimenti, come l'esercizio guardato: l'esito approvato di un esercizio chiuso non c'è ancora.
     * shortcut: la storia degli esiti approvati (#59) non esiste, quindi un esercizio chiuso si ricalcola oggi con i dati di oggi e una modifica tardiva
     * ne cambia l'esito; #59 deve leggere da qui la versione approvata. Nel periodo parziale i figurativi dell'anno intero non si contano (un costo in
     * meno e nessun provento: mai a favore).
     *
     * @param  array<int, array{0: array, 1: array}>  $esercizi  il Modello D e la catena dell'esercizio guardato e dei due precedenti
     * @return list<array<string, mixed>> le colonne, dalla più vecchia
     */
    private static function confronto(int $anno, bool $proposte, array $esercizi): array
    {
        $colonne = [];
        foreach ([$anno - 2, $anno - 1] as $y) {
            $colonne[] = self::colonna((string) $y, $y, ...$esercizi[$y]);
        }
        if ($anno === today()->year) {
            $oggi = today();
            $periodo = $oggi->format('d/m');
            $fino = $oggi->copy()->subYearsNoOverflow(1)->toDateString();
            $colonne[] = self::colonna(($anno - 1).', stesso periodo (dal 1/1 al '.$periodo.')', $anno - 1, ...self::calcolaCatena($anno - 1, $proposte, $fino));
            $colonne[] = self::colonna($anno.' (in corso)', $anno, ...$esercizi[$anno]);
        } else {
            $colonne[] = self::colonna((string) $anno, $anno, ...$esercizi[$anno]);
        }

        return $colonne;
    }

    private static function colonna(string $etichetta, int $anno, array $modelloD, array $catena): array
    {
        $s = self::secondarietaDa($anno, $modelloD);
        $criterio = $s['confermato'] ?? $s['proposto']; // quello che il Tesoriere ha confermato, altrimenti il proposto
        $gestione = $modelloD['gestione'];

        return [
            'etichetta' => $etichetta,
            'vuota' => $gestione['entrate']['importo'] === 0 && $gestione['uscite']['importo'] === 0,
            'entrate' => $gestione['entrate']['importo'],
            'uscite' => $gestione['uscite']['importo'],
            'secondarieta' => ['esito' => $s['criteri'][$criterio]['esito'], 'rapporto' => $s['criteri'][$criterio]['rapporto'], 'criterio' => $criterio],
            'areaA' => ['esito' => $catena['areaA']['esito'], 'rapporto' => $catena['areaA']['rapporto'], 'stato' => $catena['areaA']['stato']],
            'ente' => ['esito' => $catena['ente']['esito'], 'rapporto' => $catena['ente']['rapporto']],
            'forfettario' => ['esito' => $catena['forfettario']['esito'], 'ricavi' => $catena['forfettario']['ricavi']],
        ];
    }

    /**
     * Le misure con cui si giudicano le soglie dell'esercizio successivo: entrate della gestione, attivo e dipendenti medi dei dati dell'esercizio.
     * Un esercizio senza nessuna entrata né uscita confermata non ha dati: null (`Soglie` non ne ricava né un esito né il Modello E).
     * shortcut: l'esercizio storico 2025 (#63) non esiste ancora, quindi il 2025 è «senza dati» finché non arriva; da leggere qui.
     *
     * @return ?array{entrate: int, attivo: ?int, dipendenti: ?int}
     */
    private static function misura(int $anno, array $modelloD): ?array
    {
        $gestione = $modelloD['gestione'];
        if ($gestione['entrate']['importo'] === 0 && $gestione['uscite']['importo'] === 0) {
            return null;
        }
        $dati = DatiEsercizio::per($anno);

        return ['entrate' => $gestione['entrate']['importo'], 'attivo' => $dati->attivo, 'dipendenti' => $dati->dipendenti_medi];
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

    /**
     * @param  ?string  $fino  l'ultimo giorno contato, per un periodo parziale dell'esercizio (senza figurativi né saldi, che sono dell'anno intero)
     * @return array{0: array, 1: array} il Modello D e la catena, con il nome delle iniziative del pareggio
     */
    private static function calcolaCatena(int $anno, bool $proposte, ?string $fino = null): array
    {
        $impostazioni = ImpostazioniAssociazione::per($anno);
        $imputate = Prospetto::imputate(Prospetto::imputazioni($anno, $proposte, $fino), (bool) $impostazioni?->affiliazione_rete);
        $parziale = $fino !== null;
        $modelloD = $parziale ? Calcolo::modelloD($imputate, [], []) : Prospetto::modelloD($anno, $proposte, $imputate);
        $dati = DatiEsercizio::per($anno);

        $catena = TestNatura::calcola(
            $anno, $modelloD, $imputate, $parziale ? [] : Prospetto::figurativi($anno), $dati->criterio_riparto,
            self::storico2bis($anno), $impostazioni?->soglia_vicino ?? self::SOGLIA_VICINO_DI_PARTENZA, $dati->figurativi_dichiarati && ! $parziale,
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
