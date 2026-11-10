<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Compito periodico come comando Laravel (ADR 0001): parte da `schedule:run`, dal cron di sistema o, su Aruba, dalla chiamata HTTP.
Schedule::command('prova:cancella')->daily();
