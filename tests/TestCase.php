<?php

namespace Tests;

use App\Models\Assegnazione;
use App\Models\Ruolo;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Gli stili si compilano a parte (npm run build, in CI): i test HTTP non ne hanno bisogno.
        $this->withoutVite();

        // Nessuna chiamata a servizi esterni nei test (#88): ogni richiesta HTTP va simulata con `Http::fake`.
        // Il token di Google è sempre valido: dopo uno spostamento dell'orologio `VerificaAccountGoogle` lo chiede, anche per
        // l'utente che `actingAs` porta in memoria con `google_verificato_il` vecchio. Un'altra risposta: `httpDaCapo`.
        $this->httpDaCapo();
    }

    /**
     * Un client HTTP nuovo: tra due `Http::fake` vince la prima risposta che corrisponde, e un test non potrebbe
     * cambiare quella di prima. Il divieto delle richieste non simulate resta; il token di Google è valido, salvo `$fake`
     * (o salvo `null`: nessuna risposta, il test simula da sé).
     */
    protected function httpDaCapo(?array $fake = []): void
    {
        app()->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
        Http::preventStrayRequests();

        if ($fake !== null) {
            Http::fake($fake + ['oauth2.googleapis.com/token' => Http::response(['access_token' => 'token-di-accesso', 'expires_in' => 3600])]);
        }
    }

    /**
     * Un utente con un'assegnazione. `$ruolo` è il nome di un ruolo predefinito o un Ruolo; `$assegnazione`
     * accetta gli attributi di Assegnazione (scade_il, tutti_i_conti, nomina_firmata_il...) e `conti`.
     */
    protected function utenteCon(string|Ruolo $ruolo, array $assegnazione = [], array $utente = []): User
    {
        $utente = User::factory()->create($utente);
        $this->assegna($utente, $ruolo, $assegnazione);

        return $utente;
    }

    protected function assegna(User $utente, string|Ruolo $ruolo, array $attributi = []): Assegnazione
    {
        $ruolo = $ruolo instanceof Ruolo ? $ruolo : Ruolo::where('nome', $ruolo)->firstOrFail();
        $conti = $attributi['conti'] ?? null;
        unset($attributi['conti']);

        $assegnazione = Assegnazione::create($attributi + ['user_id' => $utente->id, 'ruolo_id' => $ruolo->id, 'tutti_i_conti' => $conti === null]);
        foreach ($conti ?? [] as $conto) {
            \DB::table('assegnazione_conto')->insert(['assegnazione_id' => $assegnazione->id, 'conto_id' => $conto]);
        }

        return $assegnazione;
    }
}
