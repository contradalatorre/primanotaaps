<?php

namespace Tests\Feature;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Regola del progetto: nessuna chiamata a servizi esterni nei test (#88). Una richiesta HTTP senza `Http::fake` deve far fallire il test. */
class RichiesteNonSimulateTest extends TestCase
{
    public function test_una_richiesta_non_simulata_fa_fallire_il_test(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://oauth2.googleapis.com/token');
    }

    public function test_una_richiesta_simulata_passa(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['ok' => true])]);

        $this->assertTrue(Http::get('https://oauth2.googleapis.com/token')->json('ok'));
    }
}
