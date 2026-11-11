<?php

namespace App\Http\Controllers;

use App\Models\Avanzamento;
use App\Models\User;
use App\Onboarding\Catalogo;
use App\Onboarding\Missione;
use App\Onboarding\Prova;
use App\Onboarding\Schermate;
use App\Onboarding\Svolgimento;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Il menu Impara (#71): missioni per percorso, avvio e ripresa in prova, pagina dei timbri, vista dell'Amministratore. Database vero. */
class ImparaController extends Controller
{
    public function __construct(private readonly Svolgimento $svolgimento, private readonly Prova $prova) {}

    public function indice(Request $request)
    {
        $utente = $request->user();
        $catalogo = Catalogo::predefinito();
        $avanzamenti = Avanzamento::where('user_id', $utente->id)->get()->keyBy('missione');

        // «Per te»: le missioni dei permessi dell'utente. «Altri percorsi»: le altre, che si fanno solo in prova.
        $schede = $catalogo->missioni()->map(fn (Missione $m) => [
            'missione' => $m, 'avanzamento' => $avanzamenti->get($m->id), 'suggerita' => $m->suggeritaA($utente),
            'novita' => $this->svolgimento->novita($m, $avanzamenti->get($m->id)),
        ]);
        $perPercorso = fn (bool $suggerita) => $catalogo->percorsi()->map(fn (array $p) => $p + ['schede' => $schede->where('missione.percorso', $p['id'])->where('suggerita', $suggerita)->values()])
            ->filter(fn (array $p) => $p['schede']->isNotEmpty())->values();

        return view('impara.indice', ['perTe' => $perPercorso(true), 'altri' => $perPercorso(false)]);
    }

    public function avvia(Request $request, string $missione): RedirectResponse
    {
        $m = $this->missione($missione);

        return $this->parti($request, $m, fn () => $this->svolgimento->avvia($request->user(), $m));
    }

    public function riprendi(Request $request, string $missione): RedirectResponse
    {
        $m = $this->missione($missione);

        return $this->parti($request, $m, fn () => $this->svolgimento->riprendi($request->user(), $m));
    }

    /** «Non ora» all'invito del primo accesso: non è un blocco, e le missioni restano suggerite in Impara. */
    public function invito(Request $request): RedirectResponse
    {
        $this->svolgimento->rifiutaInvito($request->user());

        return redirect()->back();
    }

    /** La pagina tipo pergamena: un timbro per missione completata, un sigillo per percorso completato. */
    public function timbri(Request $request)
    {
        $catalogo = Catalogo::predefinito();
        $fatte = Avanzamento::where('user_id', $request->user()->id)->whereNotNull('versione_completata')->get()->keyBy('missione');
        $percorsi = $catalogo->percorsi()->map(function (array $p) use ($catalogo, $fatte) {
            $missioni = $catalogo->missioni()->where('percorso', $p['id']);

            return $p + ['missioni' => $missioni, 'sigillo' => $missioni->isNotEmpty() && $missioni->every(fn (Missione $m) => $fatte->has($m->id))];
        });

        return view('impara.timbri', ['percorsi' => $percorsi, 'fatte' => $fatte, 'nuovo' => is_string($nuovo = $request->query('nuovo')) ? $nuovo : null]); // `?nuovo[]=x` non è una missione
    }

    /** Per l'Amministratore: solo quali missioni ha completato ciascuno, non errori, tempi o tentativi (#21). */
    public function avanzamento()
    {
        $catalogo = Catalogo::predefinito();
        $completate = Avanzamento::whereNotNull('versione_completata')->get()->groupBy('user_id');

        return view('impara.avanzamento', [
            'utenti' => User::where('esterno', false)->orderBy('name')->get(), 'completate' => $completate, 'missioni' => $catalogo->missioni(),
        ]);
    }

    private function missione(string $id): Missione
    {
        return Catalogo::predefinito()->missione($id) ?? abort(404);
    }

    /** Prepara lo spazio (o lo riprende) e porta alla schermata del passo; se la prova non è disponibile lo dice, senza uscire dal database vero. */
    private function parti(Request $request, Missione $missione, Closure $prepara): RedirectResponse
    {
        try {
            $avanzamento = $prepara();
            $url = $this->prova->per($request->user(), $missione, fn () => Schermate::url($missione->passi[$avanzamento->passo]->schermata));
        } catch (RuntimeException $e) {
            return redirect()->route('impara')->with('avviso', $e->getMessage());
        }
        $request->session()->put('prova.missione', $missione->id);

        return redirect($url ?? route('home'));
    }
}
