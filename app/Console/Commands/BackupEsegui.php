<?php

namespace App\Console\Commands;

use App\Backup\Esecuzione;
use Illuminate\Console\Command;

/** Il backup mensile (#68): parte da `CompitiPeriodici` il primo del mese. L'esito è nel registro dei backup e nello stato del sistema. */
class BackupEsegui extends Command
{
    protected $signature = 'backup:esegui';

    protected $description = 'Esporta il database e i CSV sul drive condiviso e fa girare la rotazione';

    public function handle(): int
    {
        $backup = Esecuzione::avvia('mensile');
        $this->info($backup ? "Backup {$backup->esito}." : 'Un backup è già in corso.');

        // Anche un backup fallito esce con successo: l'errore è già registrato e avvisato, e il compito non deve ripetersi a ogni chiamata.
        return self::SUCCESS;
    }
}
