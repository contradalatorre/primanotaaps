<?php

namespace App\Http\Controllers;

use App\Esercizio\Apertura;
use App\Esercizio\Deposito;
use App\Esercizio\Raccolta;
use App\Esercizio\Raccolte;
use App\Euro;
use App\Models\Attivita;
use App\Models\DatiEsercizio;
use App\Models\Iniziativa;
use App\Models\RaccoltaOccasionale;
use App\Models\Tipologia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Le raccolte fondi occasionali (#61, #18): l'elenco con la quadratura con C2 e la pagina di ogni raccolta, con la relazione precompilata e l'elenco guida. Le vede chi
 * ha `vedere` (come il Modello D), le scrive chi ha `gestire_esercizio`. Un esercizio depositato si legge solo dall'istantanea (#60). Mai nomi di persone fisiche: i campi
 * a testo libero lo ricordano e le elargizioni si scrivono come totali.
 */
class RaccolteController extends Controller
{
    private const IMPORTI = ['beni_modico_prezzo', 'elargizioni_persone_fisiche', 'elargizioni_persone_giuridiche'];

    public function indice(Request $request, int $anno)
    {
        // Da approvato in poi si legge la versione approvata, come il PDF di deposito (#60): né proposte né dati di oggi.
        $proposte = Deposito::istantanea($anno) === null && $request->boolean('proposte');

        return view('esercizio.raccolte', ['anno' => $anno, 'proposte' => $proposte, 'r' => Raccolte::vista($anno, $proposte)]);
    }

    public function mostra(int $anno, Iniziativa $iniziativa)
    {
        $r = Raccolte::vista($anno);
        $raccolta = collect($r['raccolte'])->firstWhere('iniziativa_id', $iniziativa->id);
        abort_if($raccolta === null, 404);
        $dati = DatiEsercizio::per($anno);

        return view('esercizio.raccolta', [
            'anno' => $anno, 'iniziativa' => $iniziativa, 'raccolta' => $raccolta, 'quadratura' => $r['quadratura'],
            'modificabile' => ! $dati->approvato(),
            'attivitaDestinazione' => Attivita::where('area', 'A')->whereNull('archiviata_il')->orderBy('nome')->get(),
            'valori' => $this->valori($raccolta),
        ]);
    }

    public function salva(Request $request, int $anno, Iniziativa $iniziativa): RedirectResponse
    {
        $this->controllaCandidata($anno, $iniziativa);
        $testo = ['nullable', 'string', 'max:5000'];
        $euro = ['nullable', 'string', 'max:30', fn ($campo, $valore, $fallisci) => $valore === null || ($c = Euro::daTesto($valore)) !== null && $c >= 0 ? null : $fallisci('Scrivi un importo in euro, per esempio 1.234,56.')];
        $campi = $request->validate([
            'dal' => ['nullable', 'date_format:Y-m-d'], 'al' => ['nullable', 'date_format:Y-m-d', ...($request->filled('dal') ? ['after_or_equal:dal'] : [])],
            'occasione' => ['nullable', 'string', 'max:255'], 'luogo' => ['nullable', 'string', 'max:255'],
            'beni_servizi' => $testo, 'descrizione' => $testo, 'modalita' => $testo, 'finalita' => $testo, 'dettaglio_costi' => $testo, 'motivazione' => $testo,
            'attivita_destinazione_id' => ['nullable', Rule::exists('attivita', 'id')->where(fn ($q) => $q->where('area', 'A')->whereNull('archiviata_il'))],
            'beni_modico_numero' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'beni_modico_prezzo' => $euro, 'elargizioni_persone_fisiche' => $euro, 'elargizioni_persone_giuridiche' => $euro,
        ]);
        foreach (self::IMPORTI as $importo) {
            $campi[$importo] = filled($campi[$importo] ?? null) ? Euro::daTesto($campi[$importo]) : null;
        }
        $campi = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $campi);

        Raccolte::salva($request->user(), $anno, $iniziativa->id, $campi + array_fill_keys(RaccoltaOccasionale::CAMPI, null));

        return redirect()->route('esercizio.raccolta', [$anno, $iniziativa])->with('ok', 'Dati della raccolta salvati.');
    }

    /** La tabella tipologia → riga dello schema delle raccolte (#89): le tipologie con una voce in area C nell'esercizio, o con una riga già scritta, e la riga di ognuna. */
    public function righe(int $anno)
    {
        $righe = DB::table('righe_raccolta')->where('esercizio', $anno)->pluck('riga', 'tipologia_id');
        $conVoceC = DB::table('voci_modello_d')->where('esercizio', $anno)->where('area', 'C')->pluck('tipologia_id');

        return view('esercizio.righe-raccolta', [
            'anno' => $anno, 'righe' => $righe,
            'tipologie' => Tipologia::whereIn('id', $conVoceC->merge($righe->keys()))->orderBy('verso')->orderBy('nome')->get(),
            'approvato' => DatiEsercizio::per($anno)->approvato(), 'aperto' => Apertura::sorgente($anno) === null,
        ]);
    }

    public function impostaRiga(Request $request, int $anno, int $tipologia): RedirectResponse
    {
        $riga = $request->validate(['riga' => ['nullable', 'string', Rule::in([...array_keys(Raccolta::PROVENTI), ...array_keys(Raccolta::ONERI)])]])['riga'] ?? null;
        Raccolte::impostaRiga($request->user(), $anno, $tipologia, $riga); // una tipologia che non c'è è un 404 (`findOrFail` sotto blocco)

        return redirect()->route('esercizio.raccolte.righe', $anno)->with('ok', $riga === null ? 'Riga tolta.' : 'Riga impostata.');
    }

    public function aggiungiLiberalita(Request $request, int $anno, Iniziativa $iniziativa): RedirectResponse
    {
        $this->controllaCandidata($anno, $iniziativa);
        $campi = $request->validate([
            'descrizione' => ['required', 'string', 'max:255'], 'quantita' => ['required', 'integer', 'min:1', 'max:1000000'], 'valore' => ['required', 'string', 'max:30'],
            'criterio' => ['required', 'string', 'max:255'], 'documento' => ['required', 'string', 'max:255'],
        ]);
        $valore = Euro::daTesto($campi['valore']);
        if ($valore === null || $valore <= 0) {
            throw ValidationException::withMessages(['valore' => 'Scrivi il valore stimato in euro, maggiore di zero, per esempio 1.234,56.']);
        }

        Raccolte::aggiungiLiberalita($request->user(), $anno, $iniziativa->id, ['valore' => $valore, 'quantita' => (int) $campi['quantita']] + array_map('trim', $campi));

        return redirect()->to(route('esercizio.raccolta', [$anno, $iniziativa]).'#liberalita')->with('ok', 'Liberalità non monetaria aggiunta.');
    }

    public function eliminaLiberalita(Request $request, int $anno, Iniziativa $iniziativa, int $riga): RedirectResponse
    {
        Raccolte::eliminaLiberalita($request->user(), $anno, $iniziativa->id, $riga);

        return redirect()->to(route('esercizio.raccolta', [$anno, $iniziativa]).'#liberalita')->with('ok', 'Liberalità non monetaria tolta.');
    }

    /** Si scrive solo di un'iniziativa che nell'esercizio è una raccolta occasionale: con imputazioni C2, con dati già scritti o predefinita in area C occasionale. */
    private function controllaCandidata(int $anno, Iniziativa $iniziativa): void
    {
        abort_unless(collect(Raccolte::calcola($anno)['raccolte'])->contains('iniziativa_id', $iniziativa->id), 404);
    }

    /** I valori del modulo: quelli scritti e non salvati, altrimenti quelli salvati; gli importi in euro. */
    private function valori(array $raccolta): array
    {
        $c = $raccolta['relazione']['campi'];
        $valori = [];
        foreach (RaccoltaOccasionale::CAMPI as $campo) {
            $v = $c[$campo] ?? null;
            $valori[$campo] = old($campo, in_array($campo, self::IMPORTI, true) && $v !== null ? Euro::formatta($v) : $v);
        }

        return $valori;
    }
}
