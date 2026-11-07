<?php

namespace App\Http\Controllers;

use App\Models\ManualeSezione;
use App\Models\RegistroAccesso;
use App\Models\RegistroModifica;
use App\Models\Tipologia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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
            $sezione->codice = (string) (ManualeSezione::pluck('codice')->max(fn ($c) => (int) $c) + 1);
            $sezione->save();
            RegistroModifica::registra($request->user(), $sezione, 'creato', null, $sezione->istantanea());
        });

        return redirect()->route('manuale')->with('ok', 'Sezione aggiunta.');
    }

    public function salva(Request $request, ManualeSezione $sezione): RedirectResponse
    {
        $sezione->fill($request->validate(self::REGOLE));
        if ($sezione->isDirty()) { // salvare senza cambiare nulla non è una modifica
            DB::transaction(function () use ($request, $sezione) {
                $prima = collect($sezione->getOriginal())->only(['codice', 'titolo', 'corpo'])->all();
                $sezione->save();
                RegistroModifica::registra($request->user(), $sezione, 'modificato', $prima, $sezione->istantanea());
            });
        }

        return redirect()->route('manuale')->with('ok', 'Salvato.');
    }

    public function esporta(Request $request): Response
    {
        RegistroAccesso::registra('esportazione_manuale', $request, $request->user());
        $testo = "# Manuale di classificazione\n\n";
        foreach (ManualeSezione::orderBy('id')->get() as $s) {
            $testo .= "## § {$s->codice} {$s->titolo}\n\n{$s->corpo}\n\n";
        }
        $testo .= "## § tipologie Le tipologie\n\n";
        foreach ($this->tipologie() as $t) {
            $testo .= "### {$t->nome} (".Tipologia::VERSI[$t->verso].")\n\n{$t->spiegazione}\n\n";
        }

        return response(rtrim($testo)."\n", 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename=manuale-di-classificazione-'.today()->toDateString().'.md',
        ]);
    }

    private function tipologie()
    {
        return Tipologia::attive()->orderBy('verso')->orderBy('nome')->get();
    }
}
