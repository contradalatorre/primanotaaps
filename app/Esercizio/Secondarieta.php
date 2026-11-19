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
 * cioè più favorevole): `distaccoNel66` lo segnala quando ci sono entrate E1; da togliere con una marcatura delle uscite.
 */
final class Secondarieta
{
    public const SOGLIE = ['a' => 30, 'b' => 66];

    /**
     * @param  array<string, mixed>  $modelloD  il risultato di `Calcolo::modelloD`
     * @param  bool  $figurativiDichiarati  il Tesoriere ha dichiarato completi i costi figurativi (anche «nessuno»): senza, il 66% è prudente e l'esito segnala il dato mancante
     * @param  int  $sogliaVicino  percentuale del limite oltre la quale l'esito è «vicino alla soglia»
     * @param  ?string  $confermato  il criterio che il Tesoriere ha confermato («a» o «b»), se l'ha fatto
     * @param  ?array{criterio: string, soglia: int}  $rientro  dopo una secondarietà non rispettata (art. 4 DM 107/2021): lo stesso criterio, con la soglia ridotta
     *                                                          (centesimi di punto percentuale, da `sogliaDiRientro`); l'altro criterio non è più a scelta
     */
    public static function calcola(array $modelloD, bool $figurativiDichiarati, int $sogliaVicino, ?string $confermato, ?array $rientro = null): array
    {
        $b = $modelloD['aree']['B']['entrate']['totale'];
        $entrate = $modelloD['gestione']['entrate'];
        $distacco = current(array_filter($modelloD['aree']['E']['entrate']['righe'], fn (array $r) => $r['voce'] === 'E1')); // entrate da distacco del personale
        $uscite = $modelloD['gestione']['uscite'];
        $costiFigurativi = $modelloD['figurativi']['costi']['totale'];

        $soglia = fn (string $criterio) => $rientro !== null && $rientro['criterio'] === $criterio ? $rientro['soglia'] : self::SOGLIE[$criterio] * 100;
        $criteri = [
            'a' => self::criterio('a', $soglia('a'), $b, [
                ['etichetta' => 'Totale entrate della gestione', 'importo' => $entrate['importo'], 'chiavi' => $entrate['chiavi']],
                ['etichetta' => 'Entrate da distacco del personale (escluse, DM 107/2021 art. 3 c. 4)', 'importo' => -$distacco['importo'], 'chiavi' => $distacco['chiavi']],
            ], $sogliaVicino),
            'b' => self::criterio('b', $soglia('b'), $b, [
                ['etichetta' => 'Totale uscite della gestione, senza le imposte', 'importo' => $uscite['importo'], 'chiavi' => $uscite['chiavi']],
                ['etichetta' => 'Costi figurativi', 'importo' => $costiFigurativi, 'chiavi' => null],
            ], $sogliaVicino),
        ];

        // Prima l'esito (un rispettato vince su un vicino: #17, manuale § 19), poi il più largo: quello che regge più entrate B in più (il
        // margine spendibile, che la pagina mostra), a parità il 30%. Il margine in euro non basta: nel 30% le entrate B in più fanno
        // crescere anche il totale, quindi a pari margine lo spendibile è maggiore.
        $gravita = fn (string $k) => self::GRAVITA[$criteri[$k]['esito']];
        $proposto = $rientro['criterio'] ?? ([$gravita('b'), $criteri['a']['spendibile']] < [$gravita('a'), $criteri['b']['spendibile']] ? 'b' : 'a');

        return [
            'criteri' => $criteri,
            'rientro' => $rientro,
            'proposto' => $proposto,
            'esito' => $criteri[$proposto]['esito'],
            'confermato' => $confermato,
            'confermatoPeggiore' => $confermato !== null && self::GRAVITA[$criteri[$confermato]['esito']] > self::GRAVITA[$criteri[$proposto]['esito']],
            'datoMancante' => ! $figurativiDichiarati,
            'distaccoNel66' => $distacco['importo'] > 0,
            'sogliaVicino' => $sogliaVicino,
        ];
    }

