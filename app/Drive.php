<?php

namespace App;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Il drive condiviso dell'associazione, con un service account (ADR 0001, spec #33): backup (#68) e istantanee del
 * passaggio di consegne (#70). Sono chiamate HTTPS a Google, senza libreria: il token si chiede con un JWT firmato
 * RS256 (scope `drive.file`: il service account vede solo i file che crea) e i file si caricano in una cartella
 * condivisa con lui. Le credenziali (`DRIVE_CREDENZIALI`, il JSON del service account in base64) stanno solo nei
 * segreti. Ogni fallimento è una `DriveNonDisponibile` con un messaggio breve: né corpi di risposta né chiavi.
 * shortcut: caricamento multipart in un colpo solo, con il file in memoria; sopra qualche decina di MB serve il caricamento a riprese.
 */
final class Drive
{
    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com';

    private const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    private ?string $token = null;

    public function configurato(): bool
    {
        $c = $this->credenziali();

        return isset($c['client_email'], $c['private_key']);
    }

    /** @return string l'id del file creato nella cartella */
    public function carica(string $nome, string $contenuto, string $mime, string $cartella): string
    {
        $confine = bin2hex(random_bytes(16));
        $metadati = json_encode(['name' => $nome, 'parents' => [$cartella]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $corpo = "--$confine\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$metadati\r\n"
            ."--$confine\r\nContent-Type: $mime\r\n\r\n$contenuto\r\n--$confine--";

        $risposta = $this->chiama('caricamento', fn () => Http::withToken($this->token())->withBody($corpo, "multipart/related; boundary=$confine")
            ->post(self::API.'/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id'));

        return $risposta->json('id') ?? throw new DriveNonDisponibile('Drive: il caricamento non ha restituito il file.');
    }

    /** Un file già tolto (404) va bene: la rotazione può ripartire dopo un'interruzione. */
    public function elimina(string $id): void
    {
        $this->chiama('eliminazione', fn () => Http::withToken($this->token())
            ->delete(self::API.'/drive/v3/files/'.rawurlencode($id).'?supportsAllDrives=true'), [404]);
    }

    private function token(): string
    {
        return $this->token ??= $this->chiama('richiesta del token', function () {
            $c = $this->credenziali();
            $ora = time();
            $firmare = $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$this->base64url(json_encode([
                'iss' => $c['client_email'], 'scope' => self::SCOPE, 'aud' => $c['token_uri'] ?? self::TOKEN, 'iat' => $ora, 'exp' => $ora + 3600,
            ]));
            // @ perché openssl avvisa (e il messaggio cita la chiave) se la chiave non è valida: qui basta il falso.
            if (! ($chiave = @openssl_pkey_get_private((string) ($c['private_key'] ?? ''))) || ! @openssl_sign($firmare, $firma, $chiave, OPENSSL_ALGO_SHA256)) {
                throw new DriveNonDisponibile('Drive: la chiave del service account non è valida.');
            }

            return Http::asForm()->post($c['token_uri'] ?? self::TOKEN, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $firmare.'.'.$this->base64url($firma),
            ]);
        })->json('access_token') ?? throw new DriveNonDisponibile('Drive: Google non ha dato il token.');
    }

    /** @param  list<int>  $accettati  stati diversi da 2xx che non sono un errore */
    private function chiama(string $fase, callable $richiesta, array $accettati = []): Response
    {
        try {
            $risposta = $richiesta();
        } catch (ConnectionException) {
            throw new DriveNonDisponibile("Drive: $fase non riuscita, il drive non risponde.");
        }
        if ($risposta->failed() && ! in_array($risposta->status(), $accettati, true)) {
            // Del corpo si legge solo un codice breve (`invalid_grant`, `storageQuotaExceeded`): il resto può dire troppo.
            $codice = $risposta->json('error.errors.0.reason') ?? $risposta->json('error');
            $codice = is_string($codice) && preg_match('/^[A-Za-z_]{1,40}$/', $codice) ? " ($codice)" : '';

            throw new DriveNonDisponibile("Drive: $fase non riuscita, risposta {$risposta->status()}$codice.");
        }

        return $risposta;
    }

    /** @return array<string, mixed> vuoto se mancano o non si leggono */
    private function credenziali(): array
    {
        $letto = json_decode((string) base64_decode((string) config('services.drive.credenziali'), true), true);

        return is_array($letto) ? $letto : [];
    }

    private function base64url(string $dati): string
    {
        return rtrim(strtr(base64_encode($dati), '+/', '-_'), '=');
    }
}
