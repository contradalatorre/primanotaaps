<?php

namespace App\Http\Controllers;

use App\Models\ImportEstratto;
use App\Models\Iniziativa;
use App\Revisione;
use App\Storico;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La schermata di revisione dei movimenti di un import (#41). Come l'import, sta dietro `importare_e_confermare`,
 * che non ha ambito (#16): niente conto da passare alla rotta. Le regole dei passi sono in `App\Revisione`.
 */
class RevisioneImportController extends Controller
{
    public function mostra(Request $request, ImportEstratto $import)
    {
        $revisione = $this->revisione($import);
        $passo = $request->query('passo');
        // `?passo[]=x` è un array: non è un passo.
        $passo = is_string($passo) && array_key_exists($passo, Revisione::PASSI) ? $passo : $revisione->passoIniziale();

        return view('revisione.mostra', [
            'revisione' => $revisione, 'import' => $import, 'passo' => $passo, 'movimenti' => $revisione->passo($passo),
            'iniziative' => Iniziativa::whereNull('archiviata_il')->orderBy('nome')->get(),
            // Il suggerimento di una regola è per chi può crearla, e solo nell'elenco: aprire un dettaglio non rifà il calcolo su tutto lo storico.
            'suggerimenti' => $request->user()->can('gestire_regole') && ! $request->filled('movimento') ? Storico::regoleSuggerite() : collect(),
            'dettaglio' => $revisione->movimenti->firstWhere('id', $request->integer('movimento')),
        ]);
    }

    /** Conferma uno, un gruppo o i selezionati: sempre `movimenti[id] = versione vista`. `dopo` è il movimento da aprire poi. */
    public function conferma(Request $request, ImportEstratto $import): RedirectResponse
    {
        $dati = $this->valida($request);
        $this->revisione($import)->conferma($request->user(), $dati['movimenti']);

        $n = count($dati['movimenti']);

        return $this->torna($import, $dati, $n.($n === 1 ? ' movimento confermato.' : ' movimenti confermati.'));
    }

    /** L'azione primaria: tutte le proposte pronte, riconosciute dall'impronta di quando la pagina è stata disegnata. */
    public function confermaPronte(Request $request, ImportEstratto $import): RedirectResponse
    {
        $dati = $request->validate(['impronta' => ['required', 'string', 'size:64']]);
        $n = $this->revisione($import)->confermaPronte($request->user(), $dati['impronta']);

        return $this->torna($import, [], $n.($n === 1 ? ' movimento confermato.' : ' movimenti confermati.'));
    }

    public function iniziativa(Request $request, ImportEstratto $import): RedirectResponse
    {
        $dati = $this->valida($request) + $request->validate(['iniziativa_id' => ['required', 'integer', 'exists:iniziative,id']]);
        $this->revisione($import)->impostaIniziativa($request->user(), $dati['movimenti'], Iniziativa::findOrFail($dati['iniziativa_id']));

        $n = count($dati['movimenti']);

        return $this->torna($import, $dati, 'Iniziativa impostata su '.$n.($n === 1 ? ' movimento.' : ' movimenti.'));
    }

    private function revisione(ImportEstratto $import): Revisione
    {
        abort_unless($import->stato === 'confermato', 404);

        return new Revisione($import->load('conto'));
    }

    /** @return array{movimenti: array<int, string>, passo?: string, dopo?: int} */
    private function valida(Request $request): array
    {
        // Oltre `max_input_vars` PHP taglia i campi del modulo in silenzio (un campo per movimento), e il server non vede quanti
        // ne erano: un invio tagliato arriva con quasi `max_input_vars` movimenti, quindi sopra questo tetto (con un margine per
        // gli altri campi) si rifiuta. Un conteggio dichiarato dal client non basterebbe senza script (le caselle si spuntano a mano).
        $massimo = max(1, min(2000, ((int) ini_get('max_input_vars') ?: 1000) - 20));

        return $request->validate([
            'movimenti' => ['required', 'array', 'min:1', 'max:'.$massimo],
            'movimenti.*' => ['string', 'size:64'],
            'passo' => ['nullable', Rule::in(array_keys(Revisione::PASSI))],
            'dopo' => ['nullable', 'integer'],
        ], ['movimenti.max' => 'Sono troppi movimenti per un solo invio (al massimo '.$massimo.'): selezionane meno, oppure usa «Conferma le proposte pronte».']);
    }

    private function torna(ImportEstratto $import, array $dati, string $ok): RedirectResponse
    {
        return redirect()->route('import.revisione', array_filter(['import' => $import, 'passo' => $dati['passo'] ?? null, 'movimento' => $dati['dopo'] ?? null]))->with('ok', $ok);
    }
}
