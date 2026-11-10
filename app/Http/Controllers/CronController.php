<?php

namespace App\Http\Controllers;

use App\CompitiPeriodici;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** La chiamata del cron HTTP di Aruba (ADR 0001), al massimo ogni 10 minuti. Fuori da sessione e accesso: la difesa è il token. */
class CronController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('app.token_cron');
        $ricevuto = $request->query('token');

        if ($token === '' || ! is_string($ricevuto) || ! hash_equals($token, $ricevuto)) {
            return response()->json(['errore' => 'Token non valido.'], 403);
        }

        return response()->json(['avviati' => CompitiPeriodici::avvia()]);
    }
}
