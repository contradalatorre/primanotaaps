<?php

namespace App\Http\Controllers;

use App\Esercizio\Approvazione;
use App\Esercizio\Prospetto;
use App\Esercizio\Verifiche;
use App\Models\DatiEsercizio;
use App\Models\EsercizioStorico;
use App\Models\VersioneEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Approvazione e riapertura dell'esercizio (#59). La pagina la vede chi ha `vedere`; approvare è di `gestire_esercizio`, riaprire di `riaprire_esercizio`
 * (l'Amministratore), entrambe azioni delicate: dietro `password.confirm:riautentica` (#36). L'elenco di controllo informa e non blocca.
 */
class ApprovazioneController extends Controller
{
    public function pagina(int $anno)
    {
        $dati = DatiEsercizio::per($anno);

        return view('esercizio.approvazione', [
            'anno' => $anno,
            'dati' => $dati,
            'storico' => EsercizioStorico::where('esercizio', $anno)->exists(),
            'controllo' => Prospetto::impedimenti($anno),
            // La chiusura per riga del Modello D (cassa, depositi): sono i saldi iniziali dell'esercizio dopo, che il Modello D calcola dai conti.
            'chiusura' => collect(Prospetto::saldi($anno))->groupBy('riga')->map(fn ($saldi) => $saldi->sum('finale'))->sortKeys()->all(),
            's' => Verifiche::secondarieta($anno),
            'versioni' => VersioneEsercizio::where('esercizio', $anno)->orderByDesc('numero')->get(),
        ]);
    }

    public function approva(Request $request, int $anno): RedirectResponse
    {
        $campi = $request->validate(['data' => ['required', 'date_format:Y-m-d']]);
        $versione = Approvazione::approva($request->user(), $anno, $campi['data']);

        return redirect()->route('esercizio.approvazione', $anno)->with('ok', "Esercizio $anno approvato il {$versione->approvata_il->format('d/m/Y')}: versione {$versione->numero} salvata.");
    }

    public function riapri(Request $request, int $anno): RedirectResponse
    {
        $campi = $request->validate(['motivazione' => ['required', 'string', 'max:1000']]);
        Approvazione::riapri($request->user(), $anno, trim($campi['motivazione']));

        return redirect()->route('esercizio.approvazione', $anno)->with('ok', "Esercizio $anno riaperto: la versione approvata resta nello storico.");
    }

    public function segnala(Request $request, int $anno): RedirectResponse
    {
        $campi = $request->validate(['data' => ['required', 'date_format:Y-m-d'], 'protocollo' => ['nullable', 'string', 'max:100']]);
        Approvazione::segnala($request->user(), $anno, $campi['data'], filled($campi['protocollo'] ?? null) ? trim($campi['protocollo']) : null);

        return redirect()->back(fallback: route('esercizio.approvazione', $anno))->with('ok', 'Segnalazione al RUNTS registrata.');
    }
}
