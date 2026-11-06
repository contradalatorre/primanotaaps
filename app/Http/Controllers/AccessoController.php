<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as GoogleUser;
use Laravel\Socialite\Facades\Socialite;
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
        return Socialite::driver('google')
            ->with(['hd' => $this->dominio(), 'prompt' => 'select_account'])
            ->redirect();
    }

    public function ritornoDaGoogle(Request $request): RedirectResponse
    {
        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            // Google non rilascia l'accesso a un account sospeso o eliminato; l'app resta in piedi.
            report($e);

            return $this->rifiuta('Google non ha confermato l’accesso. Se il tuo account del Workspace è stato sospeso o eliminato, non puoi più entrare: rivolgiti a un Amministratore.');
        }

        if (! $this->delWorkspace($google)) {
            return $this->rifiuta('Questo account non fa parte del Workspace dell’associazione. Entra con il tuo account @'.$this->dominio().'.');
        }

        // Un account ricreato nel Workspace con la stessa email ha un altro id Google: è la stessa persona.
        $utente = User::firstWhere('google_id', $google->getId())
            ?? User::firstWhere('email', $google->getEmail())
            ?? new User;
        $utente->fill([
            'google_id' => $google->getId(),
            'name' => $google->getName() ?: $google->getEmail(),
            'email' => $google->getEmail(),
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
