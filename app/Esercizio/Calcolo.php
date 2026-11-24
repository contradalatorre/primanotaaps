<?php

namespace App\Esercizio;

use App\Euro;

/**
 * Il calcolo dell'esercizio (seconda seam di test della spec #33): dato un insieme di imputazioni, i saldi dei conti, i
 * dati dell'esercizio e le impostazioni dell'associazione restituisce il Modello D. Un modulo puro: niente database, HTTP
 * né date di oggi. Chi lo chiama sceglie le imputazioni (confermate o anche proposte, mai le metà di un giroconto).
 *
 * Ogni cifra porta le sue `chiavi` («entrate:A1»): le righe del Modello D che la compongono, con cui la pagina arriva
 * all'elenco filtrato delle imputazioni. Gli avanzi sono la differenza entrate − uscite delle stesse righe.
 */
final class Calcolo
{
    /**
     * @param  list<Imputata>  $imputazioni
     * @param  list<Saldo>  $saldi
     * @param  list<Figurativo>  $figurativi
     * @param  array<string, mixed>  $impostazioni  denominazione, codice fiscale, numero RUNTS, affiliazione: vanno nell'intestazione
     */
    public static function modelloD(array $imputazioni, array $saldi, array $figurativi, array $impostazioni = []): array
    {
        $importi = ['entrate' => [], 'uscite' => []];
        $senzaRiga = ['entrate' => 0, 'uscite' => 0];

        foreach ($imputazioni as $i) {
            if (SchemaModelloD::conosce($i->lato, $i->voce)) {
                $importi[$i->lato][$i->voce] = ($importi[$i->lato][$i->voce] ?? 0) + $i->importo;

                continue;
            }
            $senzaRiga[$i->lato] += $i->importo;
        }
        $anomalie = self::avvisiSenzaRiga($imputazioni);

        $aree = [];
        foreach (SchemaModelloD::AREE as $area => $titolo) {
            $uscite = self::blocco('uscite', SchemaModelloD::voci('uscite')[$area], $importi['uscite'], 'Uscite da '.lcfirst($titolo));
            $entrate = self::blocco('entrate', SchemaModelloD::voci('entrate')[$area], $importi['entrate'], 'Entrate da '.lcfirst($titolo));
            $aree[$area] = ['titolo' => $titolo, 'uscite' => $uscite, 'entrate' => $entrate, 'avanzo' => self::avanzo($entrate['totale'], $uscite['totale'])];
        }

        $gestione = [
            'uscite' => self::somma(array_map(fn (array $a) => $a['uscite']['totale'], $aree)),
            'entrate' => self::somma(array_map(fn (array $a) => $a['entrate']['totale'], $aree)),
        ];
        $gestione['avanzo'] = self::avanzo($gestione['entrate'], $gestione['uscite']);
        $imposte = self::cifra('uscite', ['Imposte'], $importi['uscite']);
        $primaDegliInvestimenti = self::avanzo($gestione['avanzo'], $imposte);

        $investimenti = [
            'uscite' => self::blocco('uscite', SchemaModelloD::investimenti('uscite'), $importi['uscite'], 'Uscite da investimenti in immobilizzazioni o da deflussi di capitale di terzi'),
            'entrate' => self::blocco('entrate', SchemaModelloD::investimenti('entrate'), $importi['entrate'], 'Entrate da disinvestimenti di immobilizzazioni o da flussi di capitale di terzi'),
        ];
        $investimenti['avanzo'] = self::avanzo($investimenti['entrate']['totale'], $investimenti['uscite']['totale']);
        $complessivo = self::somma([$primaDegliInvestimenti, $investimenti['avanzo']]);

        // Controllo indipendente della catena degli avanzi: il foglio 2025 aveva una formula sbagliata proprio qui (#18).
        if ($complessivo['importo'] !== array_sum($importi['entrate']) - array_sum($importi['uscite'])) {
            $anomalie[] = 'Gli avanzi del Modello D non quadrano con la differenza tra entrate e uscite: è un errore del calcolo.';
        }

        $liquidita = ['cassa' => 0, 'depositi' => 0, 'iniziale' => 0];
        foreach ($saldi as $saldo) {
            $liquidita[$saldo->riga] += $saldo->finale;
            $liquidita['iniziale'] += $saldo->iniziale;
        }
        $liquidita['totale'] = $liquidita['cassa'] + $liquidita['depositi'];
        // Cassa e banca devono essere cresciute dell'avanzo complessivo; durante l'anno la differenza dice cosa manca (movimenti non confermati, giroconti a metà).
        $differenza = $liquidita['totale'] - $liquidita['iniziale'] - $complessivo['importo'];

        return [
            'intestazione' => $impostazioni,
            'aree' => $aree,
            'gestione' => $gestione,
            'imposte' => $imposte,
            'avanzoPrimaDegliInvestimenti' => $primaDegliInvestimenti,
            'investimenti' => $investimenti,
            'avanzoComplessivo' => $complessivo,
            'liquidita' => $liquidita,
            'figurativi' => self::figurativi($figurativi, $anomalie),
            'quadratura' => ['differenza' => $differenza, 'quadra' => $differenza === 0],
            'senzaRiga' => $senzaRiga,
            'anomalie' => $anomalie,
        ];
    }

