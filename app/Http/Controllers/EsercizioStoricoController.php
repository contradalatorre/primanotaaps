<?php

namespace App\Http\Controllers;

use App\Esercizio\ImportStorico;
use App\Esercizio\SaldiApertura;
use App\Euro;
use App\Import\Foglio;
use App\Import\FoglioNonValido;
use App\Models\EsercizioStorico;
use App\Models\Movimento;
use App\Onboarding\Prova;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * L'esercizio storico dal foglio approvato (#63, #18): carica → anteprima (con abbinamento manuale, saldi e figurativi) → conferma, poi bloccato.
 * Chi gestisce i dati dell'esercizio carica; lo modifica solo chi può riaprire un esercizio (l'Amministratore). Lo vede chiunque `vedere`.
 * L'anteprima è un JSON ripulito (importi e righe riconosciute, nessun testo del foglio oltre alle etichette) in storage con un nome casuale, come per gli estratti.
 */
class EsercizioStoricoController extends Controller
{
    public function indice(int $anno)
    {
        $storico = EsercizioStorico::where('esercizio', $anno)->first();

        return view('esercizio.storico', ['anno' => $anno, 'storico' => $storico, 'm' => $storico?->prospetto(), 'voci' => ImportStorico::voci()]);
    }

    public function carica(Request $request, int $anno): RedirectResponse
    {
        $messaggio = 'Carica il foglio 2025 approvato (.xlsx o .ods, al massimo 4 MB).';
        $request->validate(['foglio' => ['required', 'file', 'max:4096']], ['foglio.required' => $messaggio, 'foglio.file' => $messaggio, 'foglio.max' => $messaggio]);
        if (EsercizioStorico::where('esercizio', $anno)->exists()) {
            throw ValidationException::withMessages(['esercizio' => "L'esercizio storico $anno è già caricato ed è bloccato: lo modifica solo un Amministratore, con motivazione."]);
        }
        if (! EsercizioStorico::caricabile($anno)) {
            throw ValidationException::withMessages(['esercizio' => ImportStorico::nonCaricabile($anno)]);
        }

        try {
            $anteprima = ImportStorico::anteprima(Foglio::leggi($request->file('foglio')->get()), $anno);
        } catch (FoglioNonValido $errore) {
            throw ValidationException::withMessages(['foglio' => $errore->getMessage()]);
        }

        $this->scartaAnteprimeVecchie();
        $token = Str::random(40); // un nome casuale: quello del file caricato non entra mai in un percorso
        Storage::disk('local')->put($this->percorso($token), json_encode(['user_id' => $request->user()->id, 'anno' => $anno, 'anteprima' => $anteprima], JSON_THROW_ON_ERROR));

        return redirect()->route('esercizio.storico.anteprima', [$anno, $token]);
    }

    public function anteprima(int $anno, string $token)
    {
        $anteprima = $this->leggi($anno, $token);
        $campi = $this->campi(old());
        try {
            $importi = ImportStorico::importi($anteprima, $campi['abbinamenti']);
        } catch (ValidationException) {
            $importi = $anteprima['voci']; // un abbinamento rimasto da una pagina vecchia: si ricomincia dalle voci riconosciute
        }
        $saldoCassa = old('saldo_cassa', isset($anteprima['saldi']['cassa']) ? Euro::formatta($anteprima['saldi']['cassa']) : '');
        $saldoDepositi = old('saldo_depositi', isset($anteprima['saldi']['depositi']) ? Euro::formatta($anteprima['saldi']['depositi']) : '');
        $m = EsercizioStorico::calcola($importi, 0, 0, ['costi' => [], 'proventi' => []]);

        return view('esercizio.storico-anteprima', [
            'anno' => $anno, 'token' => $token, 'anteprima' => $anteprima, 'm' => $m, 'voci' => ImportStorico::voci(),
            'differenze' => ImportStorico::differenze($anteprima['totali'], $m), 'saldoCassa' => $saldoCassa, 'saldoDepositi' => $saldoDepositi,
            'abbinamenti' => $campi['abbinamenti'], 'figurativi' => old('figurativi', array_map(fn ($aree) => array_map(Euro::formatta(...), $aree), $anteprima['figurativi'] ?? [])),
        ]);
    }

    public function conferma(Request $request, int $anno, string $token): RedirectResponse
    {
        $anteprima = $this->leggi($anno, $token);
        if ($request->input('azione') === 'ricalcola') {
            return back()->withInput(); // ricalcola totali e differenze con gli abbinamenti scritti fin qui
        }

        $campi = $this->campi($request->all());
        $importi = ImportStorico::importi($anteprima, $campi['abbinamenti']);
        $cassa = self::euro($request->input('saldo_cassa'), 'saldo_cassa', 'il saldo finale di cassa');
        $depositi = self::euro($request->input('saldo_depositi'), 'saldo_depositi', 'il saldo finale dei depositi');
        $figurativi = ['costi' => [], 'proventi' => []];
        foreach (['costi', 'proventi'] as $tipo) {
            foreach (['A', 'B'] as $area) {
                $figurativi[$tipo][$area] = self::euro($request->input("figurativi.$tipo.$area"), "figurativi.$tipo.$area", 'l’importo figurativo', vuotoZero: true, minimo: 0);
            }
        }
        $differenze = ImportStorico::differenze($anteprima['totali'], EsercizioStorico::calcola($importi, $cassa, $depositi, $figurativi));

        ImportStorico::conferma($request->user(), $anno, $importi, $cassa, $depositi, $figurativi, $differenze, $anteprima['esempi']);
        Storage::disk('local')->delete($this->percorso($token));

        return redirect()->route('esercizio.storico', $anno)->with('ok', "Esercizio storico $anno caricato e bloccato.")->with($this->avvisoApertura($anno));
    }

