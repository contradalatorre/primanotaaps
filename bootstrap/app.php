<?php

use App\Http\Middleware\ProvaMiddleware;
use App\Http\Middleware\VerificaAccountGoogle;
use App\RegistroErrori;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('accesso'));
        $middleware->redirectUsersTo(fn () => route('home'));
        // La prova (#71, ADR 0003) cambia la connessione prima che le rotte cerchino i modelli nel database, e dopo il
        // controllo dell'account Google, che lavora sul database vero: l'ordine è Verifica, Prova, poi i parametri delle rotte.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: VerificaAccountGoogle::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ProvaMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Registro degli errori (#68): un'eccezione non gestita che finisce in una risposta 5xx è un errore grave. Le
        // eccezioni HTTP volute (404, 503 della prova non disponibile) no; e `report()` dei degradi già gestiti nemmeno.
        $exceptions->respond(function (Response $risposta, Throwable $e) {
            if ($risposta->getStatusCode() >= 500 && ! $e instanceof HttpExceptionInterface) {
                RegistroErrori::registra('app', $e, grave: true);
            }

            return $risposta;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
