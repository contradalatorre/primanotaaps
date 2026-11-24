<?php

namespace Tests\Unit;

use App\Esercizio\Calcolo;
use App\Esercizio\Figurativo;
use App\Esercizio\Imputata;
use App\Esercizio\Natura;
use App\Esercizio\TestNatura;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La catena dei test dell'area A e dell'ente (#54) sul modulo puro, a tabella: importi inventati, in centesimi.
 * Ogni riga dà le imputazioni in ingresso, i valori che quel caso vuole fissare e, se servono, le opzioni (anno, storico
 * dei 2-bis approvati, criterio di riparto, soglia «vicino», figurativi dichiarati, figurativi).
 */
class TestNaturaTest extends TestCase
{
    /** Un'entrata con la natura che le dà `Natura::calcola`; `o`: proprieta, controparte, forzata, iniziativa, senzaTipologia. */
    private static function e(string $area, ?string $voce, int $importo, array $o = []): Imputata
    {
        $natura = Natura::calcola([
            'lato' => 'entrate', 'area' => $area, 'voce' => $voce, 'tipologia_id' => ($o['senzaTipologia'] ?? false) ? null : 1,
            'proprieta' => $o['proprieta'] ?? [], 'tipo_controparte' => $o['controparte'] ?? null, 'affiliazione_rete' => false,
        ]);

        return new Imputata('entrate', $voce, $importo, $area, $o['iniziativa'] ?? null, $natura, $o['forzata'] ?? null, in_array('neutra_nel_test_dell_ente', $o['proprieta'] ?? [], true), in_array('liberalita', $o['proprieta'] ?? [], true));
    }

    private static function sponsor(int $importo): Imputata
    {
        return self::e('B', 'B3', $importo, ['proprieta' => ['commerciale_per_natura', 'neutra_nel_test_dell_ente']]);
    }

    private static function u(string $area, string $voce, int $importo, ?int $iniziativa = null): Imputata
    {
        return new Imputata('uscite', $voce, $importo, $area, $iniziativa);
    }

    /** @return array<string, array{0: list<Imputata>, 1: array<string, mixed>, 2?: array<string, mixed>}> */
    public static function casi(): array
    {
        $tolleranza = fn (int ...$anni) => array_fill_keys($anni, 'tolleranza');

        return [
            // --- 2-bis: pareggio, tolleranza, oltre ---
            'corrispettivi sotto i costi: rispettato' => [
                [self::e('A', 'A7', 5000), self::u('A', 'A2', 12000)],
                ['areaA.stato' => 'pareggio', 'areaA.esito' => 'rispettato', 'areaA.scarto' => -7000, 'areaA.corrispettivi.importo' => 5000, 'areaA.costi.importo' => 12000, 'areaA.reseCommerciali.numero' => 0],
            ],
            'corrispettivi uguali ai costi: il limite, quindi vicino' => [
                [self::e('A', 'A7', 12000), self::u('A', 'A2', 12000)],
                ['areaA.stato' => 'pareggio', 'areaA.esito' => 'vicino', 'areaA.scarto' => 0, 'areaA.rapporto' => 100.0],
            ],
            'oltre l\'80% del limite: vicino' => [
                [self::e('A', 'A7', 9700), self::u('A', 'A2', 12000)],
                ['areaA.esito' => 'vicino', 'areaA.stato' => 'pareggio'],
            ],
            'esattamente il 6% sopra i costi: in tolleranza (non commerciale, ma vicino)' => [
                [self::e('A', 'A7', 10600), self::u('A', 'A2', 10000)],
                ['areaA.stato' => 'tolleranza', 'areaA.esito' => 'vicino', 'areaA.tolleranza.disponibile' => true, 'areaA.tolleranza.usati' => 0, 'areaA.reseCommerciali.numero' => 0, 'areaA.scarto' => 600],
            ],
            'un centesimo oltre il 6%: non rispettato, i corrispettivi diventano commerciali' => [
                [self::e('A', 'A7', 10601), self::u('A', 'A2', 10000)],
                ['areaA.stato' => 'non_rispettato', 'areaA.esito' => 'non_rispettato', 'areaA.reseCommerciali.numero' => 1, 'areaA.reseCommerciali.importo' => 10601],
            ],
            'senza costi e con corrispettivi: non rispettato, rapporto non calcolabile' => [
                [self::e('A', 'A7', 100)],
                ['areaA.stato' => 'non_rispettato', 'areaA.rapporto' => null, 'areaA.costi.importo' => 0],
            ],
            'senza niente: rispettato' => [
                [],
                ['areaA.stato' => 'pareggio', 'areaA.esito' => 'rispettato', 'areaA.rapporto' => 0.0, 'areaA.scarto' => 0],
            ],
            'i corrispettivi dei soci contano nel confronto ma restano non commerciali: si rende commerciale solo quello dei terzi' => [
                [self::e('A', 'A7', 9000), self::e('A', 'A2', 3000), self::u('A', 'A2', 10000)],
                ['areaA.corrispettivi.importo' => 12000, 'areaA.stato' => 'non_rispettato', 'areaA.reseCommerciali.numero' => 1, 'areaA.reseCommerciali.importo' => 9000],
            ],
            'solo corrispettivi dei soci: il confronto dei terzi è vuoto, niente diventa commerciale e il valore normale conta' => [
                [self::e('A', 'A2', 12000), self::u('A', 'A2', 10000)],
                ['areaA.stato' => 'pareggio', 'areaA.esito' => 'rispettato', 'areaA.soloSoci' => true, 'areaA.reseCommerciali.numero' => 0, 'ente.commerciali' => 0, 'ente.nonCommerciali' => 12000 + 500, 'ente.valoreNormale' => 500],
                ['figurativi' => [new Figurativo('provento', 'A', 'prestazioni gratuite', 500)]],
            ],
            'la quota associativa non è un corrispettivo' => [
                [self::e('A', 'A1', 50000), self::u('A', 'A2', 1000)],
                ['areaA.corrispettivi.importo' => 0, 'areaA.stato' => 'pareggio'],
            ],
            'la forzatura prevale: resta non commerciale anche se il test non regge' => [
                [self::e('A', 'A7', 10601, ['forzata' => false]), self::u('A', 'A2', 10000)],
                ['areaA.stato' => 'non_rispettato', 'areaA.reseCommerciali.numero' => 0, 'areaA.reseCommerciali.importo' => 0],
            ],

            // --- costi effettivi: uscite A + quota delle spese generali ---
            'spese generali, oneri finanziari e imposte entrano per la quota dei ricavi; i costi figurativi no' => [
                // quota A = 10.000 / (10.000 A + 10.000 B) = 50%; spese generali 4.000 + 500 + 1.000 = 5.500 → 2.750
                [self::e('A', 'A7', 10000), self::e('B', 'B3', 10000), self::u('A', 'A2', 8000), self::u('E', 'E2', 4000), self::u('D', 'D1', 500), self::u('E', 'Imposte', 1000)],
                ['areaA.costi.diretti.importo' => 8000, 'areaA.costi.generali.importo' => 5500, 'areaA.costi.quota.importo' => 2750, 'areaA.costi.importo' => 10750, 'areaA.stato' => 'pareggio'],
                ['figurativi' => [new Figurativo('costo', 'A', 'ore', 999999)]],
            ],
            'le altre uscite di D (investimenti finanziari) non sono spese generali' => [
                [self::e('A', 'A7', 10000), self::u('A', 'A2', 8000), self::u('D', 'D2', 5000)],
                ['areaA.costi.generali.importo' => 0, 'areaA.costi.importo' => 8000],
            ],
            'riparto per ricavi: conta il corrispettivo dell\'area A, non la quota associativa (la quota sarebbe più grande, a favore)' => [
                // 10.000 di corrispettivi su 40.000 di entrate A–D (con 20.000 di quote) = 25% di 4.000
                [self::e('A', 'A7', 10000), self::e('A', 'A1', 20000), self::e('B', 'B3', 10000), self::u('E', 'E2', 4000)],
                ['areaA.costi.quota.importo' => 1000, 'areaA.costi.quota.numeratore' => 10000, 'areaA.costi.quota.denominatore' => 40000],
            ],
            'riparto per costi diretti: la quota è delle uscite A sulle uscite delle attività' => [
                // quota A = 8.000 / (8.000 A + 2.000 B) = 80%; spese generali 4.000 + 1.000 → 4.000
                [self::e('A', 'A7', 10000), self::u('A', 'A2', 8000), self::u('B', 'B2', 2000), self::u('E', 'E2', 4000), self::u('E', 'Imposte', 1000)],
                ['areaA.costi.quota.importo' => 4000, 'areaA.costi.importo' => 12000],
                ['riparto' => 'costi_diretti'],
            ],
            'la quota si arrotonda per difetto: più costi sarebbe a favore' => [
                // 1.001 × 1.000 / 3.000 = 333,67
                [self::e('A', 'A7', 1000), self::e('B', 'B3', 2000), self::u('E', 'E2', 1001)],
                ['areaA.costi.quota.importo' => 333, 'areaA.costi.importo' => 333],
            ],
            'senza ricavi nel riparto la quota è zero' => [
                [self::u('E', 'E2', 1000)],
                ['areaA.costi.quota.importo' => 0, 'areaA.stato' => 'pareggio'],
            ],

            // --- tolleranza del 6%: massimo 3 esercizi consecutivi dal 2026, contati dagli esiti approvati ---
            'il primo esercizio, il 2026, ha la tolleranza' => [
                [self::e('A', 'A7', 10300), self::u('A', 'A2', 10000)],
                ['areaA.stato' => 'tolleranza', 'areaA.tolleranza.usati' => 0, 'areaA.tolleranza.massimo' => 3, 'areaA.tolleranza.percento' => 6],
            ],
            'due esercizi in tolleranza: ne resta uno' => [
                [self::e('A', 'A7', 10300), self::u('A', 'A2', 10000)],
                ['areaA.stato' => 'tolleranza', 'areaA.tolleranza.usati' => 2, 'areaA.tolleranza.disponibile' => true],
                ['anno' => 2028, 'storico' => $tolleranza(2026, 2027)],
            ],
            'triennio esaurito: soglia piena' => [
                [self::e('A', 'A7', 10300), self::u('A', 'A2', 10000)],
                ['areaA.stato' => 'non_rispettato', 'areaA.tolleranza.usati' => 3, 'areaA.tolleranza.disponibile' => false],
                ['anno' => 2029, 'storico' => $tolleranza(2026, 2027, 2028)],
            ],
            'triennio esaurito, ma chiuso a pareggio il quarto anno: rispettato' => [
                [self::e('A', 'A7', 10000), self::u('A', 'A2', 10000)],
                ['areaA.stato' => 'pareggio'],
                ['anno' => 2029, 'storico' => $tolleranza(2026, 2027, 2028)],
            ],
            'dopo un esercizio non rispettato serve il pareggio: niente tolleranza' => [
                [self::e('A', 'A7', 10300), self::u('A', 'A2', 10000)],
                ['areaA.stato' => 'non_rispettato', 'areaA.tolleranza.disponibile' => false],
                ['anno' => 2029, 'storico' => [2028 => 'non_rispettato']],
            ],
            'un pareggio fa ripartire il triennio' => [
                [self::e('A', 'A7', 10300), self::u('A', 'A2', 10000)],
                ['areaA.stato' => 'tolleranza', 'areaA.tolleranza.usati' => 0],
                ['anno' => 2029, 'storico' => [2028 => 'pareggio', 2027 => 'tolleranza', 2026 => 'tolleranza']],
            ],
            'l\'esercizio precedente non è approvato: la tolleranza non si può contare' => [
                [self::e('A', 'A7', 10300), self::u('A', 'A2', 10000)],
                ['areaA.stato' => 'non_rispettato', 'areaA.tolleranza.disponibile' => false],
                ['anno' => 2027, 'storico' => []],
            ],
            'prima del 2026 non c\'è tolleranza' => [
                [self::e('A', 'A7', 10300), self::u('A', 'A2', 10000)],
                ['areaA.stato' => 'non_rispettato', 'areaA.tolleranza.disponibile' => false],
                ['anno' => 2025],
            ],

            // --- l'ordine: 2-bis → natura commerciale → test dell'ente ---
            'con il 2-bis rispettato il corrispettivo dell\'area A è non commerciale e l\'ente regge' => [
                [self::e('A', 'A7', 5000), self::e('A', 'A1', 1000), self::e('B', 'B3', 4000), self::u('A', 'A2', 20000)],
                ['areaA.stato' => 'pareggio', 'ente.commerciali' => 4000, 'ente.nonCommerciali' => 6000, 'ente.esito' => 'rispettato', 'ente.margine' => 2000],
            ],
            'con il 2-bis non rispettato lo stesso corrispettivo diventa commerciale e l\'ente non regge' => [
                [self::e('A', 'A7', 5000), self::e('A', 'A1', 1000), self::e('B', 'B3', 4000), self::u('A', 'A2', 4000)],
                ['areaA.stato' => 'non_rispettato', 'ente.commerciali' => 9000, 'ente.nonCommerciali' => 1000, 'ente.esito' => 'non_rispettato', 'ente.margine' => -8000],
            ],

            // --- test dell'ente ---
            'le sponsorizzazioni sono neutre' => [
                [self::sponsor(9000), self::e('A', 'A1', 1000)],
                ['ente.neutre' => 9000, 'ente.commerciali' => 0, 'ente.nonCommerciali' => 1000, 'ente.esito' => 'rispettato'],
            ],
            'i contributi B2 sono commerciali' => [
                [self::e('B', 'B2', 6000), self::e('A', 'A1', 5000)],
                ['ente.commerciali' => 6000, 'ente.nonCommerciali' => 5000, 'ente.esito' => 'non_rispettato'],
            ],
            'i contributi B4 sono commerciali' => [
                [self::e('B', 'B4', 6000), self::e('A', 'A1', 5000)],
                ['ente.commerciali' => 6000, 'ente.esito' => 'non_rispettato'],
            ],
            'una forzatura prevale anche su un contributo B2' => [
                [self::e('B', 'B2', 6000, ['forzata' => false]), self::e('A', 'A1', 5000)],
                ['ente.commerciali' => 0, 'ente.nonCommerciali' => 11000, 'ente.esito' => 'rispettato'],
            ],
            'la raccolta fondi occasionale è non commerciale, quella abituale è neutra' => [
                [self::e('C', 'C2', 4000), self::e('C', 'C1', 7000), self::e('B', 'B3', 3000)],
                ['ente.nonCommerciali' => 4000, 'ente.neutre' => 7000, 'ente.commerciali' => 3000, 'ente.esito' => 'rispettato'],
            ],
            'nel test dell’ente anche una liberalità in C1 resta neutra, finché nessuna fonte dice altro (#61)' => [
                [self::e('C', 'C1', 7000, ['proprieta' => ['liberalita']]), self::e('B', 'B3', 3000)],
                ['ente.neutre' => 7000, 'ente.nonCommerciali' => 0, 'ente.commerciali' => 3000],
            ],
            'una forzatura sposta anche una raccolta fondi abituale dai neutri' => [
                [self::e('C', 'C1', 8000, ['forzata' => false]), self::e('B', 'B3', 3000)],
                ['ente.neutre' => 0, 'ente.nonCommerciali' => 8000, 'ente.esito' => 'rispettato'],
            ],
            'il valore normale delle prestazioni gratuite dell\'area A pesa tra le non commerciali' => [
                [self::e('A', 'A1', 1000), self::e('B', 'B3', 2000)],
                ['ente.valoreNormale' => 3000, 'ente.nonCommerciali' => 4000, 'ente.esito' => 'rispettato'],
                ['figurativi' => [new Figurativo('provento', 'A', 'prestazioni gratuite', 3000)]],
            ],
            'senza il valore normale lo stesso ente non regge' => [
                [self::e('A', 'A1', 1000), self::e('B', 'B3', 2000)],
                ['ente.valoreNormale' => 0, 'ente.esito' => 'non_rispettato'],
            ],
            'il valore normale delle attività diverse e i costi figurativi non contano' => [
                [self::e('A', 'A1', 1000), self::e('B', 'B3', 2000)],
                ['ente.valoreNormale' => 0, 'ente.esito' => 'non_rispettato'],
                ['figurativi' => [new Figurativo('provento', 'B', 'prestazioni gratuite', 3000), new Figurativo('costo', 'A', 'volontari', 3000)]],
            ],
            'se il 2-bis non regge l\'area A è commerciale e il suo valore normale non conta' => [
                [self::e('A', 'A7', 5000), self::u('A', 'A2', 1000), self::e('B', 'B3', 2000)],
                ['areaA.stato' => 'non_rispettato', 'ente.valoreNormale' => 0, 'ente.nonCommerciali' => 0, 'ente.esito' => 'non_rispettato'],
                ['figurativi' => [new Figurativo('provento', 'A', 'prestazioni gratuite', 9000)]],
            ],
            'le entrate di D ed E sono fuori dal test' => [
                [self::e('D', 'D1', 9000), self::e('B', 'B3', 1000)],
                ['ente.fuoriDalTest' => 9000, 'ente.nonCommerciali' => 0, 'ente.commerciali' => 1000, 'ente.esito' => 'non_rispettato'],
            ],
            'le entrate di D ed E commerciali per natura o forzate commerciali contano tra i proventi' => [
                [self::e('A', 'A1', 1000), self::e('E', 'E1', 5000, ['proprieta' => ['commerciale_per_natura']]), self::e('D', 'D3', 5000, ['forzata' => true])],
                ['ente.commerciali' => 10000, 'ente.nonCommerciali' => 1000, 'ente.fuoriDalTest' => 0, 'ente.esito' => 'non_rispettato'],
            ],
            'una sponsorizzazione neutra imputata a E resta neutra, non diventa un provento' => [
                [self::e('E', 'E1', 5000, ['proprieta' => ['commerciale_per_natura', 'neutra_nel_test_dell_ente']]), self::e('A', 'A1', 1000)],
                ['ente.neutre' => 5000, 'ente.commerciali' => 0, 'ente.fuoriDalTest' => 0, 'ente.esito' => 'rispettato'],
            ],
            'un\'entrata di D forzata non commerciale resta fuori dal test' => [
                [self::e('D', 'D3', 5000, ['forzata' => false]), self::e('A', 'A1', 1000)],
                ['ente.commerciali' => 0, 'ente.nonCommerciali' => 1000, 'ente.fuoriDalTest' => 5000, 'ente.esito' => 'rispettato'],
            ],
            'solo soci: un corrispettivo di terzi commerciale per natura conta, il test non è vuoto' => [
                [self::e('A', 'A2', 10000), self::e('A', 'A7', 5000, ['proprieta' => ['commerciale_per_natura']]), self::u('A', 'A2', 8000)],
                ['areaA.soloSoci' => false, 'areaA.stato' => 'non_rispettato'],
            ],
            'un\'entrata di cui non si sa la natura conta come commerciale e si segnala' => [
                [self::e('A', null, 3000, ['senzaTipologia' => true]), self::e('A', 'A1', 10000)],
                ['ente.senzaNatura' => 1, 'ente.commerciali' => 3000, 'ente.nonCommerciali' => 10000],
            ],
            'un\'entrata di D o E di cui non si sa la natura conta come commerciale e si segnala, come in A-C' => [
                [self::e('D', null, 4000, ['senzaTipologia' => true]), self::e('A', 'A1', 10000)],
                ['ente.senzaNatura' => 1, 'ente.commerciali' => 4000, 'ente.fuoriDalTest' => 0, 'ente.nonCommerciali' => 10000, 'forfettario.senzaNatura' => 1, 'forfettario.ricavi' => 4000],
            ],
            'oltre l\'80% del limite: vicino' => [
                [self::e('A', 'A1', 1000), self::e('B', 'B3', 900)],
                ['ente.esito' => 'vicino', 'ente.margine' => 100],
            ],
            'senza entrate: rispettato' => [
                [],
                ['ente.esito' => 'rispettato', 'ente.margine' => 0, 'ente.rapporto' => 0.0],
            ],
            'solo entrate commerciali: non rispettato, rapporto non calcolabile' => [
                [self::e('B', 'B3', 100)],
                ['ente.esito' => 'non_rispettato', 'ente.rapporto' => null],
            ],
            'dato mancante: figurativi non dichiarati' => [
                [self::e('A', 'A1', 1000)],
                ['ente.datoMancante' => true],
                ['dichiarati' => false],
            ],
            'per il 2026 l\'effetto del mancato rispetto vale dal 2027' => [
                [self::e('B', 'B3', 100)],
                ['ente.esito' => 'non_rispettato', 'ente.effettoDal' => 2027],
            ],
            'per il 2028 l\'effetto vale dall\'anno stesso' => [
                [self::e('B', 'B3', 100)],
                ['ente.effettoDal' => 2028],
                ['anno' => 2028, 'storico' => [2027 => 'pareggio']],
            ],
            'rispettato: nessun effetto' => [
                [self::e('A', 'A1', 100)],
                ['ente.esito' => 'rispettato', 'ente.effettoDal' => null],
            ],

            // --- pareggio per iniziativa (informativo) ---
            'pareggio per iniziativa in area A: corrispettivi contro costi diretti' => [
                [
                    self::e('A', 'A7', 80000, ['iniziativa' => 7]), self::e('A', 'A1', 100, ['iniziativa' => 7]), self::u('A', 'A2', 30000, 7), self::u('A', 'A1', 15000, 7),
                    self::u('A', 'A2', 4000, 8), self::e('B', 'B3', 5000, ['iniziativa' => 9]), self::u('E', 'E2', 700, 7),
                ],
                ['pareggi.7.corrispettivi' => 80000, 'pareggi.7.costi' => 45000, 'pareggi.7.differenza' => 35000, 'pareggi.8.corrispettivi' => 0, 'pareggi.8.costi' => 4000, 'pareggi.8.differenza' => -4000],
            ],
        ];
    }

    /**
     * @param  list<Imputata>  $imputazioni
     * @param  array<string, mixed>  $atteso
     * @param  array<string, mixed>  $opzioni
     */
    #[DataProvider('casi')]
    public function test_a_tabella(array $imputazioni, array $atteso, array $opzioni = []): void
    {
        $this->assertCasi(self::esegui($imputazioni, $opzioni), $atteso);
    }

    /** @param  array<string, mixed>  $atteso */
    private function assertCasi(array $r, array $atteso): void
    {
        foreach ($atteso as $percorso => $valore) {
            $trovato = Arr::get($r, $percorso);
            $this->assertEquals($valore, $trovato, "Valore di $percorso");
            $this->assertSame(gettype($valore), gettype($trovato), "Tipo di $percorso");
        }
    }

    /** @return array<string, array{0: list<Imputata>, 1: array<string, mixed>}> */
    public static function casiForfettario(): array
    {
        return [
            'senza entrate: rispettato, niente imponibile' => [[], ['forfettario.ricavi' => 0, 'forfettario.esito' => 'rispettato', 'forfettario.imponibile' => 0, 'forfettario.uscitaDal' => null, 'forfettario.margine' => 8500000]],
            'quote e liberalità non sono ricavi' => [[self::e('A', 'A1', 5000), self::e('A', 'A4', 7000), self::e('C', 'C2', 900)], ['forfettario.ricavi' => 0]],
            'un corrispettivo di terzi in area A, finché il 2-bis regge, non è un ricavo' => [
                [self::e('A', 'A7', 5000), self::u('A', 'A2', 12000)],
                ['forfettario.ricavi' => 0],
            ],
            'col 2-bis non rispettato lo stesso corrispettivo diventa un ricavo' => [
                [self::e('A', 'A7', 10601), self::u('A', 'A2', 10000)],
                ['forfettario.ricavi' => 10601, 'forfettario.perArea.A' => 10601],
            ],
            'la somministrazione è commerciale per natura anche col 2-bis rispettato' => [
                [self::e('A', 'A7', 4000, ['proprieta' => ['commerciale_per_natura', 'somministrazione']]), self::u('A', 'A2', 12000)],
                ['forfettario.ricavi' => 4000],
            ],
            'una sponsorizzazione è neutra per l’ente ma conta per il forfettario' => [
                [self::sponsor(6000), self::e('B', 'B3', 14000)],
                ['forfettario.ricavi' => 20000, 'forfettario.perArea.B' => 20000, 'ente.neutre' => 6000, 'ente.commerciali' => 14000],
            ],
            'i contributi B2 e B4 contano per prudenza e si vedono a parte' => [
                [self::e('B', 'B2', 3000), self::e('B', 'B4', 2000), self::e('B', 'B3', 1000)],
                ['forfettario.ricavi' => 6000, 'forfettario.contributiB' => 5000],
            ],
            'gli interessi (area D) restano fuori, una forzatura li porta dentro' => [
                [self::e('D', 'D1', 800), self::e('D', 'D1', 300, ['forzata' => true])],
                ['forfettario.ricavi' => 300, 'forfettario.perArea.D' => 300],
            ],
            'una forzatura a non commerciale toglie dai ricavi anche un’entrata di area B' => [
                [self::e('B', 'B3', 5000, ['forzata' => false]), self::e('B', 'B3', 1000)],
                ['forfettario.ricavi' => 1000],
            ],
            'una raccolta fondi abituale (C1) conta tra i ricavi per prudenza e si vede a parte, forzata a non commerciale no' => [
                [self::e('C', 'C1', 4000), self::e('C', 'C1', 600, ['forzata' => true]), self::e('C', 'C1', 900, ['forzata' => false])],
                ['forfettario.ricavi' => 4600, 'forfettario.perArea.C' => 4600, 'forfettario.raccolteC1' => 4000],
            ],
            'una liberalità in C1 non è un ricavo: conta solo la parte corrispettiva della raccolta abituale (#61)' => [
                [self::e('C', 'C1', 4000, ['proprieta' => ['liberalita']]), self::e('C', 'C1', 1500)],
                ['forfettario.ricavi' => 1500, 'forfettario.perArea.C' => 1500, 'forfettario.raccolteC1' => 1500],
            ],
            'una liberalità in C1 forzata a commerciale conta, come ogni forzatura' => [
                [self::e('C', 'C1', 4000, ['proprieta' => ['liberalita'], 'forzata' => true])],
                ['forfettario.ricavi' => 4000],
            ],
            'un’entrata senza tipologia non si sa se è commerciale: per prudenza conta' => [
                [self::e('B', 'B3', 700, ['senzaTipologia' => true])],
                ['forfettario.ricavi' => 700, 'forfettario.senzaNatura' => 1],
            ],
            'oltre l’80% della soglia: vicino' => [
                [self::e('B', 'B3', 6800001)],
                ['forfettario.esito' => 'vicino', 'forfettario.uscitaDal' => null],
            ],
            'esattamente 85.000 euro: vicino, non oltre la soglia' => [
                [self::e('B', 'B3', 8500000)],
                ['forfettario.esito' => 'vicino', 'forfettario.margine' => 0, 'forfettario.uscitaDal' => null],
            ],
            'un centesimo oltre: non rispettato, si esce dall’anno successivo' => [
                [self::e('B', 'B3', 8500001)],
                ['forfettario.esito' => 'non_rispettato', 'forfettario.margine' => -1, 'forfettario.uscitaDal' => 2027],
            ],
            'il 3% si arrotonda per eccesso (mai a favore)' => [
                [self::e('B', 'B3', 1001)],
                ['forfettario.imponibile' => 31],
            ],
            'il 3% di un importo esatto non si tocca' => [
                [self::e('B', 'B3', 20000)],
                ['forfettario.imponibile' => 600],
            ],
        ];
    }

    /**
     * @param  list<Imputata>  $imputazioni
     * @param  array<string, mixed>  $atteso
     */
    #[DataProvider('casiForfettario')]
    public function test_il_forfettario_a_tabella(array $imputazioni, array $atteso): void
    {
        $this->assertCasi(self::esegui($imputazioni), $atteso);
    }

    public function test_il_pareggio_per_iniziativa_non_ha_l_iniziativa_in_area_b(): void
    {
        $r = self::esegui([self::e('B', 'B3', 5000, ['iniziativa' => 9]), self::u('B', 'B2', 100, 9)]);

        $this->assertSame([], $r['pareggi']);
    }

    public function test_un_iniziativa_con_sole_uscite_in_area_a_ha_il_suo_pareggio(): void
    {
        $r = self::esegui([self::u('A', 'A2', 4000, 8)]);

        $this->assertSame([8], array_keys($r['pareggi']));
    }

    public function test_le_sponsorizzazioni_non_sono_nel_2_bis_e_restano_commerciali_ma_neutre(): void
    {
        $r = self::esegui([self::sponsor(9000), self::u('A', 'A2', 100)]);

        $this->assertSame(0, $r['areaA']['corrispettivi']['importo']);
        $this->assertSame(9000, $r['ente']['neutre']);
    }

    /** @param list<Imputata> $imputazioni */
    private static function esegui(array $imputazioni, array $o = []): array
    {
        $figurativi = $o['figurativi'] ?? [];

        return TestNatura::calcola(
            $o['anno'] ?? 2026, Calcolo::modelloD($imputazioni, [], $figurativi), $imputazioni, $figurativi,
            $o['riparto'] ?? 'ricavi', $o['storico'] ?? [], $o['vicino'] ?? 80, $o['dichiarati'] ?? true,
        );
    }
}
