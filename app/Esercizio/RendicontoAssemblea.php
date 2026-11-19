<?php

namespace App\Esercizio;

use App\Euro;
use InvalidArgumentException;

/**
 * Il rendiconto per l'assemblea (#56, glossario), un modulo puro come `Calcolo`: dalle imputazioni, dai conti e dalla destinazione dell'avanzo
 * ricava il prospetto per area → attività → iniziativa → tipologia, con una colonna per conto e il totale; la stessa cifra raggruppata per voce
 * Modello D → tipologia (vista di controllo); il riepilogo per conto e la destinazione dell'avanzo. Importi in centesimi interi.
 *
 * Una «cifra» è `['conti' => [id conto => centesimi], 'totale' => centesimi]`: ha sempre tutte le colonne, anche a zero, nell'ordine di `$conti`.
 * Le imputazioni sono quelle di `Prospetto::imputazioni` (mai le metà di un giroconto): i giroconti compaiono solo nel riepilogo, fuori dai totali.
 * Nessun nome di persona: le righe portano solo area, attività, iniziativa, tipologia e conto.
 */
final class RendicontoAssemblea
{
    /**
     * @param  list<RigaRendiconto>  $righe
     * @param  list<ContoRendiconto>  $conti  le colonne, nell'ordine in cui vanno mostrate
     * @param  list<array{descrizione: string, importo: int, nota: ?string}>  $destinazioni
     */
    public static function calcola(array $righe, array $conti, array $destinazioni): array
    {
        $colonne = [];
        foreach ($conti as $conto) {
            $colonne[$conto->id] = $conto->nome;
        }
        foreach ($righe as $riga) {
            // Il denaro non sparisce in silenzio: una colonna che manca è un errore di chi chiama.
            isset($colonne[$riga->conto]) || throw new InvalidArgumentException("Un'imputazione è su un conto ({$riga->conto}) che non è tra le colonne.");
        }

        $aree = [];
        foreach (SchemaModelloD::AREE as $codice => $titolo) {
            $dellArea = array_values(array_filter($righe, fn (RigaRendiconto $r) => $r->area === $codice));
            if ($dellArea === []) {
                continue;
            }
            $attivita = array_map(fn (array $g) => self::attivita($g['chiave'], $g['righe'], $colonne), self::raggruppa($dellArea, fn (RigaRendiconto $r) => $r->attivita));
            $aree[$codice] = ['titolo' => $titolo, 'attivita' => $attivita] + self::lati($dellArea, $colonne);
        }

        $totali = self::lati($righe, $colonne);
        $destinato = array_sum(array_column($destinazioni, 'importo'));

        return [
            'conti' => $colonne,
            'aree' => $aree,
            'totali' => $totali,
            'voci' => self::voci($righe, $colonne),
            'riepilogo' => self::riepilogo($righe, $conti),
            'destinazione' => ['avanzo' => $totali['risultato']['totale'], 'destinato' => $destinato, 'residuo' => $totali['risultato']['totale'] - $destinato, 'righe' => $destinazioni],
        ];
    }

    /** @return array{nome: string, iniziative: list<array>, entrate: array, uscite: array, risultato: array} */
    private static function attivita(?string $nome, array $righe, array $colonne): array
    {
        $iniziative = array_map(function (array $g) use ($nome, $colonne) {
            $lati = [];
            foreach (['entrate', 'uscite'] as $lato) {
                $delLato = array_filter($g['righe'], fn (RigaRendiconto $r) => $r->lato === $lato);
                $lati[$lato] = ['righe' => self::tipologie($delLato, $colonne), 'totale' => self::cifra($delLato, $colonne)];
            }

            return ['nome' => $g['chiave'] ?? ($nome === null ? 'Altro' : 'Altro – '.$nome)] + $lati
                + ['risultato' => self::differenza($lati['entrate']['totale'], $lati['uscite']['totale'])];
        }, self::raggruppa($righe, fn (RigaRendiconto $r) => $r->iniziativa));

        return ['nome' => $nome ?? 'Senza attività', 'iniziative' => $iniziative] + self::lati($righe, $colonne);
    }

