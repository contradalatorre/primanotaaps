<?php

namespace App\Http\Controllers;

use App\Conversioni;
use App\Euro;
use App\Giroconti;
use App\Import\Estratto;
use App\Import\EstrattoNonValido;
use App\Import\FoglioCassa;
use App\ImportFoglio;
use App\Models\Conto;
use App\Models\ImportConflitto;
use App\Models\ImportEstratto;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Onboarding\Prova;
use App\Regole;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            'saldiDichiarati' => $conto->formato_import === 'satispay_csv' ? $conto->saldiDichiarati()->with('utente')->orderByDesc('data')->get() : null, // #49: il saldo che l'estratto non ha
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
        $foglio = $lettore instanceof FoglioCassa;
        if ($foglio && $conto->foglioChiuso()) {
            $this->rifiuta('La data di passaggio è passata ('.$conto->passaggio_il->format('d/m/Y').'): da lì la cassa si registra solo nell’app e il foglio non si importa più.');
        }
        // Il controllo sul contenuto vuole l'estensione fileinfo; senza, al posto di un errore 500 un messaggio che dice cosa fare.
        if (! extension_loaded('fileinfo')) {
            $this->rifiuta('Sul server manca l’estensione PHP «fileinfo», che serve a controllare il file: va attivata dal pannello dell’hosting (docs/pubblicazione.md).');
        }
        // Si valuta il contenuto, non il nome che il browser dichiara; 2 MB bastano a un anno di movimenti, 4 a un foglio di calcolo (un .ods si riconosce come zip o come tipo suo).
        $messaggio = $foglio ? 'Carica il foglio di calcolo della cassa (.xlsx o .ods, al massimo 4 MB).' : 'Carica il file CSV dell’estratto (testo, al massimo 2 MB).';
        $request->validate(
            ['estratto' => $foglio
                ? ['required', 'file', 'max:4096', 'mimetypes:application/zip,application/x-zip-compressed,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.oasis.opendocument.spreadsheet']
                : ['required', 'file', 'max:2048', 'mimetypes:text/*,application/csv']],
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
        $this->controllaPassaggio($conto, $estratto);

        $this->scartaAnteprimeVecchie();
        // Un nome casuale: quello del file caricato non entra mai in un percorso.
        $percorso = app(Prova::class)->cartellaEstratti().'/'.Str::random(40).'.json';
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
            // I giroconti proposti (#47): le righe nuove che una regola riconosce, da abbinare nel passo Giroconti dopo la conferma.
            $dati += ['giroconti' => collect($classi['nuove'])->filter(fn ($r) => Giroconti::regolaPer($conto, $r->verso(), $r->causale.' '.$r->descrizione) !== null)->count()];
            // Il foglio non ha un «buco» da segnalare: ha i suoi controlli, mese per mese, e il confronto del saldo iniziale con l'apertura.
            $foglio = $conto->importaDalFoglio() ? ImportFoglio::controlli($conto, $import->estratto(), $classi['nuove']) : null;
            $dati += ['classi' => $classi, 'saldoApp' => $atteso, 'foglio' => $foglio, 'buco' => $foglio !== null || $import->saldo_iniziale === null || $atteso === $import->saldo_iniziale ? null : $this->buco($conto, $import)];
        } else {
            $dati += ['conflitti' => $import->conflitti()->with('movimento')->get(), 'movimenti' => $import->movimenti()->count(), 'foglio' => $import->controlli];
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

            $estratto = $import->estratto();
            $classi = $conto->classifica($estratto);
            $controlli = null;
            if ($conto->importaDalFoglio()) {
                $this->controllaPassaggio($conto, $estratto);
                $controlli = ImportFoglio::controlli($conto, $estratto, $classi['nuove']);
                if (! $controlli['finale']['ok']) {
                    $this->rifiuta('Il saldo finale del foglio ('.Euro::formatta($controlli['finale']['foglio']).' €) non torna con quello dell’app ('.Euro::formatta($controlli['finale']['app']).' €): l’import non si conferma. Controlla il foglio e ricaricalo.');
                }
            }
            foreach (array_chunk($classi['nuove'], 200) as $blocco) {
                Movimento::insert(array_map(fn ($riga) => $import->attributiMovimento($riga, $request->user()->id), $blocco));
            }
            foreach ($classi['conflitti'] as $c) {
                $import->conflitti()->create(['movimento_id' => $c['movimento']->id, 'riga' => $c['riga']->toArray()]);
            }
            $nuovi = $import->movimenti()->get();
            $this->registraMovimenti($request->user(), $nuovi, 'importato', null);
            // Le regole subito, a conto bloccato: un salvataggio di regole (che blocca tutti i conti) aspetta e poi rifà le proposte.
            // Il foglio ha la sua tabella di conversione al posto delle regole (#64).
            if ($controlli !== null) {
                Conversioni::applicaAiNuovi($request->user(), $nuovi);
                ImportFoglio::segnalaDubbi($request->user(), $conto, $controlli);
            } else {
                Regole::applicaAiNuovi($request->user(), $nuovi);
            }

            $prima = $import->istantanea();
            $percorso = $import->percorso;
            $import->update(['stato' => 'confermato', 'percorso' => null, 'confermato_il' => now(), 'controlli' => $controlli]);
            RegistroModifica::registra($request->user(), $import, 'confermato', $prima, $import->istantanea() + [
                'nuovi' => count($classi['nuove']), 'gia_presenti' => count($classi['presenti']), 'in_conflitto' => count($classi['conflitti']),
            ]);

            return [$import, $percorso];
        });
        // Il file si butta solo a transazione conclusa: se fallisce, l'anteprima ha ancora le sue righe.
        if ($esito) {
            Storage::disk('local')->delete($esito[1]);
            $import = $esito[0];
        }

        return redirect()->route('import.mostra', $import)->with('ok', $esito
            ? 'Import confermato: i movimenti nuovi sono da classificare, con la proposta delle regole dove ce n’è una.'
            : 'Questo import era già stato confermato o scartato.');
    }

    /** Si butta un'anteprima che non si vuole più: l'import e le sue righe in attesa spariscono. */
    public function scarta(ImportEstratto $import): RedirectResponse
    {
        $this->scartaAnteprima($import->id);

        return redirect()->route('import.indice', $import->conto_id)->with('ok', 'Anteprima scartata: non è entrato nulla.');
    }

    /** Annulla per intero un import: i suoi movimenti escono (eliminazione logica, restano nel registro) se nessuno è confermato. */
    public function annulla(Request $request, ImportEstratto $import): RedirectResponse
    {
        DB::transaction(function () use ($request, $import) {
            Conto::lockForUpdate()->findOrFail($import->conto_id); // stesso ordine di conferma: prima il conto
            $import = ImportEstratto::lockForUpdate()->findOrFail($import->id);
            // Si decide sulle righe bloccate (lettura corrente, non la fotografia di MySQL): una conferma singola concorrente
            // blocca solo il suo movimento, e senza questo lock l'annullamento eliminerebbe un movimento appena confermato.
            $movimenti = $import->movimenti()->orderBy('id')->lockForUpdate()->get();
            if ($import->stato !== 'confermato' || $movimenti->contains(fn (Movimento $m) => $m->confermato())) {
                $this->rifiuta($import->stato === 'confermato'
                    ? 'Almeno un movimento di questo import è già confermato: l’import non si annulla più.'
                    : 'Solo un import confermato si annulla.');
            }

            $this->registraMovimenti($request->user(), $movimenti, 'eliminato', 'Import annullato', true);
            $import->movimenti()->update(['eliminato_da' => $request->user()->id, 'motivazione_eliminazione' => 'Import annullato', 'eliminato_il' => now()]);
            // Restano le decisioni già prese. Il file ricaricato non rimette in discussione un «tieni il presente» (`Conto::classifica`);
            // un «importa anche questa riga» no: la riga è uscita con questo import e il file la riporta come riga da importare.
            $import->conflitti()->whereNull('decisione')->delete();

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

        // Fuori dalla transazione: una lettura semplice prima del lock del conto fisserebbe la fotografia di MySQL, e le regole lette dopo sarebbero vecchie.
        $contoId = $conflitto->importEstratto->conto_id;

        DB::transaction(function () use ($request, $conflitto, $decisione, $contoId) {
            // Prima il conto, come conferma e annulla: due decisioni sulla stessa riga di import sovrapposti si mettono in fila.
            $conto = Conto::lockForUpdate()->findOrFail($contoId);
            $conflitto = ImportConflitto::lockForUpdate()->findOrFail($conflitto->id);
            $import = $conflitto->importEstratto;
            if ($conflitto->decisione !== null || $import->stato !== 'confermato') {
                $this->rifiuta('Questo conflitto è già deciso o l’import non c’è più.');
            }
            // Un movimento già presente che l'annullamento di un altro import ha eliminato non si tiene: la riga di banca sparirebbe.
            $presente = $decisione === 'tieni_esistente' ? Movimento::withTrashed()->lockForUpdate()->find($conflitto->movimento_id) : null;
            if ($decisione === 'tieni_esistente' && ($presente === null || $presente->trashed())) {
                $this->rifiuta('Il movimento già presente è stato annullato con il suo import: si può solo importare questa riga.');
            }
            if ($decisione === 'importa_a_parte' && $conto->chiuso()) {
                $this->rifiuta('Il conto è chiuso: non si importano nuovi movimenti.');
            }

            if ($decisione === 'importa_a_parte') {
                $riga = $conflitto->rigaEstratto();
                // Lo stesso conflitto può esserci in due import sovrapposti: la riga entra una volta sola.
                // Lettura con lock (corrente): su MySQL una lettura semplice vede la fotografia della transazione,
                // fissata già dalla lettura di $conflitto->importEstratto, e non il movimento di una decisione appena conclusa.
                if ($conto->movimenti()->where('chiave', $riga->chiave())->lockForUpdate()->get()->contains(fn (Movimento $m) => $conto->stessaRiga($m, $riga))) {
                    $this->rifiuta('Questa riga è già entrata, da un altro import o da un’altra decisione: tieni il movimento già presente.');
                }
                $movimento = (new Movimento)->forceFill($import->attributiMovimento($riga, $request->user()->id));
                $movimento->save();
                $this->registraMovimenti($request->user(), collect([$movimento]), 'importato', null);
                Regole::applicaAiNuovi($request->user(), collect([$movimento->refresh()]));
            }
            $conflitto->update(['decisione' => $decisione, 'deciso_da' => $request->user()->id, 'deciso_il' => now()]);
            RegistroModifica::registra($request->user(), $import, 'conflitto_deciso', null, ['conflitto' => $conflitto->id, 'decisione' => $decisione]);
        });

        return redirect()->route('import.mostra', $conflitto->import_estratto_id)->with('ok', 'Decisione registrata.');
    }

    /** @return array{da: CarbonInterface, a: CarbonInterface} il periodo in cui l'app non ha i movimenti che il file dà per presenti */
    private function buco(Conto $conto, ImportEstratto $import): array
    {
        $ultimo = $conto->movimenti()->where('data', '<', $import->periodo_dal->toDateString())->max('data');

        return ['da' => $ultimo ? Carbon::parse($ultimo)->addDay() : $conto->aperto_il, 'a' => $import->periodo_dal];
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

    /** Le anteprime lasciate lì da più di un giorno: l'ADR 0001 prevede il cron solo come chiamata HTTP e non c'è ancora, quindi si puliscono al caricamento. */
    private function scartaAnteprimeVecchie(): void
    {
        foreach (ImportEstratto::where('stato', 'anteprima')->where('created_at', '<', now()->subDay())->pluck('id') as $id) {
            $this->scartaAnteprima($id);
        }
    }

    /** Riletta con il lock: una conferma arrivata nel frattempo non deve perdere il file né l'import. */
    private function scartaAnteprima(int $id): void
    {
        $percorso = DB::transaction(function () use ($id) {
            $attuale = ImportEstratto::lockForUpdate()->find($id);
            if ($attuale?->stato !== 'anteprima') {
                return null;
            }
            $attuale->delete();

            return $attuale->percorso;
        });
        if ($percorso) {
            Storage::disk('local')->delete($percorso);
        }
    }

    /** Mai due fonti per lo stesso periodo: il foglio non ha righe dopo la data di passaggio (#19). */
    private function controllaPassaggio(Conto $conto, Estratto $estratto): void
    {
        if ($conto->importaDalFoglio() && $conto->passaggio_il && $estratto->al() > $conto->passaggio_il->toDateString()) {
            $this->rifiuta('Il foglio ha righe fino al '.date('d/m/Y', strtotime($estratto->al())).', dopo la data di passaggio ('.$conto->passaggio_il->format('d/m/Y').'): da lì la cassa si registra solo nell’app. Togli quelle righe dal foglio.');
        }
    }

    private function rifiuta(string $messaggio): never
    {
        throw ValidationException::withMessages(['estratto' => $messaggio]);
    }
}
