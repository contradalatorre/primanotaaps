<?php

namespace App\Esercizio;

use App\Euro;

/**
 * Il rendiconto di una raccolta fondi occasionale con la sua relazione illustrativa (#61, #18; art. 87 c. 6 e art. 48 c. 3 CTS, DM 9/6/2022 Allegato 1).
 * Un modulo puro come `ContiIniziativa`: niente database né HTTP; `Raccolte` gli porta le imputazioni della raccolta e i dati scritti a mano.
 *
 * Proventi (a): liberalità monetarie, valore di mercato delle liberalità non monetarie (inserite a mano, solo qui: mai nel Modello D né nei test) e altri proventi.
 * Oneri (b): le sette righe dello schema. Risultato = a − b. Una imputazione senza riga dello schema (la tabella tipologia → riga non la conosce) non entra
 * nei totali: si conta a parte e la quadratura con la voce C2 la segnala. Importi in centesimi interi.
 */
final class Raccolta
{
    public const PROVENTI = ['liberalita_monetarie' => 'Liberalità monetarie', 'altri_proventi' => 'Altri proventi'];

    public const ONERI = [
        'acquisto_beni' => 'Acquisto di beni', 'acquisto_servizi' => 'Acquisto di servizi', 'noleggi_affitti' => 'Noleggi e affitti', 'oneri_promozionali' => 'Oneri promozionali e di raccolta',
        'lavoro' => 'Lavoro dipendente o autonomo', 'rimborsi_volontari' => 'Rimborsi ai volontari', 'altri_oneri' => 'Altri oneri',
    ];

    /** Cosa scrive l'operatore, nell'ordine della relazione ministeriale: campo → [etichetta, suggerimento]. */
    public const CAMPI = [
        'dal' => ['Data di inizio', 'Il primo giorno della raccolta.'],
        'al' => ['Data di fine', 'L’ultimo giorno; lo stesso giorno se è durata una giornata.'],
        'occasione' => ['Ricorrenza o campagna', 'La celebrazione, ricorrenza o campagna di sensibilizzazione in occasione della quale si raccolgono i fondi.'],
        'beni_servizi' => ['Beni o servizi offerti', 'Cosa si è dato in cambio delle offerte, se qualcosa; altrimenti scrivi «nessuno».'],
        'descrizione' => ['Descrizione della raccolta', 'Che cosa si è fatto, in poche righe. Senza nomi di persone fisiche.'],
        'luogo' => ['Luogo', 'Dove si è svolta.'],
        'modalita' => ['Modalità', 'Come si sono raccolte le offerte (banchetto, cassetta, bonifico, PayPal).'],
        'finalita' => ['Finalità', 'A cosa servono i fondi raccolti.'],
        'dettaglio_costi' => ['Dettaglio dei costi', 'Spiega le voci di spesa più importanti; se non ci sono spese, scrivi «nessuna».'],
        'attivita_destinazione' => ['Attività di destinazione', 'L’attività di interesse generale (art. 5 CTS) a cui vanno i fondi.'],
        'beni_modico_numero' => ['Numero di beni di modico valore ceduti', 'Quanti ne hai ceduti; scrivi 0 se nessuno.'],
        'beni_modico_prezzo' => ['Prezzo dei beni di modico valore', 'Il prezzo di ciascuno, in euro; 0 se nessuno.'],
        'elargizioni_persone_fisiche' => ['Elargizioni di persone fisiche', 'Quanto è arrivato da persone fisiche, in euro: il solo totale, mai i nomi.'],
        'elargizioni_persone_giuridiche' => ['Elargizioni di persone giuridiche', 'Quanto è arrivato da enti e aziende, in euro.'],
    ];

    /** La motivazione serve se il risultato è negativo o le spese superano il 90% delle entrate (soglia indicativa: la norma dice «superiori o prossime ai ricavi»). */
    public const SOGLIA_SPESE_PERCENTO = 90;

