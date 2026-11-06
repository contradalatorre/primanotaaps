<?php

use App\Http\Controllers\AccessoController;
use App\Http\Controllers\RuoliController;
use App\Http\Controllers\UtentiController;
use App\Http\Middleware\VerificaAccountGoogle;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/accesso', [AccessoController::class, 'pagina'])->name('accesso');
    Route::get('/accesso/google', [AccessoController::class, 'vaiAGoogle'])->name('accesso.google');
    Route::get('/accesso/google/ritorno', [AccessoController::class, 'ritornoDaGoogle'])->name('accesso.ritorno');
});

Route::middleware(['auth', VerificaAccountGoogle::class])->group(function () {
    Route::view('/', 'home')->name('home');
    Route::post('/esci', [AccessoController::class, 'esci'])->name('esci');

    // Gestione di utenti, ruoli e assegnazioni (#35). L'azione delicata con riautenticazione arriva con #36.
    Route::middleware('can:gestire_utenti')->group(function () {
        Route::get('/utenti', [UtentiController::class, 'index'])->name('utenti');
        Route::get('/utenti/autorizzati.pdf', [UtentiController::class, 'elencoPdf'])->name('utenti.pdf');
        Route::post('/utenti/esterni', [UtentiController::class, 'aggiungiEsterno'])->name('utenti.esterno');
        Route::post('/utenti/{utente}/assegnazioni', [UtentiController::class, 'assegna'])->name('utenti.assegna');
        Route::delete('/assegnazioni/{assegnazione}', [UtentiController::class, 'revoca'])->name('assegnazioni.revoca');

        Route::get('/ruoli', [RuoliController::class, 'index'])->name('ruoli');
        Route::get('/ruoli/nuovo', [RuoliController::class, 'nuovo'])->name('ruoli.nuovo');
        Route::post('/ruoli', [RuoliController::class, 'crea'])->name('ruoli.crea');
        Route::get('/ruoli/{ruolo}', [RuoliController::class, 'modifica'])->name('ruoli.modifica');
        Route::put('/ruoli/{ruolo}', [RuoliController::class, 'salva'])->name('ruoli.salva');
    });
});
