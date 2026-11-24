<?php

namespace App\Http\Controllers;

use App\Area;
use App\Classificazione;
use App\Models\Attivita;
use App\Models\Giustificativo;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Tag;
use App\Models\Tipologia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Classificazione, conferma e giustificativi di un movimento. Le regole sono in `App\Classificazione`; qui il
 * controllo di chi può (`Movimento::classificabileDa`: il Tesoriere sempre, l'autore sul proprio non confermato)
 * e la forma dei dati.
 */
class ClassificazioneController extends Controller
{
    public function modifica(Request $request, Movimento $movimento)
    {
        $this->controlla($request, $movimento);
        // Senza la commissione: la aggiunge `Classificazione::salva`, la pagina la mostra a parte.
        $imputazioni = $movimento->imputazioni()->with('attivita', 'iniziativa', 'tipologia', 'tag')->where('importo', '>', 0)->orderBy('id')->get();

        // Si scelgono gli elementi attivi; quelli archiviati restano nell'elenco solo dove il movimento li ha già.
        $conservati = fn (string $campo) => $imputazioni->pluck($campo)->filter()->all();

        return view('movimenti.classificazione', [
            'movimento' => $movimento->load('conto', 'autore', 'proponente', 'regola', 'conversioneCausale', 'storicoMovimento', 'storicoEsempio', 'giustificativi'),
            'imputazioni' => $imputazioni->load('iniziativa.predefiniti'),
            'righe' => min(10, max($request->integer('righe'), $imputazioni->count() + 1, 2)),
            'tipologie' => Tipologia::where('verso', $movimento->verso)->where(fn ($q) => $q->whereNull('archiviata_il')->orWhereIn('id', $conservati('tipologia_id')))->orderBy('nome')->get(),
            'iniziative' => Iniziativa::with('predefiniti.attivita')->where(fn ($q) => $q->whereNull('archiviata_il')->orWhereIn('id', $conservati('iniziativa_id')))->orderBy('nome')->get(),
            'attivita' => Attivita::where(fn ($q) => $q->whereNull('archiviata_il')->orWhereIn('id', $conservati('attivita_id')))->orderBy('area')->orderBy('nome')->get(),
            'tag' => Tag::where(fn ($q) => $q->whereNull('archiviata_il')->orWhereIn('id', $imputazioni->flatMap->tag->pluck('id')->all()))->orderBy('nome')->get(),
            'dubbiVisti' => $movimento->note()->dubbioAperto()->orderBy('id')->pluck('id'),
        ]);
    }

    public function salva(Request $request, Movimento $movimento): RedirectResponse
    {
        $this->controlla($request, $movimento);
        $dati = $request->validate([
            'tipo_controparte' => ['nullable', Rule::in(array_keys(Movimento::TIPI_CONTROPARTE))],
            'imputazioni' => ['nullable', 'array', 'max:10'],
            'imputazioni.*' => ['array'],
            'imputazioni.*.importo' => ['nullable', 'string', 'max:20'],
            'imputazioni.*.tipologia_id' => ['nullable', 'integer', 'exists:tipologie,id'],
            'imputazioni.*.iniziativa_id' => ['nullable', 'integer', 'exists:iniziative,id'],
            'imputazioni.*.area' => ['nullable', Rule::in(array_column(Area::cases(), 'value'))],
            'imputazioni.*.attivita_id' => ['nullable', 'integer', 'exists:attivita,id'],
            'imputazioni.*.tag' => ['nullable', 'array'],
            'imputazioni.*.tag.*' => ['integer', 'exists:tag,id'],
            'dubbi_visti' => ['nullable', 'array'],
            'dubbi_visti.*' => ['integer'],
        ]);

        Classificazione::salva($request->user(), $movimento, $dati['tipo_controparte'] ?? null, $dati['imputazioni'] ?? [], array_map('intval', $dati['dubbi_visti'] ?? []));

        return redirect()->route('movimenti.classificazione', $movimento)->with('ok', 'Classificazione salvata.');
    }

    /** «Aggiungi un'imputazione»: non salva, torna alla pagina con una riga in più e quello che si era scritto. */
    public function aggiungiRiga(Request $request, Movimento $movimento): RedirectResponse
    {
        $this->controlla($request, $movimento);

        return redirect()->route('movimenti.classificazione', [$movimento, 'righe' => count((array) $request->input('imputazioni')) + 1])->withInput();
    }

    /** Conferma manuale: solo chi ha `importare_e_confermare` (la rotta); la proposta di un consigliere vale come proposta. */
    public function conferma(Request $request, Movimento $movimento): RedirectResponse
    {
        $dati = $request->validate(['versione' => ['required', 'string', 'size:64']]);
        Classificazione::conferma($request->user(), $movimento, $dati['versione']);

        return back()->with('ok', 'Movimento confermato.');
    }

    public function aggiungiGiustificativo(Request $request, Movimento $movimento): RedirectResponse
    {
        $this->controlla($request, $movimento);
        // Solo http e https: un link «javascript:» in un href sarebbe una falla.
        $dati = $request->validate(['riferimento' => ['required', 'string', 'max:255'], 'link' => ['nullable', 'url:http,https', 'max:2000']]);

        $this->conRegistro($request, $movimento, fn () => $movimento->giustificativi()->create($dati));

        return back()->with('ok', 'Giustificativo aggiunto.');
    }

    public function rimuoviGiustificativo(Request $request, Giustificativo $giustificativo): RedirectResponse
    {
        $movimento = $giustificativo->movimento;
        abort_if($movimento === null, 404); // movimento ritirato o eliminato
        $this->controlla($request, $movimento);

        $this->conRegistro($request, $movimento, fn () => $giustificativo->delete());

        return back()->with('ok', 'Giustificativo tolto.');
    }

    private function conRegistro(Request $request, Movimento $movimento, \Closure $modifica): void
    {
        DB::transaction(function () use ($request, $movimento, $modifica) {
            Classificazione::bloccaEControlla($request->user(), $movimento);
            $prima = $movimento->istantanea();
            $modifica();
            RegistroModifica::registra($request->user(), $movimento, 'modificato', $prima, $movimento->istantanea());
        });
    }

    private function controlla(Request $request, Movimento $movimento): void
    {
        abort_unless($movimento->classificabileDa($request->user()), 403);
    }
}
