<?php

namespace App\Console\Commands;

use App\EmailMenzioni;
use Illuminate\Console\Command;

/** Il riepilogo delle 8 (#46): parte da `CompitiPeriodici`, mai più di una volta al giorno. */
class MenzioniRiepilogo extends Command
{
    protected $signature = 'menzioni:riepilogo';

    protected $description = 'Manda a chi ha scelto il riepilogo le menzioni ancora da vedere';

    public function handle(): int
    {
        $this->info('Email mandate: '.EmailMenzioni::riepilogo());

        return self::SUCCESS;
    }
}
