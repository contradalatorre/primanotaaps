<?php

namespace App\Esercizio;

/**
 * La catena dei test sulla natura commerciale (#54; #17: «Ordine: test 2-bis → natura commerciale calcolata → test dell'ente»).
 * Un modulo puro come `Secondarieta`: legge il Modello D già calcolato e le imputazioni, mai il database.
 *
 *  1. Art. 79 c. 2-bis sull'area A, aggregato (sotto i 300.000 euro tutte le attività di interesse generale sono una sola): i corrispettivi
 *     (A2, A3, A7, A9) contro i costi effettivi, cioè le uscite A più la quota delle spese generali (uscite E, oneri bancari D1 e imposte;
 *     figurativi esclusi). Si confrontano i corrispettivi di soci e terzi insieme: con il pro-rata della ricerca #7 (costi dei terzi =
 *     costi × terzi ÷ proventi) T ≤ C·T/P equivale a P ≤ C (per T > 0: senza corrispettivi di terzi il test è vuoto e vale come rispettato, `soloSoci`). Tolleranza del 6% per al massimo 3 esercizi consecutivi dal 2026.
 *  2. La natura commerciale di ogni entrata: la forzatura se c'è, poi il calcolo di `Natura`, che per il corrispettivo di un terzo
 *     in area A (`dipendeDal2bis`) diventa commerciale se il 2-bis non è rispettato.
 *  3. Art. 79 c. 5, l'ente: proventi commerciali contro entrate non commerciali. Sponsorizzazioni neutre (proprietà della tipologia),
 *     B2 e B4 commerciali (area B), valore normale delle prestazioni gratuite = proventi figurativi dell'area A.
 *  4. Forfettario art. 86 (#55), in coda alla catena: i ricavi commerciali contro gli 85.000 euro, senza calcolo d'imposta. Stessa natura di ogni
 *     entrata (`commerciale`), ma le sponsorizzazioni contano (sono neutre solo per l'ente, ricerca #7) e i contributi B2 e B4 contano per prudenza.
 *
 * Gli importi sono centesimi interi, i confronti si fanno per moltiplicazione in croce (le percentuali float servono solo a mostrare)
 * e dove un arrotondamento può aiutare chi è sotto test si arrotonda contro di lui (la quota delle spese generali, per difetto).
 *
 * shortcut: i prodotti sono interi da 64 bit, senza controllo di overflow: bastano per un'associazione sotto i 300.000 euro (a 3 milioni di
 * euro la quota delle spese generali supererebbe il limite). Da passare a una moltiplicazione sicura se l'ente cresce.
 */
final class TestNatura
{
    public const TOLLERANZA_PERCENTO = 6;

    public const TOLLERANZA_ESERCIZI = 3;

    public const PRIMO_ANNO_TOLLERANZA = 2026;

    /** Art. 86 c. 1 CTS (D.Lgs. 186/2025): ricavi dell'esercizio precedente non superiori a 85.000 euro; coefficiente del 3% per le APS (c. 3). */
    public const SOGLIA_FORFETTARIO = 8_500_000;

    public const COEFFICIENTE_FORFETTARIO = 3;

    /** Per il 2026 e il 2027 il cambio di qualifica dell'ente vale dal periodo successivo (art. 79 c. 5-ter, Circ. 1/E § 2.3.4). */
    private const ULTIMO_ANNO_CON_EFFETTO_DIFFERITO = 2027;

    public const STATI = ['pareggio' => 'Corrispettivi non superiori ai costi', 'tolleranza' => 'Entro la tolleranza del 6%', 'non_rispettato' => 'Non rispettato'];

