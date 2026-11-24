<?php

namespace App\Esercizio;

use App\Euro;
use Carbon\CarbonInterface;

/**
 * Il Modello D e il rendiconto per l'assemblea come tabelle senza forma (#58): dai risultati di `Calcolo` e `RendicontoAssemblea`, già calcolati, ricava
 * sezioni `{titolo, colonne, righe}` fatte di testi e `Importo`. Il PDF e l'XLSX partono tutti e due da qui, quindi non possono divergere; la
 * pagina a schermo ha le sue viste (con i link alle imputazioni) e legge gli stessi array. Un modulo puro: niente database né HTTP.
 *
 * Nessun nome di persona: le righe portano solo voci, aree, attività, iniziative, tipologie e conti (storia 143).
 *
 * @phpstan-type Riga array{celle: list<string|Importo>, grassetto: bool}
 * @phpstan-type Sezione array{titolo: string, colonne: list<string>, righe: list<Riga>}
 */
final class Tabelle
{
    /**
     * @param  array  $m  il Modello D di `Calcolo::modelloD`
     * @param  ?array  $prec  quello dell'esercizio precedente, per la sua colonna
     * @param  bool  $storico  un esercizio storico non ha i saldi iniziali: la quadratura non si dice
     * @param  list<string>  $impedimenti  cosa manca per chiudere l'esercizio (`Prospetto::impedimenti`): accanto al prospetto provvisorio, come nella pagina
     * @return list<Sezione>
     */
    public static function modelloD(array $m, ?array $prec, int $anno, bool $storico = false, array $impedimenti = []): array
    {
        $colonne = fn (string $primo, string $importo = 'Importo (€)') => [$primo, $importo, ...($prec ? ['Esercizio '.($anno - 1).' (€)'] : [])];
        $riga = fn (string $etichetta, ?int $importo, ?int $precedente = null, bool $grassetto = false) => [
            'celle' => [$etichetta, ...($importo === null ? [] : [new Importo($importo)]), ...($prec && $importo !== null ? [new Importo($precedente ?? 0)] : [])],
            'grassetto' => $grassetto,
        ];
        $blocco = function (array $b, ?array $p) use ($riga) {
            $righe = [$riga($b['titolo'], null, null, true)];
            foreach ($b['righe'] as $i => $r) {
                $righe[] = $riga($r['voce'].') '.$r['etichetta'], $r['importo'], $p['righe'][$i]['importo'] ?? null);
            }
            $righe[] = $riga('Totale', $b['totale']['importo'], $p['totale']['importo'] ?? null, true);

            return $righe;
        };

        $sezioni = [];
        foreach ($m['aree'] as $area => $a) {
            $p = $prec['aree'][$area] ?? null;
            $sezioni[] = ['titolo' => $area.') '.$a['titolo'], 'colonne' => $colonne('Voce'), 'righe' => [
                ...$blocco($a['uscite'], $p['uscite'] ?? null), ...$blocco($a['entrate'], $p['entrate'] ?? null),
                $riga('Avanzo/disavanzo '.lcfirst($a['titolo']), $a['avanzo']['importo'], $p['avanzo']['importo'] ?? null, true),
            ]];
        }

        $sezioni[] = ['titolo' => 'Totali e avanzi', 'colonne' => $colonne('Riga'), 'righe' => [
            $riga('Totale uscite della gestione', $m['gestione']['uscite']['importo'], $prec['gestione']['uscite']['importo'] ?? null),
            $riga('Totale entrate della gestione', $m['gestione']['entrate']['importo'], $prec['gestione']['entrate']['importo'] ?? null),
            $riga('Avanzo/disavanzo d’esercizio prima delle imposte', $m['gestione']['avanzo']['importo'], $prec['gestione']['avanzo']['importo'] ?? null, true),
            $riga('Imposte', $m['imposte']['importo'], $prec['imposte']['importo'] ?? null),
            $riga('Avanzo/disavanzo d’esercizio prima degli investimenti e disinvestimenti patrimoniali e finanziamenti', $m['avanzoPrimaDegliInvestimenti']['importo'], $prec['avanzoPrimaDegliInvestimenti']['importo'] ?? null, true),
            ...$blocco($m['investimenti']['uscite'], $prec['investimenti']['uscite'] ?? null),
            ...$blocco($m['investimenti']['entrate'], $prec['investimenti']['entrate'] ?? null),
            $riga('Avanzo/disavanzo da investimenti e disinvestimenti patrimoniali e finanziamenti', $m['investimenti']['avanzo']['importo'], $prec['investimenti']['avanzo']['importo'] ?? null, true),
            $riga('Avanzo/disavanzo complessivo', $m['avanzoComplessivo']['importo'], $prec['avanzoComplessivo']['importo'] ?? null, true),
        ]];

        $sezioni[] = ['titolo' => 'Cassa e banca', 'colonne' => [...$colonne('Riga', 'Al 31/12/'.$anno.' (€)')], 'righe' => [
            $riga('Cassa', $m['liquidita']['cassa'], $prec['liquidita']['cassa'] ?? null),
            $riga('Depositi bancari e postali', $m['liquidita']['depositi'], $prec['liquidita']['depositi'] ?? null),
            $riga('Totale', $m['liquidita']['totale'], $prec['liquidita']['totale'] ?? null, true),
        ]];

        // Il precedente ha la colonna dei figurativi con le sue righe nello stesso ordine.
        foreach (['costi' => 'Costi figurativi', 'proventi' => 'Proventi figurativi'] as $chiave => $titolo) {
            $righe = [];
            foreach ($m['figurativi'][$chiave]['righe'] as $i => $r) {
                $righe[] = $riga($r['etichetta'], $r['importo'], $prec['figurativi'][$chiave]['righe'][$i]['importo'] ?? null);
            }
            $righe[] = $riga('Totale', $m['figurativi'][$chiave]['totale'], $prec['figurativi'][$chiave]['totale'] ?? null, true);
            $sezioni[] = ['titolo' => $titolo.' (non entrano in nessun totale né avanzo)', 'colonne' => $colonne('Riga', $anno.' (€)'), 'righe' => $righe];
        }

        $avvisi = $m['anomalie'];
        if (! $storico && ! $m['quadratura']['quadra']) {
            $avvisi[] = 'Non quadra: la liquidità è passata da '.Euro::formatta($m['liquidita']['iniziale']).' € a '.Euro::formatta($m['liquidita']['totale']).' €, ma l’avanzo complessivo è '
                .Euro::formatta($m['avanzoComplessivo']['importo']).' € (differenza di '.Euro::formatta($m['quadratura']['differenza']).' €).';
        }

        return [...$sezioni, ...self::avvisi($avvisi), ...self::avvisi($impedimenti, 'Impedimenti')];
    }

