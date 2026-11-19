<?php

namespace App\Esercizio;

use App\Models\DatiEsercizio;
use App\Models\EsercizioStorico;
use App\Models\ImpostazioniAssociazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\VersioneEsercizio;

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
            // Un esercizio approvato vale per com'era il giorno dell'approvazione (#59): una modifica tardiva non cambia né il confronto né le soglie dell'anno dopo.
            $esercizi[$y] = self::approvata($y) ?? self::calcolaCatena($y, $proposte);
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
     * I valori e gli esiti degli ultimi tre esercizi affiancati e, durante l'anno, lo stesso periodo dell'anno precedente (#17). Sono
     * calcolati dal vivo dai movimenti, come l'esercizio guardato, tranne gli esercizi approvati, che vengono dalla loro versione approvata (#59).
     * Nel periodo parziale i figurativi dell'anno intero non si contano (un costo in meno e nessun provento: mai a favore).
     *
     * @param  array<int, array{0: array, 1: array, 2?: array}>  $esercizi  il Modello D, la catena e (se approvato) la secondarietà dell'esercizio guardato e dei due precedenti
     * @return list<array<string, mixed>> le colonne, dalla più vecchia
     */
    private static function confronto(int $anno, bool $proposte, array $esercizi): array
    {
        // Un esercizio storico (#63) non ha movimenti né imputazioni per attività: dal foglio vengono solo le entrate e le uscite, nessun esito.
        $per = fn (string $etichetta, int $y) => ($storico = EsercizioStorico::modelloD($y)) !== null
            ? self::colonnaSenzaEsiti($etichetta, $storico['gestione']['entrate']['importo'], $storico['gestione']['uscite']['importo'])
            : self::colonna($etichetta, $y, ...$esercizi[$y]);
        $colonne = [];
        foreach ([$anno - 2, $anno - 1] as $y) {
            $colonne[] = $per((string) $y, $y);
        }
        if ($anno === today()->year) {
            $oggi = today();
            $periodo = $oggi->format('d/m');
            $fino = $oggi->copy()->subYearsNoOverflow(1)->toDateString();
            $etichetta = ($anno - 1).', stesso periodo (dal 1/1 al '.$periodo.')';
            // Lo storico ha solo i totali dell'anno: lo stesso periodo non si ricava, e uno zero sarebbe una cifra falsa.
            $colonne[] = EsercizioStorico::where('esercizio', $anno - 1)->exists()
                ? self::colonnaSenzaEsiti($etichetta.': non disponibile, esercizio storico', 0, 0, vuota: true)
                : self::colonna($etichetta, $anno - 1, ...self::calcolaCatena($anno - 1, $proposte, $fino));
            $colonne[] = self::colonna($anno.' (in corso)', $anno, ...$esercizi[$anno]);
        } else {
            $colonne[] = $per((string) $anno, $anno);
        }

        return $colonne;
    }

    private static function colonnaSenzaEsiti(string $etichetta, int $entrate, int $uscite, bool $vuota = false): array
    {
        return ['etichetta' => $etichetta, 'vuota' => $vuota, 'senzaEsiti' => true, 'entrate' => $entrate, 'uscite' => $uscite];
    }

    private static function colonna(string $etichetta, int $anno, array $modelloD, array $catena, ?array $s = null): array
    {
        $s ??= self::secondarietaDa($anno, $modelloD);
        $criterio = $s['confermato'] ?? $s['proposto']; // quello che il Tesoriere ha confermato, altrimenti il proposto
        $gestione = $modelloD['gestione'];

        return [
            'etichetta' => $etichetta,
            'senzaEsiti' => false,
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
     * Un esercizio storico (#63) non ha movimenti: le sue entrate vengono dagli importi caricati dal foglio; attivo e dipendenti medi dai dati dell'esercizio, come sempre.
     *
     * @return ?array{entrate: int, attivo: ?int, dipendenti: ?int}
     */
    private static function misura(int $anno, array $modelloD): ?array
    {
        $gestione = (EsercizioStorico::modelloD($anno) ?? $modelloD)['gestione'];
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
     * Si legge dalle versioni che oggi valgono, scritte all'approvazione (`catena.areaA.stato`, #59): una riapertura toglie l'esito, e senza un esito
     * approvato `TestNatura` non applica la tolleranza.
     *
     * @return array<int, string>
     */
    private static function storico2bis(int $anno): array
    {
        return VersioneEsercizio::where('esercizio', '<', $anno)->whereNull('riaperta_il')->pluck('stato_2bis', 'esercizio')->all();
    }

    /**
     * L'esercizio com'era il giorno dell'approvazione, se oggi è approvato: il Modello D, la catena e la secondarietà, come `calcolaCatena` più
     * la secondarietà salvata.
     *
     * @return ?array{0: array, 1: array, 2: array}
     */
    private static function approvata(int $anno): ?array
    {
        $istantanea = VersioneEsercizio::vigente($anno)?->istantanea;

        return $istantanea === null ? null : [$istantanea['modello_d'], $istantanea['catena'], $istantanea['secondarieta']];
    }

    /**
     * Quel che si salva all'approvazione e quel con cui si confronta dopo: il Modello D, i test dell'area A, dell'ente e forfettario, la secondarietà
     * (senza i dati dell'esercizio, che non servono e non sono dati puri) e il rendiconto per l'assemblea. Solo le imputazioni confermate.
     *
     * @return array{modello_d: array, catena: array, secondarieta: array, rendiconto: array}
     */
    public static function istantanea(int $anno): array
    {
        [$modelloD, $catena] = self::calcolaCatena($anno, false);
        $secondarieta = self::secondarietaDa($anno, $modelloD);
        unset($secondarieta['dati']);

        return ['modello_d' => $modelloD, 'catena' => $catena, 'secondarieta' => $secondarieta, 'rendiconto' => Prospetto::rendiconto($anno)];
    }

    /**
     * Il rientro dopo una secondarietà non rispettata (art. 4 DM 107/2021): lo stesso criterio e la soglia ridotta, fissati dalla versione approvata dell'esercizio prima
     * con la soglia più bassa, anche se poi riaperta o sostituita (un esito approvato non si dimentica: sarebbe a favore dell'ente). Se l'esercizio prima è storico (#63),
     * dagli importi del foglio.
     *
     * @return ?array{criterio: string, soglia: int}
     */
    public static function rientro(int $anno): ?array
    {
        $versione = VersioneEsercizio::conRientro($anno - 1);
        if ($versione !== null) {
            return ['criterio' => $versione->criterio_secondarieta, 'soglia' => $versione->soglia_rientro_centesimi_di_punto];
        }
        $storico = EsercizioStorico::modelloD($anno - 1);

        return $storico === null ? null : self::rientroDelloStorico($storico);
    }

    /**
     * Uno storico non ha un criterio confermato né approvato: ne basta uno non rispettato (la lettura più severa, mai a favore dell'ente, come per le forzature
     * «non commerciale» da rivedere) e il rientro è sul criterio che non regge; se non reggono né il 30% né il 66%, su quello con la soglia ridotta più bassa.
     * shortcut: chi ha rispettato il 66% ma non il 30% si ritrova la soglia ridotta e il criterio a, che il commercialista potrebbe non confermare: da rivedere con lui.
     *
     * @return ?array{criterio: string, soglia: int}
     */
    private static function rientroDelloStorico(array $modelloD): ?array
    {
        $criteri = Secondarieta::calcola($modelloD, true, self::SOGLIA_VICINO_DI_PARTENZA, null)['criteri'];
        $soglie = [];
        foreach ($criteri as $criterio => $c) {
            if ($c['esito'] === 'non_rispettato') {
                $soglie[$criterio] = Secondarieta::sogliaDiRientro($c);
            }
        }
        asort($soglie);

        return $soglie === [] ? null : ['criterio' => array_key_first($soglie), 'soglia' => reset($soglie)];
    }

    private static function secondarietaDa(int $anno, array $modelloD): array
    {
        $dati = DatiEsercizio::per($anno);
        $vicino = ImpostazioniAssociazione::per($anno)?->soglia_vicino ?? self::SOGLIA_VICINO_DI_PARTENZA;

        return Secondarieta::calcola($modelloD, $dati->figurativi_dichiarati, $vicino, $dati->criterio_secondarieta, self::rientro($anno)) + ['dati' => $dati];
    }

    /**
     * Le entrate e le uscite che attendono conferma: i movimenti proposti (il lato è quello della tipologia) e quelli ancora da classificare
     * (il verso; un movimento importato nasce così, senza imputazioni). Quanti movimenti e per quanti centesimi. Lo chiede solo la pagina dei test, non la home.
     *
     * @return array{entrate: array{numero: int, importo: int}, uscite: array{numero: int, importo: int}}
     */
    public static function inAttesa(int $anno): array
    {
        $attese = Prospetto::imputazioni($anno, true)->filter(fn (array $r) => $r['imputazione']->movimento->stato === 'proposto');
        $nonClassificati = Movimento::senzaGiroconto()->whereBetween('data', ["$anno-01-01", "$anno-12-31"])->where('stato', 'da_classificare')->get();
        $per = fn (string $lato, string $verso) => [
            'numero' => $attese->where('lato', $lato)->pluck('imputazione.movimento_id')->unique()->count() + $nonClassificati->where('verso', $verso)->count(),
            'importo' => $attese->where('lato', $lato)->sum(fn (array $r) => abs($r['imputazione']->importo)) + $nonClassificati->where('verso', $verso)->sum('importo'),
        ];

        return ['entrate' => $per('entrate', 'entrata'), 'uscite' => $per('uscite', 'uscita')];
    }
}