    /**
     * @param  array<string, mixed>  $modelloD  il risultato di `Calcolo::modelloD`
     * @param  list<Imputata>  $imputazioni  con area, natura, forzatura e neutralità (le entrate); le uscite bastano con area e iniziativa
     * @param  list<Figurativo>  $figurativi
     * @param  string  $criterioRiparto  «ricavi» o «costi_diretti» (dati dell'esercizio): come si ripartiscono le spese generali
     * @param  array<int, string>  $storico  lo stato del 2-bis degli esercizi approvati (anno → «pareggio», «tolleranza», «non_rispettato»)
     * @param  int  $sogliaVicino  percentuale del limite oltre la quale l'esito è «vicino alla soglia»
     * @param  bool  $figurativiDichiarati  i dati dell'esercizio sono dichiarati completi: senza, il valore normale mancante potrebbe migliorare l'esito
     */
    public static function calcola(int $anno, array $modelloD, array $imputazioni, array $figurativi, string $criterioRiparto, array $storico, int $sogliaVicino, bool $figurativiDichiarati): array
    {
        $areaA = self::areaA($anno, $modelloD, $imputazioni, $criterioRiparto, $storico, $sogliaVicino);
        $nonRispettato = $areaA['stato'] === 'non_rispettato';

        // Passo 2: il 2-bis non rispettato rende commerciali i corrispettivi dei terzi in area A, salvo forzatura.
        foreach ($imputazioni as $i) {
            if ($nonRispettato && $i->lato === 'entrate' && $i->forzata === null && $i->natura?->dipendeDal2bis) {
                $areaA['reseCommerciali']['numero']++;
                $areaA['reseCommerciali']['importo'] += $i->importo;
            }
        }

        return [
            'anno' => $anno,
            'areaA' => $areaA,
            'ente' => self::ente($anno, $imputazioni, $figurativi, $nonRispettato, $sogliaVicino, $figurativiDichiarati),
            'pareggi' => self::pareggi($imputazioni),
            'forfettario' => self::forfettario($anno, $imputazioni, $nonRispettato, $sogliaVicino),
        ];
    }

    private static function areaA(int $anno, array $modelloD, array $imputazioni, string $criterioRiparto, array $storico, int $sogliaVicino): array
    {
        $aree = $modelloD['aree'];
        $righe = array_values(array_filter($aree['A']['entrate']['righe'], fn (array $r) => in_array($r['voce'], Natura::CORRISPETTIVI, true)));
        $corrispettivi = ['importo' => array_sum(array_column($righe, 'importo')), 'chiavi' => array_merge(...array_column($righe, 'chiavi'))];

        $diretti = $aree['A']['uscite']['totale'];
        $oneri = current(array_filter($aree['D']['uscite']['righe'], fn (array $r) => $r['voce'] === 'D1'));
        $parti = [
            ['etichetta' => 'Uscite di supporto generale (area E)', 'importo' => $aree['E']['uscite']['totale']['importo'], 'chiavi' => $aree['E']['uscite']['totale']['chiavi']],
            ['etichetta' => 'Oneri bancari e finanziari (D1)', 'importo' => $oneri['importo'], 'chiavi' => $oneri['chiavi']],
            ['etichetta' => 'Imposte', 'importo' => $modelloD['imposte']['importo'], 'chiavi' => $modelloD['imposte']['chiavi']],
        ];
        $generali = array_sum(array_column($parti, 'importo'));

        // La quota dell'area A, la più piccola tra le letture possibili (più costi andrebbero a favore): dei ricavi, cioè i corrispettivi
        // dell'area A sul totale delle entrate delle aree A–D (ricerca #7: «ricavi dell'attività» sul «totale ricavi e proventi»), o dei costi diretti (uscite A su A–D).
        $lato = $criterioRiparto === 'costi_diretti' ? 'uscite' : 'entrate';
        $numeratore = $lato === 'uscite' ? $diretti['importo'] : $corrispettivi['importo'];
        $denominatore = array_sum(array_map(fn (string $area) => $aree[$area][$lato]['totale']['importo'], ['A', 'B', 'C', 'D']));
        $quota = $denominatore > 0 ? intdiv($generali * $numeratore, $denominatore) : 0;

        $costi = $diretti['importo'] + $quota;
        $scarto = $corrispettivi['importo'] - $costi;
        // Senza corrispettivi di terzi (solo soci, decommercializzati dall'art. 85) il confronto pro-rata dei terzi è vuoto: T ≤ C·T/P vale per T = 0.
        // Conta ogni corrispettivo che non è di un socio, anche se commerciale per natura o forzato: è un provento di terzi.
        $soloSoci = $scarto > 0 && array_sum(array_map(fn (Imputata $i) => $i->lato === 'entrate' && $i->area === 'A' && in_array($i->voce, Natura::CORRISPETTIVI, true)
            && ! in_array($i->natura?->regola, ['corrispettivo-socio', 'somministrazione-ai-soci-affiliata'], true) ? $i->importo : 0, $imputazioni)) === 0;
        $tolleranza = self::tolleranza($anno, $storico);
        $stato = match (true) {
            $scarto <= 0 || $soloSoci => 'pareggio',
            $tolleranza['disponibile'] && 100 * $corrispettivi['importo'] <= (100 + self::TOLLERANZA_PERCENTO) * $costi => 'tolleranza',
            default => 'non_rispettato',
        };

        return [
            'corrispettivi' => $corrispettivi,
            'costi' => [
                'diretti' => $diretti,
                'generali' => ['importo' => $generali, 'parti' => $parti],
                'quota' => ['importo' => $quota, 'numeratore' => $numeratore, 'denominatore' => $denominatore, 'criterio' => $criterioRiparto === 'costi_diretti' ? 'costi_diretti' : 'ricavi'],
                'importo' => $costi,
            ],
            'rapporto' => $costi > 0 ? 100.0 * $corrispettivi['importo'] / $costi : ($corrispettivi['importo'] === 0 ? 0.0 : null),
            'scarto' => $scarto, // corrispettivi meno costi: positivo se li superano
            'limiteConTolleranza' => intdiv((100 + self::TOLLERANZA_PERCENTO) * $costi, 100), // per difetto
            'stato' => $stato,
            'esito' => match ($stato) {
                'pareggio' => ! $soloSoci && 100 * $corrispettivi['importo'] > $sogliaVicino * $costi ? 'vicino' : 'rispettato',
                'tolleranza' => 'vicino', // non commerciale, ma oltre la soglia piena: il triennio si consuma
                default => 'non_rispettato',
            },
            'soloSoci' => $soloSoci,
            'tolleranza' => $tolleranza,
            'reseCommerciali' => ['numero' => 0, 'importo' => 0],
        ];
    }

