<?php

namespace App\Console\Commands;

use App\Giroconti;
use Illuminate\Console\Command;

/** La segnalazione delle metà di giroconto senza corrispondenza (#47, #33 storia 35): parte da `CompitiPeriodici`, una volta al giorno. */
class GirocontiOrfane extends Command
{
    protected $signature = 'giroconti:orfane';

    protected $description = 'Segnala per email a chi può confermare i giroconti le metà senza corrispondenza da qualche giorno';

    public function handle(): int
    {
        $this->info('Email mandate: '.Giroconti::segnalaOrfane());

        return self::SUCCESS;
    }
}
