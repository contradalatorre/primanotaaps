<?php

namespace App\Http\Middleware;

use App\Onboarding\Catalogo;
use App\Onboarding\Prova;
use App\Onboarding\Scenario;
use App\Onboarding\Svolgimento;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Se l'utente ha una missione in corso (sessione), la richiesta si svolge nel suo spazio di prova (ADR 0003): da qui al
 * ritorno il database predefinito è quello di prova e non parte nessuna chiamata esterna. Va dopo `auth` e
 * `VerificaAccountGoogle`, che lavorano sul database vero. Prima di disegnare la pagina i passi pratici già fatti si
 * completano da soli e Merlo riceve il passo corrente.
 */
class ProvaMiddleware
{
    public function __construct(private readonly Prova $prova, private readonly Svolgimento $svolgimento) {}

    public function handle(Request $request, Closure $next): Response
    {
        $utente = $request->user();
        // L'uscita dall'app (come la sessione) lavora sul database vero: la prova non deve toccare il token dell'utente.
        if ($request->routeIs('esci')) {
            return $next($request);
        }
        $missione =($id = $request->session()->get('prova.missione')) ? Catalogo::predefinito()->missione($id) : null;
        $avanzamento = $missione ? $this->svolgimento->avanzamento($utente, $missione) : null;
        if (! $missione || $avanzamento?->stato !== 'in_corso') {
            $request->session()->forget('prova.missione');

            return $next($request);
        }

        $this->prova->registraUso($utente); // sul database vero: le assegnazioni in prova sono quelle dello scenario
        try {
            $this->prova->entra($utente, $missione);
        } catch (RuntimeException $e) {
            $request->session()->forget('prova.missione');
            abort(503, $e->getMessage());
        }

        try {
            if ($this->svolgimento->sincronizza($missione, $avanzamento)) {
                $request->session()->forget('prova.missione');

                return redirect()->route('impara.timbri', ['nuovo' => $missione->id]);
            }
            // L'AI preregistrata dello scenario propone, una volta sola, per i movimenti appena importati.
            Scenario::applicaProposte($missione->scenario);
            $request->attributes->set('guida', $this->svolgimento->guida($missione, $avanzamento, $request->route()?->getName()));

            return $next($request);
        } finally {
            $this->prova->esci();
        }
    }
}
