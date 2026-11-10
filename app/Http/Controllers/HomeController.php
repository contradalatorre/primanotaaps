<?php

namespace App\Http\Controllers;

use App\Giroconti;
use App\Models\Menzione;
use Illuminate\Http\Request;

/** La home: il riquadro «Da vedere» (#45) con le menzioni di chi legge le note, e le metà di giroconto senza corrispondenza (#47) per chi le abbina. */
class HomeController extends Controller
{
    public function index(Request $request)
    {
        $utente = $request->user();

        return view('home', [
            'daVedere' => $utente->can('note') && $utente->can('vedere') ? Menzione::daVedere($utente) : collect(),
            'orfane' => $utente->can('importare_e_confermare') ? Giroconti::orfane() : collect(),
        ]);
    }
}