    /**
     * @param  list<array{lato: string, riga: ?string, importo: int, conto: string}>  $righe  le imputazioni della raccolta: `riga` è quella dello schema (null: la tabella non la conosce), `conto` «cassa» o «depositi», `data` (facoltativa) quella del movimento, «Y-m-d»
     * @param  list<array{descrizione: string, quantita: int, valore: int, criterio: string, documento: string}>  $liberalita  le liberalità non monetarie, a mano; `valore` è il valore stimato totale
     * @param  array<string, mixed>  $dati  i campi di `CAMPI` più `motivazione`
     */
    public static function calcola(array $righe, array $liberalita, array $dati): array
    {
        $proventi = array_fill_keys(array_keys(self::PROVENTI), 0);
        $oneri = array_fill_keys(array_keys(self::ONERI), 0);
        // Se l'operatore non ha scritto nessuna delle due date, sono quelle dei movimenti della raccolta; con una sola scritta l'altra resta da completare (derivata, potrebbe invertire il periodo).
        // shortcut: l'attività di destinazione non si ricava (le imputazioni della raccolta portano tutte l'attività di raccolta, nessun dato dice dove vanno i fondi): la sceglie l'operatore.
        $date = array_filter(array_column($righe, 'data'));
        if ($date !== [] && blank($dati['dal'] ?? null) && blank($dati['al'] ?? null)) {
            $dati['dal'] = min($date);
            $dati['al'] = max($date);
        }
        $senzaRiga = ['entrate' => ['numero' => 0, 'importo' => 0], 'uscite' => ['numero' => 0, 'importo' => 0]];
        $denaro = ['contanti' => 0, 'conti' => 0];

        foreach ($righe as $r) {
            $conosciuta = $r['riga'] !== null && array_key_exists($r['riga'], $r['lato'] === 'entrate' ? $proventi : $oneri);
            if (! $conosciuta) {
                $senzaRiga[$r['lato']]['numero']++;
                $senzaRiga[$r['lato']]['importo'] += $r['importo'];

                continue;
            }
            if ($r['lato'] === 'entrate') {
                $proventi[$r['riga']] += $r['importo'];
                $denaro[$r['conto'] === 'cassa' ? 'contanti' : 'conti'] += $r['importo'];
            } else {
                $oneri[$r['riga']] += $r['importo'];
            }
        }

        $nonMonetarie = array_sum(array_column($liberalita, 'valore'));
        $denaroEntrato = $proventi['liberalita_monetarie'] + $proventi['altri_proventi'];
        $proventi = ['liberalita_monetarie' => $proventi['liberalita_monetarie'], 'liberalita_non_monetarie' => $nonMonetarie, 'altri_proventi' => $proventi['altri_proventi'], 'totale' => $denaroEntrato + $nonMonetarie];
        $totaleOneri = array_sum($oneri);
        $risultato = $proventi['totale'] - $totaleOneri;
        // 100·oneri > 90·proventi, in interi: nessun arrotondamento a favore di chi deve motivare.
        $motivo = $risultato < 0 ? 'negativo' : (100 * $totaleOneri > self::SOGLIA_SPESE_PERCENTO * $proventi['totale'] ? 'oltre_90' : null);
        $motivazione = trim((string) ($dati['motivazione'] ?? ''));

        return [
            'proventi' => $proventi,
            'oneri' => ['righe' => $oneri, 'totale' => $totaleOneri],
            'risultato' => $risultato,
            'senzaRiga' => $senzaRiga,
            'monetari' => ['entrate' => $denaroEntrato, 'uscite' => $totaleOneri],
            'liberalita' => $liberalita,
            'motivazione' => ['richiesta' => $motivo !== null, 'motivo' => $motivo, 'presente' => $motivazione !== ''],
            'relazione' => [
                'denaro' => $denaro + ['totale' => $denaro['contanti'] + $denaro['conti']],
                'valore_beni' => $nonMonetarie,
                'campi' => array_intersect_key($dati, self::CAMPI + ['motivazione' => 1, 'attivita_destinazione_id' => 1]),
                'guida' => self::guida($dati, $motivo !== null),
            ],
        ];
    }

