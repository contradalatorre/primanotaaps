<?php

namespace App\Http\Controllers;

use App\Models\ImportEstratto;
use App\Revisione;
use Illuminate\Http\Request;

/**
 * La schermata di revisione dei movimenti di un import (#41). Come l'import, sta dietro `importare_e_confermare`,
 * che non ha ambito (#16): niente conto da passare alla rotta. Le regole dei passi sono in `App\Revisione`.
 */
class RevisioneImportController extends Controller
{
    public function mostra(Request $request, ImportEstratto $import)
    {
        abort_unless($import->stato === 'confermato', 404);
        $revisione = new Revisione($import->load('conto'));
        $passo = array_key_exists($request->query('passo', ''), Revisione::PASSI) ? $request->query('passo') : $revisione->passoIniziale();

        return view('revisione.mostra', [
            'revisione' => $revisione, 'import' => $import, 'passo' => $passo, 'movimenti' => $revisione->passo($passo),
            'dettaglio' => $revisione->movimenti->firstWhere('id', $request->integer('movimento')),
        ]);
    }
}
