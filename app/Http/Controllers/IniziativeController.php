<?php

namespace App\Http\Controllers;

use App\Esercizio\Iniziative;
use App\Esercizio\Preventivo;
use App\Euro;
use App\Models\Iniziativa;
use App\Models\Tipologia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * I conti per iniziativa e il preventivo (#57). I conti li vede chi ha `vedere`; il preventivo lo scrive chi ha `gestire_regole` (#16: «iniziative, preventivi»).
 */
class IniziativeController extends Controller
{
    /** Righe vuote che il modulo del preventivo offre in più, per aggiungere tipologie senza JavaScript. */
    private const RIGHE_LIBERE = 3;

    public function conti(Request $request, int $anno)
    {
        $proposte = $request->boolean('proposte');

        return view('esercizio.iniziative', ['anno' => $anno, 'proposte' => $proposte, 'schede' => Iniziative::conti($anno, $proposte)]);
    }

    public function preventivo(int $anno, Iniziativa $iniziativa)
    {
        $righe = Preventivo::righe($iniziativa->id, $anno);
        $usate = $righe->pluck('tipologia_id');

        return view('esercizio.preventivo', [
            'anno' => $anno,
            'iniziativa' => $iniziativa,
            'righe' => $righe->map(fn ($r) => ['tipologia_id' => $r->tipologia_id, 'importo' => Euro::formatta($r->importo)])
                ->pad($righe->count() + self::RIGHE_LIBERE, ['tipologia_id' => '', 'importo' => ''])->values(),
            // Una tipologia archiviata non si sceglie più, ma se è già nel preventivo resta.
            'tipologie' => Tipologia::where(fn ($t) => $t->whereNull('archiviata_il')->orWhereIn('id', $usate))->orderBy('verso')->orderBy('nome')->get(),
        ]);
    }

    public function salva(Request $request, int $anno, Iniziativa $iniziativa): RedirectResponse
    {
        // Le righe vuote del modulo si ignorano; una riga a metà no.
        $righe = collect($request->input('righe', []))->filter(fn ($r) => filled($r['tipologia_id'] ?? null) || filled($r['importo'] ?? null))->values()->all();
        $dati = Validator::make(['righe' => $righe], [
            'righe' => ['array', 'max:200'],
            'righe.*.tipologia_id' => ['required', 'integer', 'distinct', 'exists:tipologie,id'],
            'righe.*.importo' => ['required', 'string', fn ($campo, $valore, $fallisci) => ($c = Euro::daTesto($valore)) === null || $c <= 0
                ? $fallisci('L’importo deve essere maggiore di zero, in euro e al più con due decimali.') : null],
        ], [
            'righe.*.tipologia_id.distinct' => 'Una tipologia può comparire una sola volta nel preventivo.',
            'righe.*.tipologia_id.required' => 'Ogni importo ha bisogno della sua tipologia.',
            'righe.*.importo.required' => 'Ogni tipologia ha bisogno del suo importo.',
        ])->validate();

        Preventivo::salva($request->user(), $iniziativa->id, $anno, array_map(
            fn (array $r) => ['tipologia_id' => (int) $r['tipologia_id'], 'importo' => Euro::daTesto($r['importo'])], $dati['righe'],
        ));

        return redirect()->route('esercizio.iniziative', $anno)->with('ok', 'Preventivo di «'.$iniziativa->nome.'» per il '.$anno.' salvato.');
    }
}
