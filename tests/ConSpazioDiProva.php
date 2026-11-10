<?php

namespace Tests;

use App\Models\Conto;
use App\Models\User;
use App\Onboarding\Prova;

/**
 * Per i test che toccano lo spazio di prova (ADR 0003): le sue tabelle stanno in un database a parte che
 * RefreshDatabase non svuota, quindi si puliscono qui, prima e dopo ogni test.
 */
trait ConSpazioDiProva
{
    protected function setUpConSpazioDiProva(): void
    {
        $this->prova()->eliminaTutto();
    }

    protected function tearDownConSpazioDiProva(): void
    {
        $this->prova()->eliminaTutto();
    }

    protected function prova(): Prova
    {
        return app(Prova::class);
    }

    /** Il numero di conti nello spazio di prova di un utente (nel database vero non ce n'è mai). */
    protected function contiInProva(User $utente): int
    {
        return $this->prova()->per($utente, null, fn () => Conto::count());
    }
}
