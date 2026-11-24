<?php

namespace Tests;

use Illuminate\Support\Facades\Http;

/** Per i test che toccano il drive condiviso (#68): un service account inventato con una chiave generata al volo, e il drive di Google simulato. */
trait ConDriveFinto
{
    private string $chiavePubblica;

    private string $chiavePrivata;

    protected function configuraDrive(): void
    {
        $chiave = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($chiave, $privata);
        $this->chiavePrivata = $privata;
        $this->chiavePubblica = openssl_pkey_get_details($chiave)['key'];
        config(['services.drive' => [
            'credenziali' => base64_encode(json_encode(['client_email' => 'backup@progetto.iam.gserviceaccount.com', 'private_key' => $this->chiavePrivata])),
            'cartella_backup' => 'cartella-123',
        ]]);
    }

    /** Google risponde bene a tutto (il file creato ha id `file-1`), salvo ciò che si passa in `$altro`. */
    protected function fingiDrive(array $altro = []): void
    {
        $this->httpDaCapo($altro + [
            'www.googleapis.com/upload/drive/v3/files*' => Http::response(['id' => 'file-1']),
            'www.googleapis.com/drive/v3/files/*' => Http::response('', 204),
        ]);
    }
}
