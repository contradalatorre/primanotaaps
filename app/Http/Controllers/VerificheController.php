<?php

namespace App\Http\Controllers;

use App\Esercizio\Secondarieta;
use App\Esercizio\Verifiche;
use App\Models\DatiEsercizio;
use App\Models\RegistroModifica;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La pagina «Test dell'esercizio» (#53): sempre «provvisoria», calcolata dal vivo, con la spiegazione su tre livelli. La vede
 * chi ha `vedere`; confermare il criterio di secondarietà è di chi gestisce i dati dell'esercizio.
 */
class VerificheController extends Controller
{
    public function indice(): RedirectResponse
    {
        return redirect()->route('test-esercizio', today()->year);
    }

    public function mostra(Request $request, int $anno)
    {
        $proposte = $request->boolean('proposte');

        return view('esercizio.test', [
            'anno' => $anno,
            'proposte' => $proposte,
            's' => Verifiche::secondarieta($anno, $proposte),
            'inAttesa' => Verifiche::inAttesa($anno),
            'aggiornatoAl' => now(),
        ]);
    }

    public function confermaCriterio(Request $request, int $anno): RedirectResponse
    {
        $criterio = $request->validate(['criterio' => ['required', Rule::in(['a', 'b'])]])['criterio'];

        DatiEsercizio::sottoBlocco($request->user(), $anno, function (DatiEsercizio $dati) use ($request, $criterio) {
            $prima = $dati->istantanea();
            $dati->update(['criterio_secondarieta' => $criterio]);
            if ($dati->istantanea() !== $prima) {
                RegistroModifica::registra($request->user(), $dati, 'modificato', $prima, $dati->istantanea());
            }
        });

        return redirect()->route('test-esercizio', $anno)->with('ok', 'Criterio di secondarietà confermato: il '.Secondarieta::SOGLIE[$criterio].'% (criterio '.$criterio.').');
    }
}
