<?php

use App\Http\Controllers\AccessoController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/accesso', [AccessoController::class, 'pagina'])->name('accesso');
    Route::get('/accesso/google', [AccessoController::class, 'vaiAGoogle'])->name('accesso.google');
    Route::get('/accesso/google/ritorno', [AccessoController::class, 'ritornoDaGoogle'])->name('accesso.ritorno');
});

Route::middleware('auth')->group(function () {
    Route::view('/', 'home')->name('home');
    Route::post('/esci', [AccessoController::class, 'esci'])->name('esci');
});