    /**
     * Il rendiconto per l'assemblea per area → attività → iniziativa → tipologia, con una colonna per conto; poi il riepilogo per conto, gli avvisi e la destinazione dell'avanzo.
     * La nota della destinazione è dei dati dell'esercizio e la vede solo chi li gestisce: `$conNote` (stesso permesso della pagina, `gestire_esercizio`).
     *
     * @return list<Sezione>
     */
    public static function rendiconto(array $r, bool $conNote): array
    {
        $colonne = ['Area, attività, iniziativa e tipologia', ...array_values($r['conti']), 'Totale'];
        $riga = fn (string $etichetta, ?array $cifra = null, bool $grassetto = false) => [
            'celle' => [$etichetta, ...($cifra === null ? [] : [...array_map(fn (int $c) => new Importo($c), array_values($cifra['conti'])), new Importo($cifra['totale'])])],
            'grassetto' => $grassetto,
        ];
        $lato = function (string $titolo, array $l) use ($riga) {
            $righe = [$riga($titolo, null, true)];
            foreach ($l['righe'] as $t) {
                $righe[] = $riga('– '.$t['tipologia'], $t);
            }
            $righe[] = $riga('Totale '.strtolower($titolo), $l['totale'], true);

            return $righe;
        };

        $righe = [];
        foreach ($r['aree'] as $codice => $area) {
            $righe[] = $riga('Area '.$codice.' · '.$area['titolo'], null, true);
            foreach ($area['attivita'] as $attivita) {
                $righe[] = $riga($attivita['nome'], null, true);
                foreach ($attivita['iniziative'] as $iniziativa) {
                    $righe[] = $riga($iniziativa['nome'], null, true);
                    array_push($righe, ...$lato('Entrate', $iniziativa['entrate']), ...$lato('Uscite', $iniziativa['uscite']));
                    $righe[] = $riga('Risultato · '.$iniziativa['nome'], $iniziativa['risultato'], true);
                }
                $righe[] = $riga('Risultato dell’attività · '.$attivita['nome'], $attivita['risultato'], true);
            }
            array_push($righe, $riga('Entrate dell’area '.$codice, $area['entrate'], true), $riga('Uscite dell’area '.$codice, $area['uscite'], true), $riga('Risultato dell’area '.$codice, $area['risultato'], true));
        }
        array_push($righe, $riga('Totale entrate', $r['totali']['entrate'], true), $riga('Totale uscite', $r['totali']['uscite'], true), $riga('Avanzo (disavanzo) dell’esercizio', $r['totali']['risultato'], true));
        $sezioni = [['titolo' => 'Per area, attività, iniziativa e tipologia', 'colonne' => $colonne, 'righe' => $righe]];

        $conti = [];
        foreach ([...array_values($r['riepilogo']['conti']), ['nome' => 'Totale'] + $r['riepilogo']['totale']] as $c) {
            $conti[] = ['celle' => [
                $c['nome'], ...array_map(fn (string $k) => new Importo($c[$k]), ['iniziale', 'entrate', 'uscite', 'giroconti_entrata', 'giroconti_uscita', 'finale', 'nonClassificato']),
                ...(isset($c['riconciliato']) ? [
                    $c['saldoRiconciliato'] === null ? '–' : new Importo($c['saldoRiconciliato']),
                    $c['riconciliatoAl'] === null ? 'mai' : ($c['riconciliato'] ? 'sì' : 'fino al '.date('d/m/Y', strtotime($c['riconciliatoAl']))),
                ] : []),
            ], 'grassetto' => ! isset($c['riconciliato'])];
        }
        $sezioni[] = ['titolo' => 'Riepilogo per conto (i giroconti restano fuori dai totali del prospetto)', 'colonne' => ['Conto', 'Saldo iniziale', 'Entrate', 'Uscite', 'Giroconti in entrata', 'Giroconti in uscita', 'Saldo finale', 'Non classificato', 'Saldo riconciliato', 'Riconciliato'], 'righe' => $conti];

        $avvisi = [...$r['senzaRiga']['avvisi'], ...array_filter(array_column($r['riepilogo']['conti'], 'avviso'))];
        $sezioni = [...$sezioni, ...self::avvisi($avvisi)];

        $d = $r['destinazione'];
        $righe = array_map(fn (array $x) => ['celle' => [$x['descrizione'], new Importo($x['importo']), ...($conNote ? [$x['nota'] ?? ''] : [])], 'grassetto' => false], $d['righe']);
        array_push(
            $righe,
            ['celle' => ['Avanzo (disavanzo) dell’esercizio', new Importo($d['avanzo'])], 'grassetto' => true],
            ['celle' => ['Destinato', new Importo($d['destinato'])], 'grassetto' => true],
            ['celle' => ['Residuo', new Importo($d['residuo'])], 'grassetto' => true],
        );
        $sezioni[] = ['titolo' => 'Destinazione dell’avanzo', 'colonne' => ['Destinazione', 'Importo (€)', ...($conNote ? ['Nota'] : [])], 'righe' => $righe];

        return $sezioni;
    }

