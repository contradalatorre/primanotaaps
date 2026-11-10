<?php

namespace App;

use App\Mail\ErroreGrave;
use App\Models\Errore;
use App\Models\User;
use App\Onboarding\Prova;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Il registro degli errori (#68). Un errore è **grave** se ferma qualcosa che l'Amministratore deve sapere subito: un
 * backup fallito, un compito periodico che si ferma, un'eccezione non gestita in una richiesta. I gravi avvisano gli
 * Amministratori per email; un errore uguale e ancora aperto si conta senza ripetersi e riavvisa al massimo una volta
 * al giorno. Non lancia mai (è chiamato mentre qualcosa va già male) e non usa `report()`: non si riavvia da sé.
 */
final class RegistroErrori
{
    /**
     * Di un'eccezione si registrano classe e punto, non il messaggio: può portare dati (una query con i suoi valori)
     * e finirebbe nell'email. Il dettaglio resta nel log. Le stringhe le scrive il chiamante, quindi sono sicure.
     */
    public static function registra(string $origine, Throwable|string $errore, bool $grave = false): void
    {
        try {
            $messaggio = is_string($errore) ? $errore : get_class($errore).' in '.basename($errore->getFile()).':'.$errore->getLine();
            $riga = self::conta($origine, mb_substr($messaggio, 0, 500), $grave);
            if ($riga && $grave) {
                self::avvisa($riga);
            }
        } catch (Throwable $e) {
            Log::error('Registro degli errori non raggiungibile: '.$e::class.' in '.basename($e->getFile()).':'.$e->getLine());
        }
    }

    public static function risolvi(Errore $errore, User $da): void
    {
        $errore->update(['risolto_il' => now(), 'risolto_da' => $da->id, 'impronta' => null]);
    }

    /** @return Errore|null la riga se va avvisato qualcuno (nuova, o già aperta ma non avvisata da un giorno) */
    private static function conta(string $origine, string $messaggio, bool $grave): ?Errore
    {
        $adesso = now();
        $impronta = sha1($origine."\n".$messaggio);

        return Errore::query()->getConnection()->transaction(function () use ($origine, $messaggio, $grave, $adesso, $impronta) {
            // Prima inserisce (senza lock su righe che non ci sono: su MySQL due prime chiamate si bloccherebbero a vicenda), poi blocca e legge.
            $nuovo = Errore::insertOrIgnore([
                'origine' => $origine, 'grave' => $grave, 'messaggio' => $messaggio, 'impronta' => $impronta, 'primo_il' => $adesso, 'ultimo_il' => $adesso,
            ]) === 1;
            $riga = Errore::where('impronta', $impronta)->lockForUpdate()->first();
            if (! $nuovo) {
                $riga->volte++;
                $riga->ultimo_il = $adesso;
            }
            $daAvvisare = $grave && ($riga->avvisato_il === null || $riga->avvisato_il->lte($adesso->copy()->subDay()));
            if ($daAvvisare) {
                $riga->avvisato_il = $adesso;
            }
            $riga->save();

            return $daAvvisare ? $riga : null;
        });
    }

    private static function avvisa(Errore $riga): void
    {
        // In prova (ADR 0003) niente email vere, e gli utenti sarebbero quelli dello scenario.
        if (app(Prova::class)->attiva()) {
            return;
        }
        $destinatari = User::amministratoriAttivi()->pluck('email')->all();
        if ($destinatari !== []) {
            Mail::to($destinatari)->send(new ErroreGrave($riga->origine, $riga->messaggio, $riga->volte, $riga->ultimo_il));
        }
    }
}