    public function modifica(int $anno)
    {
        $storico = EsercizioStorico::where('esercizio', $anno)->firstOrFail();

        return view('esercizio.storico-modifica', ['anno' => $anno, 'storico' => $storico, 'voci' => ImportStorico::voci()]);
    }

    public function aggiorna(Request $request, int $anno): RedirectResponse
    {
        EsercizioStorico::where('esercizio', $anno)->firstOrFail();
        $request->validate(['motivazione' => ['required', 'string', 'max:1000']], ['motivazione.required' => 'Scrivi la motivazione della modifica: va nel registro.']);
        $importi = [];
        foreach (array_filter((array) $request->input('importi', []), 'is_string') as $chiave => $valore) {
            abort_unless(ImportStorico::voceValida((string) $chiave), 422);
            $importo = self::euro($valore, 'importi.'.$chiave, 'l’importo di «'.$chiave.'»', vuotoZero: true, minimo: 0);
            $importo === 0 || $importi[$chiave] = $importo;
        }
        $cassa = self::euro($request->input('saldo_cassa'), 'saldo_cassa', 'il saldo finale di cassa');
        $depositi = self::euro($request->input('saldo_depositi'), 'saldo_depositi', 'il saldo finale dei depositi');
        $figurativi = ['costi' => [], 'proventi' => []];
        foreach (['costi', 'proventi'] as $tipo) {
            foreach (['A', 'B'] as $area) {
                $figurativi[$tipo][$area] = self::euro($request->input("figurativi.$tipo.$area"), "figurativi.$tipo.$area", 'l’importo figurativo', vuotoZero: true, minimo: 0);
            }
        }

        ImportStorico::modifica($request->user(), $anno, $importi, $cassa, $depositi, $figurativi, trim($request->input('motivazione')));

        return redirect()->route('esercizio.storico', $anno)->with('ok', "Esercizio storico $anno modificato: la modifica è nel registro.")->with($this->avvisoApertura($anno));
    }

    /** Se l'apertura dell'anno dopo è già fatta, i suoi saldi iniziali si rifanno contro la chiusura appena caricata o cambiata: si mostra l'esito, senza bloccare (altrimenti non si potrebbe correggere lo storico). */
    private function avvisoApertura(int $anno): array
    {
        $errori = SaldiApertura::scostamentiAttuali($anno + 1);

        return $errori === [] ? [] : ['avviso' => 'I saldi iniziali del '.($anno + 1).' non tornano con la chiusura '.$anno.' ('.implode('; ', $errori).'): correggi lo storico o i saldi dell’apertura.'];
    }

    /** @return array{abbinamenti: array<int|string, mixed>} */
    private function campi(array $input): array
    {
        return ['abbinamenti' => is_array($input['abbinamenti'] ?? null) ? $input['abbinamenti'] : []];
    }

    /** Un importo di un modulo, in centesimi; ValidationException se non lo è. */
    public static function euro(mixed $valore, string $campo, string $nome, bool $vuotoZero = false, ?int $minimo = null): int
    {
        $testo = trim((string) $valore);
        $centesimi = $testo === '' && $vuotoZero ? 0 : (strlen($testo) <= 30 ? Euro::daTesto($testo) : null);
        if ($centesimi === null || ($minimo !== null && $centesimi < $minimo)) {
            throw ValidationException::withMessages([$campo => 'Scrivi '.$nome.' in euro, per esempio 1.234,56'.($minimo === 0 ? ' (non negativo)' : '').'.']);
        }

        return $centesimi;
    }

    private function percorso(string $token): string
    {
        return app(Prova::class)->cartellaEstratti().'/foglio-'.$token.'.json';
    }

    /** L'anteprima caricata da chi la sta guardando, per quell'esercizio; altrimenti 404. */
    private function leggi(int $anno, string $token): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1 && Storage::disk('local')->exists($this->percorso($token)), 404);
        $dati = json_decode(Storage::disk('local')->get($this->percorso($token)), true, flags: JSON_THROW_ON_ERROR);
        abort_unless($dati['user_id'] === request()->user()->id && $dati['anno'] === $anno, 404);

        return $dati['anteprima'];
    }

    private function scartaAnteprimeVecchie(): void
    {
        $disco = Storage::disk('local');
        foreach ($disco->files(app(Prova::class)->cartellaEstratti()) as $file) {
            if (str_contains($file, '/foglio-') && $disco->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disco->delete($file);
            }
        }
    }
}