    /**
     * Il rendiconto di una raccolta fondi occasionale con la sua relazione illustrativa (#61, schema del DM 9/6/2022): quello che il PDF di deposito allega e che si scarica
     * da solo, in PDF e XLSX. Nessun nome di persona: solo ciò che l'operatore ha scritto, con gli importi dal calcolo. Ciò che non ha ancora scritto si stampa «da completare».
     *
     * @param  array  $r  un risultato di `Raccolta::calcola` con il `nome` dell'iniziativa (`Raccolte::calcola`)
     * @return list<Sezione>
     */
    public static function raccolta(array $r, int $anno): array
    {
        $riga = fn (string $etichetta, string|Importo|null $valore = null, bool $grassetto = false) => ['celle' => [$etichetta, ...($valore === null ? [] : [$valore])], 'grassetto' => $grassetto];
        $importo = fn (int $c) => new Importo($c);
        $c = $r['relazione']['campi'];
        $testo = fn (string $campo) => filled($c[$campo] ?? null) ? (string) $c[$campo] : 'da completare';
        $cifra = fn (string $campo) => ($c[$campo] ?? null) === null ? 'da completare' : $importo((int) $c[$campo]);
        $data = fn (string $campo) => filled($c[$campo] ?? null) ? date('d/m/Y', strtotime((string) $c[$campo])) : null;

        $proventi = [$riga('Liberalità monetarie', $importo($r['proventi']['liberalita_monetarie'])), $riga('Liberalità non monetarie (valore di mercato)', $importo($r['proventi']['liberalita_non_monetarie'])), $riga('Altri proventi', $importo($r['proventi']['altri_proventi'])), $riga('Totale proventi', $importo($r['proventi']['totale']), true)];
        $oneri = [...array_map(fn (string $k, string $etichetta) => $riga($etichetta, $importo($r['oneri']['righe'][$k])), array_keys(Raccolta::ONERI), Raccolta::ONERI), $riga('Totale oneri', $importo($r['oneri']['totale']), true)];

        $sezioni = [
            ['titolo' => 'Evento', 'colonne' => ['Campo', 'Valore'], 'righe' => [
                $riga('Denominazione', $r['nome']), $riga('Esercizio', (string) $anno),
                $riga('Date', $data('dal') !== null && $data('al') !== null ? 'dal '.$data('dal').' al '.$data('al') : 'da completare'),
            ]],
            ['titolo' => 'Proventi (a)', 'colonne' => ['Riga', 'Importo (€)'], 'righe' => $proventi],
            ['titolo' => 'Oneri (b)', 'colonne' => ['Riga', 'Importo (€)'], 'righe' => $oneri],
            ['titolo' => 'Risultato (a − b)', 'colonne' => ['Riga', 'Importo (€)'], 'righe' => [$riga('Risultato', $importo($r['risultato']), true)]],
        ];
        if ($r['liberalita'] !== []) {
            $sezioni[] = ['titolo' => 'Liberalità non monetarie', 'colonne' => ['Descrizione', 'Quantità', 'Valore stimato (€)', 'Criterio di stima', 'Documento'], 'righe' => array_map(
                fn (array $l) => ['celle' => [$l['descrizione'], (string) $l['quantita'], $importo($l['valore']), $l['criterio'], $l['documento']], 'grassetto' => false], $r['liberalita'],
            )];
        }

        $denaro = $r['relazione']['denaro'];
        $relazione = [
            $riga('Ricorrenza o campagna', $testo('occasione')), $riga('Descrizione', $testo('descrizione')), $riga('Luogo', $testo('luogo')), $riga('Modalità', $testo('modalita')),
            $riga('Finalità', $testo('finalita')), $riga('Beni o servizi offerti', $testo('beni_servizi')),
            $riga('Denaro raccolto in contanti', $importo($denaro['contanti'])), $riga('Denaro raccolto su conto corrente o altro', $importo($denaro['conti'])),
            $riga('Valore dei beni (liberalità non monetarie)', $importo($r['relazione']['valore_beni'])),
            $riga('Costi sostenuti', $importo($r['oneri']['totale'])), $riga('Dettaglio dei costi', $testo('dettaglio_costi')),
            $riga('Netto raccolto', $importo($r['risultato']), true), $riga('Attività di destinazione', $testo('attivita_destinazione')),
            $riga('Beni di modico valore ceduti (numero)', ($c['beni_modico_numero'] ?? null) === null ? 'da completare' : (string) $c['beni_modico_numero']),
            $riga('Beni di modico valore ceduti (prezzo di ciascuno)', $cifra('beni_modico_prezzo')),
            $riga('Elargizioni di persone fisiche', $cifra('elargizioni_persone_fisiche')), $riga('Elargizioni di persone giuridiche', $cifra('elargizioni_persone_giuridiche')),
        ];
        if ($r['motivazione']['richiesta'] || filled($c['motivazione'] ?? null)) {
            $relazione[] = $riga('Motivazione', $testo('motivazione'));
        }
        $sezioni[] = ['titolo' => 'Relazione illustrativa', 'colonne' => ['Voce', 'Valore'], 'righe' => $relazione];

        return $sezioni;
    }