    /**
     * Gli esercizi consecutivi già chiusi con un avanzo entro il 6%, dagli esiti approvati. Il triennio riparte dopo un pareggio; dopo
     * un esercizio non rispettato serve il pareggio. Un esercizio dal 2026 senza esito approvato non si può contare: per prudenza
     * la tolleranza non c'è (il suo uso, ignoto, potrebbe averla già consumata).
     *
     * @param  array<int, string>  $storico
     * @return array{disponibile: bool, usati: int, massimo: int, percento: int, motivo: string}
     */
    private static function tolleranza(int $anno, array $storico): array
    {
        $risposta = fn (bool $disponibile, int $usati, string $motivo) => [
            'disponibile' => $disponibile, 'usati' => $usati, 'massimo' => self::TOLLERANZA_ESERCIZI, 'percento' => self::TOLLERANZA_PERCENTO, 'motivo' => $motivo,
        ];
        if ($anno < self::PRIMO_ANNO_TOLLERANZA) {
            return $risposta(false, 0, 'La tolleranza del 6% vale dal '.self::PRIMO_ANNO_TOLLERANZA.'.');
        }

        $usati = 0;
        $y = $anno - 1;
        while ($y >= self::PRIMO_ANNO_TOLLERANZA && ($storico[$y] ?? null) === 'tolleranza') {
            $usati++;
            $y--;
        }
        if ($y >= self::PRIMO_ANNO_TOLLERANZA && ! isset($storico[$y])) {
            return $risposta(false, $usati, "L’esercizio $y non ha un esito approvato: la tolleranza si conta dagli esiti approvati, quindi per ora non si applica.");
        }
        if ($y >= self::PRIMO_ANNO_TOLLERANZA && $storico[$y] === 'non_rispettato') {
            return $risposta(false, $usati, "L’esercizio $y non ha rispettato il test: quest’anno i corrispettivi non devono superare i costi, senza tolleranza.");
        }
        if ($usati >= self::TOLLERANZA_ESERCIZI) {
            return $risposta(false, $usati, 'Per '.self::TOLLERANZA_ESERCIZI.' esercizi consecutivi i corrispettivi hanno superato i costi entro il 6%: la tolleranza è finita e vale la soglia piena (corrispettivi non superiori ai costi).');
        }

        return $risposta(true, $usati, 'La tolleranza del 6% vale per al massimo '.self::TOLLERANZA_ESERCIZI.' esercizi consecutivi dal '.self::PRIMO_ANNO_TOLLERANZA.': negli esiti approvati ne sono stati usati '.$usati.', ne restano '.(self::TOLLERANZA_ESERCIZI - $usati).'.');
    }

