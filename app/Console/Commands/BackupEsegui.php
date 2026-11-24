<?php

namespace App\Console\Commands;

use App\Backup\Esecuzione;
use App\RegistroErrori;
use Illuminate\Console\Command;

/** Il backup mensile (#68): parte da `CompitiPeriodici` il primo del mese. L'esito è nel registro dei backup e nello stato del sistema. */
class BackupEsegui extends Command
{
    protected $signature = 'backup:esegui';

    protected $description = 'Esporta il database e i CSV sul drive condiviso e fa girare la rotazione';

    public function handle(): int
    {
        $backup = Esecuzione::avvia('mensile');
        if ($backup === null) {
            // Il compito periodico si segna come servito: senza questo il mensile salterebbe in silenzio fino al mese dopo.
            RegistroErrori::registra('backup', 'Il backup mensile non è partito: un altro backup era già in corso.', grave: true);
        }
        $this->info($backup ? "Backup {$backup->esito}." : 'Un backup è già in corso.');

        // Anche un backup fallito esce con successo: l'errore è già registrato e avvisato, e il compito non deve ripetersi a ogni chiamata.
        return self::SUCCESS;
    }
}
