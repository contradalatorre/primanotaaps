<?php

use App\CompitiPeriodici;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Compiti periodici come comandi Laravel (ADR 0001): partono da `schedule:run` (cron di sistema) o, su Aruba,
// dalla chiamata HTTP (`CompitiPeriodici::avvia`). L'elenco è uno solo.
foreach (CompitiPeriodici::ELENCO as $comando => $quando) {
    Schedule::command($comando)->cron($quando);
}
