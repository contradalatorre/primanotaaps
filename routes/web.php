<?php

use App\Http\Controllers\AccessoController;
use App\Http\Controllers\EmergenzaController;
use App\Http\Controllers\MigrazioniController;
use App\Http\Controllers\RuoliController;
use App\Http\Controllers\UtentiController;
use App\Http\Middleware\VerificaAccountGoogle;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::middleware('guest')->group(function () {
    Route::get('/accesso', [AccessoController::class, 'pagina'])->name('accesso');
    Route::get('/accesso/emergenza', [EmergenzaController::class, 'pagina'])->name('accesso.emergenza');
    // 5 tentativi ogni 10 minuti per indirizzo: password lunga e TOTP reggono, ma il registro non si riempia di prove.
    Route::post('/accesso/emergenza', [EmergenzaController::class, 'entra'])->middleware('throttle:5,10');
});

// Fuori da `guest`: chi è già dentro ci passa per rifare l'accesso (#36); il controller rimanda a casa chi arriva
// senza averlo chiesto.
Route::get('/accesso/google', [AccessoController::class, 'vaiAGoogle'])->name('accesso.google');
Route::get('/accesso/google/ritorno', [AccessoController::class, 'ritornoDaGoogle'])->name('accesso.ritorno');

Route::middleware(['auth', VerificaAccountGoogle::class])->group(function () {
    Route::view('/', 'home')->name('home');
    Route::post('/esci', [AccessoController::class, 'esci'])->name('esci');
    Route::get('/riautentica', [AccessoController::class, 'riautentica'])->name('riautentica');

    // Gestione di utenti, ruoli e assegnazioni (#35). Le scritture sono azioni delicate (#36): `password.confirm`
    // (timeout in config/auth.php, un'ora) manda a /riautentica chi ha l'ultimo accesso più vecchio.
    Route::middleware('can:gestire_utenti')->group(function () {
        Route::get('/utenti', [UtentiController::class, 'index'])->name('utenti');
        Route::get('/utenti/autorizzati.pdf', [UtentiController::class, 'elencoPdf'])->name('utenti.pdf');
        Route::get('/ruoli', [RuoliController::class, 'index'])->name('ruoli');
        Route::get('/ruoli/nuovo', [RuoliController::class, 'nuovo'])->name('ruoli.nuovo');
        Route::get('/ruoli/{ruolo}', [RuoliController::class, 'modifica'])->name('ruoli.modifica');

        Route::middleware('password.confirm:riautentica')->group(function () {
            Route::post('/utenti/esterni', [UtentiController::class, 'aggiungiEsterno'])->name('utenti.esterno');
            Route::post('/utenti/{utente}/assegnazioni', [UtentiController::class, 'assegna'])->name('utenti.assegna');
            Route::delete('/assegnazioni/{assegnazione}', [UtentiController::class, 'revoca'])->name('assegnazioni.revoca');
            Route::post('/ruoli', [RuoliController::class, 'crea'])->name('ruoli.crea');
            Route::put('/ruoli/{ruolo}', [RuoliController::class, 'salva'])->name('ruoli.salva');
        });

        // Aggiornamento dello schema senza riga di comando (ADR 0001), riservato a chi gestisce gli utenti (#37).
        Route::get('/manutenzione/migrazioni', [MigrazioniController::class, 'pagina'])->name('migrazioni');
        Route::post('/manutenzione/migrazioni', [MigrazioniController::class, 'avvia'])->name('migrazioni.avvia');
    });
});

// Prima installazione: il database è vuoto, quindi niente sessione (la tabella `sessions` non c'è) né CSRF.
Route::post('/manutenzione/migrazioni/prima-installazione', [MigrazioniController::class, 'primaInstallazione'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);