    /** @return list<array{campo: string, etichetta: string, suggerimento: string, fatto: bool}> */
    private static function guida(array $dati, bool $conMotivazione): array
    {
        $guida = [];
        foreach (self::CAMPI as $campo => [$etichetta, $suggerimento]) {
            $valore = $dati[$campo] ?? null;
            $guida[] = ['campo' => $campo, 'etichetta' => $etichetta, 'suggerimento' => $suggerimento, 'fatto' => is_string($valore) ? trim($valore) !== '' : $valore !== null];
        }
        if ($conMotivazione) {
            $guida[] = ['campo' => 'motivazione', 'etichetta' => 'Motivazione', 'suggerimento' => 'Il risultato è negativo o le spese superano il 90% delle entrate: spiega perché.', 'fatto' => trim((string) ($dati['motivazione'] ?? '')) !== ''];
        }

        return $guida;
    }

    /**
     * La somma delle raccolte contro la voce C2 del Modello D, in entrata e in uscita (solo il denaro: le liberalità non monetarie non sono nel Modello D). La differenza viene
     * da imputazioni senza riga dello schema o senza iniziativa, che nessuna raccolta conta: il messaggio le dice.
     *
     * @param  list<array>  $raccolte  i risultati di `calcola`
     * @param  array{entrate: int, uscite: int}  $c2  la voce C2 del Modello D
     * @param  array{entrate: int, uscite: int}  $senzaIniziativa  imputazioni C2 che nessuna raccolta può contare
     * @return array{entrate: array, uscite: array, quadra: bool, messaggi: list<string>}
     */
    public static function quadratura(array $raccolte, array $c2, array $senzaIniziativa): array
    {
        $q = ['messaggi' => []];
        foreach (['entrate' => 'Entrate', 'uscite' => 'Uscite'] as $lato => $nome) {
            $somma = array_sum(array_map(fn (array $r) => $r['monetari'][$lato], $raccolte));
            $senzaRiga = array_sum(array_map(fn (array $r) => $r['senzaRiga'][$lato]['importo'], $raccolte));
            $q[$lato] = ['raccolte' => $somma, 'c2' => $c2[$lato], 'differenza' => $c2[$lato] - $somma, 'senzaRiga' => $senzaRiga, 'senzaIniziativa' => $senzaIniziativa[$lato]];
            if ($q[$lato]['differenza'] !== 0) {
                $q['messaggi'][] = $nome.': le raccolte sommano '.Euro::formatta($somma).' € e la voce C2 '.Euro::formatta($c2[$lato]).' €; '
                    .Euro::formatta($senzaRiga).' € senza riga dello schema e '.Euro::formatta($senzaIniziativa[$lato]).' € senza iniziativa non sono in nessuna raccolta.';
            }
        }
        $q['quadra'] = $q['messaggi'] === [];

        return $q;
    }

    /**
     * Cosa impedisce il PDF di deposito: la quadratura con C2 e le motivazioni mancanti.
     *
     * @param  list<array{nome: string}>  $raccolte  i risultati di `calcola` con il `nome` dell'iniziativa
     * @return list<string>
     */
    public static function bloccanti(array $raccolte, array $quadratura): array
    {
        $bloccanti = $quadratura['messaggi'];
        foreach ($raccolte as $r) {
            if ($r['motivazione']['richiesta'] && ! $r['motivazione']['presente']) {
                $bloccanti[] = '«'.$r['nome'].'»: '.($r['motivazione']['motivo'] === 'negativo' ? 'il risultato è negativo' : 'le spese superano il '.self::SOGLIA_SPESE_PERCENTO.'% delle entrate').': serve la motivazione.';
            }
        }

        return $bloccanti;
    }
}