    /** La natura che vale per un'entrata: la forzatura, poi il calcolo (con il 2-bis); null se non si può dire. */
    private static function commerciale(Imputata $i, bool $areaACommerciale): ?bool
    {
        if ($i->forzata !== null) {
            return $i->forzata;
        }

        return $areaACommerciale && $i->natura?->dipendeDal2bis ? true : $i->natura?->commerciale;
    }

    /**
     * Art. 79 c. 5: l'ente è commerciale se i proventi commerciali superano le entrate non commerciali. Contano le aree A, B e C
     * (D ed E sono fuori dai test, salvo le entrate commerciali per natura o forzate tali) e il valore normale delle prestazioni gratuite dell'area A, finché l'area A è non commerciale.
     */
    private static function ente(int $anno, array $imputazioni, array $figurativi, bool $areaACommerciale, int $sogliaVicino, bool $figurativiDichiarati): array
    {
        $commerciali = $nonCommerciali = $neutre = $fuori = $senzaNatura = 0;
        foreach ($imputazioni as $i) {
            if ($i->lato !== 'entrate' || $i->natura === null) {
                continue;
            }
            // Neutre: sponsorizzazioni e pubblicità (proprietà della tipologia), in qualunque area.
            if ($i->neutra) {
                $neutre += $i->importo;

                continue;
            }
            $commerciale = self::commerciale($i, $areaACommerciale);
            // D ed E non sono tra le non commerciali (fuori dal test), ma un'entrata commerciale per natura o forzata tale resta tra i proventi.
            if (! in_array($i->area, ['A', 'B', 'C'], true)) {
                $commerciale === true ? $commerciali += $i->importo : $fuori += $i->importo;

                continue;
            }
            // Le raccolte fondi abituali (C1) possono avere corrispettivo continuativo, e finché non si distingue dalle liberalità
            // si tengono neutre, mai tra le non commerciali; una forzatura le sposta (prevale).
            if ($i->area === 'C' && $i->voce === 'C1' && $i->forzata === null) {
                $neutre += $i->importo;

                continue;
            }
            if ($commerciale === null) {
                $senzaNatura++;
                $commerciale = true; // prudente
            }
            $commerciale ? $commerciali += $i->importo : $nonCommerciali += $i->importo;
        }

        $valoreNormale = $areaACommerciale ? 0 : array_sum(array_map(fn (Figurativo $f) => $f->tipo === 'provento' && $f->area === 'A' ? $f->importo : 0, $figurativi));
        $nonCommerciali += $valoreNormale;

        $esito = match (true) {
            $commerciali > $nonCommerciali => 'non_rispettato',
            100 * $commerciali > $sogliaVicino * $nonCommerciali => 'vicino',
            default => 'rispettato',
        };

        return [
            'commerciali' => $commerciali,
            'nonCommerciali' => $nonCommerciali,
            'neutre' => $neutre,
            'valoreNormale' => $valoreNormale,
            'fuoriDalTest' => $fuori,
            'senzaNatura' => $senzaNatura,
            'rapporto' => $nonCommerciali > 0 ? 100.0 * $commerciali / $nonCommerciali : ($commerciali === 0 ? 0.0 : null),
            'margine' => $nonCommerciali - $commerciali, // negativo se i proventi commerciali prevalgono
            'esito' => $esito,
            'effettoDal' => $esito === 'non_rispettato' ? ($anno <= self::ULTIMO_ANNO_CON_EFFETTO_DIFFERITO ? $anno + 1 : $anno) : null,
            'datoMancante' => ! $figurativiDichiarati,
        ];
    }

