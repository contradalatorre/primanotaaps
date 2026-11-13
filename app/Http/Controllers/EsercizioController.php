<?php

namespace App\Http\Controllers;

use App\Esercizio\Apertura;
use App\Esercizio\Prospetto;
use App\Models\EsercizioStorico;
use App\Models\Movimento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Il Modello D a schermo (#51): sempre «provvisorio», calcolato dal vivo, con ogni cifra che porta alle sue imputazioni.
 * Lo vede chi ha `vedere`; aprire un esercizio nuovo è di chi può modificare la tabella delle voci.
 */
class EsercizioController extends Controller
{
    public function indice(): RedirectResponse
    {
        return redirect()->route('modello-d', today()->year);
    }

    public function modelloD(Request $request, int $anno)
    {
        $proposte = $request->boolean('proposte');
        // Un esercizio storico (#63) non ha movimenti: il suo Modello D è ricalcolato dagli importi caricati dal foglio.
        $proprio = EsercizioStorico::where('esercizio', $anno)->first();
        $precedente = EsercizioStorico::modelloD($anno - 1);

        return view('esercizio.modello-d', [
            'anno' => $anno,
            'proposte' => $proposte,
            'storico' => $proprio !== null,
            'm' => $proprio?->prospetto() ?? Prospetto::modelloD($anno, $proposte),
            'precedente' => $precedente,
            // Si può caricare l'esercizio precedente dal foglio solo se nell'app non ha movimenti (contarebbe due volte).
            'storicoCaricabile' => $precedente === null && ! Movimento::whereBetween('data', [($anno - 1).'-01-01', ($anno - 1).'-12-31'])->exists(),
            'impedimenti' => $proprio ? [] : Prospetto::impedimenti($anno),
            'aggiornatoAl' => now(),
            'vociCaricate' => DB::table('voci_modello_d')->where('esercizio', $anno)->exists(),
            'sorgenteApertura' => Apertura::sorgente($anno),
        ]);
    }

    /** Le imputazioni dietro una cifra: `chiavi` sono le righe del Modello D che la compongono («entrate:A1,uscite:A2»). */
    public function imputazioni(Request $request, int $anno)
    {
        $proposte = $request->boolean('proposte');
        $chiavi = array_values(array_filter(explode(',', $request->string('chiavi')->toString())));

        return view('esercizio.imputazioni', [
            'anno' => $anno,
            'proposte' => $proposte,
            'titolo' => $request->string('titolo')->limit(120)->toString(),
            'elenco' => Prospetto::elenco($anno, $proposte, $chiavi),
        ]);
    }

    public function apri(Request $request, int $anno): RedirectResponse
    {
        Apertura::apri($request->user(), $anno);

        return redirect()->route('modello-d', $anno)->with('ok', "Esercizio $anno aperto: voci e predefiniti delle iniziative copiati dall'esercizio precedente.");
    }
}
