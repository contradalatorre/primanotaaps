<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Le preferenze di ognuno: oggi come ricevere le menzioni (#46). */
class PreferenzeController extends Controller
{
    public const EMAIL_MENZIONI = [
        'riepilogo' => 'Riepilogo alle 8, solo se ci sono menzioni da vedere',
        'immediata' => 'Un’email subito, a ogni menzione',
        'nessuna' => 'Nessuna email',
    ];

    public function index()
    {
        return view('preferenze', ['opzioni' => self::EMAIL_MENZIONI]);
    }

    public function salva(Request $request): RedirectResponse
    {
        $dati = $request->validate(['email_menzioni' => ['required', 'in:'.implode(',', array_keys(self::EMAIL_MENZIONI))]]);
        $request->user()->update($dati);

        return redirect()->route('preferenze')->with('ok', 'Preferenza salvata.');
    }
}
