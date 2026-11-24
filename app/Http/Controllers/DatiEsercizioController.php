<?php

namespace App\Http\Controllers;

use App\Esercizio\SchemaModelloD;
use App\Euro;
use App\Models\DatiEsercizio;
use App\Models\DestinazioneAvanzo;
use App\Models\RegistroModifica;
use App\Models\RigaFigurativa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * I dati dell'esercizio (#53, storia 113): dipendenti, attivo, criterio di riparto dei costi promiscui, e i costi e proventi
 * figurativi. Li scrive e li legge chi ha `gestire_esercizio`: le descrizioni dei figurativi possono nominare persone e non
 * compaiono nel Modello D (storia 143), solo qui. Ogni scrittura si fa a riga dei dati bloccata (`DatiEsercizio::sottoBlocco`).
 */
class DatiEsercizioController extends Controller
{
    /** Il PDF di deposito e l'istantanea portano costi figurativi, destinazione dell'avanzo e criterio di riparto: dopo il deposito non si scrivono più (#60), come l'annotazione in calce. */
    private static function nonDepositato(DatiEsercizio $dati): void
    {
        if ($dati->stato === 'depositato') {
            throw ValidationException::withMessages(['stato' => "L'esercizio {$dati->esercizio} è depositato: i suoi dati non si cambiano più. Per correggere un errore l'Amministratore lo riapre."]);
        }
    }

    public function indice(int $anno)
    {
        return view('esercizio.dati', [
            'anno' => $anno,
            'dati' => DatiEsercizio::per($anno),
            'figurativi' => RigaFigurativa::where('esercizio', $anno)->orderBy('id')->get(),
            'destinazioni' => DestinazioneAvanzo::where('esercizio', $anno)->orderBy('id')->get(),
            'areeFigurative' => SchemaModelloD::figurativi(),
        ]);
    }

    public function salva(Request $request, int $anno): RedirectResponse
    {
        $campi = $request->validate([
            'dipendenti_medi' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'attivo' => ['nullable', 'string', 'max:30'],
            'criterio_riparto' => ['required', Rule::in(array_keys(DatiEsercizio::CRITERI_RIPARTO))],
        ]);
        $attivo = filled($campi['attivo'] ?? null) ? Euro::daTesto($campi['attivo']) : null;
        if (filled($campi['attivo'] ?? null) && ($attivo === null || $attivo < 0)) {
            throw ValidationException::withMessages(['attivo' => 'Scrivi il totale dell’attivo in euro, per esempio 12.345,67.']);
        }

        DatiEsercizio::sottoBlocco($request->user(), $anno, function (DatiEsercizio $dati) use ($request, $campi, $attivo) {
            self::nonDepositato($dati);
            $prima = $dati->istantanea(); // dipendenti_medi: una media con decimali sale, mai a favore
            $dati->fill([
                'dipendenti_medi' => isset($campi['dipendenti_medi']) ? (int) ceil($campi['dipendenti_medi']) : null, 'attivo' => $attivo, 'criterio_riparto' => $campi['criterio_riparto'],
                'figurativi_dichiarati' => $request->boolean('figurativi_dichiarati'),
            ])->save();
            if ($dati->istantanea() !== $prima) {
                RegistroModifica::registra($request->user(), $dati, 'modificato', $prima, $dati->istantanea());
            }
        });

        return redirect()->route('dati-esercizio', $anno)->with('ok', 'Dati dell’esercizio '.$anno.' salvati.');
    }

