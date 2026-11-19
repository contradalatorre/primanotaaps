<?php

namespace App\Http\Controllers;

use App\Classificazione;
use App\Euro;
use App\Models\Conto;
use App\Models\Giroconto;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Movimenti di cassa scritti a mano. Il conto sta nell'indirizzo per inserire (`can:inserire_movimenti_cassa,conto`);
 * per modificare, ritirare ed eliminare un movimento già scritto il controllo dell'ambito è qui.
 */
class MovimentiController extends Controller
{
    public function index(Request $request)
    {
        $conti = Conto::orderBy('nome')->get();
        $conto = $conti->firstWhere('id', $request->integer('conto'));

        return view('movimenti.indice', [
            'conti' => $conti,
            'conto' => $conto,
            'senzaGiustificativo' => $request->boolean('senza_giustificativo'),
            'movimenti' => Movimento::conNote()->with('conto', 'autore', 'proponente', 'regola', 'conversioneCausale', 'storicoMovimento', 'storicoEsempio', 'giustificativi', 'imputazioni.attivita', 'imputazioni.iniziativa', 'imputazioni.tipologia', 'imputazioni.tag')
                ->when($conto, fn ($q) => $q->where('conto_id', $conto->id))
                // «Spese senza giustificativo»: le uscite che non ne hanno nessuno.
                ->when($request->boolean('senza_giustificativo'), fn ($q) => $q->speseSenzaGiustificativo())
                ->when($request->integer('anno') >= 1000, fn ($q) => $q->whereBetween('data', [$request->integer('anno').'-01-01', $request->integer('anno').'-12-31'])) // dall'elenco di controllo dell'approvazione (#59)
                ->orderByDesc('data')->orderByDesc('id')->paginate(50)->withQueryString(),
        ]);
    }

    public function nuovo(Conto $conto)
    {
        $this->controllaCassaAperta($conto);

        return view('movimenti.modulo', ['conto' => $conto, 'movimento' => new Movimento(['data' => today(), 'verso' => 'uscita'])]);
    }

    public function crea(Request $request, Conto $conto): RedirectResponse
    {
        $this->controllaCassaAperta($conto); // rifiuto rapido: quello che vale si rifà sotto, a conto bloccato

        DB::transaction(function () use ($request, $conto) {
            // Come la chiusura e la conferma dell'import: prima il conto. Senza, un movimento scritto mentre il conto si chiude
            // finirebbe dopo la data di chiusura.
            $conto = Conto::lockForUpdate()->findOrFail($conto->id);
            $this->controllaCassaAperta($conto);
            $dati = $this->dati($request, $conto);
            $movimento = new Movimento($dati);
            $movimento->conto_id = $conto->id;
            $movimento->inserito_da = $request->user()->id;
            $movimento->save();
            RegistroModifica::registra($request->user(), $movimento, 'inserito', null, $movimento->istantanea());
        });

        return redirect()->route('movimenti', ['conto' => $conto->id])->with('ok', 'Movimento inserito: è da classificare.');
    }

    public function modifica(Request $request, Movimento $movimento)
    {
        $this->controllaAutore($request, $movimento);

        return view('movimenti.modulo', ['conto' => $movimento->conto, 'movimento' => $movimento]);
    }

    public function salva(Request $request, Movimento $movimento): RedirectResponse
    {
        $this->controllaAutore($request, $movimento);

        DB::transaction(function () use ($request, $movimento) {
            // Prima il conto, come `crea` e la chiusura: la data si controlla contro un conto che non si sta chiudendo.
            $conto = Conto::lockForUpdate()->findOrFail($movimento->conto_id);
            $this->bloccaEControllaAutore($request, $movimento);
            $dati = $this->dati($request, $conto);
            $prima = $movimento->istantanea();
            $movimento->update($dati);
            RegistroModifica::registra($request->user(), $movimento, 'modificato', $prima, $movimento->istantanea());
        });

        return redirect()->route('movimenti', ['conto' => $movimento->conto_id])->with('ok', 'Movimento salvato.');
    }

    /** Il ritiro dell'autore è un'eliminazione logica senza motivazione, possibile finché il movimento non è confermato. */
    public function ritira(Request $request, Movimento $movimento): RedirectResponse
    {
        $this->controllaAutore($request, $movimento);
        $this->eliminaLogicamente($request, $movimento, 'ritirato', null, true);

        return redirect()->route('movimenti', ['conto' => $movimento->conto_id])->with('ok', 'Movimento ritirato.');
    }

