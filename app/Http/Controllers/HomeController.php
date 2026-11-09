<?php

namespace App\Http\Controllers;

use App\Models\Menzione;
use Illuminate\Http\Request;

/** La home: oggi il riquadro «Da vedere» (#45) con le menzioni di chi legge le note. */
class HomeController extends Controller
{
    public function index(Request $request)
    {
        $utente = $request->user();

        return view('home', ['daVedere' => $utente->can('note') ? Menzione::daVedere($utente) : collect()]);
    }
}
