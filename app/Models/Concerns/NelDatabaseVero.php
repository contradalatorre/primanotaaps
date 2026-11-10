<?php

namespace App\Models\Concerns;

use App\Onboarding\Prova;

/** Per i modelli che in prova restano nel database vero (avanzamento, spazi): il default allora è la connessione `prova`. */
trait NelDatabaseVero
{
    public function getConnectionName(): ?string
    {
        return app(Prova::class)->connessioneReale();
    }
}
