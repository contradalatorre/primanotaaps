<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as GoogleUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class AccessoController extends Controller
{
    public function pagina()
    {
        return view('accesso', ['dominio' => $this->dominio()]);
    }

    public function vaiAGoogle()
    {
        // `hd` e `prompt` guidano solo la scelta dell'account; il controllo vero è al ritorno.
        // `offline` + `consent`: Google rilascia il refresh token a ogni accesso, non solo al primo
        // (serve a VerificaAccountGoogle per accorgersi di un account sospeso).
        return Socialite::driver('google')
            ->with(['hd' => $this->dominio(), 'prompt' => 'select_account consent', 'access_type' => 'offline'])
            ->redirect();
    }

    public function ritornoDaGoogle(Request $request): RedirectResponse
    {
        if ($request->query('error') === 'access_denied') {
            return $this->rifiuta('Accesso annullato. Per entrare serve confermare l’account su Google: riprova.');
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            // Sessione scaduta o doppio clic: non c'entra l'account.
            return $this->rifiuta('La richiesta di accesso è scaduta. Riprova a entrare con Google.');
        } catch (Throwable $e) {
            // Google non rilascia l'accesso a un account sospeso o eliminato; l'app resta in piedi.
            report($e);

            return $this->rifiuta('Google non ha confermato l’accesso. Se il tuo account del Workspace è stato sospeso o eliminato, non puoi più entrare: rivolgiti a un Amministratore.');
        }

        if (! $this->delWorkspace($google)) {
            return $this->rifiuta('Questo account non fa parte del Workspace dell’associazione. Entra con il tuo account @'.$this->dominio().'.');
        }

        // Mai ereditare la riga di un altro: un google_id nuovo (account ricreato) o un'email aggiornata
        // già in uso da un'altra riga li risolve un Amministratore.
        if (User::where('email', $google->getEmail())->where('google_id', '!=', $google->getId())->exists()) {
            return $this->rifiuta('Questo indirizzo risulta già associato a un altro accesso: rivolgiti a un Amministratore.');
        }

        $utente = User::firstWhere('google_id', $google->getId()) ?? new User;

        // Senza refresh token non si potrà più chiedere a Google se l'account è valido: meglio non far entrare.
        $refreshToken = $google->refreshToken ?: $utente->google_refresh_token;
        if (! $refreshToken) {
            return $this->rifiuta('Google non ha completato l’autorizzazione. Riprova a entrare e conferma l’accesso.');
        }

        $utente->fill([
            'google_id' => $google->getId(),
            'name' => $google->getName() ?: $google->getEmail(),
            'email' => $google->getEmail(),
        ])->forceFill([
            'google_refresh_token' => $refreshToken,
            'google_verificato_il' => now(),
        ])->save();

        Auth::login($utente);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function esci(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('accesso');
    }

    private function dominio(): ?string
    {
        return config('services.google.workspace_domain');
    }

    /** Dominio del Workspace (`hd`), email verificata e indirizzo dello stesso dominio. */
    private function delWorkspace(GoogleUser $google): bool
    {
        $dominio = strtolower((string) $this->dominio());
        $dati = $google->user;

        return $dominio !== ''
            && strtolower((string) ($dati['hd'] ?? '')) === $dominio
            && filter_var($dati['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && str_ends_with(strtolower((string) $google->getEmail()), '@'.$dominio);
    }

    private function rifiuta(string $messaggio): RedirectResponse
    {
        return redirect()->route('accesso')->with('errore', $messaggio);
    }
}
