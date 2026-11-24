<?php

namespace App\Http\Controllers;

use App\Forzature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Le forzature della natura commerciale (#52). Le regole stanno in `App\Forzature`; qui la forma dei dati. Forzare e togliere è
 * di chi classifica (`importare_e_confermare`, `Forzature` rilegge il permesso a riga bloccata); l'elenco lo vede chi ha `vedere`.
 */
class ForzatureController extends Controller
{
    public function indice(int $anno)
    {
        return view('esercizio.forzature', ['anno' => $anno, 'forzate' => Forzature::dell($anno)]);
    }

    public function forza(Request $request): RedirectResponse
    {
        $dati = $request->validate([
            'imputazioni' => ['required', 'array', 'min:1', 'max:200'], 'imputazioni.*' => ['integer'],
            'natura' => ['required', 'in:commerciale,non_commerciale'],
            'motivazione' => ['required', 'string', 'max:'.Forzature::MOTIVAZIONE_MASSIMO],
        ], ['imputazioni.required' => 'Scegli almeno un’imputazione.', 'motivazione.required' => 'Scrivi la motivazione: una forzatura senza motivo non si salva.']);

        Forzature::forza($request->user(), $dati['imputazioni'], $dati['natura'] === 'commerciale', $dati['motivazione']);

        return back()->with('ok', count($dati['imputazioni']) === 1 ? 'Natura commerciale forzata.' : 'Natura commerciale forzata su '.count($dati['imputazioni']).' imputazioni.');
    }

    public function togli(Request $request): RedirectResponse
    {
        $dati = $request->validate(['imputazioni' => ['required', 'array', 'min:1', 'max:200'], 'imputazioni.*' => ['integer']], ['imputazioni.required' => 'Scegli almeno un’imputazione.']);

        Forzature::togli($request->user(), $dati['imputazioni']);

        return back()->with('ok', 'Forzatura tolta: vale di nuovo la natura calcolata.');
    }
}