    /** @return array{entrate: array, uscite: array, risultato: array} */
    private static function lati(array $righe, array $colonne): array
    {
        $entrate = self::cifra(array_filter($righe, fn (RigaRendiconto $r) => $r->lato === 'entrate'), $colonne);
        $uscite = self::cifra(array_filter($righe, fn (RigaRendiconto $r) => $r->lato === 'uscite'), $colonne);

        return ['entrate' => $entrate, 'uscite' => $uscite, 'risultato' => self::differenza($entrate, $uscite)];
    }

    /**
     * La vista di controllo: per lato (entrate, poi uscite) e per voce Modello D, nell'ordine dello schema; le voci fuori schema dopo, quelle senza voce in fondo.
     *
     * @return list<array{lato: string, voce: ?string, etichetta: string, righe: list<array>, totale: array}>
     */
    private static function voci(array $righe, array $colonne): array
    {
        $voci = [];
        foreach (['entrate', 'uscite'] as $lato) {
            $schema = self::etichette($lato);
            $posizione = fn (?string $v) => $v === null ? PHP_INT_MAX : (($p = array_search($v, array_keys($schema), true)) === false ? PHP_INT_MAX - 1 : $p);
            $gruppi = self::raggruppa(
                array_filter($righe, fn (RigaRendiconto $r) => $r->lato === $lato),
                fn (RigaRendiconto $r) => $r->voce,
                fn (?string $a, ?string $b) => [$posizione($a), $a] <=> [$posizione($b), $b],
            );
            foreach ($gruppi as $g) {
                $voci[] = [
                    'lato' => $lato, 'voce' => $g['chiave'],
                    'etichetta' => $g['chiave'] === null ? 'Senza voce Modello D' : ($schema[$g['chiave']] ?? 'Voce «'.$g['chiave'].'», che non è nello schema'),
                    'righe' => self::tipologie($g['righe'], $colonne), 'totale' => self::cifra($g['righe'], $colonne),
                ];
            }
        }

        return $voci;
    }

    /** @return array<string, string> codice → etichetta, nell'ordine del Modello D: aree A-E, imposte, investimenti */
    private static function etichette(string $lato): array
    {
        $etichette = [];
        foreach (SchemaModelloD::voci($lato) as $voci) {
            $etichette += $voci;
        }
        if ($lato === 'uscite') {
            $etichette['Imposte'] = 'Imposte';
        }

        return $etichette + SchemaModelloD::investimenti($lato);
    }

    /** @return list<array{tipologia: string, conti: array<int, int>, totale: int}> */
    private static function tipologie(array $righe, array $colonne): array
    {
        return array_map(fn (array $g) => ['tipologia' => $g['chiave']] + self::cifra($g['righe'], $colonne), self::raggruppa($righe, fn (RigaRendiconto $r) => $r->tipologia));
    }

    /**
     * Il riepilogo per conto, con i giroconti fuori dai totali. `nonClassificato` è il denaro che le imputazioni non spiegano: movimenti non
     * ancora classificati o confermati, o la metà di un giroconto non ancora confermato; con tutto a posto è zero.
     *
     * @param  list<ContoRendiconto>  $conti
     */
    private static function riepilogo(array $righe, array $conti): array
    {
        $perConto = [];
        foreach ($conti as $c) {
            $somma = fn (string $lato) => array_sum(array_map(fn (RigaRendiconto $r) => $r->conto === $c->id && $r->lato === $lato ? $r->importo : 0, $righe));
            $entrate = $somma('entrate');
            $uscite = $somma('uscite');
            $perConto[$c->id] = [
                'nome' => $c->nome, 'iniziale' => $c->iniziale, 'entrate' => $entrate, 'uscite' => $uscite,
                'giroconti_entrata' => $c->giroconti_entrata, 'giroconti_uscita' => $c->giroconti_uscita, 'finale' => $c->finale,
                'nonClassificato' => $c->finale - ($c->iniziale + $entrate - $uscite + $c->giroconti_entrata - $c->giroconti_uscita),
            ] + self::riconciliazione($c);
        }
        $totale = [];
        foreach (['iniziale', 'entrate', 'uscite', 'giroconti_entrata', 'giroconti_uscita', 'finale', 'nonClassificato'] as $campo) {
            $totale[$campo] = array_sum(array_column($perConto, $campo));
        }

        return ['conti' => $perConto, 'totale' => $totale];
    }

