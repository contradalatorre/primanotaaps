<?php

namespace App\Http\Controllers;

use App\Euro;
use App\Import\EstrattoNonValido;
use App\Import\SatispayRiepilogo;
use App\Models\Conto;
use App\Models\RegistroModifica;
use App\Models\SaldoDichiarato;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La riconciliazione di un conto senza saldo nell'estratto (Satispay, #49): il riepilogo mensile come file di controllo e il saldo
 * dichiarato a una data. Come l'import stanno dietro `importare_e_confermare` (#16). Il saldo dell'app è già «incassi − commissioni
 * − versamenti»: i movimenti sono al netto e i versamenti sono uscite.
 */
class SatispayController extends Controller
{
    /** Confronta il riepilogo con i movimenti dell'app, mese per mese. Non salva niente: il file è di controllo. */
    public function riepilogo(Request $request, Conto $conto): RedirectResponse
    {
        $this->soloSatispay($conto);
        // Il controllo sul contenuto vuole l'estensione fileinfo (come in ImportEstrattiController::carica): senza, un messaggio al posto dell'errore 500.
        if (! extension_loaded('fileinfo')) {
            throw ValidationException::withMessages(['riepilogo' => 'Sul server manca l’estensione PHP «fileinfo», che serve a controllare il file: va attivata dal pannello dell’hosting (docs/pubblicazione.md).']);
        }
        $messaggio = 'Carica il file CSV del riepilogo mensile (testo, al massimo 2 MB).';
        $request->validate(
            ['riepilogo' => ['required', 'file', 'max:2048', 'mimetypes:text/*,application/csv']],
            ['riepilogo.required' => $messaggio, 'riepilogo.file' => $messaggio, 'riepilogo.max' => $messaggio, 'riepilogo.mimetypes' => $messaggio],
        );
        try {
            $mesi = SatispayRiepilogo::leggi($request->file('riepilogo')->get());
        } catch (EstrattoNonValido $errore) {
            throw ValidationException::withMessages(['riepilogo' => $errore->getMessage()]);
        }

        $confronti = [];
        foreach ($mesi as $mese) {
            $dal = Carbon::parse($mese['mese'].'-01');
            $app = $conto->movimenti()->where('verso', 'entrata')->whereBetween('data', [$dal->toDateString(), $dal->copy()->endOfMonth()->toDateString()])
                ->selectRaw('count(*) as transazioni, coalesce(sum(importo), 0) as netto, coalesce(sum(commissione), 0) as commissioni')->first();
            $confronti[] = ['mese' => $dal->format('m/Y'), 'voci' => [
                ['nome' => 'Totale netto', 'file' => $mese['netto'], 'app' => (int) $app->netto],
                ['nome' => 'Commissioni', 'file' => $mese['commissioni'], 'app' => (int) $app->commissioni],
                ['nome' => 'Transazioni', 'file' => $mese['transazioni'], 'app' => (int) $app->transazioni, 'numero' => true],
            ]];
        }

        return redirect()->route('import.indice', $conto)->with('riepilogo', $confronti);
    }

    /** Registra il saldo letto dalla dashboard a una data; rifarlo per la stessa data lo corregge (resta nel registro). */
    public function saldoDichiarato(Request $request, Conto $conto): RedirectResponse
    {
        $this->soloSatispay($conto);
        $dati = $request->validate(
            ['data' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'importo' => ['required', 'string'], 'spiegazione' => ['nullable', 'string', 'max:1000']],
            ['data.*' => 'Scrivi la data del saldo (non nel futuro).', 'importo.required' => 'Scrivi il saldo dichiarato in euro (es. 123,45).'],
        );
        $importo = Euro::daTesto($dati['importo']);
        if ($importo === null) {
            throw ValidationException::withMessages(['importo' => 'Il saldo dichiarato dev’essere un importo in euro con al più due decimali (es. 123,45).']);
        }
        if ($dati['data'] < $conto->aperto_il->toDateString()) {
            throw ValidationException::withMessages(['data' => 'Il conto è stato aperto il '.$conto->aperto_il->format('d/m/Y').': non ha un saldo prima.']);
        }

        // A conto bloccato: due invii insieme (doppio clic) non si pestano sulla stessa data e il registro tiene ogni passaggio.
        DB::transaction(function () use ($request, $conto, $dati, $importo) {
            Conto::lockForUpdate()->findOrFail($conto->id);
            $saldo = SaldoDichiarato::firstOrNew(['conto_id' => $conto->id, 'data' => $dati['data']]);
            $prima = $saldo->exists ? ['importo' => $saldo->importo, 'spiegazione' => $saldo->spiegazione] : null;
            $saldo->fill(['user_id' => $request->user()->id, 'importo' => $importo, 'spiegazione' => trim($dati['spiegazione'] ?? '') ?: null])->save();
            RegistroModifica::registra($request->user(), $conto, 'saldo_dichiarato', $prima, ['data' => $dati['data'], 'importo' => $importo, 'spiegazione' => $saldo->spiegazione]);
        });

        return redirect()->route('import.indice', $conto)->with('ok', 'Saldo dichiarato registrato.');
    }

    private function soloSatispay(Conto $conto): void
    {
        abort_unless($conto->formato_import === 'satispay_csv', 404);
    }
}
