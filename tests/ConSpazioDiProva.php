<?php

namespace Tests;

use App\Models\Conto;
use App\Models\User;
use App\Onboarding\Prova;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Per i test che toccano lo spazio di prova (ADR 0003): le sue tabelle stanno in un database a parte che
 * RefreshDatabase non svuota, quindi si puliscono qui, prima e dopo ogni test.
 * `eliminaTutto` cancella gli spazi di *tutti*, e i prefissi `p<id>_` dipendono dagli id degli utenti, uguali in ogni processo: due processi di
 * test sullo stesso database (due `phpunit` nello stesso progetto Compose) se li cancellerebbero a vicenda («p591_migrations» mancante).
 * Per questo un test ha lo spazio di prova per sé dal setUp al tearDown, con un `GET_LOCK` di MySQL: l'altro processo aspetta il suo turno (#87).
 */
trait ConSpazioDiProva
{
    private bool $bloccoProvaPreso = false;

    protected function setUpConSpazioDiProva(): void
    {
        $this->prendiBloccoProva();
        $this->prova()->eliminaTutto();
    }

    protected function tearDownConSpazioDiProva(): void
    {
        try {
            $this->prova()->eliminaTutto();
        } finally {
            $this->rilasciaBloccoProva();
        }
    }

    /** Il nome vale per il server, non per la sessione: lo stesso per ogni processo che usa lo stesso database di prova. */
    protected function nomeBloccoProva(): string
    {
        return 'spazio-di-prova:'.config('database.connections.'.Prova::CONNESSIONE.'.database');
    }

    /** Su una connessione a sé: `prova` la ricrea `Prova` a ogni cambio di prefisso, e il blocco finirebbe con la sessione. */
    private function prendiBloccoProva(): void
    {
        if ($this->bloccoProvaPreso) {
            return;
        }
        $connessione = DB::connectUsing('blocco-prova', ['prefix' => ''] + config('database.connections.'.Prova::CONNESSIONE), true);
        if ((int) $connessione->selectOne('select get_lock(?, 300) as preso', [$this->nomeBloccoProva()])->preso !== 1) {
            DB::purge('blocco-prova');
            throw new RuntimeException('Lo spazio di prova è in uso da un altro processo di test da più di cinque minuti.');
        }
        $this->bloccoProvaPreso = true;
    }

    private function rilasciaBloccoProva(): void
    {
        if ($this->bloccoProvaPreso) {
            DB::purge('blocco-prova'); // chiude la sessione: il server libera il blocco
            $this->bloccoProvaPreso = false;
        }
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