    /**
     * Le righe sopra ogni prospetto, dalle impostazioni dell'associazione: ente, codice fiscale e RUNTS, titolo e lo stato. Un esercizio aperto è sempre
     * «provvisorio – aggiornato al …»: sta qui, in intestazione, e non in filigrana (il PDF/A non ammette trasparenze). Un esercizio approvato (#59) porta al suo posto
     * `$approvazione` («Approvato il … · versione N») e, se i dati di oggi non sono più quelli approvati, la frase sulle differenze.
     *
     * @param  array{denominazione?: ?string, codice_fiscale?: ?string, numero_runts?: ?string}  $impostazioni
     * @return list<string> la prima è la denominazione
     */
    public static function intestazione(array $impostazioni, string $titolo, bool $storico, bool $proposte, CarbonInterface $ora, ?string $approvazione = null, bool $conDifferenze = false): array
    {
        return [
            ($impostazioni['denominazione'] ?? '') ?: 'Denominazione da inserire',
            'Codice fiscale '.(($impostazioni['codice_fiscale'] ?? '') ?: 'da inserire').' · numero RUNTS '.(($impostazioni['numero_runts'] ?? '') ?: 'da inserire'),
            $titolo,
            $storico ? 'Esercizio storico: importi caricati dal foglio approvato'
                : ($approvazione === null ? 'Provvisorio –' : $approvazione.' ·').' aggiornato al '.$ora->format('d/m/Y H:i').'. '
                .($proposte ? 'Con anche le imputazioni proposte, ancora da confermare.' : 'Solo le imputazioni confermate.').($conDifferenze ? ' Ci sono differenze dalla versione approvata.' : ''),
        ];
    }

