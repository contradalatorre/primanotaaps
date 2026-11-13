<?php

namespace App\Esercizio;

/**
 * Le soglie dimensionali (#55, ricerca #6 § 4): il rendiconto per cassa e il Modello E (art. 13 CTS), l'organo di controllo (art. 30) e la
 * revisione legale (art. 31), misurate sull'esercizio PRECEDENTE a quello guardato. Un modulo puro: legge solo le misure già pronte
 * (`Verifiche::misura`), mai il database né la data di oggi.
 *
 * Le entrate sono il totale entrate della gestione (senza disinvestimenti e finanziamenti, che nel Modello D stanno a parte). Gli artt. 30 e 31
 * scattano se due limiti su tre sono superati per due esercizi consecutivi (qui l'esercizio precedente e quello prima) e cessano solo dopo due esercizi consecutivi sotto i limiti.
 * Il rendiconto per cassa vale solo per un ente senza personalità giuridica: l'app non lo sa e lo dice nel testo.
 *
 * Mai a favore: un attivo o dei dipendenti non dichiarati contano come limite superato (con l'avviso «dato mancante»); un esercizio che
 * non ha nessun dato nell'app non dà un esito, e non può dare nemmeno «potete redigere il Modello E».
 * L'obbligo degli artt. 30 e 31 si guarda su quattro esercizi (due consecutivi oltre lo fanno nascere, un solo esercizio sotto non lo fa cessare).
 * shortcut: oltre il quarto esercizio prima la storia non si guarda (un obbligo nato ancora prima non si presume); da estendere se un esercizio più vecchio dovesse contare.
 * shortcut: l'esercizio storico 2025 (#63) non esiste ancora, quindi per il 2026 l'esercizio precedente è «senza dati»; da leggere in `Verifiche::misura`.
 */
final class Soglie
{
    /** Art. 13 c. 2: il rendiconto per cassa vale fino a 300.000 euro; c. 2-bis: la forma aggregata (Modello E) fino a 60.000, dal rendiconto 2026. */
    public const LIMITE_CASSA = 30_000_000;

    public const LIMITE_MODELLO_E = 6_000_000;

    public const PRIMO_ANNO_MODELLO_E = 2026;

    /** Art. 30 c. 2 (associazioni) e art. 31 c. 1, dopo la L. 104/2024: attivo e entrate in centesimi, dipendenti medi. */
    public const ORGANO_DI_CONTROLLO = ['attivo' => 15_000_000, 'entrate' => 30_000_000, 'dipendenti' => 7];

    public const REVISIONE = ['attivo' => 150_000_000, 'entrate' => 300_000_000, 'dipendenti' => 20];

    /**
     * @param  array<int, ?array{entrate: int, attivo: ?int, dipendenti: ?int, inAttesa?: int}>  $misure  per anno: i quattro esercizi che precedono `$anno`; null se non hanno dati nell'app. `inAttesa`: le entrate ancora da confermare dell'esercizio (mai a favore: l'art. 13 non è «rispettato»)
     * @param  int  $sogliaVicino  percentuale del limite oltre la quale l'esito è «vicino alla soglia»
     */
    public static function calcola(int $anno, array $misure, int $sogliaVicino): array
    {
        $prima = $misure[$anno - 1] ?? null;
        $primaDiPrima = $misure[$anno - 2] ?? null;

        return [
            'anno' => $anno,
            'riferimento' => $anno - 1,
            'art13' => self::art13($anno, $prima, $sogliaVicino),
            'art30' => self::obbligo(self::ORGANO_DI_CONTROLLO, $prima, $primaDiPrima, [$misure[$anno - 3] ?? null, $misure[$anno - 4] ?? null], $sogliaVicino),
            'art31' => self::obbligo(self::REVISIONE, $prima, $primaDiPrima, [$misure[$anno - 3] ?? null, $misure[$anno - 4] ?? null], $sogliaVicino),
        ];
    }

