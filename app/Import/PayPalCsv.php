<?php

namespace App\Import;

use App\Euro;

/**
 * Estratto di PayPal (CSV scaricato dal sito, #11 e #14): `,` con BOM, intestazioni in italiano, date senza zeri
 * iniziali (3/3/2026), virgola decimale, dal più vecchio. Niente righe di saldo: i saldi si ricavano dalla colonna
 * `Saldo`. Il tipo dell'operazione è la colonna `Descrizione` (la causale dell'app), la controparte è `Nome`, la
 * chiave contro i doppioni è il codice transazione. Il movimento è al netto; la tariffa di un incasso è la commissione.
 */
class PayPalCsv implements FormatoImport
{
    /** Le uniche colonne che si leggono: email, `Nome banca`, `Conto bancario` e il resto si scartano (#27). */
    private const COLONNE = ['Data', 'Descrizione', 'Valuta', 'Lordo', 'Tariffa', 'Netto', 'Saldo', 'Codice transazione', 'Nome'];

    /**
     * Le regole dei giroconti PayPal ↔ banca (#47, #48): il bonifico dalla banca che ricarica PayPal e il trasferimento di PayPal
     * verso la banca. Si guarda il testo della causale senza l'apostrofo, che nei file può essere tipografico.
     *
     * shortcut: la finestra di 5 giorni copre i tempi di un bonifico o di un trasferimento in giorni lavorativi; da verificare su estratti veri.
     *
     * @return array<string, array{nome: string, verso: string, contiene: list<string>, con: string, giorni: int, meta?: string}>
     */
    public function regoleGiroconti(): array
    {
        return [
            'ricarica' => ['nome' => 'Bonifico dalla banca a PayPal', 'verso' => 'entrata', 'contiene' => ['Bonifico bancario sul conto PayPal'], 'con' => 'banca', 'giorni' => 5],
            'trasferimento' => ['nome' => 'Trasferimento da PayPal alla banca', 'verso' => 'uscita', 'contiene' => ['Trasferimento avviato'], 'con' => 'banca', 'giorni' => 5],
        ];
    }

