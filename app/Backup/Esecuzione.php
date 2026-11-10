<?php

namespace App\Backup;

use App\Drive;
use App\DriveNonDisponibile;
use App\Models\Backup;
use App\Models\User;
use App\RegistroErrori;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Un backup (#68): esporta, carica sul drive condiviso, fa girare la rotazione e lascia traccia. Non lancia mai: un
 * backup fallito è una riga `fallito`, un errore grave nel registro e un'email agli Amministratori, così il compito
 * periodico che lo avvia non lo nasconde. Con un backup già in corso non ne parte un altro.
 */
final class Esecuzione
{
    /** @return Backup|null il tentativo, o null se ce n'è già uno in corso */
    public static function avvia(string $tipo, ?User $da = null): ?Backup
    {
        $lucchetto = Cache::lock('backup', 3600);
        if (! $lucchetto->get()) {
            return null;
        }
        try {
            self::chiudiInterrotti();
            $backup = Backup::create(['tipo' => $tipo, 'esito' => 'in_corso', 'user_id' => $da?->id, 'avviato_il' => now()]);
            try {
                self::esegui($backup);
            } catch (Throwable $e) {
                // Dei nostri errori il messaggio è sicuro; degli altri (database, zip) resta il dettaglio nel log e qui solo il punto.
                $nostro = $e instanceof BackupNonRiuscito || $e instanceof DriveNonDisponibile;
                $messaggio = $nostro ? $e->getMessage() : 'Errore imprevisto: '.RegistroErrori::descrivi($e);
                $nostro || report($e);
                $backup->update(['esito' => 'fallito', 'errore' => $messaggio, 'concluso_il' => now()]);
                RegistroErrori::registra('backup', 'Backup non riuscito: '.$messaggio, grave: true);
            }

            return $backup;
        } finally {
            $lucchetto->release();
        }
    }

    /**
     * Un errore fatale (tempo o memoria finiti) non passa da `catch`: il tentativo resta `in_corso` e nessuno lo
     * saprebbe. Oltre l'ora del lucchetto non può più essere vivo: diventa `fallito`, errore grave e email.
     * Si chiama a ogni nuovo backup e quando si apre lo stato del sistema.
     */
    public static function chiudiInterrotti(): void
    {
        foreach (Backup::where('esito', 'in_corso')->where('avviato_il', '<', now()->subHour())->get() as $backup) {
            $messaggio = 'Il backup avviato il '.$backup->avviato_il->format('d/m/Y H:i').' si è interrotto senza finire (tempo o memoria del server esauriti?).';
            $backup->update(['esito' => 'fallito', 'errore' => $messaggio, 'concluso_il' => now()]);
            RegistroErrori::registra('backup', 'Backup non riuscito: '.$messaggio, grave: true);
        }
    }

    private static function esegui(Backup $backup): void
    {
        $drive = app(Drive::class);
        $cartella = (string) config('services.drive.cartella_backup');
        if (! $drive->configurato() || $cartella === '') {
            throw new BackupNonRiuscito('Il drive condiviso non è configurato (credenziali del service account o cartella mancanti).');
        }

        $contenuto = Esportazione::crea();
        $nome = 'prima-nota-backup-'.$backup->avviato_il->format('Y-m-d-His').'.zip';
        $id = $drive->carica($nome, $contenuto, 'application/zip', $cartella);
        $backup->update(['esito' => 'riuscito', 'file' => $nome, 'drive_id' => $id, 'dimensione' => strlen($contenuto), 'concluso_il' => now()]);

        self::ruota($drive);
    }

    /** Il backup è fatto: una rotazione che non riesce non lo fa fallire, resta un errore non grave e riprova al prossimo. */
    private static function ruota(Drive $drive): void
    {
        // Una rotazione per tipo: uno su richiesta non è un mensile e non deve toglierne uno (il mensile del 1° può essere l'ultima copia pulita).
        $sulDrive = Backup::sulDrive()->get()->keyBy('id');
        $daEliminare = $sulDrive->groupBy('tipo', preserveKeys: true)->flatMap(fn ($gruppo) => Rotazione::daEliminare($gruppo->map->avviato_il->all(), now()));
        foreach ($daEliminare as $id) {
            try {
                $drive->elimina($sulDrive[$id]->drive_id);
                $sulDrive[$id]->update(['eliminato_il' => now()]);
            } catch (DriveNonDisponibile $e) {
                RegistroErrori::registra('backup.rotazione', 'Un backup vecchio non si è potuto togliere dal drive: '.$e->getMessage());

                return; // il drive non risponde: inutile insistere con gli altri
            }
        }
    }
}
