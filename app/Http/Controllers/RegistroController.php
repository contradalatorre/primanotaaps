<?php

namespace App\Http\Controllers;

use App\Models\RegistroModifica;
use App\Models\User;
use Illuminate\Http\Request;

/** Il registro completo delle modifiche, per chi ha `vedere_registro_modifiche`. La storia di un movimento sta nei movimenti. */
class RegistroController extends Controller
{
    public function index(Request $request)
    {
        $utenteId = $request->integer('utente') ?: null;
        $oggetto = in_array($request->query('oggetto'), ['conto', 'movimento', 'tipologia', 'attivita', 'iniziativa', 'tag', 'import', 'manuale', 'nota', 'regola', 'impostazioni', 'esercizio'], true) ? $request->query('oggetto') : null;

        return view('registro', [
            'righe' => RegistroModifica::with('user')
                ->when($oggetto, fn ($q) => $q->where('oggetto_tipo', $oggetto))
                ->when($utenteId, fn ($q) => $q->where('user_id', $utenteId))
                ->orderByDesc('id')->paginate(50)->withQueryString(),
            'utenti' => User::whereIn('id', RegistroModifica::select('user_id'))->orderBy('name')->get(),
            'oggetto' => $oggetto,
            'utenteId' => $utenteId,
        ]);
    }
}
