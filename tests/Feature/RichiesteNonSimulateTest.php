<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Regola del progetto: nessuna chiamata a servizi esterni nei test (#88). Una richiesta HTTP senza `Http::fake` deve far fallire il test. */
class RichiesteNonSimulateTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_richiesta_non_simulata_fa_fallire_il_test(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://www.googleapis.com/drive/v3/files');
    }

    public function test_una_richiesta_simulata_passa(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['ok' => true])]);

        $this->assertTrue(Http::get('https://www.googleapis.com/drive/v3/files')->json('ok'));
    }

    public function test_spostare_l_orologio_non_porta_a_google_e_la_sessione_resta(): void
    {
        $utente = User::factory()->create(['google_verificato_il' => now()]);
        $this->travelTo(now()->addDays(3));

        $this->actingAs($utente)->get(route('home'))->assertOk();

        Http::assertSentCount(1); // il rinnovo del token, simulato di default dalla TestCase
    }
}
