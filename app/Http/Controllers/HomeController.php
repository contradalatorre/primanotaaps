<?php

namespace App\Http\Controllers;

use App\Esercizio\Verifiche;
use App\Giroconti;
use App\Models\Menzione;
use Illuminate\Http\Request;

/** La home: il riquadro «Da vedere» (#45) con le menzioni di chi legge le note, e le metà di giroconto senza corrispondenza (#47) per chi le abbina. */
class HomeController extends Controller
{
    public function index(Request $request)
    {
        $utente = $request->user();

        // shortcut: il Modello D si ricalcola a ogni apertura della home, senza cache; da rivedere se diventa lento con molti movimenti.
        $secondarieta = $utente->can('vedere') ? Verifiche::secondarieta(today()->year) : null;

        return view('home', [
            'secondarieta' => $secondarieta !== null && $secondarieta['esito'] !== 'rispettato' ? $secondarieta : null,
            'daVedere' => $utente->can('note') && $utente->can('vedere') ? Menzione::daVedere($utente) : collect(),
            'orfane' => $utente->can('importare_e_confermare') ? Giroconti::orfane() : collect(),
        ]);
    }
}
