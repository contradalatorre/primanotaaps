<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

class VerificaAccountGoogleTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    protected function setUp(): void
    {
        parent::setUp();

        // Qui il token lo decide ogni test (anche nessuna risposta, per provare che non si chiede): niente risposta di default.
        $this->httpDaCapo(null);
    }

    /** Utente la cui ultima verifica con Google risale a più di un'ora fa. */
    private function utenteDaVerificare(): User
    {
        $utente = User::factory()->create(['google_verificato_il' => now()->subMinutes(61)]);
        $this->actingAs($utente);

        return $utente;
    }

    public function test_il_login_chiede_a_google_il_refresh_token_a_ogni_accesso(): void
    {
        $url = $this->get(route('accesso.google'))->assertRedirect()->headers->get('Location');

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('offline', $query['access_type']);
        $this->assertStringContainsString('consent', $query['prompt']);
    }

    public function test_il_refresh_token_e_salvato_cifrato_al_login(): void
    {
        Socialite::fake('google', GoogleUser::fake([
            'id' => '1001', 'name' => 'Maria Rossi', 'email' => 'maria.rossi@associazione.example',
            'hd' => 'associazione.example', 'email_verified' => true, 'refreshToken' => 'segreto-di-google',
        ]));

        $this->get(route('accesso.ritorno'))->assertRedirect(route('home'));

        $this->assertSame('segreto-di-google', User::firstWhere('google_id', '1001')->google_refresh_token);
        $this->assertNotSame('segreto-di-google', DB::table('users')->value('google_refresh_token'));
    }

    public function test_senza_refresh_token_il_login_e_rifiutato(): void
    {
        Socialite::fake('google', GoogleUser::fake([
            'id' => '1001', 'name' => 'Maria Rossi', 'email' => 'maria.rossi@associazione.example',
            'hd' => 'associazione.example', 'email_verified' => true, 'refreshToken' => null,
        ]));

        $this->get(route('accesso.ritorno'))->assertRedirect(route('accesso'));

        $this->assertGuest();
    }

    public function test_entro_l_ora_non_si_chiama_google(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create());

        $this->get(route('home'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_un_account_sospeso_perde_l_accesso_con_un_messaggio(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->utenteDaVerificare();

        $this->get(route('home'))->assertRedirect(route('accesso'));

        $this->assertGuest();
    }

    public function test_il_messaggio_per_l_account_sospeso_e_mostrato(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->utenteDaVerificare();

        $this->followingRedirects()->get(route('home'))->assertSee('sospeso o eliminato');
    }

    public function test_un_account_valido_resta_dentro_e_si_verifica_al_piu_una_volta_l_ora(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'x'], 200)]);
        $this->utenteDaVerificare();

        $this->get(route('home'))->assertOk();
        $this->get(route('home'))->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'refresh-token-finto');

        $this->travel(61)->minutes();
        $this->get(route('home'))->assertOk();
        Http::assertSentCount(2);
    }

    public function test_un_errore_di_google_non_toglie_l_accesso_e_non_si_riprova_a_ogni_richiesta(): void
    {
        Http::fake([self::TOKEN_URL => Http::response('', 500)]);
        $this->utenteDaVerificare();

        $this->get(route('home'))->assertOk();
        $this->get(route('home'))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_se_google_non_risponde_la_sessione_resta(): void
    {
        Http::fake([self::TOKEN_URL => fn () => throw new ConnectionException('timeout')]);
        $this->utenteDaVerificare();

        $this->get(route('home'))->assertOk();
    }

    public function test_un_altro_dispositivo_dello_stesso_utente_e_buttato_fuori_alla_sua_verifica(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
        $utente = $this->utenteDaVerificare();

        $this->get(route('home'))->assertRedirect(route('accesso'));

        // La verifica fallita non è stata segnata: l'altro dispositivo la rifà e viene buttato fuori.
        $this->assertTrue($utente->fresh()->google_verificato_il->lt(now()->subHour()));
    }

    public function test_si_esce_dal_menu_anche_a_verifica_scaduta(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'x'], 200)]);
        $this->utenteDaVerificare();

        $this->post(route('esci'))->assertRedirect(route('accesso'));

        $this->assertGuest();
    }
}
