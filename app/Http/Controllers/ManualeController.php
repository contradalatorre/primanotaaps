<?php

namespace App\Http\Controllers;

use App\Models\ManualeSezione;
use App\Models\RegistroAccesso;
use App\Models\RegistroModifica;
use App\Models\Tipologia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Il manuale di classificazione (#44): lo legge chi vede, lo scrive chi ha `gestire_regole`. Le sezioni non si cancellano. */
class ManualeController extends Controller
{
    private const REGOLE = ['titolo' => ['required', 'string', 'max:255'], 'corpo' => ['required', 'string', 'max:65000']];

    public function index()
    {
        return view('manuale', ['sezioni' => ManualeSezione::orderBy('id')->get(), 'tipologie' => $this->tipologie()]);
    }

    public function crea(Request $request): RedirectResponse
    {
        $dati = $request->validate(self::REGOLE);

        DB::transaction(function () use ($request, $dati) {
            $sezione = new ManualeSezione($dati);
            // shortcut: max+1 senza blocco, due creazioni simultanee danno un errore sul vincolo unico; da rivedere se serve scrivere in parallelo.
            $sezione->codice = (string) (ManualeSezione::pluck('codice')->max(fn ($c) => (int) $c) + 1);
            $sezione->save();
            RegistroModifica::registra($request->user(), $sezione, 'creato', null, $sezione->istantanea());
        });

        return redirect()->route('manuale')->with('ok', 'Sezione aggiunta.');
    }

    public function salva(Request $request, ManualeSezione $sezione): RedirectResponse
    {
        $dati = $request->validate(self::REGOLE + ['versione' => ['required', 'string', 'size:64']]);

        DB::transaction(function () use ($request, $sezione, $dati) {
            $sezione = ManualeSezione::lockForUpdate()->findOrFail($sezione->id);
            if (! hash_equals($sezione->versione(), $dati['versione'])) {
                throw ValidationException::withMessages(['versione' => 'La sezione è cambiata dopo che l’hai aperta: rileggila prima di salvare.']);
            }
            $prima = $sezione->istantanea();
            $sezione->fill(Arr::only($dati, ['titolo', 'corpo']));
            if ($sezione->isDirty()) { // salvare senza cambiare nulla non è una modifica
                $sezione->save();
                RegistroModifica::registra($request->user(), $sezione, 'modificato', $prima, $sezione->istantanea());
            }
        });

        return redirect()->route('manuale')->with('ok', 'Salvato.');
    }

    public function esporta(Request $request): Response
    {
        RegistroAccesso::registra('esportazione_manuale', $request, $request->user());

        return response(ManualeSezione::testo(), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename=manuale-di-classificazione-'.today()->toDateString().'.md',
        ]);
    }

    private function tipologie()
    {
        return Tipologia::attive()->orderBy('verso')->orderBy('nome')->get();
    }
}
