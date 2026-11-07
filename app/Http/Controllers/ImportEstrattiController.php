<?php

namespace App\Http\Controllers;

use App\Import\EstrattoNonValido;
use App\Models\Conto;
use App\Models\ImportConflitto;
use App\Models\ImportEstratto;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Import degli estratti conto (#40): carica → anteprima → conferma, con annullamento e decisione dei conflitti.
 * Il permesso `importare_e_confermare` non ha ambito (#16): sta sulle rotte, qui restano i controlli sul conto.
 */
class ImportEstrattiController extends Controller
{
    public function index(Conto $conto)
    {
        abort_unless(ImportEstratto::lettorePer($conto), 404);

        return view('import.indice', [
            'conto' => $conto,
            'importazioni' => $conto->importEstratti()->with('utente')->latest('id')->get(),
            'riconciliatoFinoAl' => $conto->riconciliatoFinoAl(),
        ]);
    }

    /** Legge il file e lo tiene (già ripulito) in attesa di conferma: finché non si conferma non entra nulla. */
    public function carica(Request $request, Conto $conto): RedirectResponse
    {
        $lettore = ImportEstratto::lettorePer($conto);
        if (! $lettore) {
            $this->rifiuta('Questo conto non ha un formato di import per gli estratti: i movimenti della cassa si scrivono a mano.');
        }
        if ($conto->chiuso()) {
            $this->rifiuta('Il conto è chiuso: non si importano nuovi movimenti.');
        }
        // Si valuta il contenuto, non il nome che il browser dichiara; 2 MB bastano a un anno di movimenti.
        $messaggio = 'Carica il file CSV dell’estratto (testo, al massimo 2 MB).';
        $request->validate(
            ['estratto' => ['required', 'file', 'max:2048', 'mimetypes:text/plain,text/csv,application/csv']],
            ['estratto.required' => $messaggio, 'estratto.file' => $messaggio, 'estratto.max' => $messaggio, 'estratto.mimetypes' => $messaggio],
        );

        try {
            $estratto = $lettore->leggi($request->file('estratto')->get());
        } catch (EstrattoNonValido $errore) {
            $this->rifiuta($errore->getMessage());
        }
        if ($estratto->dal() < $conto->aperto_il->toDateString()) {
            $this->rifiuta('Il file ha movimenti del '.date('d/m/Y', strtotime($estratto->dal())).', prima dell’apertura del conto ('.$conto->aperto_il->format('d/m/Y').').');
        }
        if ($conto->chiuso_il && $estratto->al() > $conto->chiuso_il->toDateString()) {
            $this->rifiuta('Il file ha movimenti dopo la chiusura del conto ('.$conto->chiuso_il->format('d/m/Y').').');
        }

        $this->scartaAnteprimeVecchie();
        // Un nome casuale: quello del file caricato non entra mai in un percorso.
        $percorso = 'estratti/'.Str::random(40).'.json';
        Storage::disk('local')->put($percorso, $estratto->toJson());
        $import = ImportEstratto::create([
            'conto_id' => $conto->id, 'user_id' => $request->user()->id, 'stato' => 'anteprima', 'percorso' => $percorso,
            'periodo_dal' => $estratto->dal(), 'periodo_al' => $estratto->al(),
            'saldo_iniziale' => $estratto->saldoIniziale, 'saldo_finale' => $estratto->saldoFinale, 'righe' => count($estratto->righe),
        ]);

        return redirect()->route('import.mostra', $import);
    }

    public function mostra(ImportEstratto $import)
    {
        $dati = ['import' => $import->load('conto', 'utente')];
        if ($import->stato === 'anteprima') {
            $conto = $import->conto;
            $classi = $conto->classifica($import->estratto());
            $atteso = $conto->saldoAl($import->periodo_dal->toDateString(), false);
            $dati += ['classi' => $classi, 'saldoApp' => $atteso, 'buco' => $atteso === $import->saldo_iniziale ? null : $this->buco($conto, $import)];
        } else {
            $dati += ['conflitti' => $import->conflitti()->with('movimento')->get(), 'movimenti' => $import->movimenti()->count()];
        }

        return view('import.mostra', $dati);
    }

    public function conferma(Request $request, ImportEstratto $import): RedirectResponse
    {
        $esito = DB::transaction(function () use ($request, $import) {
            // Prima il conto, poi l'import: un altro import sovrapposto o un secondo clic aspettano qui, e ricalcolano
            // nuovi, presenti e conflitti su quello che c'è davvero adesso.
            $conto = Conto::lockForUpdate()->findOrFail($import->conto_id);
            $import = ImportEstratto::lockForUpdate()->findOrFail($import->id);
            if ($import->stato !== 'anteprima') {
                return null;
            }
            if ($conto->chiuso()) {
                $this->rifiuta('Il conto è stato chiuso: non si importano nuovi movimenti.');
            }

            $classi = $conto->classifica($import->estratto());
            foreach (array_chunk($classi['nuove'], 200) as $blocco) {
                Movimento::insert(array_map(fn ($riga) => $import->attributiMovimento($riga, $request->user()->id), $blocco));
            }
            foreach ($classi['conflitti'] as $c) {
                $import->conflitti()->create(['movimento_id' => $c['movimento']->id, 'riga' => $c['riga']->toArray()]);
            }
            $this->registraMovimenti($request->user(), $import->movimenti()->get(), 'importato', null);

            $prima = $import->istantanea();
            Storage::disk('local')->delete($import->percorso);
            $import->update(['stato' => 'confermato', 'percorso' => null, 'confermato_il' => now()]);
            RegistroModifica::registra($request->user(), $import, 'confermato', $prima, $import->istantanea() + [
                'nuovi' => count($classi['nuove']), 'gia_presenti' => count($classi['presenti']), 'in_conflitto' => count($classi['conflitti']),
            ]);

            return $import;
        });

        return redirect()->route('import.mostra', $import)->with('ok', $esito
            ? 'Import confermato: i movimenti nuovi sono da classificare.'
            : 'Questo import era già stato confermato o scartato.');
    }

    /** Si butta un'anteprima che non si vuole più: l'import e le sue righe in attesa spariscono. */
    public function scarta(ImportEstratto $import): RedirectResponse
    {
        if ($import->stato === 'anteprima') {
            Storage::disk('local')->delete($import->percorso);
            $import->delete();
        }

        return redirect()->route('import.indice', $import->conto_id)->with('ok', 'Anteprima scartata: non è entrato nulla.');
    }

    /** Annulla per intero un import: i suoi movimenti escono (eliminazione logica, restano nel registro) se nessuno è confermato. */
    public function annulla(Request $request, ImportEstratto $import): RedirectResponse
    {
        DB::transaction(function () use ($request, $import) {
            $import = ImportEstratto::lockForUpdate()->findOrFail($import->id);
            if (! $import->annullabile()) {
                $this->rifiuta($import->stato === 'confermato'
                    ? 'Almeno un movimento di questo import è già confermato: l’import non si annulla più.'
                    : 'Solo un import confermato si annulla.');
            }

            $movimenti = $import->movimenti()->get();
            $this->registraMovimenti($request->user(), $movimenti, 'eliminato', 'Import annullato', true);
            $import->movimenti()->update(['eliminato_da' => $request->user()->id, 'motivazione_eliminazione' => 'Import annullato', 'eliminato_il' => now()]);
            $import->conflitti()->delete();

            $prima = $import->istantanea();
            $import->update(['stato' => 'annullato', 'annullato_il' => now()]);
            RegistroModifica::registra($request->user(), $import, 'annullato', $prima, $import->istantanea());
        });

        return redirect()->route('import.mostra', $import)->with('ok', 'Import annullato: i suoi movimenti non ci sono più e il file si può caricare di nuovo.');
    }

    /** L'operatore decide un conflitto: tiene il movimento presente o importa anche la riga del file, mai sovrascrive. */
    public function decidi(Request $request, ImportConflitto $conflitto): RedirectResponse
    {
        $decisione = $request->validate(['decisione' => ['required', Rule::in(array_keys(ImportConflitto::DECISIONI))]])['decisione'];

        DB::transaction(function () use ($request, $conflitto, $decisione) {
            $conflitto = ImportConflitto::lockForUpdate()->findOrFail($conflitto->id);
            $import = $conflitto->importEstratto;
            if ($conflitto->decisione !== null || $import->stato !== 'confermato') {
                $this->rifiuta('Questo conflitto è già deciso o l’import non c’è più.');
            }

            if ($decisione === 'importa_a_parte') {
                $movimento = (new Movimento)->forceFill($import->attributiMovimento($conflitto->rigaEstratto(), $request->user()->id));
                $movimento->save();
                $this->registraMovimenti($request->user(), collect([$movimento]), 'importato', null);
            }
            $conflitto->update(['decisione' => $decisione, 'deciso_da' => $request->user()->id, 'deciso_il' => now()]);
            RegistroModifica::registra($request->user(), $import, 'conflitto_deciso', null, ['conflitto' => $conflitto->id, 'decisione' => $decisione]);
        });

        return redirect()->route('import.mostra', $conflitto->import_estratto_id)->with('ok', 'Decisione registrata.');
    }

    /** @return array{da: \Carbon\CarbonInterface, a: \Carbon\CarbonInterface} il periodo in cui l'app non ha i movimenti che il file dà per presenti */
    private function buco(Conto $conto, ImportEstratto $import): array
    {
        $ultimo = $conto->movimenti()->where('data', '<', $import->periodo_dal->toDateString())->max('data');

        return ['da' => $ultimo ? \Illuminate\Support\Carbon::parse($ultimo)->addDay() : $conto->aperto_il, 'a' => $import->periodo_dal];
    }

    private function registraMovimenti(User $utente, Collection $movimenti, string $azione, ?string $motivazione, bool $comeEliminati = false): void
    {
        foreach (array_chunk($movimenti->all(), 200) as $blocco) {
            RegistroModifica::insert(array_map(fn (Movimento $m) => [
                'user_id' => $utente->id, 'oggetto_tipo' => 'movimento', 'oggetto_id' => $m->id, 'azione' => $azione,
                'prima' => $comeEliminati ? json_encode($m->istantanea()) : null, 'dopo' => $comeEliminati ? null : json_encode($m->istantanea()),
                'motivazione' => $motivazione, 'created_at' => now(),
            ], $blocco));
        }
    }

    /** Le anteprime lasciate lì da più di un giorno: su un hosting condiviso non c'è un'attività pianificata che le pulisca. */
    private function scartaAnteprimeVecchie(): void
    {
        foreach (ImportEstratto::where('stato', 'anteprima')->where('created_at', '<', now()->subDay())->get() as $vecchia) {
            Storage::disk('local')->delete($vecchia->percorso);
            $vecchia->delete();
        }
    }

    private function rifiuta(string $messaggio): never
    {
        throw ValidationException::withMessages(['estratto' => $messaggio]);
    }
}
