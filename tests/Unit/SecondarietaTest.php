<?php

namespace Tests\Unit;

use App\Esercizio\Calcolo;
use App\Esercizio\Figurativo;
use App\Esercizio\Imputata;
use App\Esercizio\Secondarieta;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Il test di secondarietà (art. 6 CTS, DM 107/2021) sul modulo puro, a tabella: importi inventati, in centesimi.
 * Ogni riga dà le imputazioni e i figurativi in ingresso e i soli valori che quel caso vuole fissare.
 */
class SecondarietaTest extends TestCase
{
    private static function e(string $voce, int $importo): Imputata
    {
        return new Imputata('entrate', $voce, $importo);
    }

    private static function u(string $voce, int $importo): Imputata
    {
        return new Imputata('uscite', $voce, $importo);
    }

    /** @return array<string, array{0: list<Imputata>, 1: list<Figurativo>, 2: array<string, mixed>, 3?: array<string, mixed>}> */
    public static function casi(): array
    {
        return [
            // 9.000 di B su 25.000 di entrate: il 30% non regge; con 5.400 di volontari il 66% regge (esempio della ricerca #6).
            'il 30% fallisce e il 66% regge grazie ai figurativi' => [
                [self::e('B3', 900000), self::e('A1', 1600000), self::u('A2', 2000000)],
                [new Figurativo('costo', 'A', 'ore di volontariato', 540000)],
                [
                    'a.esito' => 'non_rispettato', 'a.rapporto' => 36.0, 'a.margine' => -150000, 'a.spendibile' => -214286,
                    'b.esito' => 'rispettato', 'b.denominatore.importo' => 2540000, 'b.margine' => 776400, 'b.spendibile' => 776400,
                    'proposto' => 'b', 'esito' => 'rispettato',
                ],
            ],
            // Senza i figurativi il 66% regge ma è al 91% del limite: oltre l'80%, quindi vicino.
            'senza figurativi il 66% è vicino alla soglia' => [
                [self::e('B3', 1200000), self::e('A1', 1300000), self::u('A2', 2000000)],
                [],
                ['a.esito' => 'non_rispettato', 'b.esito' => 'vicino', 'b.rapporto' => 60.0, 'proposto' => 'b', 'esito' => 'vicino'],
            ],
            // Proposto è il criterio con più margine in euro, anche se nessuno è rispettato.
            'nessun criterio rispettato' => [
                [self::e('B3', 1000000), self::e('A1', 500000), self::u('A2', 1000000)],
                [],
                ['a.esito' => 'non_rispettato', 'b.esito' => 'non_rispettato', 'esito' => 'non_rispettato', 'proposto' => 'b', 'a.margine' => -550000, 'b.margine' => -340000],
            ],
            // Il più largo è quello che regge più entrate B in più (lo spendibile), non quello con più margine: 30% → 7.142,85 contro 66% → 6.000,38,
            // anche se il margine in euro del 66% (6.000,38) supera quello del 30% (5.000,00).
            'il criterio proposto è quello con più margine spendibile' => [
                [self::e('B3', 1000000), self::e('A1', 4000000), self::u('A2', 2424300)],
                [],
                ['a.margine' => 500000, 'a.spendibile' => 714285, 'b.margine' => 600038, 'b.spendibile' => 600038, 'proposto' => 'a'],
            ],
            // La soglia «vicino» è oltre l'80% del limite: al 24% esatto (80% di 30) è ancora rispettato, un centesimo oltre è vicino.
            'esattamente all\'80% del limite è rispettato' => [
                [self::e('B3', 2400), self::e('A1', 7600), self::u('A2', 100000)],
                [],
                ['a.esito' => 'rispettato', 'a.rapporto' => 24.0, 'a.margine' => 600, 'esito' => 'rispettato', 'proposto' => 'b'],
            ],
            'un centesimo oltre l\'80% del limite è vicino' => [
                [self::e('B3', 2401), self::e('A1', 7599)],
                [],
                ['a.esito' => 'vicino', 'b.esito' => 'non_rispettato', 'esito' => 'vicino', 'proposto' => 'a'],
            ],
            'al 30% esatto il criterio è rispettato, vicino alla soglia' => [
                [self::e('B3', 3000), self::e('A1', 7000)],
                [],
                ['a.esito' => 'vicino', 'a.margine' => 0, 'a.spendibile' => 0, 'esito' => 'vicino'],
            ],
            'un centesimo oltre il 30% non è rispettato' => [
                [self::e('B3', 3001), self::e('A1', 6999)],
                [],
                ['a.esito' => 'non_rispettato', 'a.margine' => -1, 'a.spendibile' => -2],
            ],
            // Il margine spendibile sul 30% conta che le entrate B in più fanno crescere anche il totale: (30·TEG − 100·EB) / 70.
            'il margine spendibile sul 30% tiene conto del totale che cresce' => [
                [self::e('B3', 1000000), self::e('A1', 3000000)],
                [],
                ['a.margine' => 200000, 'a.spendibile' => 285714, 'a.rapporto' => 25.0],
            ],
            // Le regole fisse.
            'le sponsorizzazioni contano nel numeratore del 30%' => [
                [self::e('B3', 500000), self::e('A1', 1500000)],
                [],
                ['a.numeratore.importo' => 500000, 'a.rapporto' => 25.0],
            ],
            'le imposte sono fuori dai costi del 66%' => [
                [self::e('B3', 100000), self::u('A2', 200000), self::u('Imposte', 700000)],
                [],
                ['b.denominatore.importo' => 200000, 'b.rapporto' => 50.0],
            ],
            'i proventi figurativi sono fuori dal 30%' => [
                [self::e('B3', 100000), self::e('A1', 900000)],
                [new Figurativo('provento', 'A', 'prestazione gratuita', 500000)],
                ['a.denominatore.importo' => 1000000, 'a.rapporto' => 10.0, 'b.denominatore.importo' => 0],
            ],
            'i costi figurativi di A e di B entrano nel 66%' => [
                [self::e('B3', 100000), self::u('A2', 100000)],
                [new Figurativo('costo', 'A', 'x', 30000), new Figurativo('costo', 'B', 'y', 20000)],
                ['b.denominatore.importo' => 150000],
            ],
            'il distacco del personale (E1) è fuori dal 30% (DM 107/2021, art. 3 c. 4)' => [
                [self::e('B3', 100000), self::e('A1', 900000), self::e('E1', 400000)],
                [],
                ['a.denominatore.importo' => 1000000, 'a.rapporto' => 10.0],
            ],
            // Denominatori a zero.
            'senza movimenti entrambi i criteri sono rispettati' => [
                [], [],
                ['a.esito' => 'rispettato', 'a.rapporto' => 0.0, 'b.esito' => 'rispettato', 'proposto' => 'a', 'esito' => 'rispettato'],
            ],
            'entrate B senza uscite né figurativi: il 66% non è rispettato' => [
                [self::e('B3', 100000)],
                [],
                ['b.esito' => 'non_rispettato', 'b.rapporto' => null, 'b.margine' => -100000, 'a.esito' => 'non_rispettato'],
            ],
            // Proposta, conferma, dato mancante.
            'il criterio confermato peggiore di quello proposto si segnala' => [
                [self::e('B3', 900000), self::e('A1', 1600000), self::u('A2', 2000000)],
                [new Figurativo('costo', 'A', 'x', 540000)],
                ['proposto' => 'b', 'confermato' => 'a', 'confermatoPeggiore' => true],
                ['confermato' => 'a'],
            ],
            'il criterio confermato uguale al proposto non si segnala' => [
                [self::e('B3', 900000), self::e('A1', 1600000), self::u('A2', 2000000)],
                [new Figurativo('costo', 'A', 'x', 540000)],
                ['proposto' => 'b', 'confermato' => 'b', 'confermatoPeggiore' => false],
                ['confermato' => 'b'],
            ],
            'figurativi non dichiarati: dato mancante' => [
                [self::e('B3', 100000)], [],
                ['datoMancante' => true],
                ['dichiarati' => false],
            ],
            'figurativi dichiarati (anche nessuno): niente dato mancante' => [
                [self::e('B3', 100000)], [],
                ['datoMancante' => false],
                ['dichiarati' => true],
            ],
            'la soglia «vicino» cambia con l\'impostazione' => [
                [self::e('B3', 1500), self::e('A1', 8500)],
                [],
                ['a.esito' => 'vicino', 'sogliaVicino' => 40],
                ['vicino' => 40],
            ],
        ];
    }

