<?php

namespace Tests\Feature;

use App\Totp;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmergenzaCredenzialiTest extends TestCase
{
    public function test_il_comando_genera_una_password_lunga_il_suo_hash_e_un_segreto_totp_validi(): void
    {
        Artisan::call('emergenza:credenziali');
        $righe = explode("\n", Artisan::output());
        $dopo = fn (string $inizio) => collect($righe)->first(fn ($r) => str_starts_with($r, $inizio));

        $password = $righe[1];
        $hash = substr($dopo('EMERGENZA_PASSWORD_HASH='), strlen('EMERGENZA_PASSWORD_HASH='));
        $segreto = substr($dopo('EMERGENZA_TOTP_SECRET='), strlen('EMERGENZA_TOTP_SECRET='));

        $this->assertGreaterThanOrEqual(30, strlen($password));
        $this->assertTrue(Hash::check($password, $hash));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $segreto);
        $this->assertNotNull(Totp::verifica($segreto, Totp::codice($segreto, intdiv(time(), 30)), time()));
        $this->assertStringContainsString("secret=$segreto", $dopo('otpauth://'));
    }
}