    private static function art13(int $anno, ?array $prima, int $sogliaVicino): array
    {
        $entrate = $prima['entrate'] ?? null;
        $stato = $entrate === null ? null : self::stato($entrate, self::LIMITE_CASSA, $sogliaVicino);

        return [
            'senzaDati' => $prima === null,
            'entrate' => $entrate,
            'soglia' => self::LIMITE_CASSA,
            'margine' => $entrate === null ? null : self::LIMITE_CASSA - $entrate, // negativo se superata
            'inAttesa' => $prima['inAttesa'] ?? 0,
            'cassa' => $entrate !== null && $entrate <= self::LIMITE_CASSA,
            'modelloE' => $entrate !== null && $anno >= self::PRIMO_ANNO_MODELLO_E && $entrate <= self::LIMITE_MODELLO_E,
            'limiteModelloE' => self::LIMITE_MODELLO_E,
            'esito' => match ($stato) {
                null => null,
                'superato' => 'non_rispettato',
                'vicino' => 'vicino',
                default => ($prima['inAttesa'] ?? 0) > 0 ? 'vicino' : 'rispettato', // entrate non confermate potrebbero superare il limite
            },
        ];
    }

    /**
     * @param  array{attivo: int, entrate: int, dipendenti: int}  $limiti
     * @param  array{0: ?array, 1: ?array}  $piuVecchi  il terzo e il quarto esercizio prima di quello guardato
     */
    private static function obbligo(array $limiti, ?array $prima, ?array $primaDiPrima, array $piuVecchi, int $sogliaVicino): array
    {
        if ($prima === null) {
            return ['senzaDati' => true, 'parametri' => [], 'superati' => null, 'mancanti' => null, 'consecutivi' => null, 'persiste' => false, 'inCessazione' => null, 'esito' => null];
        }
        $ora = self::misura($limiti, $prima, $sogliaVicino);
        $primaDiPrimaOltre = $primaDiPrima === null ? null : self::misura($limiti, $primaDiPrima, $sogliaVicino)['superati'] >= 2;
        $consecutivi = $ora['superati'] < 2 ? false : $primaDiPrimaOltre;
        // Oltre, sotto, oltre, oltre (dal più vecchio): l'obbligo nato dai due oltre non è cessato con un solo esercizio sotto. Con il quarto ignoto, per prudenza, può esserci.
        [$terzo, $quarto] = array_map(fn (?array $m) => $m === null ? null : self::misura($limiti, $m, $sogliaVicino)['superati'] >= 2, $piuVecchi);
        $persiste = $ora['superati'] >= 2 && $primaDiPrimaOltre === false && $terzo === true && $quarto !== false;
        // Art. 30 c. 3 e art. 31: l'obbligo cessa solo dopo due esercizi consecutivi sotto i limiti; con uno solo sotto, dopo uno oltre, non è ancora cessato.
        $inCessazione = $ora['superati'] < 2 && $primaDiPrimaOltre === true;

        return [
            'senzaDati' => false,
            'parametri' => $ora['parametri'],
            'superati' => $ora['superati'],
            'mancanti' => $ora['mancanti'],
            // null: l'esercizio prima non è nell'app e non si può dire; per prudenza vale come due esercizi consecutivi.
            'consecutivi' => $consecutivi,
            'persiste' => $persiste,
            'inCessazione' => $inCessazione,
            'esito' => match (true) {
                $ora['superati'] >= 2 => $consecutivi === false && ! $persiste ? 'vicino' : 'non_rispettato',
                $inCessazione, $ora['vicini'] >= 2 => 'vicino',
                default => 'rispettato',
            },
        ];
    }

    /**
     * @param  array{attivo: int, entrate: int, dipendenti: int}  $limiti
     * @return array{parametri: array<string, array>, superati: int, mancanti: int, vicini: int}
     */
    private static function misura(array $limiti, array $esercizio, int $sogliaVicino): array
    {
        $parametri = [];
        foreach ($limiti as $nome => $limite) {
            $valore = $esercizio[$nome];
            $parametri[$nome] = ['valore' => $valore, 'limite' => $limite, 'stato' => $valore === null ? 'mancante' : self::stato($valore, $limite, $sogliaVicino)];
        }
        $stati = array_column($parametri, 'stato');
        $contano = fn (array $tra) => count(array_filter($stati, fn (string $s) => in_array($s, $tra, true)));

        return [
            'parametri' => $parametri,
            'superati' => $contano(['superato', 'mancante']),
            'mancanti' => $contano(['mancante']),
            'vicini' => $contano(['vicino', 'superato', 'mancante']), // un dato mancante vale superato, quindi anche oltre la soglia «vicino»
        ];
    }

    /** «Superato» solo oltre il limite (le leggi dicono «non superiori»); «vicino» oltre la percentuale del limite. */
    private static function stato(int $valore, int $limite, int $sogliaVicino): string
    {
        return match (true) {
            $valore > $limite => 'superato',
            100 * $valore > $sogliaVicino * $limite => 'vicino',
            default => 'sotto',
        };
    }
}
