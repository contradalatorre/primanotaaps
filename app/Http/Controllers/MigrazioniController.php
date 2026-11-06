<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
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
        return redirect()->route('migrazioni')->with('esito_migrazioni', $this->esegui());
    }

    /**
     * Su un database vuoto non esistono né `users` né `sessions`: nessun accesso è possibile, quindi la
     * pagina protetta non si può usare. Questa chiamata, fuori da sessione e CSRF, vale solo finché la
     * tabella `migrations` non esiste, e solo con il token dei segreti del repo.
     */
    public function primaInstallazione(Request $request): JsonResponse
    {
        $token = (string) config('app.token_migrazioni');

        if ($token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['errore' => 'Token non valido.'], 403);
        }

        if (Schema::hasTable('migrations')) {
            return response()->json(['errore' => 'Il database è già installato: usa la pagina Migrazioni.'], 409);
        }

        return response()->json(['esito' => $this->esegui()]);
    }

    private function esegui(): string
    {
        try {
            Artisan::call('migrate', ['--force' => true]);

            return trim(Artisan::output());
        } catch (Throwable $e) {
            report($e);

            // Il messaggio dell'eccezione può contenere host e utente del database: resta nel log.
            return 'Le migrazioni non sono andate a buon fine. Il dettaglio è nel log dell’app.';
        }
    }
}
