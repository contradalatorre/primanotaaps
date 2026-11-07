<?php

namespace App\Import;

use App\Euro;

/**
 * Estratto di Crédit Agricole (Nowbanking, «Lista Movimenti Estratto Conto», CSV): `;`, prima riga con i nomi
 * delle colonne, poi una riga di saldo iniziale, i movimenti dal più vecchio e una riga di saldo finale. Le due
 * righe di saldo si riconoscono dall'importo vuoto. Il formato è descritto in #11 e #14.
 */
final class CreditAgricoleCsv
{
    /** Le uniche colonne che si leggono: ogni altra si scarta (#27). */
    private const COLONNE = ['Data Op.', 'Data Val.', 'Causale', 'Descrizione', 'Importo', 'Saldo Parziale'];

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
