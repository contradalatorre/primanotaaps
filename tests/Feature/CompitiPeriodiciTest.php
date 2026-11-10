<?php

namespace Tests\Feature;

use App\Models\Menzione;
use App\Models\Movimento;
use App\Models\Nota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Il cron HTTP di Aruba (ADR 0001, #46): chiama l'endpoint ogni 10 minuti circa e parte ciò che è scaduto dall'ultima volta. Dati inventati. */
class CompitiPeriodiciTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/manutenzione/cron?token=token-di-prova-lunghissimo';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.token_cron' => 'token-di-prova-lunghissimo']);
        Mail::fake();
        // Una menzione da vedere per una persona col riepilogo (la preferenza di serie).
        $autore = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $maria = $this->utenteCon('Consigliere', [], ['name' => 'Maria Rossi']);
        $nota = Nota::create(['oggetto_tipo' => 'movimento', 'oggetto_id' => Movimento::factory()->create()->id, 'user_id' => $autore->id, 'testo' => 'Guarda @Maria Rossi']);
        Menzione::create(['nota_id' => $nota->id, 'user_id' => $maria->id]);
    }

    public function test_senza_il_token_giusto_non_parte_niente(): void
    {
        $this->travelTo('2026-06-01 07:55');
        $this->get(self::URL)->assertOk();
        $this->travelTo('2026-06-01 08:07');

        $this->get('/manutenzione/cron')->assertForbidden();
        $this->get('/manutenzione/cron?token=sbagliato')->assertForbidden();
        $this->get('/manutenzione/cron?token=')->assertForbidden();
        config(['app.token_cron' => '']);
        $this->get('/manutenzione/cron?token=')->assertForbidden(); // token non configurato: mai aperto

        Mail::assertNothingSent();
    }

    public function test_il_riepilogo_parte_alla_prima_chiamata_dopo_le_8_e_una_volta_al_giorno(): void
    {
        $this->travelTo('2026-06-01 07:55');
        $this->get(self::URL)->assertOk(); // la prima volta registra e basta: niente parte "per recupero"
        $this->travelTo('2026-06-01 08:07'); // il cron non cade mai sul minuto esatto
        $this->get(self::URL)->assertOk()->assertJsonPath('avviati', ['menzioni:riepilogo']);
        Mail::assertSentCount(1);

        $this->travelTo('2026-06-01 08:17');
        $this->get(self::URL)->assertOk()->assertJsonPath('avviati', []);
        $this->travelTo('2026-06-02 08:03'); // a mezzanotte è scaduto anche il compito giornaliero
        $this->get(self::URL)->assertOk()->assertJsonPath('avviati', ['prova:cancella', 'menzioni:riepilogo']);
        Mail::assertSentCount(2);
    }

    public function test_dopo_giorni_senza_chiamate_il_compito_parte_una_volta_sola(): void
    {
        $this->travelTo('2026-06-01 07:55');
        $this->get(self::URL);
        $this->travelTo('2026-06-04 09:30'); // tre 8 mancati

        $this->get(self::URL)->assertJsonPath('avviati', ['prova:cancella', 'menzioni:riepilogo']);
        Mail::assertSentCount(1);
    }

    public function test_parte_anche_il_compito_giornaliero_di_mezzanotte(): void
    {
        $this->travelTo('2026-06-01 23:50');
        $this->get(self::URL);
        $this->travelTo('2026-06-02 00:07');

        $this->get(self::URL)->assertJsonPath('avviati', ['prova:cancella']);
    }

    public function test_la_chiamata_riuscita_registra_l_avvio_e_non_crea_sessioni(): void
    {
        $this->travelTo('2026-06-01 07:55');
        // Niente cookie di sessione: `assertSessionMissing` avvierebbe lei la sessione e troverebbe sempre `_token`.
        $this->get(self::URL)->assertOk()->assertCookieMissing(config('session.cookie'));

        $this->assertSame('2026-06-01 07:55:00', DB::table('compiti_periodici')->where('nome', 'menzioni:riepilogo')->value('ultimo_avvio'));
    }
}
