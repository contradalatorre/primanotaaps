<?php

namespace App\Esercizio;

/**
 * Il test di secondarietà delle attività diverse (art. 6 CTS, DM 107/2021, art. 3) sul Modello D già calcolato: un modulo puro,
 * senza database, che legge solo i totali del prospetto (mai le descrizioni dei figurativi, che possono nominare persone).
 * Basta uno dei due criteri, esercizio per esercizio:
 *   a) entrate B ≤ 30% delle entrate della gestione (senza il distacco del personale, art. 3 c. 4);
 *   b) entrate B ≤ 66% delle uscite della gestione più i costi figurativi (art. 3 c. 3).
 * Regole fisse (#17, nessuna opzione): le sponsorizzazioni sono in B, quindi contano; le imposte non sono nelle uscite della
 * gestione; i proventi figurativi non entrano in nessun denominatore.
 *
 * Gli importi sono centesimi interi e i confronti si fanno per moltiplicazione in croce, senza percentuali arrotondate: la
 * percentuale (float) serve solo a mostrare.
 *
 * shortcut: le uscite per il personale distaccato non si possono marcare, quindi non si tolgono dal 66% (che ne risulta più basso,
 * cioè più favorevole): da rivedere se l'associazione distacca personale (nessuna fonte del repo lo prevede oggi).
 */
final class Secondarieta
{
    private const SOGLIE = ['a' => 30, 'b' => 66];

    /**
     * @param  array<string, mixed>  $modelloD  il risultato di `Calcolo::modelloD`
     * @param  bool  $figurativiDichiarati  il Tesoriere ha dichiarato completi i costi figurativi (anche «nessuno»): senza, il 66% è prudente e l'esito segnala il dato mancante
     * @param  int  $sogliaVicino  percentuale del limite oltre la quale l'esito è «vicino alla soglia»
     * @param  ?string  $confermato  il criterio che il Tesoriere ha confermato («a» o «b»), se l'ha fatto
     */
    public static function calcola(array $modelloD, bool $figurativiDichiarati, int $sogliaVicino, ?string $confermato): array
    {
        $b = $modelloD['aree']['B']['entrate']['totale'];
        $entrate = $modelloD['gestione']['entrate'];
        $distacco = current(array_filter($modelloD['aree']['E']['entrate']['righe'], fn (array $r) => $r['voce'] === 'E1')); // entrate da distacco del personale
        $uscite = $modelloD['gestione']['uscite'];
        $costiFigurativi = $modelloD['figurativi']['costi']['totale'];

        $criteri = [
            'a' => self::criterio('a', $b, [
                ['etichetta' => 'Totale entrate della gestione', 'importo' => $entrate['importo'], 'chiavi' => $entrate['chiavi']],
                ['etichetta' => 'Entrate da distacco del personale (escluse, DM 107/2021 art. 3 c. 4)', 'importo' => -$distacco['importo'], 'chiavi' => $distacco['chiavi']],
            ], $sogliaVicino),
            'b' => self::criterio('b', $b, [
                ['etichetta' => 'Totale uscite della gestione, senza le imposte', 'importo' => $uscite['importo'], 'chiavi' => $uscite['chiavi']],
                ['etichetta' => 'Costi figurativi', 'importo' => $costiFigurativi, 'chiavi' => null],
            ], $sogliaVicino),
        ];

        // Il più largo: più margine in euro, a parità il 30%. Hanno lo stesso numeratore, quindi l'ordine è quello della quota di soglia usata.
        $proposto = $criteri['b']['margine'] > $criteri['a']['margine'] ? 'b' : 'a';

        return [
            'criteri' => $criteri,
            'proposto' => $proposto,
            'esito' => $criteri[$proposto]['esito'],
            'confermato' => $confermato,
            'confermatoPeggiore' => $confermato !== null && self::GRAVITA[$criteri[$confermato]['esito']] > self::GRAVITA[$criteri[$proposto]['esito']],
            'datoMancante' => ! $figurativiDichiarati,
            'sogliaVicino' => $sogliaVicino,
        ];
    }

    private const GRAVITA = ['rispettato' => 0, 'vicino' => 1, 'non_rispettato' => 2];

    /**
     * @param  array{importo: int, chiavi: list<string>}  $numeratore
     * @param  list<array{etichetta: string, importo: int, chiavi: ?list<string>}>  $parti  gli addendi del denominatore, con le chiavi delle imputazioni dietro ognuno
     */
    private static function criterio(string $criterio, array $numeratore, array $parti, int $sogliaVicino): array
    {
        $soglia = self::SOGLIE[$criterio];
        $den = array_sum(array_column($parti, 'importo'));
        $num = $numeratore['importo'];
        $scarto = $soglia * $den - 100 * $num; // centesimi × 100: positivo se il criterio regge

        $esito = match (true) {
            $scarto < 0 => 'non_rispettato',
            10000 * $num > $sogliaVicino * $soglia * $den => 'vicino',
            default => 'rispettato',
        };
        $rapporto = $den > 0 ? 100.0 * $num / $den : ($num === 0 ? 0.0 : null);

        return [
            'soglia' => $soglia,
            'numeratore' => $numeratore,
            'denominatore' => ['importo' => $den, 'parti' => $parti],
            'rapporto' => $rapporto,
            'esito' => $esito,
            // Distanza dalla soglia: in euro e in punti percentuali, con il segno (negativo se oltre).
            'margine' => self::diviso($scarto, 100),
            'marginePunti' => $rapporto === null ? null : $soglia - $rapporto,
            // Altre entrate B che il criterio regge ancora (o da togliere, se negativo). Nel 30% le entrate B in più fanno crescere
            // anche il totale: 100(B+x) ≤ 30(TEG+x) → x ≤ (30·TEG − 100·B) / 70. Nel 66% le uscite non si muovono.
            'spendibile' => $criterio === 'a' ? self::diviso($scarto, 100 - $soglia) : self::diviso($scarto, 100),
        ];
    }

    /** Divisione per difetto anche con i negativi (`intdiv` tronca verso zero): un margine non si arrotonda mai a favore. */
    private static function diviso(int $numeratore, int $divisore): int
    {
        return intdiv($numeratore - ($numeratore % $divisore + $divisore) % $divisore, $divisore);
    }
}
