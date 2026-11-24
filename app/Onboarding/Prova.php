<?php

namespace App\Onboarding;

use App\Models\SpazioProva;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

    private ?User $utente = null;

    private ?string $connessioneUtente = null;

    /** @var ?array<string,mixed> gli attributi dell'utente com'erano sul database vero, da rimettere uscendo */
    private ?array $attributiUtente = null;

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
        $dove = fn (string $nome) => Arr::only(config("database.connections.{$nome}"), ['host', 'port', 'database']);
        if ($dove($reale) === $dove(self::CONNESSIONE)) {
            throw new RuntimeException('Lo spazio di prova non è disponibile: il database di prova è quello vero.');
        }
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

        // Gli attributi li ha letti `auth` dalla riga vera: la preferenza che la pagina mostra (e che si salva) è quella della riga nello spazio.
        // Letta prima di cambiare stato, così un errore non lascia il default sulla connessione di prova. Senza missione lo spazio può non
        // esistere ancora (`prepara`); uno nato prima di una colonna nuova non la ha: vale quella vera.
        // shortcut: solo le preferenze (oggi una); una nuova colonna scritta dall'utente in prova va aggiunta qui.
        $preferenze = null;
        if ($missione) {
            try {
                $preferenze = (array) DB::connection(self::CONNESSIONE)->table($utente->getTable())->where($utente->getKeyName(), $utente->getKey())->first(['email_menzioni']) ?: null;
            } catch (QueryException $e) {
                report($e);
            }
        }

        $this->reale = $reale;
        $this->missione = $missione;
        $this->mailPrima = config('mail.default');
        // Nessuna chiamata esterna: niente email (il mailer scrive in memoria) e nessuna richiesta HTTP in uscita.
        config(['mail.default' => 'array']);
        $this->httpPrima = Http::preventingStrayRequests();
        Http::preventStrayRequests();
        DB::setDefaultConnection(self::CONNESSIONE);
        // `auth` ha caricato l'utente sul database vero: finché si è in prova anche lui, con le sue relazioni (assegnazioni,
        // sottoquery di `Menzione::rivolteA`) e i suoi salvataggi (preferenze), lavora sulla sua riga nello spazio.
        $this->utente = $utente;
        $this->connessioneUtente = $utente->getConnectionName();
        $this->attributiUtente = $utente->getAttributes();
        $utente->setConnection(self::CONNESSIONE)->unsetRelations();
        if ($preferenze) {
            $utente->forceFill($preferenze)->syncOriginal();
        }
    }

    public function esci(): void
    {
        if (! $this->attiva()) {
            return;
        }
        DB::setDefaultConnection($this->reale);
        DB::purge(self::CONNESSIONE);
        Http::preventStrayRequests($this->httpPrima);
        config(['mail.default' => $this->mailPrima]);
        $this->utente->setConnection($this->connessioneUtente)->unsetRelations()->setRawAttributes($this->attributiUtente, true);
        $this->reale = $this->missione = $this->mailPrima = $this->utente = $this->connessioneUtente = $this->attributiUtente = null;
    }

    /** Esegue `$lavoro` nello spazio dell'utente e ne esce sempre. */
    public function per(User $utente, ?Missione $missione, Closure $lavoro): mixed
    {
        $this->entra($utente, $missione);
        try {
            return $lavoro();
        } finally {
            $this->esci();
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

    /**
     * Da chiamare fuori dalla prova, ad ogni richiesta in prova: al più una volta al minuto aggiorna l'ultimo uso
     * (serve a cancellare gli spazi inutilizzati da 6 mesi) e se l'utente ha ora un ruolo (serve a cancellare lo
     * spazio quando lo perde). Il segno non si toglie mai: chi perde il ruolo e continua a usare lo spazio va cancellato lo stesso.
     */
    public function registraUso(User $utente): void
    {
        SpazioProva::whereKey($utente->id)->where('ultimo_uso', '<', now()->subMinute())
            ->update(['ultimo_uso' => now()] + ($utente->assegnazioniAttive()->exists() ? ['con_assegnazione' => true] : []));
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
        $conn = DB::connection(self::CONNESSIONE);
        $nomi = collect($conn->getSchemaBuilder()->getTables($conn->getDatabaseName()))->pluck('name');

        return $nomi->map(fn (string $n) => preg_match('/^(p\d+_)users$/', $n, $m) ? $m[1] : null)->filter()->values()->all();
    }

    /**
     * Solo le tabelle del prefisso, scelte con una corrispondenza esatta (`p1_` non prende `p12_`): mai `db:wipe`,
     * che cancellerebbe gli spazi di tutti. Le chiavi esterne si spengono per la sola durata della cancellazione.
     */
    private function eliminaTabelle(string $prefisso): void
    {
        $conn = DB::connection(self::CONNESSIONE);
        $nomi = collect($conn->getSchemaBuilder()->getTables($conn->getDatabaseName()))->pluck('name')->filter(fn (string $n) => str_starts_with($n, $prefisso));
        $conn->getSchemaBuilder()->disableForeignKeyConstraints();
        foreach ($nomi as $nome) {
            $conn->getSchemaBuilder()->drop(substr($nome, strlen($prefisso)));
        }
        $conn->getSchemaBuilder()->enableForeignKeyConstraints();
        // Anche le anteprime degli estratti dello spazio (`ImportEstrattiController`): le righe sono sparite con le tabelle.
        Storage::disk('local')->deleteDirectory(self::cartellaProva($prefisso));
    }

    /** Dove `ImportEstrattiController` scrive le anteprime: in prova una cartella dello spazio, che si cancella con lo spazio. */
    public function cartellaEstratti(): string
    {
        return $this->attiva() ? self::cartellaProva(config('database.connections.'.self::CONNESSIONE.'.prefix')) : 'estratti';
    }

    private static function cartellaProva(string $prefisso): string
    {
        return "estratti/prova/{$prefisso}";
    }
}
