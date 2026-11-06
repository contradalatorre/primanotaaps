<?php

namespace App\Http\Controllers;

use App\Mail\AccessoDiEmergenza;
use App\Models\RegistroAccesso;
use App\Models\User;
use App\Permesso;
use App\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * L'account di emergenza (#16, #36): uno solo, locale, con password lunga e TOTP; entra chi li ha, quando Google
 * non funziona o non resta nessun Amministratore. L'app non può verificare né l'una né l'altra cosa: il controllo
 * è che ogni uso finisce nel registro degli accessi e avvisa gli Amministratori.
 */
class EmergenzaController extends Controller
{
    /** Senza password e segreto TOTP in configurazione l'accesso di emergenza non esiste (un segreto vuoto darebbe codici prevedibili). */
    public static function attivo(): bool
    {
        return filled(config('prima_nota.emergenza.password_hash')) && filled(config('prima_nota.emergenza.totp_secret'));
    }

    public function pagina()
    {
        abort_unless(self::attivo(), 404);

        return view('emergenza');
    }

    public function entra(Request $request): RedirectResponse
    {
        abort_unless(self::attivo(), 404);

        $passwordGiusta = Hash::check((string) $request->input('password'), config('prima_nota.emergenza.password_hash'));
        $passo = Totp::verifica(config('prima_nota.emergenza.totp_secret'), (string) $request->input('codice'), now()->unix());

        // Un codice già usato (o più vecchio dell'ultimo usato) non vale: chi lo ha sbirciato non può riusarlo.
        if (! $passwordGiusta || $passo === null || $passo <= Cache::get('emergenza.ultimo_passo', 0)) {
            RegistroAccesso::registra('emergenza.fallita', $request);

            return redirect()->route('accesso.emergenza')->with('errore', 'Password o codice non validi.');
        }
        Cache::forever('emergenza.ultimo_passo', $passo);

        $utente = User::firstWhere('emergenza', true) ?? tap((new User)->forceFill([
            'name' => 'Account di emergenza',
            'email' => 'emergenza@account-locale.invalid', // fuori dal Workspace: nessun accesso Google può reclamarlo
            'emergenza' => true,
        ]))->save();

        Auth::login($utente);
        $request->session()->regenerate();
        $request->session()->passwordConfirmed(); // vedi VerificaAccountGoogle: la sessione di emergenza dura un'ora

        // Prima il registro, poi la posta: quando Google è fuori uso la posta del Workspace spesso lo è con lui.
        RegistroAccesso::registra('emergenza.accesso', $request, $utente);
        $this->avvisaAmministratori($request);

        return redirect()->route('home');
    }

    private function avvisaAmministratori(Request $request): void
    {
        $destinatari = User::amministratoriAttivi()->pluck('email');
        if ($destinatari->isEmpty()) {
            // Nessun Amministratore attivo è proprio il caso del recupero: si avvisa chi lo era e ha la scadenza passata.
            $destinatari = User::where('esterno', false)
                ->whereHas('assegnazioni.ruolo', fn ($r) => $r->whereJsonContains('permessi', Permesso::GestireUtenti->value))
                ->pluck('email');
        }
        if ($destinatari->isEmpty()) {
            return;
        }

        try {
            Mail::to($destinatari->all())->send(new AccessoDiEmergenza($request->ip(), now()));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
