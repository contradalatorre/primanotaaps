<?php

namespace App\Import;

/**
 * La scheda «Cassa» del foglio di calcolo 2026, letta come un estratto (#64, #19): ogni riga con una data e un importo è un movimento, il saldo
 * iniziale e le righe di totale si saltano. Le colonne si riconoscono dal nome dell'intestazione e solo quelle in elenco si leggono (le altre, con nomi
 * di persona, non si guardano mai); le date possono essere date o testo, il prefisso «->» o «→» delle causali si toglie.
 *
 * La chiave contro i doppioni è data + segno + importo + riferimento documento normalizzato + numero d'ordine tra le righe identiche: non contiene
 * causale, descrizione né saldo, così correggere questi campi nel foglio è un conflitto e non una riga nuova più una sparita. Il saldo non entra nella
 * riga (`saldoParziale` resta vuoto: cambiandone una, tutte le successive risulterebbero diverse) ma nei saldi di fine mese dell'`Estratto`.
 * shortcut: le intestazioni sono un'ipotesi sulla scheda del foglio vero (come per `EsempiDalFoglio`); il riferimento documento non si conserva nel
 * movimento (conta solo nella chiave); da rivedere con il primo foglio vero.
 */
class FoglioCassa implements FormatoImport
{
    private const MAX_RIGA_INTESTAZIONE = 15;

    /** Nome normalizzato dell'intestazione → colonna; la prima colonna con quel nome vale. «Nuova causale» vince su «causale». */
    private const INTESTAZIONI = [
        'data' => 'data', 'nuova causale' => 'causale', 'causale' => 'causale_vecchia', 'descrizione' => 'descrizione', 'dettaglio' => 'descrizione',
        'entrate' => 'entrate', 'uscite' => 'uscite', 'importo' => 'importo', 'saldo' => 'saldo',
        'riferimento documento' => 'riferimento', 'rif. documento' => 'riferimento', 'rif documento' => 'riferimento', 'documento' => 'riferimento',
        'n. documento' => 'riferimento', 'riferimento' => 'riferimento',
    ];

    /** Prelievi e versamenti: giroconti con il c/c. Senza `meta`: la metà sta sull'estratto della banca, non si scrive qui. */
    public function regoleGiroconti(): array
    {
        return [
            'prelievo' => ['nome' => 'Prelievo dal c/c', 'verso' => 'entrata', 'contiene' => ['Prelievo'], 'con' => 'banca', 'giorni' => 5],
            'versamento' => ['nome' => 'Versamento sul c/c', 'verso' => 'uscita', 'contiene' => ['Versamento'], 'con' => 'banca', 'giorni' => 5],
        ];
    }