    /**
     * Un costo figurativo è solo «ore rilevate × retribuzione oraria del CCNL» (#17: nessuna stima forfettaria): l'importo lo calcola
     * l'app e ore e tariffa restano scritte nella descrizione. Un provento è il valore normale documentato di una prestazione gratuita.
     */
    public function aggiungiFigurativo(Request $request, int $anno): RedirectResponse
    {
        $costo = $request->input('tipo') === 'costo';
        $campi = $request->validate([
            'tipo' => ['required', Rule::in(['costo', 'provento'])],
            'area' => ['required', Rule::in(array_keys(SchemaModelloD::figurativi()))],
            'descrizione' => ['required', 'string', 'max:200'],
            'ore' => [$costo ? 'required' : 'nullable', 'string', 'max:12'],
            'tariffa' => [$costo ? 'required' : 'nullable', 'string', 'max:12'],
            'importo' => [$costo ? 'nullable' : 'required', 'string', 'max:30'],
        ]);
        $ore = $costo ? Euro::daTesto($campi['ore']) : null; // centesimi di ora: stesso formato di un importo, due decimali
        $tariffa = $costo ? Euro::daTesto($campi['tariffa']) : null;
        if ($costo && ($ore === null || $ore <= 0)) {
            throw ValidationException::withMessages(['ore' => 'Scrivi le ore rilevate, per esempio 40 o 12,5.']);
        }
        if ($costo && ($tariffa === null || $tariffa <= 0)) {
            throw ValidationException::withMessages(['tariffa' => 'Scrivi la retribuzione oraria del CCNL in euro, per esempio 12,50.']);
        }
        // Limiti ragionevoli (anche perché il prodotto stia in un intero): 100.000 ore, 1.000 €/h.
        if ($costo && $ore > 10_000_000) {
            throw ValidationException::withMessages(['ore' => 'Le ore rilevate non possono superare 100.000.']);
        }
        if ($costo && $tariffa > 100_000) {
            throw ValidationException::withMessages(['tariffa' => 'La retribuzione oraria non può superare 1.000 € l’ora.']);
        }
        // Per difetto: un costo figurativo in più andrebbe a favore dell'ente (secondarietà, art. 79 c. 2-bis).
        $importo = $costo ? intdiv($ore * $tariffa, 100) : Euro::daTesto($campi['importo']);
        if ($importo === null || $importo <= 0) {
            throw ValidationException::withMessages([$costo ? 'ore' : 'importo' => 'Scrivi un importo in euro maggiore di zero, per esempio 1.234,56.']);
        }
        $descrizione = trim($campi['descrizione']).($costo ? ' — '.Euro::formatta($ore).' ore × '.Euro::formatta($tariffa).' €/h' : '');

        DatiEsercizio::sottoBlocco($request->user(), $anno, function (DatiEsercizio $dati) use ($request, $anno, $campi, $importo, $descrizione) {
            self::nonDepositato($dati);
            $riga = RigaFigurativa::create(['esercizio' => $anno, 'tipo' => $campi['tipo'], 'area' => $campi['area'], 'descrizione' => $descrizione, 'importo' => $importo]);
            RegistroModifica::registra($request->user(), $riga, 'creata', null, $riga->istantanea());
        });

        return redirect()->route('dati-esercizio', $anno)->with('ok', 'Riga figurativa aggiunta.');
    }

    /**
     * La destinazione dell'avanzo (#56): righe con descrizione, importo e nota, proposte all'assemblea. Compaiono solo nel rendiconto per
     * l'assemblea, che non porta nomi di persone (storia 143): la descrizione e la nota non li devono contenere. Un importo oltre l'avanzo non si blocca: il residuo esce negativo.
     */
    public function aggiungiDestinazione(Request $request, int $anno): RedirectResponse
    {
        $campi = $request->validate([
            'descrizione' => ['required', 'string', 'max:200'],
            'importo' => ['required', 'string', 'max:30'],
            'nota' => ['nullable', 'string', 'max:200'],
        ]);
        $importo = Euro::daTesto($campi['importo']);
        if ($importo === null || $importo <= 0) {
            throw ValidationException::withMessages(['importo' => 'Scrivi un importo in euro maggiore di zero, per esempio 1.234,56.']);
        }

        DatiEsercizio::sottoBlocco($request->user(), $anno, function (DatiEsercizio $dati) use ($request, $anno, $campi, $importo) {
            self::nonDepositato($dati);
            $riga = DestinazioneAvanzo::create(['esercizio' => $anno, 'descrizione' => trim($campi['descrizione']), 'importo' => $importo, 'nota' => filled($campi['nota'] ?? null) ? trim($campi['nota']) : null]);
            RegistroModifica::registra($request->user(), $riga, 'creata', null, $riga->istantanea());
        });

        return redirect()->to(route('dati-esercizio', $anno).'#destinazione')->with('ok', 'Destinazione dell’avanzo aggiunta.');
    }

    public function eliminaDestinazione(Request $request, int $anno, int $riga): RedirectResponse
    {
        DatiEsercizio::sottoBlocco($request->user(), $anno, function (DatiEsercizio $dati) use ($request, $anno, $riga) {
            self::nonDepositato($dati);
            // Letta a blocco preso: una riga già eliminata da un'altra richiesta è un 404, non un secondo registro.
            $riga = DestinazioneAvanzo::where('esercizio', $anno)->findOrFail($riga);
            $prima = $riga->istantanea();
            $riga->delete();
            RegistroModifica::registra($request->user(), $riga, 'eliminata', $prima, null);
        });

        return redirect()->to(route('dati-esercizio', $anno).'#destinazione')->with('ok', 'Destinazione dell’avanzo eliminata.');
    }

    public function eliminaFigurativo(Request $request, int $anno, int $riga): RedirectResponse
    {
        DatiEsercizio::sottoBlocco($request->user(), $anno, function (DatiEsercizio $dati) use ($request, $anno, $riga) {
            self::nonDepositato($dati);
            // Letta a blocco preso: una riga già eliminata da un'altra richiesta è un 404, non un secondo registro.
            $riga = RigaFigurativa::where('esercizio', $anno)->findOrFail($riga);
            $prima = $riga->istantanea();
            $riga->delete();
            RegistroModifica::registra($request->user(), $riga, 'eliminata', $prima, null);
        });

        return redirect()->route('dati-esercizio', $anno)->with('ok', 'Riga figurativa eliminata.');
    }
}
