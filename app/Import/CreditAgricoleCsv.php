<?php

namespace App\Import;

use App\Euro;

/**
 * Estratto di Crédit Agricole (Nowbanking, «Lista Movimenti Estratto Conto», CSV): `;`, prima riga con i nomi
 * delle colonne, poi una riga di saldo iniziale, i movimenti dal più vecchio e una riga di saldo finale. Le due
 * righe di saldo si riconoscono dall'importo vuoto. Il formato è descritto in #11 e #14.
 */
class CreditAgricoleCsv implements FormatoImport
{
    /** Le uniche colonne che si leggono: ogni altra si scarta (#27). */
    private const COLONNE = ['Data Op.', 'Data Val.', 'Causale', 'Descrizione', 'Importo', 'Saldo Parziale'];

    /**
     * Le regole dei giroconti di questo formato (#47, #33 storia 37), per chiave: aggiungerne una è una voce qui, ritirarla è toglierla.
     * Una regola guarda un movimento del conto (verso; testo di causale e descrizione, senza maiuscole e minuscole) e cerca la sua
     * metà su un altro conto del tipo `con`: stesso importo, verso opposto, data entro `giorni` giorni. Con `meta` (un testo, dove
     * {conto} è il conto del movimento) l'app propone anche di scrivere la metà che manca sul conto `con`.
     *
     * shortcut: le parole di causale e descrizione di prelievi e versamenti sono quelle dell'elenco breve di #11, da verificare su estratti veri.
     *
     * @return array<string, array{nome: string, verso: string, contiene: list<string>, con: string, giorni: int, meta?: string}>
     */
    public function regoleGiroconti(): array
    {
        return [
            // Prima di «versamento»: vince la prima che corrisponde. Il versamento di Satispay in banca (#49) lo dice l'ordinante, nella descrizione.
            // shortcut: la parola «SATISPAY» è un'ipotesi (#33: descrizione del versamento da verificare su un estratto vero, fuori dal repo).
            'satispay' => ['nome' => 'Versamento da Satispay', 'verso' => 'entrata', 'contiene' => ['SATISPAY'], 'con' => 'satispay', 'giorni' => 3, 'meta' => 'Versamento su {conto}'],
            // Senza `meta`: la metà sta sull'estratto PayPal, che la porta (il trasferimento di PayPal e la sua ricarica hanno la loro regola in `PayPalCsv`).
            // shortcut: la parola «PAYPAL» è un'ipotesi, come «SATISPAY» (descrizione dell'ordinante o del beneficiario da verificare su un estratto vero).
            'paypal_accredito' => ['nome' => 'Trasferimento da PayPal', 'verso' => 'entrata', 'contiene' => ['PAYPAL'], 'con' => 'paypal', 'giorni' => 5],
            'paypal_ricarica' => ['nome' => 'Bonifico a PayPal', 'verso' => 'uscita', 'contiene' => ['PAYPAL'], 'con' => 'paypal', 'giorni' => 5],
            'prelievo' => ['nome' => 'Prelievo di contanti', 'verso' => 'uscita', 'contiene' => ['PRELIEV'], 'con' => 'cassa', 'giorni' => 3, 'meta' => 'Prelievo da {conto}'],
            'versamento' => ['nome' => 'Versamento di contanti', 'verso' => 'entrata', 'contiene' => ['VERSAMENT'], 'con' => 'cassa', 'giorni' => 3, 'meta' => 'Versamento su {conto}'],
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
        if (count($righe) < 3) {
            throw new EstrattoNonValido('Il file non contiene movimenti tra la riga del saldo iniziale e quella del saldo finale.');
        }

        $cella = fn (array $riga, string $nome) => trim($riga[$colonne[$nome]] ?? '');
        [$nIniziale, $iniziale] = array_shift($righe);
        [$nFinale, $finale] = array_pop($righe);
        $saldoIniziale = $this->saldo($iniziale, $cella, $nIniziale, 'iniziale');
        $saldoFinale = $this->saldo($finale, $cella, $nFinale, 'finale');

        $movimenti = [];
        $progressivo = $saldoIniziale;
        foreach ($righe as [$n, $riga]) {
            $importo = $this->importo($cella($riga, 'Importo'), "Riga $n: l'importo");
            $saldoParziale = $this->importo($cella($riga, 'Saldo Parziale'), "Riga $n: il saldo parziale");
            $progressivo += $importo;
            if ($progressivo !== $saldoParziale) {
                throw new EstrattoNonValido("Riga $n: il saldo progressivo non torna (dopo questo movimento dovrebbe essere ".Euro::formatta($progressivo).', nel file è '.Euro::formatta($saldoParziale).'). Il file è incompleto o è stato modificato.');
            }
            $valuta = $cella($riga, 'Data Val.');
            $movimenti[] = new RigaEstratto(
                $this->data($cella($riga, 'Data Op.'), $n),
                $valuta === '' ? null : $this->data($valuta, $n),
                Iban::maschera($cella($riga, 'Causale')) ?: null,
                Iban::maschera($cella($riga, 'Descrizione')),
                $importo,
                $saldoParziale,
            );
        }
        if ($progressivo !== $saldoFinale) {
            throw new EstrattoNonValido('Il saldo finale ('.Euro::formatta($saldoFinale).') non è la somma del saldo iniziale e dei movimenti ('.Euro::formatta($progressivo).').');
        }

        return new Estratto($movimenti, $saldoIniziale, $saldoFinale);
    }

    /** @return list<array{0: int, 1: list<?string>}> numero di riga e celle, senza le righe vuote */
    private function righeNonVuote(string $contenuto): array
    {
        $flusso = fopen('php://temp', 'r+');
        fwrite($flusso, $contenuto);
        rewind($flusso);
        $righe = [];
        for ($n = 1; ($celle = fgetcsv($flusso, null, ';', '"', '')) !== false; $n++) {
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
            throw new EstrattoNonValido('Il file non è un estratto di Crédit Agricole (Nowbanking): nella prima riga mancano le colonne '.implode(', ', $mancanti).'.');
        }

        return array_intersect_key($posizioni, array_flip(self::COLONNE));
    }

    private function saldo(array $riga, \Closure $cella, int $n, string $quale): int
    {
        if ($cella($riga, 'Importo') !== '' || $cella($riga, 'Saldo Parziale') === '') {
            throw new EstrattoNonValido("Manca la riga del saldo $quale: l'estratto deve cominciare e finire con le righe dei saldi (riga $n).");
        }

        return $this->importo($cella($riga, 'Saldo Parziale'), "Riga $n: il saldo $quale");
    }

    /** «'-12,50», «1.234,56», «1234,56»: i centesimi con il segno. */
    private function importo(string $testo, string $cosa): int
    {
        $centesimi = Euro::daTesto(ltrim($testo, "'"));
        if ($centesimi === null) {
            throw new EstrattoNonValido("$cosa («{$testo}») non è un importo in euro.");
        }

        return $centesimi;
    }

    private function data(string $testo, int $n): string
    {
        if (! preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $testo, $m) || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            throw new EstrattoNonValido("Riga $n: la data («{$testo}») non è nella forma gg/mm/aaaa.");
        }

        return "$m[3]-$m[2]-$m[1]";
    }
}
