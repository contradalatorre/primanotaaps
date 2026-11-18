<?php

namespace App\Import;

/**
 * Gli esempi di classificazione dalle schede dei conti (Cassa, Banca, Paypal) del foglio 2025 (#63, #19): descrizione, causale dell'estratto, verso,
 * importo e classificazione convertita dalla «Nuova causale». Non sono movimenti e non entrano in nessun rendiconto: alimentano lo storico (#43).
 * Un modulo puro, che non conosce il database: la tabella di conversione la passa il chiamante.
 *
 * Si leggono solo le colonne in elenco esplicito, riconosciute dal nome dell'intestazione (data, causale, nuova causale, descrizione o dettaglio, entrate e uscite
 * o importo): le altre (nome, email, saldo…) non si guardano mai, e le email dentro la descrizione e la causale dell'estratto si tolgono. I nomi di persona scritti nella
 * descrizione libera non si possono togliere qui.
 * shortcut: i nomi nella descrizione restano finché non c'è il mascheratore (#65); da rivedere con il primo foglio vero. Le intestazioni sono un'ipotesi sulle schede del foglio.
 */
final class EsempiDalFoglio
{
    /**
     * @param  array<string, list<list<mixed>>>  $fogli  quelli di `Foglio::leggi`
     * @param  array<string, array{verso: string, tipologia_id: ?int, area: ?string, attivita_id: ?int, iniziativa_id: ?int}>  $conversione  per chiave della causale (`chiave`)
     * @return array{esempi: list<array<string, mixed>>, scartate: array<string, int>, schede: list<string>}
     */
    public static function leggi(array $fogli, int $anno, array $conversione): array
    {
        $esempi = [];
        $scartate = [];
        $schede = [];
        $scarta = function (string $motivo) use (&$scartate) {
            $scartate[$motivo] = ($scartate[$motivo] ?? 0) + 1;
        };

        foreach ($fogli as $nome => $righe) {
            if (! preg_match('/^(cassa|banca|paypal)/i', (string) $nome) || ($colonne = self::colonne($righe)) === null) {
                continue;
            }
            [$intestazione, $c] = $colonne;
            $schede[] = (string) $nome;

            foreach (array_slice($righe, $intestazione + 1) as $riga) {
                $causale = self::chiave($riga[$c['nuova']] ?? null);
                $data = self::data($riga[$c['data']] ?? null);
                if ($causale === '' && $data === null) { // il saldo iniziale, i totali, le righe vuote: non sono movimenti
                    continue;
                }
                $conv = $conversione[$causale] ?? null;
                [$verso, $importo] = self::importo($riga, $c);
                $descrizione = self::senzaEmail($riga[$c['descrizione'] ?? -1] ?? '');

                $motivo = match (true) {
                    $data === null => 'senza_data',
                    ! str_starts_with($data, $anno.'-') => 'fuori_anno',
                    $causale === '' => 'senza_causale',
                    $conv === null => 'non_convertita',
                    $conv['tipologia_id'] === null => 'senza_tipologia',
                    $importo === null => 'senza_importo',
                    $conv['verso'] !== $verso => 'verso_diverso',
                    $descrizione === '' => 'senza_descrizione',
                    default => null,
                };
                if ($motivo !== null) {
                    $scarta($motivo);

                    continue;
                }
                $estratto = self::senzaEmail($riga[$c['causale'] ?? -1] ?? ''); // anche la causale dell'estratto (PayPal) può avere un'email
                $esempi[] = [
                    'verso' => $verso, 'causale' => $estratto === '' ? null : $estratto, 'descrizione' => $descrizione, 'importo' => $importo, 'data' => $data,
                    'tipologia_id' => $conv['tipologia_id'], 'area' => $conv['area'], 'attivita_id' => $conv['attivita_id'], 'iniziativa_id' => $conv['iniziativa_id'],
                ];
            }
        }

        return ['esempi' => $esempi, 'scartate' => $scartate, 'schede' => $schede];
    }

    /** Il testo di una cella senza le email (e le parentesi rimaste vuote), a spazi ridotti. */
    private static function senzaEmail(mixed $cella): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace(['/[\w.+-]+@[\w-]+\.[\w.-]+/u', '/[(\[<]\s*[)\]>]/u'], '', (string) $cella)));
    }

    /** La chiave con cui si cerca una causale nella tabella di conversione: senza il prefisso «->» o «→», in minuscolo, senza spazi ai lati. */
    public static function chiave(mixed $causale): string
    {
        $causale = trim((string) $causale);
        foreach (['->', '→'] as $prefisso) {
            if (str_starts_with($causale, $prefisso)) {
                $causale = substr($causale, strlen($prefisso));
            }
        }

        return mb_strtolower(trim($causale));
    }

    /** @return ?array{0: int, 1: array<string, int>} la riga dell'intestazione e le colonne ammesse per nome; null se la scheda non ha data e nuova causale */
    private static function colonne(array $righe): ?array
    {
        foreach (array_slice($righe, 0, 15, true) as $i => $riga) {
            $c = [];
            foreach ($riga as $k => $cella) {
                $nome = is_string($cella) ? rtrim(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $cella))), ':') : '';
                $chiave = match ($nome) {
                    'data' => 'data', 'causale' => 'causale', 'nuova causale' => 'nuova', 'descrizione', 'dettaglio' => 'descrizione',
                    'entrate' => 'entrate', 'uscite' => 'uscite', 'importo' => 'importo', default => null,
                };
                if ($chiave !== null) {
                    $c[$chiave] ??= $k;
                }
            }
            if (isset($c['data'], $c['nuova']) && (isset($c['importo']) || isset($c['entrate']) || isset($c['uscite']))) {
                return [$i, $c];
            }
        }

        return null;
    }

    /** @return array{0: ?string, 1: ?int} verso e importo (centesimi, positivo), o null se la riga non ha un importo */
    private static function importo(array $riga, array $c): array
    {
        $entrata = Foglio::centesimi($riga[$c['entrate'] ?? -1] ?? null);
        $uscita = Foglio::centesimi($riga[$c['uscite'] ?? -1] ?? null);
        $firmato = Foglio::centesimi($riga[$c['importo'] ?? -1] ?? null);
        $netto = $firmato ?? (($entrata ?? 0) - ($uscita ?? 0));

        return $netto === 0 ? [null, null] : [$netto > 0 ? 'entrata' : 'uscita', abs($netto)];
    }

    /** «AAAA-MM-GG» da una data del foglio o da un testo «g/m/aaaa». */
    private static function data(mixed $cella): ?string
    {
        $testo = trim((string) $cella);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $testo, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $testo : null;
        }
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $testo, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return null;
    }
}