    /** @throws EstrattoNonValido */
    public function leggi(string $contenuto): Estratto
    {
        try {
            $fogli = Foglio::leggi($contenuto, fn (string $scheda) => (bool) preg_match('/^cassa/i', $scheda));
        } catch (FoglioNonValido $e) {
            throw new EstrattoNonValido($e->getMessage());
        }
        $nome = collect(array_keys($fogli))->first(fn ($n) => preg_match('/^cassa/i', (string) $n));
        if ($nome === null) {
            throw new EstrattoNonValido('Nel foglio non c’è la scheda «Cassa».');
        }
        $righe = $fogli[$nome];
        [$intestazione, $c] = $this->colonne($righe) ?? throw new EstrattoNonValido('Nella scheda «Cassa» non trovo l’intestazione: servono le colonne Data, Nuova causale e Entrate e Uscite (o Importo).');

        $movimenti = [];
        $visti = [];
        $saldoIniziale = null;
        $ultimiDelMese = [];
        $saldoFinale = null;
        foreach ($righe as $i => $riga) {
            if ($i <= $intestazione) {
                continue;
            }
            $data = Foglio::data($riga[$c['data']] ?? null);
            $testo = implode(' ', array_filter($riga, 'is_string'));
            $entrata = Foglio::centesimi($riga[$c['entrate'] ?? -1] ?? null);
            $uscita = Foglio::centesimi($riga[$c['uscite'] ?? -1] ?? null);
            $firmato = Foglio::centesimi($riga[$c['importo'] ?? -1] ?? null);
            $saldo = Foglio::centesimi($riga[$c['saldo'] ?? -1] ?? null);

            // Il saldo iniziale sta prima dei movimenti: una «Rettifica saldo iniziale» dopo è un movimento come gli altri.
            if ($movimenti === [] && preg_match('/saldo\s+iniziale/iu', $testo)) {
                $saldoIniziale ??= $saldo ?? $entrata;

                continue;
            }
            $netto = $firmato ?? (($entrata ?? 0) - ($uscita ?? 0));
            if ($data === null) {
                // Le righe vuote e i totali non sono movimenti; un importo con una data illeggibile sì, e non si lascia cadere in silenzio.
                if ($netto !== 0 && ! preg_match('/total/iu', $testo)) {
                    throw new EstrattoNonValido('Riga '.($i + 1).' del foglio: c’è un importo ma la data non si legge. Scrivila come data o come g/m/aaaa.');
                }

                continue;
            }
            if ($netto === 0) {
                continue;
            }

            $causale = Iban::maschera(Foglio::senzaEmail(preg_replace('/^\s*(->|→)\s*/u', '', (string) ($riga[$c['causale']] ?? ''))));
            // Un totale può avere la data («Totale febbraio»): senza causale e con una cella che comincia per «Totale» non è un movimento.
            if ($causale === '' && array_filter($riga, fn ($cella) => is_string($cella) && preg_match('/^\s*total[ei]\b/iu', $cella)) !== []) {
                continue;
            }
            // Senza il segno di Importo, Entrate e Uscite sono positive: un'uscita scritta con il meno diventerebbe un'entrata.
            if ($firmato === null && (($entrata ?? 0) < 0 || ($uscita ?? 0) < 0)) {
                throw new EstrattoNonValido('Riga '.($i + 1).' del foglio: un importo di Entrate o di Uscite è negativo. Scrivilo positivo nella colonna giusta.');
            }
            $descrizione = Iban::maschera(Foglio::senzaEmail($riga[$c['descrizione'] ?? -1] ?? ''));
            $riferimento = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) ($riga[$c['riferimento'] ?? -1] ?? '')));
            $uguali = implode('|', [$data, $netto > 0 ? 'e' : 'u', abs($netto), $riferimento]);
            $visti[$uguali] = ($visti[$uguali] ?? 0) + 1;

            $movimenti[] = new RigaEstratto($data, null, $causale === '' ? null : $causale, $descrizione !== '' ? $descrizione : ($causale ?: 'Senza descrizione'), $netto, null, sha1($uguali.'|'.$visti[$uguali]));
            // Saldo finale: l'ultimo saldo scritto più le righe dopo di esso senza saldo (l'app confronta con tutte le righe), non quello dell'ultimo mese in ordine di data.
            $saldoFinale = $saldo ?? ($saldoFinale === null ? null : $saldoFinale + $netto);
            // Saldo di fine mese: quello dopo l'ultima riga del mese nell'ordine del foglio (con le ultime righe senza saldo, il corrente).
            if ($saldoFinale !== null) {
                $ultimiDelMese[substr($data, 0, 7)] = [count($movimenti) - 1, $saldoFinale];
            }
        }
        if ($movimenti === []) {
            throw new EstrattoNonValido('Nella scheda «Cassa» non ci sono movimenti con data e importo.');
        }
        $saldiMensili = $this->saldiConfrontabili($movimenti, $ultimiDelMese);

        return new Estratto($movimenti, $saldoIniziale, $saldoFinale, $saldiMensili);
    }

    /**
     * Il saldo del foglio è per ordine di riga, quello dell'app per data: un mese è confrontabile solo se, fino alla sua ultima riga, il foglio ha
     * esattamente le righe fino a quel mese. Con le righe fuori ordine di data il saldo di quel mese si lascia fuori (un dubbio falso sul movimento).
     *
     * @param  list<RigaEstratto>  $movimenti
     * @param  array<string, array{0: int, 1: int}>  $ultime  mese → [indice dell'ultima riga del mese, saldo dopo di essa]
     * @return array<string, int>
     */
    private function saldiConfrontabili(array $movimenti, array $ultime): array
    {
        $mesi = array_map(fn (RigaEstratto $r) => substr($r->data, 0, 7), $movimenti);
        $confrontabili = [];
        foreach ($ultime as $mese => [$k, $saldo]) {
            if (max(array_slice($mesi, 0, $k + 1)) === $mese && ($k === count($mesi) - 1 || min(array_slice($mesi, $k + 1)) > $mese)) {
                $confrontabili[$mese] = $saldo;
            }
        }

        return $confrontabili;
    }

    /** @return ?array{0: int, 1: array<string, int>} la riga dell'intestazione e le colonne; null se mancano data, causale e un importo */
    private function colonne(array $righe): ?array
    {
        foreach (array_slice($righe, 0, self::MAX_RIGA_INTESTAZIONE, true) as $i => $riga) {
            $c = [];
            foreach ($riga as $k => $cella) {
                $nome = is_string($cella) ? rtrim(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $cella))), ':') : '';
                if (($colonna = self::INTESTAZIONI[$nome] ?? null) !== null) {
                    $c[$colonna] ??= $k;
                }
            }
            if (isset($c['causale_vecchia']) && ! isset($c['causale'])) {
                $c['causale'] = $c['causale_vecchia'];
            }
            if (isset($c['data'], $c['causale']) && (isset($c['importo']) || isset($c['entrate']) || isset($c['uscite']))) {
                return [$i, $c];
            }
        }

        return null;
    }
}
