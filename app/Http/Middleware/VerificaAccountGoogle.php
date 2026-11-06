<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * La sessione dura 30 giorni e si rinnova a ogni richiesta: senza questo controllo un account
 * sospeso o eliminato nel Workspace resterebbe dentro. Al più una volta l'ora si chiede a Google
 * di rinnovare il token; `invalid_grant` vuol dire che l'account non è più valido.
 */
class VerificaAccountGoogle
{
    public function handle(Request $request, Closure $next): Response
    {
        $utente = $request->user();

        if ($utente->google_verificato_il?->gt(now()->subHour())) {
            return $next($request);
        }

        if ($this->googleHaRevocato($utente->google_refresh_token)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('accesso')
                ->with('errore', 'Il tuo account del Workspace non è più valido (sospeso o eliminato): rivolgiti a un Amministratore.');
        }

        // Si segna anche se Google non ha risposto: con Google giù non si aspetta il timeout a ogni richiesta.
        $utente->forceFill(['google_verificato_il' => now()])->save();

        return $next($request);
    }

    /** Solo `invalid_grant` o l'assenza del token revocano l'accesso; errori di rete o di Google lasciano la sessione. */
    private function googleHaRevocato(?string $refreshToken): bool
    {
        if (! $refreshToken) {
            return true;
        }

        try {
            $risposta = Http::asForm()->timeout(5)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);
        } catch (ConnectionException $e) {
            report($e);

            return false;
        }

        if ($risposta->status() === 400 && $risposta->json('error') === 'invalid_grant') {
            return true;
        }

        if (! $risposta->successful()) {
            report(new RuntimeException('Google ha risposto '.$risposta->status().' al rinnovo del token.'));
        }

        return false;
    }
}