    /**
     * Art. 86: i ricavi commerciali dell'esercizio (A: i corrispettivi resi commerciali dal 2-bis o per natura; B: tutte le attività diverse,
     * sponsorizzazioni comprese; C, D ed E solo se la natura o una forzatura le fa commerciali: interessi, sopravvenienze e liberalità restano fuori).
     * Un'entrata di cui non si sa la natura conta (prudenza, come nell'ente). Il 3% si arrotonda per eccesso. L'accesso al regime si misura sull'esercizio
     * precedente (per il 2026 vale la previsione): qui si vedono i ricavi dell'esercizio, che sono quelli che contano dall'anno dopo.
     */
    private static function forfettario(int $anno, array $imputazioni, bool $areaACommerciale, int $sogliaVicino): array
    {
        $perArea = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
        $contributiB = $senzaNatura = 0;
        foreach ($imputazioni as $i) {
            if ($i->lato !== 'entrate' || $i->natura === null) {
                continue;
            }
            $commerciale = self::commerciale($i, $areaACommerciale);
            if ($commerciale === null && in_array($i->area, ['A', 'B', 'C'], true)) {
                $senzaNatura++;
                $commerciale = true;
            }
            if ($commerciale !== true) {
                continue;
            }
            $perArea[$i->area] = ($perArea[$i->area] ?? 0) + $i->importo;
            $contributiB += $i->area === 'B' && in_array($i->voce, ['B2', 'B4'], true) ? $i->importo : 0;
        }
        $ricavi = array_sum($perArea);
        $margine = self::SOGLIA_FORFETTARIO - $ricavi;

        return [
            'ricavi' => $ricavi,
            'perArea' => $perArea,
            'contributiB' => $contributiB,
            'senzaNatura' => $senzaNatura,
            'soglia' => self::SOGLIA_FORFETTARIO,
            'margine' => $margine, // negativo se la soglia è superata
            'rapporto' => 100.0 * $ricavi / self::SOGLIA_FORFETTARIO,
            'imponibile' => intdiv(self::COEFFICIENTE_FORFETTARIO * $ricavi + 99, 100), // per eccesso: stima, nessuna imposta
            'esito' => match (true) {
                $margine < 0 => 'non_rispettato',
                100 * $ricavi > $sogliaVicino * self::SOGLIA_FORFETTARIO => 'vicino',
                default => 'rispettato',
            },
            'uscitaDal' => $margine < 0 ? $anno + 1 : null, // art. 86 c. 14: dal periodo successivo a quello del superamento
        ];
    }

    /**
     * L'indicatore di pareggio per iniziativa in area A: corrispettivi contro costi diretti dell'iniziativa. Informativo, non è il test di
     * legge (che è aggregato e conta anche una quota delle spese generali): documenta una scelta come la Festa della Torre in A7.
     *
     * @return array<int, array{iniziativa: int, corrispettivi: int, costi: int, differenza: int}> per id dell'iniziativa
     */
    private static function pareggi(array $imputazioni): array
    {
        $pareggi = [];
        foreach ($imputazioni as $i) {
            if ($i->area !== 'A' || $i->iniziativa === null) {
                continue;
            }
            $pareggi[$i->iniziativa] ??= ['iniziativa' => $i->iniziativa, 'corrispettivi' => 0, 'costi' => 0, 'differenza' => 0];
            if ($i->lato === 'uscite') {
                $pareggi[$i->iniziativa]['costi'] += $i->importo;
            } elseif (in_array($i->voce, Natura::CORRISPETTIVI, true)) {
                $pareggi[$i->iniziativa]['corrispettivi'] += $i->importo;
            }
        }
        $pareggi = array_map(fn (array $p) => ['differenza' => $p['corrispettivi'] - $p['costi']] + $p, $pareggi);
        ksort($pareggi);

        return $pareggi;
    }
}
