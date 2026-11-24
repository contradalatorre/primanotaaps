<?php

namespace App\Esercizio;

/**
 * La natura commerciale calcolata di un'imputazione (#52; glossario: Natura commerciale). Un modulo puro: dagli `ingressi`
 * (lato, area, voce Modello D, tipologia e sue proprietà, socio o terzo, affiliazione dell'esercizio) esce l'esito e il
 * perché, con la norma. `commerciale` è null quando non si può dire: un'uscita (la natura riguarda i proventi, come nei test
 * dell'ente e nel forfettario) o un'entrata senza tipologia o senza voce.
 *
 * Il test 2-bis dell'area A (#54) non è qui: i corrispettivi dei terzi in area A sono non commerciali finché il test è
 * rispettato, e `dipendeDal2bis` dice a #54 quali rendere commerciali quando non lo è. L'attività non cambia nessun esito
 * (sotto i 300.000 euro la lettera dell'art. 5 non conta, in B sono tutte commerciali): non è tra gli ingressi.
 */
final readonly class Natura
{
    /** Le voci di entrata dell'area A che sono corrispettivi (A2 e A3 degli associati, A7 e A9 di terzi ed enti pubblici). */
    public const CORRISPETTIVI = ['A2', 'A3', 'A7', 'A9'];

    private const VOCI_DEGLI_ASSOCIATI = ['A2', 'A3'];

    private const ETICHETTE = [
        'importo' => 'importo', 'lato' => 'verso', 'area' => 'area', 'voce' => 'voce', 'tipologia_id' => 'tipologia', 'proprieta' => 'proprietà fiscali della tipologia',
        'tipo_controparte' => 'socio o terzo', 'affiliazione_rete' => 'affiliazione a una rete nazionale',
    ];

    public function __construct(public ?bool $commerciale, public string $regola, public string $perche, public bool $dipendeDal2bis = false) {}

    /**
     * @param  array{lato: string, area: string, voce: ?string, tipologia_id: ?int, proprieta: list<string>, tipo_controparte: ?string, affiliazione_rete: bool}  $i
     */
    public static function calcola(array $i): self
    {
        if ($i['lato'] !== 'entrate') {
            return new self(null, 'uscita', 'La natura commerciale riguarda le entrate: un’uscita non ne ha una.');
        }
        if ($i['tipologia_id'] === null) {
            return new self(null, 'senza-tipologia', 'Si calcola dalla tipologia: scegli la tipologia.');
        }

        $socio = $i['tipo_controparte'] === 'socio';
        $perNatura = in_array('commerciale_per_natura', $i['proprieta'], true);
        $somministrazione = in_array('somministrazione', $i['proprieta'], true);

        if ($i['area'] === 'A' && $i['affiliazione_rete'] && $somministrazione && $socio) {
            return new self(false, 'somministrazione-ai-soci-affiliata', 'Somministrazione ai soci e l’associazione è affiliata a una rete nazionale: non è commerciale (art. 85 c. 4 CTS, a patto che sia nella sede, complementare e senza pubblicità verso terzi).');
        }
        if ($perNatura) {
            return new self(true, 'commerciale-per-natura', 'La tipologia è commerciale per natura, anche verso i soci (art. 85 c. 3 CTS)'
                .($somministrazione && $i['affiliazione_rete'] && ! $socio ? ': con l’affiliazione a una rete nazionale la somministrazione ai soci non lo sarebbe (art. 85 c. 4), indica che la controparte è un socio.' : '.'));
        }

        return match ($i['area']) {
            'B' => new self(true, 'area-b', 'Attività diversa (area B, art. 6 CTS): le entrate sono commerciali nel test dell’ente (art. 79 c. 5 CTS).'),
            'C' => $i['voce'] === 'C1'
                ? new self(false, 'area-c1', 'Raccolta fondi abituale (C1): le liberalità non sono commerciali (art. 79 c. 4 e 5-bis CTS), ma la parte corrispettiva è neutra nel test dell’ente (non conta né tra i proventi commerciali né tra i non commerciali) e conta tra i ricavi del forfettario (art. 86), per prudenza. Forzarla a non commerciale non cambia né l’uno né l’altro.')
                : new self(false, 'area-c', 'Raccolta fondi (area C): liberalità e raccolte occasionali non sono commerciali (art. 79 c. 4 e 5-bis CTS).'),
            'D', 'E' => new self(false, 'area-d-e', 'Attività finanziaria, patrimoniale o di supporto (area D o E): non è un’attività d’impresa e resta fuori dai test.'),
            default => self::areaA($i, $socio),
        };
    }

    private static function areaA(array $i, bool $socio): self
    {
        $voce = $i['voce'];
        if ($voce === null) {
            return new self(null, 'senza-voce', 'La combinazione di area e tipologia non ha una voce Modello D: non si può dire.');
        }
        if (! in_array($voce, self::CORRISPETTIVI, true)) {
            return new self(false, $voce === 'A1' ? 'quota-non-corrispettiva' : 'non-corrispettivo', 'Non è un corrispettivo (quota, liberalità, 5 per mille o contributo): non è commerciale (art. 79 c. 5-bis CTS).');
        }
        // La scelta di chi classifica (socio o terzo) vince sulla voce; senza scelta decide la voce: A2 e A3 sono degli associati.
        $daSocio = $socio || ($i['tipo_controparte'] !== 'terzo' && in_array($voce, self::VOCI_DEGLI_ASSOCIATI, true));
        if ($daSocio) {
            return new self(false, 'corrispettivo-socio', 'Corrispettivo di un socio per un’attività in attuazione degli scopi: non è commerciale (art. 85 c. 1 CTS).');
        }

        return new self(false, 'corrispettivo-area-a', 'Corrispettivo dell’area A: non è commerciale finché i corrispettivi non superano i costi effettivi (art. 79 c. 2 e 2-bis CTS); se il test non è rispettato diventa commerciale.', true);
    }

    /**
     * Cosa è cambiato tra gli ingressi di un tempo e quelli di adesso, in parole per chi legge («area», «voce»).
     *
     * @return list<string>
     */
    public static function cambiamenti(array $prima, array $adesso): array
    {
        return collect(self::ETICHETTE)->filter(fn (string $etichetta, string $chiave) => ($prima[$chiave] ?? null) !== ($adesso[$chiave] ?? null))->values()->all();
    }
}
