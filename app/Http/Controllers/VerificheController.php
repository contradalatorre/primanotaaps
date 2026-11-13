<?php

namespace App\Http\Controllers;

use App\Esercizio\Annotazione;
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
        $test = Verifiche::pagina($anno, $proposte);
        $dati = $test['s']['dati'];
        $proposta = Annotazione::testo($anno, $test['s'], $dati->criterio_riparto);

        return view('esercizio.test', [
            'anno' => $anno,
            'proposte' => $proposte,
            's' => $test['s'],
            'catena' => $test['catena'],
            'annotazione' => ['proposta' => $proposta, 'salvata' => $dati->annotazione_in_calce, 'testo' => $dati->annotazione_in_calce ?? $proposta],
            'inAttesa' => Verifiche::inAttesa($anno),
            'aggiornatoAl' => now(),
        ]);
    }

    /**
     * L'annotazione in calce (#54): il testo che il Tesoriere scrive al posto di quello precompilato dagli esiti. Il testo uguale al proposto
     * (o vuoto, o «ripristina») non si fissa: l'annotazione segue i dati finché nessuno la corregge. I ritorni a capo del browser sono CRLF.
     */
    public function annotazione(Request $request, int $anno): RedirectResponse
    {
        $campi = $request->validate(['testo' => ['nullable', 'string', 'max:5000'], 'ripristina' => ['nullable', 'boolean']]);
        $testo = $request->boolean('ripristina') ? '' : trim(str_replace(["\r\n", "\r"], "\n", $campi['testo'] ?? ''));

        $proposte = $request->boolean('proposte'); // l'interruttore della pagina da cui si salva: il testo proposto era calcolato con quella scelta

        DatiEsercizio::sottoBlocco($request->user(), $anno, function (DatiEsercizio $dati) use ($request, $anno, $testo, $proposte) {
            $prima = $dati->istantanea();
            // La proposta si calcola a riga bloccata, con i dati appena riletti.
            $proposta = Annotazione::testo($anno, Verifiche::secondarieta($anno, $proposte), $dati->criterio_riparto);
            $dati->update(['annotazione_in_calce' => $testo === '' || $testo === $proposta ? null : $testo]);
            if ($dati->istantanea() !== $prima) {
                RegistroModifica::registra($request->user(), $dati, 'modificato', $prima, $dati->istantanea());
            }
        });

        return redirect()->route('test-esercizio', ['anno' => $anno] + ($proposte ? ['proposte' => 1] : []))->with('ok', 'Annotazione in calce salvata.');
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
