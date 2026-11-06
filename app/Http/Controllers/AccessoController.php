<?php

namespace App\Http\Controllers;

use App\Models\Assegnazione;
use App\Models\Ruolo;
use App\Models\User;
use App\Permesso;
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
        return view('accesso', ['dominio' => $this->dominio(), 'emergenza' => EmergenzaController::attivo()]);
    }

    /** Chi è già dentro ci passa solo per rifare l'accesso (`?riautentica=1`, vedi `riautentica()`). */
    public function vaiAGoogle(Request $request)
    {
        $riautentica = Auth::check() && $request->boolean('riautentica');
        if (Auth::check() && ! $riautentica) {
            return redirect()->route('home');
        }

        // `hd` e `prompt` guidano solo la scelta dell'account; il controllo vero è al ritorno. Gli esterni
        // entrano con un account personale, che `hd` nasconderebbe: per loro la scelta resta libera.
        // `offline` + `consent`: Google rilascia il refresh token a ogni accesso, non solo al primo
        // (serve a VerificaAccountGoogle per accorgersi di un account sospeso).
        $parametri = ['prompt' => 'select_account consent', 'access_type' => 'offline'];
        if ($riautentica) {
            // Si ripassa dal flusso OAuth scegliendo l'account (`login` non è un valore documentato da Google);
            // il controllo che sia lo stesso è sul `google_id` al ritorno.
            $parametri = ['prompt' => 'select_account', 'login_hint' => Auth::user()->email];
            $request->session()->put('riautenticazione', true);
        }
        if (! $request->boolean('esterno') && ! ($riautentica && Auth::user()->esterno)) {
            $parametri['hd'] = $this->dominio();
        }

        return Socialite::driver('google')->with($parametri)->redirect();
    }

    /** Pagina delle azioni delicate: chi ha l'ultimo accesso più vecchio di un'ora lo rifà qui (`password.confirm`). */
    public function riautentica()
    {
        return view('riautentica');
    }

    public function ritornoDaGoogle(Request $request): RedirectResponse
    {
        $riautentica = Auth::check();
        if ($riautentica && ! $request->session()->pull('riautenticazione')) {
            return redirect()->route('home');
        }
        $rotta = $riautentica ? 'riautentica' : 'accesso';

        if ($request->query('error') === 'access_denied') {
            return $this->rifiuta('Accesso annullato. Per entrare serve confermare l’account su Google: riprova.', $rotta);
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            // Sessione scaduta o doppio clic: non c'entra l'account.
            return $this->rifiuta('La richiesta di accesso è scaduta. Riprova a entrare con Google.', $rotta);
        } catch (Throwable $e) {
            // Google non rilascia l'accesso a un account sospeso o eliminato; l'app resta in piedi.
            report($e);

            return $this->rifiuta('Google non ha confermato l’accesso. Se il tuo account del Workspace è stato sospeso o eliminato, non puoi più entrare: rivolgiti a un Amministratore.', $rotta);
        }

        if ($riautentica) {
            // Rifare l'accesso con un altro account non conferma chi è già dentro.
            if ($google->getId() !== Auth::user()->google_id) {
                return $this->rifiuta('Hai rifatto l’accesso con un altro account Google: serve lo stesso con cui sei entrato.', $rotta);
            }
            $request->session()->passwordConfirmed();

            return redirect()->intended(route('home'));
        }

        if ($this->delWorkspace($google)) {
            // Mai ereditare la riga di un altro: un google_id nuovo (account ricreato) o un'email aggiornata
            // già in uso da un'altra riga li risolve un Amministratore.
            if (User::where('email', $google->getEmail())->where(fn ($q) => $q->whereNull('google_id')->orWhere('google_id', '!=', $google->getId()))->exists()) {
                return $this->rifiuta('Questo indirizzo risulta già associato a un altro accesso: rivolgiti a un Amministratore.');
            }

            $utente = User::firstWhere('google_id', $google->getId()) ?? new User;
        } else {
            // Fuori dal Workspace entra solo un esterno già aggiunto da un Amministratore, con la nomina in corso.
            $utente = $this->esternoAggiunto($google);
            if (! $utente) {
                return $this->rifiuta('Questo account non fa parte del Workspace dell’associazione. Entra con il tuo account @'.$this->dominio().', oppure chiedi a un Amministratore di aggiungerti come esterno.');
            }
            if (! $utente->assegnazioniAttive()->exists()) {
                return $this->rifiuta('La tua nomina come esterno è scaduta: rivolgiti a un Amministratore.');
            }
        }

        // Senza refresh token non si potrà più chiedere a Google se l'account è valido: meglio non far entrare.
        $refreshToken = $google->refreshToken ?: $utente->google_refresh_token;
        if (! $refreshToken) {
            return $this->rifiuta('Google non ha completato l’autorizzazione. Riprova a entrare e conferma l’accesso.');
        }

        // Il nome di un esterno è quello della nomina, non quello del profilo Google personale.
        $utente->fill($utente->esterno ? ['google_id' => $google->getId()] : [
            'google_id' => $google->getId(),
            'name' => $google->getName() ?: $google->getEmail(),
            'email' => $google->getEmail(),
        ])->forceFill([
            'google_refresh_token' => $refreshToken,
            'google_verificato_il' => now(),
        ])->save();

        $this->assegnaAmministratoreIniziale($utente);

        Auth::login($utente);
        $request->session()->regenerate();
        $request->session()->passwordConfirmed(); // l'accesso è l'ultima autenticazione per le azioni delicate

        return redirect()->intended(route('home'));
    }

    public function esci(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('accesso');
    }

    /** L'esterno già riconosciuto dal google_id, oppure quello appena aggiunto (senza google_id) con questo indirizzo verificato. */
    private function esternoAggiunto(GoogleUser $google): ?User
    {
        $esterni = User::where('esterno', true);

        return (clone $esterni)->firstWhere('google_id', $google->getId())
            ?? (filter_var($google->user['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
                ? $esterni->whereNull('google_id')->firstWhere('email', $google->getEmail())
                : null);
    }

    /**
     * Avvio a freddo: vedi config/prima_nota.php. Vale una volta sola, finché nessuno ha mai avuto un ruolo con la
     * gestione utenti (scadenza ignorata). Se poi gli Amministratori scadono tutti, il recupero è dell'account di
     * emergenza (#36), che lascia traccia nel registro e avvisa gli Amministratori: non di questo elenco, in silenzio.
     */
    private function assegnaAmministratoreIniziale(User $utente): void
    {
        if ($utente->esterno || ! in_array(strtolower($utente->email), config('prima_nota.amministratori_iniziali'), true)) {
            return;
        }

        // Il ruolo si sceglie dal permesso, non dal nome: l'Amministratore può averlo rinominato.
        $conGestioneUtenti = fn ($ruoli) => $ruoli->whereJsonContains('permessi', Permesso::GestireUtenti->value);
        if (Assegnazione::whereHas('ruolo', $conGestioneUtenti)->exists()) {
            return;
        }

        $ruolo = $conGestioneUtenti(Ruolo::query())->orderBy('id')->first();
        $ruolo && $utente->assegnazioni()->create(['ruolo_id' => $ruolo->id]);
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

    private function rifiuta(string $messaggio, string $rotta = 'accesso'): RedirectResponse
    {
        return redirect()->route($rotta)->with('errore', $messaggio);
    }
}