    /** Il piè di pagina senza il numero di pagina, che lo mette il PDF: data di generazione e versione dell'esercizio. */
    public static function piede(int $anno, CarbonInterface $ora, int $versione, bool $storico): string
    {
        return 'Generato il '.$ora->format('d/m/Y H:i').' · Esercizio '.$anno.' · versione '.$versione.($storico ? '' : ' (provvisoria)');
    }

    /**
     * Le sezioni come righe di un foglio unico: titolo e intestazioni in grassetto, una riga vuota tra una sezione e l'altra.
     *
     * @param  list<Sezione>  $sezioni
     * @param  list<string>  $intestazione  le righe sopra tutto: ente, esercizio, «provvisorio»
     * @return list<Riga>
     */
    public static function comeRighe(array $sezioni, array $intestazione): array
    {
        $righe = array_map(fn (string $t, int $i) => ['celle' => [$t], 'grassetto' => $i === 0], $intestazione, array_keys($intestazione));
        foreach ($sezioni as $s) {
            $righe[] = ['celle' => [], 'grassetto' => false];
            $righe[] = ['celle' => [$s['titolo']], 'grassetto' => true];
            if ($s['colonne'] !== []) {
                $righe[] = ['celle' => $s['colonne'], 'grassetto' => true];
            }
            array_push($righe, ...$s['righe']);
        }

        return $righe;
    }

    /** @param list<string> $avvisi */
    private static function avvisi(array $avvisi, string $titolo = 'Avvisi'): array
    {
        return $avvisi === [] ? [] : [['titolo' => $titolo, 'colonne' => [], 'righe' => array_map(fn (string $a) => ['celle' => [$a], 'grassetto' => false], array_values($avvisi))]];
    }
}
