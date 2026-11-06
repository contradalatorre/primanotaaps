<?php

use App\Http\Controllers\AccessoController;
use App\Http\Controllers\MigrazioniController;
use App\Http\Middleware\VerificaAccountGoogle;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::middleware('guest')->group(function () {
    Route::get('/accesso', [AccessoController::class, 'pagina'])->name('accesso');
    Route::get('/accesso/google', [AccessoController::class, 'vaiAGoogle'])->name('accesso.google');
    Route::get('/accesso/google/ritorno', [AccessoController::class, 'ritornoDaGoogle'])->name('accesso.ritorno');
});

Route::middleware(['auth', VerificaAccountGoogle::class])->group(function () {
    Route::view('/', 'home')->name('home');
    Route::post('/esci', [AccessoController::class, 'esci'])->name('esci');

    Route::middleware('can:avviare-migrazioni')->group(function () {
        Route::get('/manutenzione/migrazioni', [MigrazioniController::class, 'pagina'])->name('migrazioni');
        Route::post('/manutenzione/migrazioni', [MigrazioniController::class, 'avvia'])->name('migrazioni.avvia');
    });
});

// Prima installazione: il database è vuoto, quindi niente sessione (la tabella `sessions` non c'è) né CSRF.
Route::post('/manutenzione/migrazioni/prima-installazione', [MigrazioniController::class, 'primaInstallazione'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);
