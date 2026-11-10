<?php

namespace App\Backup;

use App\Onboarding\Prova;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * L'esportazione completa del database vero (#68): uno zip con `database.sql` (per ricostruirlo) e un CSV leggibile per
 * tabella. Senza `mysqldump` né shell (su Aruba non ci sono): lo SQL si scrive da PHP, in una transazione sola per avere
 * una fotografia coerente. Contiene dati personali: vive solo nella memoria del processo e in un file temporaneo fuori
 * dallo spazio pubblico, tolto sempre. Legge dalla connessione vera anche se la richiesta è «in prova» (ADR 0003).
 * shortcut: lo zip si costruisce in memoria; va bene per decine di migliaia di righe, oltre serve scrivere a flusso.
 */
final class Esportazione
{
    /** Sessioni, cache e code non sono dati dell'associazione; le tabelle `p<id>_` sono lo spazio di prova (ADR 0003). */
    private const ESCLUSE = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    /** I segreti non escono dall'app nemmeno nel backup: il token di Google serve solo a chi lo ha avuto. */
    private const MASCHERATE = ['users' => ['google_refresh_token', 'remember_token']];

    public static function tabellaDaEsportare(string $nome): bool
    {
        return ! in_array($nome, self::ESCLUSE, true) && ! preg_match('/^p\d+_/', $nome);
    }

    /** @return string il contenuto dello zip */
    public static function crea(): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new BackupNonRiuscito('Manca l’estensione zip di PHP sul server.');
        }
        $conn = DB::connection(app(Prova::class)->connessioneReale());
        Storage::disk('local')->makeDirectory('backup-tmp');
        $percorso = tempnam(Storage::disk('local')->path('backup-tmp'), 'zip');
        try {
            $zip = new ZipArchive;
            if ($zip->open($percorso, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new BackupNonRiuscito('Non riesco a creare il file temporaneo.');
            }
            $conn->transaction(fn () => self::riempi($zip, $conn));
            if (! $zip->close()) {
                throw new BackupNonRiuscito('Non riesco a scrivere lo zip.');
            }

            return (string) file_get_contents($percorso);
        } finally {
            @unlink($percorso);
        }
    }

    private static function riempi(ZipArchive $zip, Connection $conn): void
    {
        $sql = "-- Prima nota: esportazione completa del ".now()->format('d/m/Y H:i').".\nSET FOREIGN_KEY_CHECKS=0;\n";
        $tabelle = collect($conn->getSchemaBuilder()->getTables($conn->getDatabaseName()))->pluck('name')->filter(self::tabellaDaEsportare(...))->sort()->values();
        foreach ($tabelle as $tabella) {
            $colonne = $conn->getSchemaBuilder()->getColumnListing($tabella);
            $mascherate = self::MASCHERATE[$tabella] ?? [];
            $csv = fopen('php://temp', 'w+');
            fwrite($csv, "\xEF\xBB\xBF"); // con il BOM l'Excel italiano riconosce l'UTF-8
            fputcsv($csv, $colonne, ';');

            $sql .= "\nDROP TABLE IF EXISTS `$tabella`;\n".$conn->selectOne("SHOW CREATE TABLE `$tabella`")->{'Create Table'}.";\n";
            $righe = [];
            foreach ($conn->table($tabella)->cursor() as $riga) {
                $riga = array_replace((array) $riga, array_fill_keys(array_intersect($mascherate, $colonne), null));
                fputcsv($csv, array_map(fn ($v) => $v ?? '', $riga), ';');
                $righe[] = '('.implode(',', array_map(fn ($v) => self::valore($conn, $v), $riga)).')';
                if (count($righe) === 100) {
                    $sql .= self::inserisci($tabella, $colonne, $righe);
                    $righe = [];
                }
            }
            if ($righe) {
                $sql .= self::inserisci($tabella, $colonne, $righe);
            }
            rewind($csv);
            $zip->addFromString("csv/$tabella.csv", stream_get_contents($csv));
            fclose($csv);
        }
        $zip->addFromString('database.sql', $sql."\nSET FOREIGN_KEY_CHECKS=1;\n");
        $zip->addFromString('LEGGIMI.txt', self::leggimi());
    }

    private static function inserisci(string $tabella, array $colonne, array $righe): string
    {
        return "INSERT INTO `$tabella` (`".implode('`,`', $colonne).'`) VALUES '.implode(",\n", $righe).";\n";
    }

    private static function valore(Connection $conn, mixed $valore): string
    {
        return match (true) {
            $valore === null => 'NULL',
            is_int($valore), is_float($valore) => (string) $valore,
            is_bool($valore) => $valore ? '1' : '0',
            default => $conn->getPdo()->quote((string) $valore),
        };
    }

    private static function leggimi(): string
    {
        return "Esportazione completa di Prima nota, ".now()->format('d/m/Y H:i').".\n\n"
            ."database.sql  per ricostruire il database (mysql nome_database < database.sql)\n"
            ."csv/          una tabella per file, da aprire con Excel o LibreOffice (separatore punto e virgola, UTF-8)\n\n"
            ."Contiene dati personali: conservalo solo sul drive condiviso dell'associazione e non inoltrarlo.\n"
            ."Non ci sono sessioni, cache e il token di Google degli utenti. Lo spazio di prova dell'onboarding non è incluso.\n";
    }
}