    /**
     * Elimina un movimento manuale, anche confermato, con motivazione. Gli importati non si eliminano mai uno per uno.
     * shortcut: «fino al deposito» (#16) non si controlla perché gli esercizi arrivano con #55 e successivi; lì
     * va rifiutata l'eliminazione di un movimento di un esercizio depositato.
     */
    public function elimina(Request $request, Movimento $movimento): RedirectResponse
    {
        abort_unless($movimento->manuale, 403, 'Un movimento importato non si elimina uno per uno.');
        $motivazione = $request->validate(['motivazione' => ['required', 'string', 'max:1000']])['motivazione'];
        $this->eliminaLogicamente($request, $movimento, 'eliminato', $motivazione);

        return redirect()->route('movimenti', ['conto' => $movimento->conto_id])->with('ok', 'Movimento eliminato: resta nel registro delle modifiche.');
    }

    public function storia(Movimento $movimento)
    {
        return view('movimenti.storia', [
            'movimento' => $movimento->load('conto', 'autore'),
            'righe' => RegistroModifica::with('user')->where('oggetto_tipo', 'movimento')->where('oggetto_id', $movimento->id)->orderBy('id')->get(),
        ]);
    }

    private function eliminaLogicamente(Request $request, Movimento $movimento, string $azione, ?string $motivazione, bool $comeAutore = false): void
    {
        DB::transaction(function () use ($request, $movimento, $azione, $motivazione, $comeAutore) {
            // Prima il conto, come `crea`, `salva` e la chiusura: una cassa si chiude a saldo zero (#50) e un movimento eliminato
            // mentre si chiude lo cambierebbe; su un conto chiuso non si scrive, nemmeno per eliminare.
            if (Conto::lockForUpdate()->findOrFail($movimento->conto_id)->chiuso()) {
                throw ValidationException::withMessages(['movimento' => 'Il conto è chiuso: i suoi movimenti non si eliminano.']);
            }
            // Anche per il Tesoriere: un ritiro concorrente porterebbe due righe di registro e sovrascriverebbe chi ha eliminato.
            // Il movimento già eliminato non si trova più (404).
            Classificazione::blocca($movimento);
            $movimento->refresh();
            if ($comeAutore) {
                $this->controllaAutore($request, $movimento);
            }
            if (Giroconto::di($movimento)) { // l'altra metà resterebbe sola: prima si scioglie il giroconto (#47)
                throw ValidationException::withMessages(['movimento' => 'Il movimento è una metà di giroconto: scioglilo dal passo Giroconti prima di eliminarlo.']);
            }
            $prima = $movimento->istantanea();
            $movimento->forceFill(['eliminato_da' => $request->user()->id, 'motivazione_eliminazione' => $motivazione])->save();
            $movimento->delete();
            RegistroModifica::registra($request->user(), $movimento, $azione, $prima, null, $motivazione);
        });
    }

    /** Il controllo fuori dalla transazione è solo un rifiuto rapido: quello che vale si rifà qui, a riga bloccata (il Tesoriere può aver confermato). */
    private function bloccaEControllaAutore(Request $request, Movimento $movimento): void
    {
        Classificazione::blocca($movimento);
        $movimento->refresh();
        $this->controllaAutore($request, $movimento);
    }

    private function controllaAutore(Request $request, Movimento $movimento): void
    {
        abort_unless($movimento->modificabileDa($request->user()), 403);
    }

    private function controllaCassaAperta(Conto $conto): void
    {
        if ($conto->tipo !== 'cassa') {
            throw ValidationException::withMessages(['conto' => 'Gli estratti di banca, PayPal e Satispay si caricano: a mano si scrive solo sulla cassa.']);
        }
        if ($conto->chiuso()) {
            throw ValidationException::withMessages(['conto' => 'Il conto è chiuso: non si scrivono nuovi movimenti.']);
        }
    }

    private function dati(Request $request, Conto $conto): array
    {
        $dati = $request->validate([
            'data' => ['required', 'date', 'after_or_equal:'.$conto->aperto_il->toDateString(), 'before_or_equal:'.($conto->chiuso_il ?? today())->toDateString()],
            'verso' => ['required', Rule::in(array_keys(Movimento::VERSI))],
            'importo' => ['required', 'string', function ($campo, $valore, $fallisci) {
                $centesimi = Euro::daTesto($valore);
                if ($centesimi === null) {
                    $fallisci('Scrivi l’importo in euro, con al più due decimali (es. 12,50).');
                } elseif ($centesimi <= 0) {
                    $fallisci('Scrivi l’importo senza segno e più di zero: il verso dice se entra o esce.');
                }
            }],
            'descrizione' => ['required', 'string', 'max:1000'],
            'valuta_originale' => ['nullable', 'string', 'max:100'],
        ], attributes: ['valuta_originale' => 'valuta originale']);

        return ['importo' => Euro::daTesto($dati['importo'])] + $dati;
    }
}
