<?php

namespace App\Http\Controllers;

use App\Models\Menzione;
use App\Models\Movimento;
use App\Models\Nota;
use App\Note;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Note, dubbi e menzioni (#45). Si leggono e si scrivono con `note` su qualunque movimento visibile, anche fuori
 * dall'ambito: niente conto nella rotta. Le regole e le transazioni sono in `App\Note`.
 */
class NoteController extends Controller
{
    public function indice(Movimento $movimento)
    {
        return view('note.pagina', ['movimento' => $movimento->load('conto'), 'note' => Nota::di($movimento)]);
    }

    public function crea(Request $request, Movimento $movimento): RedirectResponse
    {
        $dati = $request->validate(['testo' => ['required', 'string', 'max:1000'], 'dubbio' => ['nullable', 'boolean']]);
        Note::scrivi($request->user(), $movimento, $dati['testo'], (bool) ($dati['dubbio'] ?? false));

        return back()->with('ok', ($dati['dubbio'] ?? false) ? 'Dubbio segnato.' : 'Nota aggiunta.');
    }

    public function modifica(Request $request, Nota $nota): RedirectResponse
    {
        $dati = $request->validate(['testo' => ['required', 'string', 'max:1000']]);
        Note::modifica($request->user(), $nota, $dati['testo']);

        return back()->with('ok', 'Nota modificata.');
    }

    public function elimina(Request $request, Nota $nota): RedirectResponse
    {
        Note::elimina($request->user(), $nota);

        return back()->with('ok', 'Nota eliminata.');
    }

    public function oscura(Request $request, Nota $nota): RedirectResponse
    {
        $dati = $request->validate(['motivazione' => ['required', 'string', 'max:1000']]);
        Note::oscura($request->user(), $nota, $dati['motivazione']);

        return back()->with('ok', 'Nota oscurata: il testo resta nel registro delle modifiche.');
    }

    public function togliDubbio(Request $request, Movimento $movimento): RedirectResponse
    {
        Note::togliDubbio($request->user(), $movimento);

        return back()->with('ok', 'Dubbio tolto.');
    }

    public function fatto(Request $request, Menzione $menzione): RedirectResponse
    {
        Note::segnaVista($request->user(), $menzione);

        return back()->with('ok', 'Segnata come fatta.');
    }
}
