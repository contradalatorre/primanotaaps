<?php

namespace App\Http\Controllers;

use App\Giroconti;
use App\Models\Giroconto;
use App\Models\ImportEstratto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Conferma, completamento e scioglimento dei giroconti (#47), dal passo Giroconti della revisione. Come l'import sta
 * dietro `importare_e_confermare`, che non ha ambito (#16); le regole e i controlli a riga bloccata sono in `App\Giroconti`.
 */
class GirocontiController extends Controller
{
    public function conferma(Request $request, ImportEstratto $import): RedirectResponse
    {
        $dati = $request->validate(['movimento_a' => ['required', 'integer'], 'movimento_b' => ['required', 'integer', 'different:movimento_a'], 'dubbi_visti' => ['nullable', 'array'], 'dubbi_visti.*' => ['integer']]);
        Giroconti::conferma($request->user(), $dati['movimento_a'], $dati['movimento_b'], array_map('intval', $dati['dubbi_visti'] ?? []));

        return $this->torna($import, 'giroconti', 'Giroconto confermato: le due metà non sono né entrate né uscite.');
    }

    public function meta(Request $request, ImportEstratto $import): RedirectResponse
    {
        $dati = $request->validate(['movimento' => ['required', 'integer'], 'conto_id' => ['required', 'integer'], 'dubbi_visti' => ['nullable', 'array'], 'dubbi_visti.*' => ['integer']]);
        Giroconti::registraMeta($request->user(), $dati['movimento'], $dati['conto_id'], array_map('intval', $dati['dubbi_visti'] ?? []));

        return $this->torna($import, 'giroconti', 'Metà registrata e giroconto confermato.');
    }

    public function scioglie(Request $request, ImportEstratto $import, Giroconto $giroconto): RedirectResponse
    {
        Giroconti::scioglie($request->user(), $giroconto->id);

        return $this->torna($import, 'tutti', 'Giroconto sciolto: le due metà sono di nuovo da classificare.');
    }

    private function torna(ImportEstratto $import, string $passo, string $ok): RedirectResponse
    {
        return redirect()->route('import.revisione', [$import, 'passo' => $passo])->with('ok', $ok);
    }
}
