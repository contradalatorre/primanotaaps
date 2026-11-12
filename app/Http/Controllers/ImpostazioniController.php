<?php

namespace App\Http\Controllers;

use App\Models\ImpostazioniAssociazione;
use App\Models\RegistroModifica;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Le impostazioni dell'associazione (#51, storia 135): denominazione, codice fiscale, numero RUNTS e affiliazione a una rete
 * nazionale (art. 85 c. 4). Valgono da un esercizio in poi: salvare per l'esercizio N scrive la riga «dal N» e non tocca i precedenti.
 * Per chi ha `gestire_impostazioni` (#16); ogni modifica va nel registro.
 */
class ImpostazioniController extends Controller
{
    public function index()
    {
        return view('impostazioni', ['righe' => ImpostazioniAssociazione::orderByDesc('dal_esercizio')->get(), 'anno' => today()->year]);
    }

    public function salva(Request $request): RedirectResponse
    {
        $dati = $request->validate([
            'dal_esercizio' => ['required', 'integer', 'between:2020,2100'],
            'denominazione' => ['required', 'string', 'max:255'],
            'codice_fiscale' => ['nullable', 'string', 'max:16'],
            'numero_runts' => ['nullable', 'string', 'max:255'],
        ]);
        $dati['affiliazione_rete'] = $request->boolean('affiliazione_rete');

        DB::transaction(function () use ($dati, $request) {
            $riga = ImpostazioniAssociazione::where('dal_esercizio', $dati['dal_esercizio'])->lockForUpdate()->first();
            $prima = $riga?->istantanea();
            $riga ??= new ImpostazioniAssociazione;
            $riga->fill($dati)->save();
            RegistroModifica::registra($request->user(), $riga, $prima === null ? 'creato' : 'modificato', $prima, $riga->istantanea());
        });

        return redirect()->route('impostazioni')->with('ok', 'Impostazioni salvate, valgono dall’esercizio '.$dati['dal_esercizio'].' in poi.');
    }
}