    /**
     * Gli avvisi per le imputazioni che il Modello D non sa collocare (senza voce, o con una voce fuori schema), uno per lato e voce. Valgono
     * per tutto ciò che ha `lato`, `voce` e `importo` (`Imputata`, `RigaRendiconto`): rendiconto e Modello D dicono la stessa cosa.
     *
     * @param  iterable<object{lato: string, voce: ?string, importo: int}>  $imputazioni
     * @return list<string>
     */
    public static function avvisiSenzaRiga(iterable $imputazioni): array
    {
        $perAnomalia = [];
        foreach ($imputazioni as $i) {
            if (! SchemaModelloD::conosce($i->lato, $i->voce)) {
                $perAnomalia[$i->lato.'|'.($i->voce ?? '')][] = $i->importo;
            }
        }
        $avvisi = [];
        foreach ($perAnomalia as $chiave => $somme) {
            [$lato, $voce] = explode('|', $chiave);
            $avvisi[] = count($somme).' imputazioni in '.$lato.' per '.Euro::formatta(array_sum($somme)).' € '
                .($voce === '' ? 'senza voce Modello D' : 'con la voce «'.$voce.'», che non è nello schema').': non sono nel prospetto.';
        }

        return $avvisi;
    }

    /** @return array{importo: int, chiavi: list<string>} */
    private static function cifra(string $lato, array $codici, array $importi): array
    {
        return ['importo' => array_sum(array_map(fn (string $c) => $importi[$c] ?? 0, $codici)), 'chiavi' => array_map(fn (string $c) => "$lato:$c", $codici)];
    }

    private static function blocco(string $lato, array $voci, array $importi, string $titolo): array
    {
        $righe = [];
        foreach ($voci as $codice => $etichetta) {
            $righe[] = ['voce' => $codice, 'etichetta' => $etichetta] + self::cifra($lato, [$codice], $importi);
        }

        return ['titolo' => $titolo, 'righe' => $righe, 'totale' => self::cifra($lato, array_keys($voci), $importi)];
    }

    /** @param list<array{importo: int, chiavi: list<string>}> $cifre */
    private static function somma(array $cifre): array
    {
        return ['importo' => array_sum(array_column($cifre, 'importo')), 'chiavi' => array_merge(...array_column($cifre, 'chiavi'))];
    }

    private static function avanzo(array $entrate, array $uscite): array
    {
        return ['importo' => $entrate['importo'] - $uscite['importo'], 'chiavi' => array_merge($entrate['chiavi'], $uscite['chiavi'])];
    }

    /** I costi e i proventi figurativi stanno a parte: non entrano in nessun totale né avanzo. */
    private static function figurativi(array $figurativi, array &$anomalie): array
    {
        $prospetto = [];
        foreach (['costo' => 'costi', 'provento' => 'proventi'] as $tipo => $nome) {
            $righe = [];
            foreach (SchemaModelloD::figurativi() as $area => $etichetta) {
                $righe[$area] = ['area' => $area, 'etichetta' => $etichetta, 'importo' => 0];
            }
            foreach ($figurativi as $f) {
                if ($f->tipo !== $tipo) {
                    continue;
                }
                if (! isset($righe[$f->area])) {
                    // Senza la descrizione libera: può nominare una persona (storia 143).
                    $anomalie[] = "Un $tipo figurativo di ".Euro::formatta($f->importo)." € è nell'area {$f->area}, che nel Modello D non ha una riga: non è nel prospetto.";

                    continue;
                }
                $righe[$f->area]['importo'] += $f->importo;
            }
            $prospetto[$nome] = ['righe' => array_values($righe), 'totale' => array_sum(array_column($righe, 'importo'))];
        }

        return $prospetto;
    }
}
