<?php

namespace App\Http\Controllers;

use App\Esercizio\Apertura;
use App\Esercizio\Deposito;
use App\Esercizio\Prospetto;
use App\Models\EsercizioStorico;
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
        // Un esercizio depositato (#60) si legge solo dall'istantanea, identica al PDF: né proposte né dati di oggi.
        $salvata = Deposito::salvata($anno);
        $proposte = $salvata === null && $request->boolean('proposte');
        // Un esercizio storico (#63) non ha movimenti: il suo Modello D è ricalcolato dagli importi caricati dal foglio.
        $proprio = EsercizioStorico::where('esercizio', $anno)->first();
        $precedente = $salvata ? $salvata['precedente'] : EsercizioStorico::modelloD($anno - 1);

        return view('esercizio.modello-d', [
            'anno' => $anno,
            'proposte' => $proposte,
            'depositato' => $salvata !== null,
            'storico' => $proprio !== null,
            'm' => $salvata ? $salvata['prospetti']['modello_d'] : ($proprio?->prospetto() ?? Prospetto::modelloD($anno, $proposte)),
            'precedente' => $precedente,
            // Si può caricare l'esercizio precedente dal foglio solo se è prima del primo esercizio con movimenti (altrimenti si conterebbe due volte).
            'storicoCaricabile' => $precedente === null && EsercizioStorico::caricabile($anno - 1),
            'impedimenti' => $proprio || $salvata ? [] : Prospetto::impedimenti($anno),
            'aggiornatoAl' => now(),
            'vociCaricate' => DB::table('voci_modello_d')->where('esercizio', $anno)->exists(),
            'sorgenteApertura' => Apertura::sorgente($anno),
        ]);
    }

    /**
     * Il rendiconto per l'assemblea a schermo (#56), dal vivo come il Modello D; `per=voce` lo raggruppa per voce Modello D → tipologia.
     * Un esercizio storico (#63) non ha movimenti né rendiconto: solo il Modello D dai suoi importi.
     */
    public function rendiconto(Request $request, int $anno)
    {
        $storico = EsercizioStorico::where('esercizio', $anno)->exists();
        $salvata = Deposito::salvata($anno); // depositato: solo l'istantanea (#60)
        $proposte = $salvata === null && $request->boolean('proposte');

        return view('esercizio.rendiconto', [
            'anno' => $anno,
            'proposte' => $proposte,
            'depositato' => $salvata !== null,
            'perVoce' => $request->query('per') === 'voce',
            'storico' => $storico,
            'r' => $storico ? null : ($salvata ? $salvata['prospetti']['rendiconto'] : Prospetto::rendiconto($anno, $proposte)),
            'aggiornatoAl' => now(),
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