    public const ETICHETTE = ['rispettato' => 'Rispettato', 'vicino' => 'Vicino alla soglia', 'non_rispettato' => 'Non rispettato'];

    private const GRAVITA = ['rispettato' => 0, 'vicino' => 1, 'non_rispettato' => 2];

    /**
     * @param  array{importo: int, chiavi: list<string>}  $numeratore
     * @param  list<array{etichetta: string, importo: int, chiavi: ?list<string>}>  $parti  gli addendi del denominatore, con le chiavi delle imputazioni dietro ognuno
     */
    private static function criterio(string $criterio, int $sogliaCentesimi, array $numeratore, array $parti, int $sogliaVicino): array
    {
        // La soglia in centesimi di punto percentuale (3000 = 30%): la soglia ridotta del rientro non è un intero.
        $den = array_sum(array_column($parti, 'importo'));
        $num = $numeratore['importo'];
        $scarto = $sogliaCentesimi * $den - 10000 * $num; // centesimi × 10000: positivo se il criterio regge

        $esito = match (true) {
            $scarto < 0 => 'non_rispettato',
            1000000 * $num > $sogliaVicino * $sogliaCentesimi * $den => 'vicino',
            default => 'rispettato',
        };
        $rapporto = $den > 0 ? 100.0 * $num / $den : ($num === 0 ? 0.0 : null);

        return [
            'soglia' => $sogliaCentesimi % 100 === 0 ? intdiv($sogliaCentesimi, 100) : $sogliaCentesimi / 100,
            'sogliaCentesimiDiPunto' => $sogliaCentesimi,
            'numeratore' => $numeratore,
            'denominatore' => ['importo' => $den, 'parti' => $parti],
            'rapporto' => $rapporto,
            'esito' => $esito,
            // Distanza dalla soglia: in euro e in punti percentuali, con il segno (negativo se oltre).
            'margine' => self::diviso($scarto, 10000),
            'marginePunti' => $rapporto === null ? null : $sogliaCentesimi / 100 - $rapporto,
            // Altre entrate B che il criterio regge ancora (o da togliere, se negativo). Nel 30% le entrate B in più fanno crescere
            // anche il totale: 100(B+x) ≤ 30(TEG+x) → x ≤ (30·TEG − 100·B) / 70. Nel 66% le uscite non si muovono.
            'spendibile' => $criterio === 'a' ? self::diviso($scarto, 10000 - $sogliaCentesimi) : self::diviso($scarto, 10000),
        ];
    }

    /**
     * La soglia con cui riparte l'esercizio dopo un criterio non rispettato (art. 4 DM 107/2021): quella in vigore meno i punti sforati, questi
     * per eccesso, in centesimi di punto percentuale; mai sotto zero. Senza denominatore tutto il rapporto è sforato.
     * shortcut: dopo un secondo non rispettato di fila lo sforamento si misura sulla soglia già ridotta (la lettura più severa, mai a favore dell'ente):
     * da confermare con il commercialista.
     *
     * @param  array<string, mixed>  $criterio  uno di `calcola()['criteri']`
     */
    public static function sogliaDiRientro(array $criterio): int
    {
        $soglia = $criterio['sogliaCentesimiDiPunto'];
        $den = $criterio['denominatore']['importo'];
        if ($den <= 0) {
            return 0;
        }
        $rapporto = intdiv(10000 * $criterio['numeratore']['importo'] + $den - 1, $den); // centesimi di punto, per eccesso

        return max(0, $soglia - max(0, $rapporto - $soglia));
    }

    /** La soglia come si scrive: «30», «27,35». */
    public static function formatta(int|float $soglia): string
    {
        return rtrim(rtrim(number_format($soglia, 2, ',', ''), '0'), ',');
    }

    /** Divisione per difetto anche con i negativi (`intdiv` tronca verso zero): un margine non si arrotonda mai a favore. */
    private static function diviso(int $numeratore, int $divisore): int
    {
        return intdiv($numeratore - ($numeratore % $divisore + $divisore) % $divisore, $divisore);
    }
}
