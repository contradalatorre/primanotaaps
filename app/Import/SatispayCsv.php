<?php

namespace App\Import;

use App\Euro;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Report transazioni di Satispay (`PAYMENT_CSV`, CSV `,`, punto decimale, date ISO con millisecondi): un incasso per riga con la
 * sua commissione, senza il nome di chi paga. Non ha saldi: l'estratto ne è privo e il conto si riconcilia con il riepilogo
 * mensile e con il saldo dichiarato (#14). Chiave contro i doppioni: l'id della transazione.
 *
 * shortcut: i nomi delle colonne sono quelli del report `PAYMENT_FEE` della Report API (ricerca #5), gli importi in euro con il
 * punto come dice #14; vanno confermati su un export vero, fuori dal repo, e le colonne corrette qui in `COLONNE`.
 */
class SatispayCsv implements FormatoImport
{
    /** Le uniche colonne che si leggono: ogni altra (negozio, centro di costo, codici) si scarta (#27). */
    private const COLONNE = ['transaction_id', 'transaction_date', 'total_amount', 'fee_amount', 'transaction_type'];

    /** Gli unici tipi che l'app sa trattare; ogni altro (un rimborso, ad esempio) si segnala e il file non entra. */
    private const TIPI_NOTI = ['TO_BUSINESS'];

    /** Nessuna: il report non ha versamenti. Il versamento in banca si riconosce sull'estratto della banca (`CreditAgricoleCsv`), che propone la metà qui. */
    public function regoleGiroconti(): array
    {
        return [];
    }

    /** @throws EstrattoNonValido */
    public function leggi(string $contenuto): Estratto
    {
        $contenuto = preg_replace('/^\xEF\xBB\xBF/', '', $contenuto);
        if (! mb_check_encoding($contenuto, 'UTF-8')) {
            $contenuto = mb_convert_encoding($contenuto, 'UTF-8', 'Windows-1252');
        }

        $flusso = fopen('php://temp', 'r+');
        fwrite($flusso, $contenuto);
        rewind($flusso);
        $colonne = null;
        $righe = [];
        $viste = [];
        for ($n = 1; ($celle = fgetcsv($flusso, null, ',', '"', '')) !== false; $n++) {
            if (trim(implode('', $celle)) === '') {
                continue;
            }
            if ($colonne === null) {
                $colonne = $this->colonne($celle);

                continue;
            }
            $riga = $this->riga($celle, $colonne, $n);
            if (isset($viste[$riga->idEsterno])) {
                throw new EstrattoNonValido("Riga $n: la transazione {$riga->idEsterno} compare due volte nel file.");
            }
            $viste[$riga->idEsterno] = true;
            $righe[] = $riga;
        }
        fclose($flusso);
        if ($righe === []) {
            throw new EstrattoNonValido('Il file non contiene transazioni.');
        }

        return new Estratto($righe, null, null);
    }

    /** Posizione di ogni colonna ammessa, dalla riga delle intestazioni. @return array<string, int> */
    private function colonne(array $intestazioni): array
    {
        $posizioni = array_flip(array_map(fn ($c) => trim((string) $c), $intestazioni));
        $mancanti = array_diff(self::COLONNE, array_keys($posizioni));
        if ($mancanti) {
            throw new EstrattoNonValido('Il file non è il report transazioni di Satispay: nella prima riga mancano le colonne '.implode(', ', $mancanti).'.');
        }

        return array_intersect_key($posizioni, array_flip(self::COLONNE));
    }

    private function riga(array $celle, array $colonne, int $n): RigaEstratto
    {
        $cella = fn (string $nome) => trim($celle[$colonne[$nome]] ?? '');
        $id = $cella('transaction_id');
        if ($id === '') {
            throw new EstrattoNonValido("Riga $n: manca l'id della transazione.");
        }
        if (! in_array($cella('transaction_type'), self::TIPI_NOTI, true)) {
            throw new EstrattoNonValido("Riga $n: la transazione $id è di un tipo che l'app non conosce («{$cella('transaction_type')}»). Non è stata importata, e con lei nessuna riga del file: serve decidere come trattarla (un rimborso, ad esempio, ha il segno opposto).");
        }
        $lordo = $this->importo($cella('total_amount'), "Riga $n: l'importo");
        $commissione = $this->importo($cella('fee_amount'), "Riga $n: la commissione");
        if ($lordo <= 0 || $commissione < 0 || $commissione > $lordo) {
            throw new EstrattoNonValido("Riga $n: l'importo ({$cella('total_amount')}) e la commissione ({$cella('fee_amount')}) non tornano: l'incasso dev'essere positivo e la commissione non può superarlo.");
        }

        return new RigaEstratto($this->data($cella('transaction_date'), $n), null, null, "Incasso Satispay $id", $lordo - $commissione, null, $id, $commissione);
    }

    private function importo(string $testo, string $cosa): int
    {
        $centesimi = Euro::daTesto($testo);
        if ($centesimi === null) {
            throw new EstrattoNonValido("$cosa («{$testo}») non è un importo in euro.");
        }

        return $centesimi;
    }

    /** «2026-05-03T10:15:22.123Z»: con il fuso (Z o ±hh:mm) il giorno è quello di Roma, senza è quello scritto. */
    private function data(string $testo, int $n): string
    {
        try {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}(T|$)/', $testo)) {
                throw new \InvalidArgumentException;
            }
            $data = CarbonImmutable::parse($testo);
            if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $testo)) {
                $data = $data->setTimezone('Europe/Rome');
            }
        } catch (Throwable) {
            throw new EstrattoNonValido("Riga $n: la data («{$testo}») non è nella forma aaaa-mm-ggThh:mm:ss.mmmZ.");
        }

        return $data->toDateString();
    }
}