    /**
     * @param  list<Imputata>  $imputazioni
     * @param  list<Figurativo>  $figurativi
     * @param  array<string, mixed>  $atteso
     * @param  array<string, mixed>  $opzioni  dichiarati (default: sì), vicino (default: 80), confermato
     */
    #[DataProvider('casi')]
    public function test_a_tabella(array $imputazioni, array $figurativi, array $atteso, array $opzioni = []): void
    {
        $modello = Calcolo::modelloD($imputazioni, [], $figurativi);

        $r = Secondarieta::calcola($modello, $opzioni['dichiarati'] ?? true, $opzioni['vicino'] ?? 80, $opzioni['confermato'] ?? null);

        foreach ($atteso as $percorso => $valore) {
            $trovato = Arr::get($r, preg_match('/^[ab]\./', $percorso) ? 'criteri.'.$percorso : $percorso);
            $this->assertEquals($valore, $trovato, "Valore di $percorso");
            $this->assertSame(gettype($valore), gettype($trovato), "Tipo di $percorso");
        }
    }

    #[DataProvider('margini')]
    public function test_il_margine_spendibile_e_l_ultimo_che_tiene_il_criterio(string $criterio, int $entrateB, int $altreEntrate, int $uscite): void
    {
        $con = fn (int $extraB) => Secondarieta::calcola(Calcolo::modelloD([self::e('B3', $entrateB + $extraB), self::e('A1', $altreEntrate), self::u('A2', $uscite)], [], []), true, 80, null)['criteri'][$criterio];
        $x = $con(0)['spendibile'];

        $this->assertNotSame('non_rispettato', $con($x)['esito'], 'Con il margine spendibile il criterio regge');
        $this->assertSame('non_rispettato', $con($x + 1)['esito'], 'Un centesimo oltre non regge più');
    }

    /** @return array<string, array{string, int, int, int}> */
    public static function margini(): array
    {
        return [
            '30%, con margine' => ['a', 1000000, 3000000, 0],
            '30%, margine che non è un multiplo di 70' => ['a', 123457, 3333333, 0],
            '30%, già oltre la soglia: il margine è da togliere' => ['a', 1000000, 1000000, 0],
            '66%' => ['b', 1000000, 0, 2000000],
            '66%, importi dispari' => ['b', 777777, 0, 1234567],
        ];
    }
}
