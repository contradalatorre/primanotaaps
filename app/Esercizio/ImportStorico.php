<?php

namespace App\Esercizio;

use App\Import\EsempiDalFoglio;
use App\Import\FoglioNonValido;
use App\Models\ConversioneCausale;
use App\Models\DatiEsercizio;
use App\Models\EsempioClassificazione;
use App\Models\EsercizioStorico;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Permesso;
use Illuminate\Validation\ValidationException;

/**
 * Il caricamento dell'esercizio storico dal foglio approvato (#63, #18): dal foglio `RUNTS_Rendiconto` le voci riconosciute e i saldi finali,
 * l'abbinamento manuale di ciò che non si riconosce, il ricalcolo di totali e avanzi (`Calcolo`, lo stesso di un esercizio vero) con le
 * differenze rispetto al foglio, e la conferma, che crea l'esercizio storico bloccato. Il file e la sua anteprima non toccano il database.
 */
final class ImportStorico
{
    public const FOGLIO = 'RUNTS_Rendiconto';

    /** Come si chiamano nella pagina i totali e gli avanzi che il foglio scrive e l'app ricalcola. */
    private const ETICHETTE = [
        'gestione:uscite' => 'Totale uscite della gestione', 'gestione:entrate' => 'Totale entrate della gestione',
        'avanzo_prima_imposte' => 'Avanzo/disavanzo d’esercizio prima delle imposte', 'avanzo_prima_investimenti' => 'Avanzo/disavanzo prima degli investimenti e disinvestimenti',
        'investimenti:uscite' => 'Totale uscite da investimenti', 'investimenti:entrate' => 'Totale entrate da disinvestimenti',
        'avanzo_investimenti' => 'Avanzo/disavanzo da investimenti e disinvestimenti', 'avanzo_complessivo' => 'Avanzo/disavanzo complessivo',
    ];

    /**
     * @param  array<string, list<list<mixed>>>  $fogli  quelli di `Foglio::leggi`
     * @return array{voci: array<string, int>, nonRiconosciute: list<array{riga: int, etichetta: string, importo: int}>, saldi: array{cassa: ?int, depositi: ?int}, totali: array<string, int>, esempi: list<array<string, mixed>>, scartate: array<string, int>, schede: list<string>}
     *
     * @throws FoglioNonValido
     */
    public static function anteprima(array $fogli, int $anno): array
    {
        $rendiconto = RendicontoFoglio::leggi($fogli[self::FOGLIO] ?? throw new FoglioNonValido('Nel file non c’è il foglio «'.self::FOGLIO.'».'));

        // Dalle schede dei conti, nello stesso file, gli esempi di classificazione: non sono movimenti e non entrano in nessun rendiconto.
        return $rendiconto + EsempiDalFoglio::leggi($fogli, $anno, ConversioneCausale::perChiave());
    }

    /**
     * Le voci riconosciute più quelle che la persona ha abbinato a mano (riga del foglio → «lato:codice»; vuoto: la riga si ignora). Un abbinamento a
     * una voce che non esiste, o a una riga che non c'è, è un errore: arriva da un modulo che si può falsificare.
     *
     * @param  array<int|string, mixed>  $abbinamenti
     * @return array<string, int> «lato:codice» → centesimi
     */
    public static function importi(array $anteprima, array $abbinamenti): array
    {
        $importi = $anteprima['voci'];
        $righe = array_column($anteprima['nonRiconosciute'], null, 'riga');
        foreach ($abbinamenti as $riga => $voce) {
            if ($voce === null || $voce === '') {
                continue;
            }
            if (! isset($righe[$riga]) || ! self::voceValida((string) $voce)) {
                throw ValidationException::withMessages(['abbinamenti' => 'L’abbinamento della riga '.$riga.' del foglio non è valido.']);
            }
            $importi[$voce] = ($importi[$voce] ?? 0) + $righe[$riga]['importo'];
        }

        return $importi;
    }

    public static function nonCaricabile(int $anno): string
    {
        return "Il $anno non può essere un esercizio storico: si carica solo un anno precedente al primo esercizio con movimenti nell'app.";
    }

    /** «uscite:A1», «entrate:I4», «uscite:Imposte»: una riga dello schema. */
    public static function voceValida(string $chiave): bool
    {
        $parti = explode(':', $chiave);

        return count($parti) === 2 && SchemaModelloD::conosce($parti[0], $parti[1]);
    }

    /** @return array<string, string> «lato:codice» → etichetta, per i moduli: tutte le righe dello schema */
    public static function voci(): array
    {
        $voci = [];
        foreach (['uscite', 'entrate'] as $lato) {
            foreach ([...array_values(SchemaModelloD::voci($lato)), SchemaModelloD::investimenti($lato)] as $righe) {
                foreach ($righe as $codice => $etichetta) {
                    $voci["$lato:$codice"] = "$codice) $etichetta ($lato)";
                }
            }
        }
        $voci['uscite:Imposte'] = 'Imposte (uscite)';

        return $voci;
    }

