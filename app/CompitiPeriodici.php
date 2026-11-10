<?php

namespace App;

use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * I compiti periodici (ADR 0001): comandi Laravel con la loro scadenza, in un elenco solo. `routes/console.php` li
 * registra per `schedule:run` (cron di sistema, VPS); su Aruba, dove c'è solo una chiamata HTTP a orari non esatti,
 * li avvia `avvia()`: parte ciò che è scaduto dall'ultima volta, una volta sola anche dopo giorni di silenzio.
 */
final class CompitiPeriodici
{
    /** Nome del comando => espressione cron (ora dell'app). L'ordine è quello di avvio. */
    public const ELENCO = [
        'prova:cancella' => '0 0 * * *',
        'menzioni:riepilogo' => '0 8 * * *',
    ];

    /**
     * La prima volta che vede un compito registra l'ora e basta: niente parte «per recupero» su un'installazione appena fatta.
     *
     * @return list<string> i comandi avviati
     */
    public static function avvia(): array
    {
        $adesso = now();
        // L'avvio si prenota a riga bloccata e solo dopo si esegue: due chiamate sovrapposte non spediscono due volte.
        $scaduti = DB::transaction(function () use ($adesso) {
            $scaduti = [];
            foreach (self::ELENCO as $nome => $quando) {
                // Prima inserisce (senza lock su righe che non ci sono: su MySQL due prime chiamate si bloccherebbero a vicenda).
                if (DB::table('compiti_periodici')->insertOrIgnore(['nome' => $nome, 'ultimo_avvio' => $adesso])) {
                    continue;
                }
                $ultimoAvvio = Carbon::parse(DB::table('compiti_periodici')->where('nome', $nome)->lockForUpdate()->value('ultimo_avvio'));
                $ultimaScadenza = (new CronExpression($quando))->getPreviousRunDate($adesso, 0, true, config('app.timezone'));
                if ($ultimaScadenza->getTimestamp() > $ultimoAvvio->getTimestamp()) {
                    DB::table('compiti_periodici')->where('nome', $nome)->update(['ultimo_avvio' => $adesso]);
                    $scaduti[] = $nome;
                }
            }

            return $scaduti;
        });

        foreach ($scaduti as $nome) {
            try {
                Artisan::call($nome);
            } catch (Throwable $e) {
                report($e); // shortcut: un compito fallito riparte alla prossima scadenza, non alla chiamata dopo
            }
        }

        return $scaduti;
    }
}
