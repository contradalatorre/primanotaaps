<?php

namespace App\Http\Controllers;

use App\Euro;
use App\Models\Attivita;
use App\Models\Conto;
use App\Models\Iniziativa;
use App\Models\Regola;
use App\Models\Tag;
use App\Models\Tipologia;
use App\Regole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Le regole del tesoriere (#42). Tutto dietro `gestire_regole` (rotte); la logica è in `App\Regole`. */
class RegoleController extends Controller
{
    public function index()
    {
        return view('regole.indice', ['regole' => Regole::elenco()]);
    }

    public function nuova()
    {
        return $this->modulo(new Regola);
    }

    public function modifica(Regola $regola)
    {
        return $this->modulo($regola->load('tag'));
    }

    public function crea(Request $request): RedirectResponse
    {
        [, $cambiati] = Regole::salva($request->user(), null, $request->all());

        return redirect()->route('regole')->with('ok', 'Regola salvata. '.$this->effetto($cambiati));
    }

    public function salva(Request $request, Regola $regola): RedirectResponse
    {
        [, $cambiati] = Regole::salva($request->user(), $regola, $request->all());

        return redirect()->route('regole')->with('ok', 'Regola salvata. '.$this->effetto($cambiati));
    }

    /** Lo stesso modulo, con sotto cosa farebbe la regola: non salva nulla. `regola` è l'id della regola che si modifica. */
    public function anteprima(Request $request)
    {
        $regola = $request->filled('regola') ? Regola::with('tag')->findOrFail($request->integer('regola')) : new Regola;
        $anteprima = Regole::anteprima($request->all(), $regola->exists ? $regola : null);
        $request->flash();

        return $this->modulo($regola, $anteprima);
    }

    /** L'elenco completo, nel nuovo ordine (trascinando o con Su e Giù). */
    public function ordina(Request $request): RedirectResponse
    {
        $dati = $request->validate(['ordine' => ['required', 'array', 'max:500'], 'ordine.*' => ['integer']]);
        $cambiati = Regole::riordina($request->user(), array_map('intval', $dati['ordine']));

        return redirect()->route('regole')->with('ok', 'Ordine salvato. '.$this->effetto($cambiati));
    }

    private function effetto(int $cambiati): string
    {
        return $cambiati === 0 ? 'Nessuna proposta è cambiata.' : ($cambiati === 1 ? 'Una proposta è cambiata.' : "$cambiati proposte sono cambiate.");
    }

    private function modulo(Regola $regola, ?array $anteprima = null)
    {
        // Le scelte archiviate non si offrono più, tranne quella che la regola ha già.
        $attive = fn ($modello, $corrente) => $modello::query()->orderBy('nome')->get()->reject(fn ($e) => $e->archiviata() && ! in_array($e->id, (array) $corrente, true));

        return view('regole.modulo', [
            'regola' => $regola, 'anteprima' => $anteprima,
            'valori' => $regola->exists ? $regola->only(['nome', 'conto_id', 'verso', 'causale', 'testo', 'tipologia_id', 'iniziativa_id', 'area', 'attivita_id', 'tipo_controparte'])
                + [
                    'importo_min' => $regola->importo_min === null ? null : Euro::formatta($regola->importo_min), 'importo_max' => $regola->importo_max === null ? null : Euro::formatta($regola->importo_max),
                    'valida_dal' => $regola->valida_dal?->toDateString(), 'valida_al' => $regola->valida_al?->toDateString(), 'tag' => $regola->tag->modelKeys(),
                ] : [],
            'conti' => Conto::orderBy('nome')->get(),
            'tipologie' => $attive(Tipologia::class, $regola->tipologia_id),
            'iniziative' => $attive(Iniziativa::class, $regola->iniziativa_id),
            'attivita' => $attive(Attivita::class, $regola->attivita_id),
            'tag' => $attive(Tag::class, $regola->exists ? $regola->tag->modelKeys() : []),
        ]);
    }
}
