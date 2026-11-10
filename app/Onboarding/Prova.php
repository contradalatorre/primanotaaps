<?php

namespace App\Onboarding;

use App\Models\SpazioProva;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Lo spazio di prova (ADR 0003): un database separato con lo stesso schema, e per ogni utente le sue tabelle col
 * prefisso `p<id>_`. Mentre si è «in prova» il default dell'app è la connessione `prova`: tutto il resto dell'app
 * (controllori, modelli, viste) non sa di esserci. Fuori dalla prova non cambia nulla.
 */
#[Singleton]
final class Prova
{
    public const CONNESSIONE = 'prova';

    private ?string $reale = null;

    private ?Missione $missione = null;

    private ?string $mailPrima = null;

    private bool $httpPrima = false;

    public function attiva(): bool
    {
        return $this->reale !== null;
    }

    /** Il nome della connessione vera: per i modelli che in prova restano lì (`NelDatabaseVero`). */
    public function connessioneReale(): string
    {
        return $this->reale ?? config('database.default');
    }

    /** I permessi validi in prova: quelli della missione in corso, non dell'assegnazione. Null fuori dalla prova (o senza missione). */
    public function permessi(): ?array
    {
        return $this->attiva() && $this->missione ? $this->missione->permessi : null;
    }

    /**
     * Da qui in poi la richiesta lavora nello spazio dell'utente. Senza una missione valgono i permessi veri
     * (serve a preparare lo scenario); con una, quelli della missione.
     *
     * @throws RuntimeException se il database di prova non risponde: non si continua mai sul database vero
     */
    public function entra(User $utente, ?Missione $missione = null): void
    {
        if ($this->attiva()) {
            throw new \LogicException('Si è già in prova.');
        }
        $reale = config('database.default');
        // Sessione, cache e coda restano sul database vero anche se il loro nome non è mai stato scritto in .env.
        config([
            'session.connection' => $reale, 'cache.stores.database.connection' => $reale, 'cache.stores.database.lock_connection' => $reale,
            'queue.connections.database.connection' => $reale, 'queue.failed.database' => $reale,
            'database.connections.'.self::CONNESSIONE.'.prefix' => $this->prefisso($utente->id),
        ]);
        DB::purge(self::CONNESSIONE);
        try {
            DB::connection(self::CONNESSIONE)->getPdo();
        } catch (Throwable $e) {
            DB::purge(self::CONNESSIONE); // senza, la connessione fallita resterebbe in memoria con il database sbagliato
            report($e);
            throw new RuntimeException('Lo spazio di prova non è disponibile: il database di prova non risponde.', previous: $e);
        }

        $this->reale = $reale;
        $this->missione = $missione;
        $this->mailPrima = config('mail.default');
        // Nessuna chiamata esterna: niente email (il mailer scrive in memoria) e nessuna richiesta HTTP in uscita.
        config(['mail.default' => 'array']);
        $this->httpPrima = Http::preventingStrayRequests();
        Http::preventStrayRequests();
        DB::setDefaultConnection(self::CONNESSIONE);
        $utente->unsetRelations();
    }

    public function esci(?User $utente = null): void
    {
        if (! $this->attiva()) {
            return;
        }
        DB::setDefaultConnection($this->reale);
        DB::purge(self::CONNESSIONE);
        Http::preventStrayRequests($this->httpPrima);
        config(['mail.default' => $this->mailPrima]);
        $this->reale = $this->missione = $this->mailPrima = null;
        $utente?->unsetRelations();
    }

    /** Esegue `$lavoro` nello spazio dell'utente e ne esce sempre. */
    public function per(User $utente, ?Missione $missione, Closure $lavoro): mixed
    {
        $this->entra($utente, $missione);
        try {
            return $lavoro();
        } finally {
            $this->esci($utente);
        }
    }

    /** Azzera lo spazio dell'utente e lo riempie con lo scenario della missione. */
    public function prepara(User $utente, Missione $missione): void
    {
        $this->per($utente, null, function () use ($utente, $missione) {
            $this->eliminaTabelle($this->prefisso($utente->id));
            // Le stesse migrazioni del database vero, sul prefisso dell'utente: lo schema di prova è sempre quello vero.
            if (Artisan::call('migrate', ['--database' => self::CONNESSIONE, '--force' => true]) !== 0) {
                throw new RuntimeException('Le migrazioni dello spazio di prova sono fallite: '.Artisan::output());
            }
            Scenario::applica($utente, $missione->scenario);
        });
        SpazioProva::updateOrCreate(['user_id' => $utente->id], ['missione' => $missione->id, 'con_assegnazione' => $utente->assegnazioniAttive()->exists(), 'ultimo_uso' => now()]);
    }

    /** Cancella lo spazio di un utente (le tabelle e la riga in `spazi_prova`); timbri e avanzamento restano. */
    public function elimina(int $utenteId): void
    {
        $this->con($utenteId, fn () => $this->eliminaTabelle($this->prefisso($utenteId)));
        SpazioProva::whereKey($utenteId)->delete();
    }

    /** Per i test: tutti gli spazi di tutti, senza toccare altro del database di prova. */
    public function eliminaTutto(): void
    {
        foreach ($this->prefissiEsistenti() as $prefisso) {
            $this->con((int) substr($prefisso, 1), fn () => $this->eliminaTabelle($prefisso));
        }
        SpazioProva::query()->delete();
    }

    public function esiste(int $utenteId): bool
    {
        return $this->con($utenteId, fn () => Schema::connection(self::CONNESSIONE)->hasTable('users'));
    }

    private function prefisso(int $utenteId): string
    {
        return "p{$utenteId}_";
    }

    /** La connessione `prova` col prefisso di un utente, senza cambiare il default. */
    private function con(int $utenteId, Closure $lavoro): mixed
    {
        config(['database.connections.'.self::CONNESSIONE.'.prefix' => $this->prefisso($utenteId)]);
        DB::purge(self::CONNESSIONE);
        try {
            return $lavoro();
        } finally {
            DB::purge(self::CONNESSIONE);
        }
    }

    /** @return list<string> i prefissi `p<id>_` delle tabelle che ci sono nel database di prova */
    private function prefissiEsistenti(): array
    {
        $nomi = collect(DB::connection(self::CONNESSIONE)->getSchemaBuilder()->getTables())->pluck('name');

        return $nomi->map(fn (string $n) => preg_match('/^(p\d+_)users$/', $n, $m) ? $m[1] : null)->filter()->values()->all();
    }

    /**
     * Solo le tabelle del prefisso, scelte con una corrispondenza esatta (`p1_` non prende `p12_`): mai `db:wipe`,
     * che cancellerebbe gli spazi di tutti. Le chiavi esterne si spengono per la sola durata della cancellazione.
     */
    private function eliminaTabelle(string $prefisso): void
    {
        $conn = DB::connection(self::CONNESSIONE);
        $nomi = collect($conn->getSchemaBuilder()->getTables())->pluck('name')->filter(fn (string $n) => str_starts_with($n, $prefisso));
        $conn->getSchemaBuilder()->disableForeignKeyConstraints();
        foreach ($nomi as $nome) {
            $conn->getSchemaBuilder()->drop(substr($nome, strlen($prefisso)));
        }
        $conn->getSchemaBuilder()->enableForeignKeyConstraints();
    }
}
