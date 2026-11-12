<?php

namespace App\Http\Controllers;

use App\Esercizio\Apertura;
use App\Esercizio\Prospetto;
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

        return view('esercizio.modello-d', [
            'anno' => $anno,
            'proposte' => $proposte,
            'm' => Prospetto::modelloD($anno, $proposte),
            'impedimenti' => Prospetto::impedimenti($anno),
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
