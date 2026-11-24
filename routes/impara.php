<?php

use App\Http\Controllers\ImparaController;
use App\Http\Controllers\ProvaController;
use App\Http\Middleware\ProvaMiddleware;
use App\Http\Middleware\VerificaAccountGoogle;
use Illuminate\Support\Facades\Route;

// Onboarding (#71). Questo gruppo NON ha `ProvaMiddleware`: Impara, i timbri e l'avvio lavorano sul database vero.
Route::middleware(['auth', VerificaAccountGoogle::class])->group(function () {
    Route::get('/impara', [ImparaController::class, 'indice'])->name('impara');
    Route::get('/impara/timbri', [ImparaController::class, 'timbri'])->name('impara.timbri');
    Route::get('/impara/avanzamento', [ImparaController::class, 'avanzamento'])->middleware('can:gestire_utenti')->name('impara.avanzamento');
    Route::post('/impara/invito', [ImparaController::class, 'invito'])->name('impara.invito');
    Route::post('/impara/{missione}/avvia', [ImparaController::class, 'avvia'])->name('impara.avvia');
    Route::post('/impara/{missione}/riprendi', [ImparaController::class, 'riprendi'])->name('impara.riprendi');

    // Le azioni di Merlo dentro la prova: queste sì lavorano sullo spazio di prova.
    Route::middleware(ProvaMiddleware::class)->prefix('prova')->name('prova.')->group(function () {
        Route::post('/avanti', [ProvaController::class, 'avanti'])->name('avanti');
        Route::post('/salta', [ProvaController::class, 'salta'])->name('salta');
        Route::post('/esci', [ProvaController::class, 'esci'])->name('esci');
        Route::post('/estratto/{conto}', [ProvaController::class, 'estratto'])->middleware('can:importare_e_confermare')->name('estratto');
    });
});
