<?php

namespace App\Console\Commands;

use App\Totp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Genera password e segreto TOTP dell'account di emergenza (#36); si lancia in locale, mai sul server. */
class EmergenzaCredenziali extends Command
{
    protected $signature = 'emergenza:credenziali';

    protected $description = 'Genera la password e il segreto TOTP dell’account di emergenza';

    public function handle(): int
    {
        $password = Str::random(40);
        $segreto = Totp::nuovoSegreto();

        $this->line('Password (da conservare con l’associazione: qui non si recupera):');
        $this->line($password);
        $this->newLine();
        $this->line('Da mettere tra i segreti di GitHub (docs/pubblicazione.md):');
        $this->line('EMERGENZA_PASSWORD_HASH='.Hash::make($password));
        $this->line('EMERGENZA_TOTP_SECRET='.$segreto);
        $this->newLine();
        $this->line('Da aggiungere all’app di autenticazione (inquadrando il QR di questo indirizzo, o digitando il segreto):');
        $this->line('otpauth://totp/Prima%20nota:emergenza?issuer=Prima%20nota&secret='.$segreto);

        return self::SUCCESS;
    }
}