    /**
     * Riconciliato se lo è fino all'ultimo giorno del conto nell'esercizio (o oltre); altrimenti l'avviso, con quanto il saldo è cambiato
     * dopo l'ultima riconciliazione. Il confronto col saldo riconciliato ha senso solo prima: dopo, quel saldo è di un'altra data.
     *
     * @return array{riconciliato: bool, riconciliatoAl: ?string, saldoRiconciliato: ?int, differenzaRiconciliato: ?int, avviso: ?string}
     */
    private static function riconciliazione(ContoRendiconto $c): array
    {
        if ($c->riconciliatoAl === null) {
            return ['riconciliato' => false, 'riconciliatoAl' => null, 'saldoRiconciliato' => null, 'differenzaRiconciliato' => null, 'avviso' => '«'.$c->nome.'» non è mai stato riconciliato'];
        }
        if ($c->riconciliatoAl >= $c->fino) {
            return ['riconciliato' => true, 'riconciliatoAl' => $c->riconciliatoAl, 'saldoRiconciliato' => $c->finale, 'differenzaRiconciliato' => 0, 'avviso' => null];
        }
        $differenza = $c->finale - ($c->saldoRiconciliato ?? 0);

        return [
            'riconciliato' => false, 'riconciliatoAl' => $c->riconciliatoAl, 'saldoRiconciliato' => $c->saldoRiconciliato, 'differenzaRiconciliato' => $differenza,
            'avviso' => '«'.$c->nome.'» è riconciliato solo fino al '.date('d/m/Y', strtotime($c->riconciliatoAl)).': da allora il saldo è cambiato di '.Euro::formatta($differenza).' €',
        ];
    }

    /** @return array{conti: array<int, int>, totale: int} */
    private static function cifra(array $righe, array $colonne): array
    {
        $conti = array_fill_keys(array_keys($colonne), 0);
        foreach ($righe as $r) {
            $conti[$r->conto] += $r->importo;
        }

        return ['conti' => $conti, 'totale' => array_sum($conti)];
    }

    private static function differenza(array $entrate, array $uscite): array
    {
        $conti = [];
        foreach ($entrate['conti'] as $id => $importo) {
            $conti[$id] = $importo - $uscite['conti'][$id];
        }

        return ['conti' => $conti, 'totale' => $entrate['totale'] - $uscite['totale']];
    }

    /**
     * Le righe divise per chiave, in ordine di chiave senza badare alle maiuscole (o con `$confronta`); la chiave null (nessuna attività, nessuna
     * iniziativa) va in fondo. Le chiavi stanno in una lista, non in un array associativo: un nome «2026» non diventi un intero.
     *
     * @return list<array{chiave: ?string, righe: list<RigaRendiconto>}>
     */
    private static function raggruppa(array $righe, callable $chiave, ?callable $confronta = null): array
    {
        $gruppi = [];
        foreach ($righe as $r) {
            $k = $chiave($r);
            $indice = $k === null ? "\0" : 'k'.$k;
            $gruppi[$indice] ??= ['chiave' => $k, 'righe' => []];
            $gruppi[$indice]['righe'][] = $r;
        }
        $confronta ??= fn (?string $a, ?string $b) => $a === null ? 1 : ($b === null ? -1 : strcmp(mb_strtolower($a), mb_strtolower($b)));
        usort($gruppi, fn (array $a, array $b) => $confronta($a['chiave'], $b['chiave']));

        return $gruppi;
    }
}
