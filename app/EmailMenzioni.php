<?php

namespace App;

use App\Mail\MenzioniDaVedere;
use App\Models\Menzione;
use App\Models\User;
use App\Onboarding\Prova;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Le email delle menzioni (#46), secondo la preferenza di ognuno: `riepilogo` (alle 8, se c'è qualcosa da vedere),
 * `immediata` (appena scritta la nota) o `nessuna`. Partono dalla posta del Workspace e non portano mai il testo della
 * nota. La posta giù non ferma niente: l'errore va nel log, la nota e gli altri destinatari restano.
 */
final class EmailMenzioni
{
    /** Il riepilogo delle 8: una email a chi ha menzioni da vedere. @return int quante ne ha mandate */
    public static function riepilogo(): int
    {
        $inviate = 0;
        foreach (self::lettori('riepilogo') as $utente) {
            $inviate += self::invia($utente, Menzione::daVedere($utente), false);
        }

        return $inviate;
    }

    /** Subito dopo la nota: solo le menzioni appena create, a chi ha scelto l'email immediata. */
    public static function immediate(Collection $nuove): void
    {
        // In prova (ADR 0003) niente email vere; e uno spazio nato prima di #46 non ha ancora `email_menzioni`.
        if ($nuove->isEmpty() || app(Prova::class)->attiva()) {
            return;
        }
        foreach (self::lettori('immediata') as $utente) {
            self::invia($utente, Menzione::daVedere($utente)->whereIn('id', $nuove->pluck('id')->all()), true);
        }
    }

    /** Chi ha quella preferenza e legge ancora le note: senza, `daVedere` troverebbe comunque le menzioni dirette. */
    private static function lettori(string $preferenza): Collection
    {
        return User::where('emergenza', false)->where('email_menzioni', $preferenza)->get()
            ->filter(fn (User $u) => $u->puo(Permesso::Vedere) && $u->puo(Permesso::Note));
    }

    private static function invia(User $utente, Collection $menzioni, bool $immediata): int
    {
        $menzioni = $menzioni->unique('nota_id'); // @Maria e @ruolo di Maria nella stessa nota: una riga
        if ($menzioni->isEmpty()) {
            return 0;
        }
        try {
            Mail::to($utente->email)->send(new MenzioniDaVedere($menzioni, $immediata));

            return 1;
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }
}
