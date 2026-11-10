<?php

namespace Tests\Feature;

use App\Drive;
use App\DriveNonDisponibile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\ConDriveFinto;
use Tests\TestCase;

/** Il drive condiviso con service account (ADR 0001, #68): nessuna chiamata vera, tutto dietro `Http::fake`. Chiave generata al volo. */
class DriveTest extends TestCase
{
    use ConDriveFinto;

    private const SEGNALE = 'SEGNALE-CHE-NON-DEVE-USCIRE';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraDrive();
    }

    public function test_non_configurato_senza_credenziali_leggibili(): void
    {
        $this->assertTrue(app(Drive::class)->configurato());
        config(['services.drive.credenziali' => null]);
        $this->assertFalse(app(Drive::class)->configurato());
        config(['services.drive.credenziali' => 'non-e-base64-di-un-json']);
        $this->assertFalse(app(Drive::class)->configurato());
    }

    public function test_carica_chiede_il_token_firmato_e_manda_il_file_nella_cartella(): void
    {
        $this->fingiDrive();

        $id = app(Drive::class)->carica('backup.zip', 'CONTENUTO-DEL-FILE', 'application/zip', 'cartella-123');

        $this->assertSame('file-1', $id);
        Http::assertSent(function (Request $r) {
            if ($r->url() !== 'https://oauth2.googleapis.com/token') {
                return false;
            }
            [$intestazione, $corpo, $firma] = explode('.', $r['assertion']);
            $decodifica = fn (string $s) => json_decode(base64_decode(strtr($s, '-_', '+/')), true);
            $valida = openssl_verify("$intestazione.$corpo", base64_decode(strtr($firma, '-_', '+/')), $this->chiavePubblica, OPENSSL_ALGO_SHA256) === 1;

            return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && $valida
                && $decodifica($intestazione)['alg'] === 'RS256'
                && $decodifica($corpo)['iss'] === 'backup@progetto.iam.gserviceaccount.com'
                && $decodifica($corpo)['scope'] === 'https://www.googleapis.com/auth/drive.file'
                && $decodifica($corpo)['aud'] === 'https://oauth2.googleapis.com/token';
        });
        Http::assertSent(function (Request $r) {
            $corpo = $r->body();

            return str_starts_with($r->url(), 'https://www.googleapis.com/upload/drive/v3/files?')
                && str_contains($r->url(), 'uploadType=multipart') && str_contains($r->url(), 'supportsAllDrives=true')
                && str_starts_with($r->header('Content-Type')[0], 'multipart/related; boundary=')
                && $r->header('Authorization')[0] === 'Bearer token-di-accesso'
                && str_contains($corpo, '"name":"backup.zip"') && str_contains($corpo, '"parents":["cartella-123"]')
                && str_contains($corpo, "Content-Type: application/zip\r\n\r\nCONTENUTO-DEL-FILE\r\n");
        });
    }

    public function test_elimina_un_file_e_se_non_c_e_piu_va_bene_lo_stesso(): void
    {
        $this->fingiDrive(['www.googleapis.com/drive/v3/files/gia-tolto*' => Http::response(['error' => ['code' => 404]], 404)]);

        app(Drive::class)->elimina('file-1');
        app(Drive::class)->elimina('gia-tolto');

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_starts_with($r->url(), 'https://www.googleapis.com/drive/v3/files/file-1?') && str_contains($r->url(), 'supportsAllDrives=true'));
    }

    public function test_un_errore_di_google_diventa_un_messaggio_senza_il_corpo_della_risposta(): void
    {
        $this->fingiDrive(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => self::SEGNALE], 400)]);

        try {
            app(Drive::class)->carica('backup.zip', 'x', 'application/zip', 'cartella-123');
            $this->fail('Doveva fallire');
        } catch (DriveNonDisponibile $e) {
            $this->assertStringContainsString('400', $e->getMessage());
            $this->assertStringContainsString('invalid_grant', $e->getMessage());
            $this->assertStringNotContainsString(self::SEGNALE, $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('PRIVATE KEY', $e->getMessage().$e->getTraceAsString());
        }
    }

    public function test_un_errore_del_drive_e_la_rete_giu_diventano_drive_non_disponibile(): void
    {
        $this->fingiDrive(['www.googleapis.com/upload/drive/v3/files*' => Http::response(['error' => ['errors' => [['reason' => 'storageQuotaExceeded', 'message' => self::SEGNALE]]]], 403)]);
        $this->expectException(DriveNonDisponibile::class);
        $this->expectExceptionMessage('storageQuotaExceeded');

        app(Drive::class)->carica('backup.zip', 'x', 'application/zip', 'cartella-123');
    }

    public function test_senza_rete_il_messaggio_non_porta_dettagli(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: '.self::SEGNALE));

        try {
            app(Drive::class)->carica('backup.zip', 'x', 'application/zip', 'cartella-123');
            $this->fail('Doveva fallire');
        } catch (DriveNonDisponibile $e) {
            $this->assertStringNotContainsString(self::SEGNALE, $e->getMessage());
        }
    }

    public function test_una_chiave_non_valida_non_finisce_nel_messaggio(): void
    {
        config(['services.drive.credenziali' => base64_encode(json_encode(['client_email' => 'a@b.c', 'private_key' => 'CHIAVE-'.self::SEGNALE]))]);
        $this->fingiDrive();

        try {
            app(Drive::class)->carica('backup.zip', 'x', 'application/zip', 'cartella-123');
            $this->fail('Doveva fallire');
        } catch (DriveNonDisponibile $e) {
            $this->assertStringNotContainsString(self::SEGNALE, $e->getMessage());
        }
        Http::assertNothingSent();
    }
}
