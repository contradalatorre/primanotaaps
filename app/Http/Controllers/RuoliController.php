<?php

namespace App\Http\Controllers;

use App\Models\Ruolo;
use App\Models\User;
use App\Permesso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Ruoli: insiemi di permessi scelti dall'elenco fisso; i predefiniti si modificano come gli altri. */
class RuoliController extends Controller
{
    public function index()
    {
        return view('ruoli.indice', ['ruoli' => Ruolo::withCount('assegnazioni')->orderBy('nome')->get()]);
    }

    public function nuovo()
    {
        return view('ruoli.modulo', ['ruolo' => new Ruolo(['permessi' => []])]);
    }

    public function crea(Request $request): RedirectResponse
    {
        Ruolo::create($this->dati($request));

        return redirect()->route('ruoli')->with('ok', 'Ruolo creato.');
    }

    public function modifica(Ruolo $ruolo)
    {
        return view('ruoli.modulo', ['ruolo' => $ruolo]);
    }

    public function salva(Request $request, Ruolo $ruolo): RedirectResponse
    {
        $dati = $this->dati($request, $ruolo);
        User::proteggendoUltimoAmministratore(fn () => $ruolo->update($dati));

        return redirect()->route('ruoli')->with('ok', 'Ruolo salvato.');
    }

    private function dati(Request $request, ?Ruolo $ruolo = null): array
    {
        $dati = $request->validate([
            'nome' => ['required', 'string', 'max:100', Rule::unique('ruoli', 'nome')->ignore($ruolo)],
            'permessi' => ['array'],
            'permessi.*' => [Rule::enum(Permesso::class)],
        ]);

        return ['nome' => $dati['nome'], 'permessi' => array_values(array_unique($dati['permessi'] ?? []))];
    }
}
