<?php

namespace App\Http\Controllers;

use App\Backup\Esecuzione;
use App\Models\Backup;
use App\Models\Conto;
use App\Models\Errore;
use App\Models\RegistroAccesso;
use App\Models\User;
use App\RegistroErrori;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Lo stato del sistema (#68): per chi ha `gestire_impostazioni`. Ultimo backup, import e riconciliazione per conto, errori aperti, Amministratori. */
class StatoSistemaController extends Controller
{
    public function index(Request $request)
    {
        return view('sistema', [
            'ultimoTentativo' => Backup::latest('avviato_il')->latest('id')->first(),
            'ultimoRiuscito' => Backup::where('esito', 'riuscito')->latest('avviato_il')->latest('id')->first(),
            'conti' => Conto::aperti()->orderBy('nome')->get()->map(fn (Conto $conto) => [
                'conto' => $conto,
                'ultimoImport' => $conto->importEstratti()->where('stato', 'confermato')->latest('confermato_il')->latest('id')->first(),
                'riconciliatoFinoAl' => $conto->riconciliatoFinoAl(),
            ]),
            'errori' => Errore::aperti()->orderByDesc('ultimo_il')->get(),
            'amministratori' => User::amministratoriAttivi()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function backup(Request $request): RedirectResponse
    {
        $backup = Esecuzione::avvia('su_richiesta', $request->user());
        if ($backup === null) {
            return redirect()->route('sistema')->with('avviso', 'Un backup è già in corso: aspetta che finisca.');
        }
        // L'esportazione contiene dati personali: come ogni esportazione, finisce nel registro degli accessi.
        RegistroAccesso::registra('esportazione_backup', $request, $request->user());

        return $backup->esito === 'riuscito'
            ? redirect()->route('sistema')->with('ok', 'Backup caricato sul drive condiviso.')
            : redirect()->route('sistema')->with('avviso', 'Il backup non è riuscito: '.$backup->errore.' Gli Amministratori hanno ricevuto un’email.');
    }

    public function risolvi(Request $request, Errore $errore): RedirectResponse
    {
        RegistroErrori::risolvi($errore, $request->user());

        return redirect()->route('sistema')->with('ok', 'Errore segnato come risolto.');
    }
}
