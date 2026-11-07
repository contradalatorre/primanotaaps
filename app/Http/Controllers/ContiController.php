<?php

namespace App\Http\Controllers;

use App\Euro;
use App\Models\Assegnazione;
use App\Models\Conto;
use App\Models\RegistroModifica;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Conti dell'associazione: li vedono tutti; crearli, modificarli e chiuderli spetta a chi ha `gestire_regole`
 * (l'elenco fisso di #16 non ha un permesso a parte per i dati di base: rimandato all'orchestratore).
 */
class ContiController extends Controller
{
    public function index()
    {
        return view('conti.indice', ['conti' => Conto::orderBy('chiuso_il')->orderBy('nome')->get()]);
    }

    public function nuovo()
    {
        return view('conti.modulo', ['conto' => new Conto(['tipo' => 'cassa', 'formato_import' => 'manuale', 'aperto_il' => today()])]);
    }

    public function crea(Request $request): RedirectResponse
    {
        $request->validate(['tipo' => ['required', Rule::in(array_keys(Conto::TIPI))]]);
        $dati = $this->dati($request, $request->string('tipo')->toString());

        DB::transaction(function () use ($dati, $request) {
            $conto = Conto::create($dati);
            RegistroModifica::registra($request->user(), $conto, 'creato', null, $conto->istantanea());
        });

        return redirect()->route('conti')->with('ok', 'Conto creato.');
    }

    public function modifica(Conto $conto)
    {
        return view('conti.modulo', ['conto' => $conto]);
    }

    public function salva(Request $request, Conto $conto): RedirectResponse
    {
        if ($conto->chiuso()) {
            throw ValidationException::withMessages(['conto' => 'Un conto chiuso non si modifica.']);
        }
        $dati = $this->dati($request, $conto->tipo, $conto);

        DB::transaction(function () use ($conto, $dati, $request) {
            $prima = $conto->istantanea();
            $conto->update($dati);
            RegistroModifica::registra($request->user(), $conto, 'modificato', $prima, $conto->istantanea());
        });

        return redirect()->route('conti')->with('ok', 'Conto salvato.');
    }

    /**
     * Chiude il conto e fa decadere le assegnazioni su quel conto. Chi resta senza conti non diventa «su tutti»:
     * l'assegnazione resta inerte e l'avviso, oltre che qui, sta nel menu e nella pagina Utenti degli Amministratori.
     */
    public function chiudi(Request $request, Conto $conto): RedirectResponse
    {
        if ($conto->chiuso()) {
            throw ValidationException::withMessages(['conto' => 'Il conto è già chiuso.']);
        }
        $request->validate([
            'chiuso_il' => ['required', 'date', 'after_or_equal:'.$conto->aperto_il->toDateString(), 'before_or_equal:today'],
        ]);

        $rimasti = DB::transaction(function () use ($request, $conto) {
            $prima = $conto->istantanea();
            $conto->update(['chiuso_il' => $request->date('chiuso_il')]);
            $toccate = DB::table('assegnazione_conto')->where('conto_id', $conto->id)->pluck('assegnazione_id');
            DB::table('assegnazione_conto')->where('conto_id', $conto->id)->delete();
            RegistroModifica::registra($request->user(), $conto, 'chiuso', $prima, $conto->istantanea());

            return Assegnazione::senzaConti()->whereIn('id', $toccate)->with('user')->get()->pluck('user.name')->unique()->sort()->values();
        });

        $risposta = redirect()->route('conti')->with('ok', 'Conto chiuso: le assegnazioni su questo conto sono decadute.');

        return $rimasti->isEmpty() ? $risposta : $risposta->with('avviso',
            'Restano senza conti, quindi in sola lettura: '.$rimasti->join(', ').'. Un Amministratore può dare loro un nuovo conto dalla pagina Utenti.');
    }

    private function dati(Request $request, string $tipo, ?Conto $conto = null): array
    {
        $dati = $request->validate([
            'nome' => ['required', 'string', 'max:100', Rule::unique('conti', 'nome')->ignore($conto)],
            'formato_import' => ['required', Rule::in(array_keys(Conto::FORMATI_IMPORT[$tipo]))],
            'riga_modello_d' => ['nullable', Rule::in(array_keys(Conto::RIGHE_MODELLO_D))],
            'aperto_il' => ['required', 'date', function ($campo, $valore, $fallisci) use ($conto) {
                $primo = $conto?->movimenti()->min('data');
                if ($primo && $valore > $primo) {
                    $fallisci('Il conto ha movimenti dal '.date('d/m/Y', strtotime($primo)).': non può aprirsi dopo.');
                }
            }],
            'saldo_iniziale' => ['required', 'string', fn ($campo, $valore, $fallisci) => Euro::daTesto($valore) === null
                && $fallisci('Scrivi l’importo in euro, con al più due decimali (es. 150,00).')],
        ], attributes: ['formato_import' => 'formato di import', 'aperto_il' => 'data di apertura', 'saldo_iniziale' => 'saldo di partenza']);

        return [
            'tipo' => $tipo,
            'nome' => $dati['nome'],
            'formato_import' => $dati['formato_import'],
            // Come deciso in #12: PayPal e Satispay nei depositi, la cassa nella riga cassa; la banca nei depositi.
            'riga_modello_d' => $dati['riga_modello_d'] ?: ($tipo === 'cassa' ? 'cassa' : 'depositi'),
            'aperto_il' => $dati['aperto_il'],
            'saldo_iniziale' => Euro::daTesto($dati['saldo_iniziale']),
        ];
    }
}