    /** @throws EstrattoNonValido */
    public function leggi(string $contenuto): Estratto
    {
        $contenuto = preg_replace('/^\xEF\xBB\xBF/', '', $contenuto);
        if (! mb_check_encoding($contenuto, 'UTF-8')) {
            $contenuto = mb_convert_encoding($contenuto, 'UTF-8', 'Windows-1252');
        }

        $righe = $this->righeNonVuote($contenuto);
        $colonne = $this->colonne(array_shift($righe)[1] ?? []);
        if ($righe === []) {
            throw new EstrattoNonValido('Il file non contiene movimenti.');
        }

        $cella = fn (array $riga, string $nome) => trim($riga[$colonne[$nome]] ?? '');
        $movimenti = [];
        $codici = [];
        $progressivo = null;
        foreach ($righe as [$n, $riga]) {
            $lordo = $this->importo($cella($riga, 'Lordo'), "Riga $n: il lordo");
            $tariffa = $cella($riga, 'Tariffa') === '' ? 0 : $this->importo($cella($riga, 'Tariffa'), "Riga $n: la tariffa");
            $netto = $this->importo($cella($riga, 'Netto'), "Riga $n: il netto");
            $saldo = $this->importo($cella($riga, 'Saldo'), "Riga $n: il saldo");
            if ($netto !== $lordo + $tariffa) {
                throw new EstrattoNonValido("Riga $n: il netto (".Euro::formatta($netto).') non è il lordo ('.Euro::formatta($lordo).') più la tariffa ('.Euro::formatta($tariffa).'). Il file è stato modificato.');
            }
            // shortcut: la tariffa è solo quella trattenuta su un incasso; su un'uscita o restituita in un rimborso non si sa ancora dove imputarla (serve un estratto vero).
            if ($tariffa > 0 || ($tariffa < 0 && $lordo <= 0)) {
                throw new EstrattoNonValido("Riga $n: una tariffa su un pagamento in uscita o restituita non è prevista. Scrivi al gruppo che cura l'app, con un esempio inventato della riga.");
            }
            if ($netto === 0) {
                throw new EstrattoNonValido("Riga $n: un movimento di importo zero non è previsto."); // shortcut: non si sa se esistano righe così nei file veri
            }
            if ($cella($riga, 'Valuta') !== 'EUR') {
                throw new EstrattoNonValido("Riga $n: la valuta («{$cella($riga, 'Valuta')}») non è l'euro: le conversioni di valuta non sono ancora previste."); // shortcut: solo euro (#33)
            }
            $codice = $cella($riga, 'Codice transazione');
            if ($codice === '' || strlen($codice) > 40) {
                throw new EstrattoNonValido("Riga $n: manca il codice transazione, o è troppo lungo.");
            }
            if (isset($codici[$codice])) {
                throw new EstrattoNonValido("Riga $n: il codice transazione «{$codice}» compare già alla riga {$codici[$codice]}.");
            }
            $codici[$codice] = $n;

            $saldoIniziale ??= $saldo - $netto;
            $progressivo = ($progressivo ?? $saldoIniziale) + $netto;
            if ($progressivo !== $saldo) {
                throw new EstrattoNonValido("Riga $n: il saldo progressivo non torna (dopo questo movimento dovrebbe essere ".Euro::formatta($progressivo).', nel file è '.Euro::formatta($saldo).'). Il file è incompleto, è stato modificato o non è in ordine di data.');
            }

            $tipo = Iban::maschera($cella($riga, 'Descrizione'));
            $movimenti[] = new RigaEstratto(
                $this->data($cella($riga, 'Data'), $n), null, $tipo ?: null, Iban::maschera($cella($riga, 'Nome')) ?: $tipo,
                $netto, $saldo, $codice, -$tariffa,
            );
        }

        return new Estratto($movimenti, $saldoIniziale, $progressivo);
    }

    /** @return list<array{0: int, 1: list<?string>}> numero di riga e celle, senza le righe vuote */
    private function righeNonVuote(string $contenuto): array
    {
        $flusso = fopen('php://temp', 'r+');
        fwrite($flusso, $contenuto);
        rewind($flusso);
        $righe = [];
        for ($n = 1; ($celle = fgetcsv($flusso, null, ',', '"', '')) !== false; $n++) {
            if (trim(implode('', $celle)) !== '') {
                $righe[] = [$n, $celle];
            }
        }
        fclose($flusso);

        return $righe;
    }

    /** Posizione di ogni colonna ammessa, dalla riga delle intestazioni. @return array<string, int> */
    private function colonne(array $intestazioni): array
    {
        $posizioni = array_flip(array_map(fn ($c) => trim((string) $c), $intestazioni));
        $mancanti = array_diff(self::COLONNE, array_keys($posizioni));
        if ($mancanti) {
            throw new EstrattoNonValido('Il file non è un estratto di PayPal (CSV con le intestazioni in italiano): nella prima riga mancano le colonne '.implode(', ', $mancanti).'.');
        }

        return array_intersect_key($posizioni, array_flip(self::COLONNE));
    }

    /** «-0,85», «1.234,56», «20,00»: i centesimi con il segno. */
    private function importo(string $testo, string $cosa): int
    {
        $centesimi = Euro::daTesto($testo);
        if ($centesimi === null) {
            throw new EstrattoNonValido("$cosa («{$testo}») non è un importo in euro.");
        }

        return $centesimi;
    }

    /** «3/3/2026»: giorno e mese anche senza lo zero iniziale. */
    private function data(string $testo, int $n): string
    {
        if (! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $testo, $m) || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            throw new EstrattoNonValido("Riga $n: la data («{$testo}») non è nella forma g/m/aaaa.");
        }

        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
}
