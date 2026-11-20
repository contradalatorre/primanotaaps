<?php

namespace Tests;

use App\Models\Assegnazione;
use App\Models\Ruolo;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Gli stili si compilano a parte (npm run build, in CI): i test HTTP non ne hanno bisogno.
        $this->withoutVite();

        // Nessuna chiamata a servizi esterni nei test (#88): ogni richiesta HTTP va simulata con `Http::fake`.
        Http::preventStrayRequests();
    }

    /**
     * Dopo uno spostamento dell'orologio in avanti: `VerificaAccountGoogle` chiederebbe a Google di rinnovare il token
     * (da ora le richieste non simulate fanno fallire il test, #88). Non serve per i test che vogliono proprio quella richiesta.
     */
    protected function riverificaAccountGoogle(): void
    {
        User::query()->update(['google_verificato_il' => now()]);
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
