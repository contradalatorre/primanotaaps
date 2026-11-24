<?php

namespace App\Http\Controllers;

use App\Area;
use App\Esercizio\Riclassificazione;
use App\Models\Attivita;
use App\Models\DatiEsercizio;
use App\Models\Iniziativa;
use App\Models\Tipologia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * La riclassificazione in blocco (#62): la pagina dei cambi, l'anteprima (una GET: dopo la riautenticazione si torna all'anteprima, non a un modulo senza dati) e
 * l'applicazione, dietro `password.confirm:riautentica`. Il permesso è `riclassificare_in_blocco` (sulle rotte); la logica è in `App\Esercizio\Riclassificazione`.
 */
class RiclassificazioneController extends Controller
{
    public function indice(Request $request)
    {
        $esercizio = $request->integer('esercizio') ?: today()->year;

        return view('riclassificazione.indice', [
            'esercizio' => $esercizio,
            'stato' => DatiEsercizio::per($esercizio)->stato,
            'iniziative' => Iniziativa::with('predefiniti.attivita')->whereNull('archiviata_il')->orderBy('nome')->get()->filter(fn (Iniziativa $i) => $i->predefinitiPer($esercizio) !== null),
            'attivita' => Attivita::whereNull('archiviata_il')->orderBy('area')->orderBy('nome')->get(),
            'tipologie' => Tipologia::orderBy('verso')->orderBy('nome')->get(),
            'voci' => DB::table('voci_modello_d')->where('esercizio', $esercizio)->orderBy('area')->orderBy('voce')->get()->groupBy('tipologia_id'),
        ]);
    }

    public function anteprima(Request $request)
    {
        $cambio = $this->cambio($request);

        return view('riclassificazione.anteprima', ['cambio' => $cambio, 'esito' => Riclassificazione::anteprima($request->user(), $cambio)]);
    }

    public function applica(Request $request): RedirectResponse
    {
        $cambio = $this->cambio($request);
        $impronta = $request->validate(['impronta' => ['required', 'string', 'size:40']])['impronta'];
        $esito = Riclassificazione::applica($request->user(), $cambio, $impronta);

        $ok = $esito['descrizione'].($esito['aggiornate'] === 0 ? '' : ' '.$esito['aggiornate'].($esito['aggiornate'] === 1 ? ' imputazione aggiornata.' : ' imputazioni aggiornate.'))
            .($esito['a_mano'] === [] ? '' : ' '.count($esito['a_mano']).(count($esito['a_mano']) === 1 ? ' movimento cambiato a mano non è stato toccato' : ' movimenti cambiati a mano non sono stati toccati').': l’elenco è qui sotto.');

        return redirect()->route('riclassificazione', ['esercizio' => $cambio['esercizio'] ?? today()->year])->with('ok', $ok)->with('a_mano', $esito['a_mano'])->with('da_rivedere', $esito['da_rivedere']);
    }

    /** @return array{tipo: string, iniziativa_id?: int, esercizio?: int, area?: string, attivita_id?: ?int, tipologia_id?: int, proprieta?: list<string>, voce?: ?string} */
    private function cambio(Request $request): array
    {
        $tipo = $request->validate(['tipo' => ['required', Rule::in(['iniziativa', 'tipologia', 'voce'])]])['tipo'];
        $esercizio = ['esercizio' => ['required', 'integer', 'between:2000,2100']];
        $area = ['required', Rule::enum(Area::class)];

        return ['tipo' => $tipo] + match ($tipo) {
            'iniziativa' => $this->tipati($request->validate($esercizio + ['iniziativa_id' => ['required', 'integer', 'exists:iniziative,id'], 'area' => $area, 'attivita_id' => ['nullable', 'integer', 'exists:attivita,id']]), ['iniziativa_id', 'esercizio', 'attivita_id']),
            'tipologia' => $this->tipati($request->validate(['tipologia_id' => ['required', 'integer', 'exists:tipologie,id'], 'proprieta' => ['nullable', 'array'], 'proprieta.*' => [Rule::in(array_keys(Tipologia::PROPRIETA))]]), ['tipologia_id']) + ['proprieta' => $request->input('proprieta', [])],
            'voce' => $this->tipati($request->validate($esercizio + ['tipologia_id' => ['required', 'integer', 'exists:tipologie,id'], 'area' => $area, 'voce' => ['nullable', 'string', 'max:20']]) + ['voce' => null], ['tipologia_id', 'esercizio']), // la chiave c'è sempre: un modulo senza `voce` (riga da togliere) non cambia l'impronta
        };
    }

    /** I campi numerici come numeri (l'impronta dell'anteprima e quella dell'applicazione vengono dallo stesso testo), i campi vuoti come null. */
    private function tipati(array $dati, array $interi): array
    {
        foreach ($interi as $campo) {
            $dati[$campo] = isset($dati[$campo]) ? (int) $dati[$campo] : null;
        }
        if (array_key_exists('voce', $dati)) {
            $dati['voce'] = isset($dati['voce']) ? trim($dati['voce']) ?: null : null;
        }

        return $dati;
    }
}