    /**
     * I totali e gli avanzi che il foglio scrive e l'app ricalcola diversi.
     *
     * @param  array<string, int>  $totaliFoglio  quelli di `RendicontoFoglio`
     * @return list<array{chiave: string, etichetta: string, foglio: int, app: int}>
     */
    public static function differenze(array $totaliFoglio, array $modello): array
    {
        $differenze = [];
        foreach ($totaliFoglio as $chiave => $foglio) {
            $app = self::valoreApp($chiave, $modello);
            if ($app !== null && $app !== $foglio) {
                $differenze[] = ['chiave' => $chiave, 'etichetta' => self::ETICHETTE[$chiave] ?? self::etichettaDiArea($chiave), 'foglio' => $foglio, 'app' => $app];
            }
        }

        return $differenze;
    }

    private static function valoreApp(string $chiave, array $m): ?int
    {
        return match (true) {
            preg_match('/^(uscite|entrate):([A-E])$/', $chiave, $p) === 1 => $m['aree'][$p[2]][$p[1]]['totale']['importo'],
            preg_match('/^avanzo:([A-E])$/', $chiave, $p) === 1 => $m['aree'][$p[1]]['avanzo']['importo'],
            default => match ($chiave) {
                'gestione:uscite' => $m['gestione']['uscite']['importo'],
                'gestione:entrate' => $m['gestione']['entrate']['importo'],
                'avanzo_prima_imposte' => $m['gestione']['avanzo']['importo'],
                'avanzo_prima_investimenti' => $m['avanzoPrimaDegliInvestimenti']['importo'],
                'investimenti:uscite' => $m['investimenti']['uscite']['totale']['importo'],
                'investimenti:entrate' => $m['investimenti']['entrate']['totale']['importo'],
                'avanzo_investimenti' => $m['investimenti']['avanzo']['importo'],
                'avanzo_complessivo' => $m['avanzoComplessivo']['importo'],
                default => null,
            },
        };
    }

    private static function etichettaDiArea(string $chiave): string
    {
        [$che, $area] = explode(':', $chiave);

        return ($che === 'avanzo' ? 'Avanzo/disavanzo' : 'Totale '.$che).' dell’area '.$area;
    }

    /**
     * Crea l'esercizio storico. Si scrive a riga dei dati dell'esercizio bloccata (come ogni scrittura dei dati dell'esercizio) e il permesso si
     * rilegge lì. Un esercizio con movimenti nell'app non può essere storico: contarebbe due volte, e uno già storico è bloccato.
     *
     * @param  array<string, int>  $importi
     * @param  array{costi: array<string, int>, proventi: array<string, int>}  $figurativi
     * @param  list<array<string, mixed>>  $differenze
     * @param  list<array<string, mixed>>  $esempi  quelli di `anteprima`: si caricano solo qui, con l'esercizio, così non si ricaricano e non lasciano proposte orfane (#43)
     */
    public static function conferma(User $utente, int $anno, array $importi, int $cassa, int $depositi, array $figurativi, array $differenze, array $esempi = []): EsercizioStorico
    {
        return DatiEsercizio::sottoBlocco($utente, $anno, function () use ($utente, $anno, $importi, $cassa, $depositi, $figurativi, $differenze, $esempi) {
            if (EsercizioStorico::where('esercizio', $anno)->exists()) {
                throw ValidationException::withMessages(['esercizio' => "L'esercizio storico $anno è già caricato ed è bloccato: lo modifica solo un Amministratore, con motivazione."]);
            }
            if (! EsercizioStorico::caricabile($anno)) {
                throw ValidationException::withMessages(['esercizio' => self::nonCaricabile($anno)]);
            }
            $storico = EsercizioStorico::create([
                'esercizio' => $anno, 'importi' => $importi, 'saldo_cassa' => $cassa, 'saldo_depositi' => $depositi,
                'figurativi' => $figurativi, 'differenze' => $differenze, 'caricato_da' => $utente->id,
            ]);
            foreach (array_chunk($esempi, 200) as $blocco) {
                EsempioClassificazione::insert(array_map(fn (array $e) => $e + ['created_at' => now(), 'updated_at' => now()], $blocco));
            }
            RegistroModifica::registra($utente, $storico, 'caricato', null, $storico->istantanea() + ['esempi' => count($esempi)]);

            return $storico;
        });
    }

    /**
     * La modifica di un esercizio storico bloccato: solo chi può riaprire un esercizio (l'Amministratore), con motivazione, e finisce nel registro con
     * il prima e il dopo. Come la conferma, a riga bloccata e con il permesso riletto lì.
     *
     * @param  array<string, int>  $importi
     * @param  array{costi: array<string, int>, proventi: array<string, int>}  $figurativi
     */
    public static function modifica(User $utente, int $anno, array $importi, int $cassa, int $depositi, array $figurativi, string $motivazione): EsercizioStorico
    {
        return DatiEsercizio::sottoBlocco($utente, $anno, function () use ($utente, $anno, $importi, $cassa, $depositi, $figurativi, $motivazione) {
            $storico = EsercizioStorico::where('esercizio', $anno)->lockForUpdate()->firstOrFail();
            $prima = $storico->istantanea();
            $storico->update(['importi' => $importi, 'saldo_cassa' => $cassa, 'saldo_depositi' => $depositi, 'figurativi' => $figurativi]);
            $dopo = $storico->istantanea();
            // Un importo tornato a zero sparisce dall'istantanea: nel registro compare con il valore di prima e «–» dopo.
            RegistroModifica::registra($utente, $storico, 'modificato', $prima, $dopo + array_fill_keys(array_diff(array_keys($prima), array_keys($dopo)), null), $motivazione);

            return $storico;
        }, Permesso::RiaprireEsercizio);
    }
}
