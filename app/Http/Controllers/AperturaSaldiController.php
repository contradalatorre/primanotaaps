<?php

namespace App\Http\Controllers;

use App\Esercizio\SaldiApertura;
use App\Euro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * La procedura di apertura (#63): i saldi dei conti al 1/1, una sola volta. Li inserisce chi gestisce i dati dell'esercizio (permesso riletto a riga bloccata).
 */
class AperturaSaldiController extends Controller
{
    public function indice(int $anno)
    {
        $conti = SaldiApertura::conti($anno);
        $chiusura = SaldiApertura::chiusura($anno);

        return view('esercizio.apertura', [
            'anno' => $anno, 'conti' => $conti, 'chiusura' => $chiusura, 'fatta' => SaldiApertura::fatta($anno),
            'precompilati' => SaldiApertura::precompilati($conti, $chiusura),
            'riscontri' => $conti->mapWithKeys(fn ($c) => [$c->id => SaldiApertura::riscontri($c, $anno)])->all(),
        ]);
    }

    public function inserisci(Request $request, int $anno): RedirectResponse
    {
        $campi = (array) $request->input('saldi', []);
        $saldi = [];
        foreach ($campi as $id => $valore) {
            $centesimi = is_scalar($valore) && strlen((string) $valore) <= 30 ? Euro::daTesto((string) $valore) : null;
            if (! ctype_digit((string) $id) || $centesimi === null) {
                throw ValidationException::withMessages(['saldi.'.$id => 'Scrivi il saldo in euro, per esempio 1.234,56.']);
            }
            $saldi[(int) $id] = $centesimi;
        }
        if ($saldi === []) {
            throw ValidationException::withMessages(['saldi' => 'Scrivi i saldi di tutti i conti.']);
        }

        SaldiApertura::inserisci($request->user(), $anno, $saldi);

        return redirect()->route('esercizio.saldi', $anno)->with('ok', "Saldi di apertura del $anno inseriti.");
    }
}
