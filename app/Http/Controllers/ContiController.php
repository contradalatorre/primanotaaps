<?php

namespace App\Http\Controllers;

use App\ConteDiCassa;
use App\Euro;
use App\Models\Conto;
use App\Models\RegistroModifica;
use App\Models\User;
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
        $rimasti = DB::transaction(function () use ($request, $conto) {
            // Prima il conto, come la conferma dell'import: una scrittura di movimenti in corso si mette in fila e l'ultimo
            // movimento si legge dopo. Poi gli Amministratori (la chiusura può togliere l'ambito all'ultimo), e solo dopo
            // ogni lettura semplice: MySQL (REPEATABLE READ) fotografa alla prima.
            $conto = Conto::lockForUpdate()->findOrFail($conto->id);

            return User::proteggendoUltimoAmministratore(function () use ($request, $conto) {
                if ($conto->chiuso()) {
                    throw ValidationException::withMessages(['conto' => 'Il conto è già chiuso.']);
                }
                // Non prima dell'ultimo movimento: i movimenti dopo la chiusura non si potrebbero più modificare.
                $ultimo = $conto->movimenti()->max('data');
                $request->validate([
                    'chiuso_il' => ['required', 'date', 'after_or_equal:'.max($conto->aperto_il->toDateString(), $ultimo ?? ''), 'before_or_equal:today'],
                ], ['chiuso_il.after_or_equal' => 'Il conto non può chiudersi prima della sua apertura o dell’ultimo movimento'.($ultimo ? ' ('.date('d/m/Y', strtotime($ultimo)).')' : '').'.']);
                if ($conto->tipo === 'cassa') {
                    ConteDiCassa::controllaChiusura($conto, $request->date('chiuso_il')->toDateString());
                }

                $prima = $conto->istantanea();
                $conto->update(['chiuso_il' => $request->date('chiuso_il')]);
                $toccate = DB::table('assegnazione_conto')->where('conto_id', $conto->id)->pluck('assegnazione_id');
                $persone = User::whereHas('assegnazioni', fn ($a) => $a->whereIn('id', $toccate))->orderBy('name')->get();
                DB::table('assegnazione_conto')->where('conto_id', $conto->id)->delete();
                // Le righe tolte da assegnazione_conto non hanno un registro proprio: si riassumono qui, chi ne aveva l'ambito.
                RegistroModifica::registra($request->user(), $conto, 'chiuso', $prima, $conto->istantanea(),
                    $persone->isEmpty() ? null : 'Assegnazioni decadute su questo conto, per ripristinarle a mano: '.$persone->pluck('name')->join(', ').' (assegnazioni n. '.$toccate->sort()->join(', ').').');

                return User::senzaConti()->whereIn('id', $persone->pluck('id'))->orderBy('name')->pluck('name');
            });
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
            'riga_modello_d' => ($dati['riga_modello_d'] ?? null) ?: ($tipo === 'cassa' ? 'cassa' : 'depositi'),
            'aperto_il' => $dati['aperto_il'],
            'saldo_iniziale' => Euro::daTesto($dati['saldo_iniziale']),
        ];
    }
}
