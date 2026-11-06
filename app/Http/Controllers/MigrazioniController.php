<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Aggiorna lo schema del database senza riga di comando (ADR 0001: su Aruba Easy non c'è SSH). */
class MigrazioniController extends Controller
{
    public function pagina(Request $request)
    {
        return view('migrazioni', ['esito' => $request->session()->get('esito_migrazioni')]);
    }

    public function avvia(Request $request): RedirectResponse
    {
        // --isolated: un doppio clic non fa partire due migrazioni insieme (e non duplica i dati che inseriscono).
        return redirect()->route('migrazioni')->with('esito_migrazioni', $this->esegui(['--isolated' => true])['esito']);
    }

    /**
     * Su un database vuoto non esistono né `users` né `sessions`: nessun accesso è possibile, quindi la
     * pagina protetta non si può usare. Questa chiamata, fuori da sessione e CSRF, vale solo finché la
     * tabella `migrations` non esiste o è vuota, e solo con il token dei segreti del repo.
     * Se le migrazioni falliscono risponde 424: il workflow non lo accetta e `curl --retry` non lo ripete
     * (ripete solo 408, 429 e 5xx), così un'installazione a metà non diventa un 409 "già installato".
     */
    public function primaInstallazione(Request $request): JsonResponse
    {
        $token = (string) config('app.token_migrazioni');

        if ($token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['errore' => 'Token non valido.'], 403);
        }

        if (Schema::hasTable('migrations') && DB::table('migrations')->exists()) {
            return response()->json(['errore' => 'Il database è già installato: usa la pagina Migrazioni.'], 409);
        }

        $risultato = $this->esegui();

        return response()->json(['esito' => $risultato['esito']], $risultato['riuscite'] ? 200 : 424);
    }

    /** @return array{riuscite: bool, esito: string} */
    private function esegui(array $opzioni = []): array
    {
        try {
            Artisan::call('migrate', ['--force' => true] + $opzioni);

            return ['riuscite' => true, 'esito' => trim(Artisan::output())];
        } catch (Throwable $e) {
            report($e);

            // Il messaggio dell'eccezione può contenere host e utente del database: resta nel log.
            return ['riuscite' => false, 'esito' => 'Le migrazioni non sono andate a buon fine. Il dettaglio è nel log dell’app.'];
        }
    }
}
