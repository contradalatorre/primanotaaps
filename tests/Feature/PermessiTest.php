<?php

namespace Tests\Feature;

use App\Http\Middleware\VerificaAccountGoogle;
use App\Models\Conto;
use App\Models\Ruolo;
use App\Models\User;
use App\Permesso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PermessiTest extends TestCase
{
    use RefreshDatabase;

    /** Tabella di #16: per ogni ruolo predefinito i permessi concessi. */
    private const PREDEFINITI = [
        'Amministratore' => [
            'vedere', 'inserire_movimenti_cassa', 'modificare_movimenti_propri', 'proporre_classificazioni',
            'importare_e_confermare', 'eliminare_movimento_manuale', 'gestire_regole', 'chiedere_suggerimenti_ai',
            'riclassificare_in_blocco', 'gestire_esercizio', 'riaprire_esercizio', 'vedere_registro_modifiche',
            'vedere_storia_movimento', 'note', 'oscurare_nota', 'gestire_utenti', 'gestire_impostazioni',
            'gestire_passaggio_consegne',
        ],
        'Tesoriere' => [
            'vedere', 'inserire_movimenti_cassa', 'modificare_movimenti_propri', 'proporre_classificazioni',
            'importare_e_confermare', 'eliminare_movimento_manuale', 'gestire_regole', 'chiedere_suggerimenti_ai',
            'riclassificare_in_blocco', 'gestire_esercizio', 'vedere_registro_modifiche', 'vedere_storia_movimento', 'note',
        ],
        'Consigliere' => [
            'vedere', 'inserire_movimenti_cassa', 'modificare_movimenti_propri', 'proporre_classificazioni',
            'vedere_storia_movimento', 'note',
        ],
        'Lettore esterno' => ['vedere', 'note'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([7, 8] as $id) {
            Conto::factory()->create(['id' => $id]);
        }

        // Le funzioni che usano i permessi arrivano coi ticket successivi: intanto ogni permesso ha una rotta di prova
        // dietro lo stesso middleware delle rotte vere. Il conto, se c'è, è l'ambito.
        foreach (Permesso::cases() as $permesso) {
            Route::middleware(['web', 'auth', VerificaAccountGoogle::class, 'can:'.$permesso->value.',conto'])
                ->get('/prova/'.$permesso->value.'/{conto?}', fn () => 'ok');
        }
    }

    /** @return iterable<string, array{Permesso}> */
    public static function permessi(): iterable
    {
        foreach (Permesso::cases() as $permesso) {
            yield $permesso->value => [$permesso];
        }
    }

    public function test_i_ruoli_predefiniti_hanno_i_permessi_della_tabella(): void
    {
        foreach (self::PREDEFINITI as $nome => $permessi) {
            $this->assertEqualsCanonicalizing($permessi, Ruolo::where('nome', $nome)->firstOrFail()->permessi, $nome);
        }
        $this->assertEqualsCanonicalizing(array_column(Permesso::cases(), 'value'), self::PREDEFINITI['Amministratore'], 'l’Amministratore ha tutti i permessi');
    }

    #[DataProvider('permessi')]
    public function test_chi_ha_il_permesso_passa(Permesso $permesso): void
    {
        $ruolo = Ruolo::create(['nome' => 'Prova', 'permessi' => [$permesso->value]]);

        $this->actingAs($this->utenteCon($ruolo))->get('/prova/'.$permesso->value)->assertOk();
    }

    #[DataProvider('permessi')]
    public function test_chi_non_ha_il_permesso_e_respinto(Permesso $permesso): void
    {
        $altri = array_values(array_diff(array_column(Permesso::cases(), 'value'), [$permesso->value]));
        $ruolo = Ruolo::create(['nome' => 'Prova', 'permessi' => $altri]);

        $this->actingAs($this->utenteCon($ruolo))->get('/prova/'.$permesso->value)->assertForbidden();
    }

    #[DataProvider('permessi')]
    public function test_chi_non_ha_assegnazioni_e_respinto(Permesso $permesso): void
    {
        $this->actingAs(User::factory()->create())->get('/prova/'.$permesso->value)->assertForbidden();
    }

    #[DataProvider('permessi')]
    public function test_un_ospite_va_all_accesso(Permesso $permesso): void
    {
        $this->get('/prova/'.$permesso->value)->assertRedirect(route('accesso'));
    }

    #[DataProvider('permessi')]
    public function test_un_assegnazione_scaduta_non_da_piu_permessi(Permesso $permesso): void
    {
        $ruolo = Ruolo::create(['nome' => 'Prova', 'permessi' => [$permesso->value]]);
        $utente = $this->utenteCon($ruolo, ['scade_il' => '2026-11-10']);

        $this->travelTo('2026-11-10 23:59');
        $this->actingAs($utente)->get('/prova/'.$permesso->value)->assertOk(); // l'ultimo giorno vale ancora

        $this->travelTo('2026-11-11 00:00');
        $this->actingAs($utente->fresh())->get('/prova/'.$permesso->value)->assertForbidden();
    }

    public function test_i_permessi_di_piu_assegnazioni_si_sommano(): void
    {
        $utente = $this->utenteCon('Lettore esterno');
        $this->assegna($utente, Ruolo::create(['nome' => 'Cassiere', 'permessi' => ['inserire_movimenti_cassa']]));

        $this->actingAs($utente)->get('/prova/vedere')->assertOk();
        $this->actingAs($utente)->get('/prova/inserire_movimenti_cassa')->assertOk();
        $this->actingAs($utente)->get('/prova/importare_e_confermare')->assertForbidden();
    }

    public function test_un_assegnazione_scaduta_non_toglie_quelle_valide(): void
    {
        $utente = $this->utenteCon('Tesoriere', ['scade_il' => now()->subDay()->toDateString()]);
        $this->assegna($utente, 'Lettore esterno');

        $this->actingAs($utente)->get('/prova/vedere')->assertOk();
        $this->actingAs($utente->fresh())->get('/prova/gestire_regole')->assertForbidden();
    }

    public function test_l_ambito_limita_la_scrittura_alla_cassa_festa(): void
    {
        $utente = $this->utenteCon('Consigliere', ['conti' => [7]]);

        $this->actingAs($utente)->get('/prova/inserire_movimenti_cassa/7')->assertOk();
        $this->actingAs($utente)->get('/prova/inserire_movimenti_cassa/8')->assertForbidden();
        $this->actingAs($utente)->get('/prova/proporre_classificazioni/8')->assertForbidden();
        $this->actingAs($utente)->get('/prova/modificare_movimenti_propri/7')->assertOk();
    }

    public function test_senza_un_conto_l_ambito_non_si_applica(): void
    {
        $utente = $this->utenteCon('Consigliere', ['conti' => [7]]);

        $this->assertTrue($utente->puo(Permesso::InserireMovimentiCassa));
    }

    public function test_un_assegnazione_senza_conti_non_da_il_permesso_nemmeno_senza_chiedere_un_conto(): void
    {
        $this->assertFalse($this->utenteCon('Consigliere', ['conti' => []])->puo(Permesso::InserireMovimentiCassa));
    }

    public function test_l_ambito_non_limita_la_lettura(): void
    {
        $utente = $this->utenteCon('Consigliere', ['conti' => [7]]);

        $this->actingAs($utente)->get('/prova/vedere/8')->assertOk();
        $this->actingAs($utente)->get('/prova/vedere_storia_movimento/8')->assertOk();
        $this->actingAs($utente)->get('/prova/note/8')->assertOk();
    }

    public function test_gli_ambiti_di_assegnazioni_diverse_si_sommano(): void
    {
        $utente = $this->utenteCon('Consigliere', ['conti' => [7]]);
        $this->assegna($utente, 'Consigliere', ['conti' => [8]]);

        $this->actingAs($utente)->get('/prova/inserire_movimenti_cassa/7')->assertOk();
        $this->actingAs($utente)->get('/prova/inserire_movimenti_cassa/8')->assertOk();
        $this->actingAs($utente)->get('/prova/inserire_movimenti_cassa/9')->assertForbidden();
    }

    public function test_un_assegnazione_con_tutti_i_conti_vale_su_ogni_conto(): void
    {
        $this->actingAs($this->utenteCon('Consigliere'))->get('/prova/inserire_movimenti_cassa/99')->assertOk();
    }

    public function test_un_assegnazione_rimasta_senza_conti_non_vale_su_nessuno(): void
    {
        $utente = $this->utenteCon('Consigliere', ['conti' => []]);

        $this->actingAs($utente)->get('/prova/inserire_movimenti_cassa/7')->assertForbidden();
    }

    #[DataProvider('permessi')]
    public function test_un_esterno_con_il_ruolo_di_amministratore_ha_solo_i_permessi_di_lettura(Permesso $permesso): void
    {
        $esterno = $this->utenteCon('Amministratore', ['scade_il' => now()->addMonth()->toDateString()], ['esterno' => true]);

        $risposta = $this->actingAs($esterno)->get('/prova/'.$permesso->value);

        $permesso->valePerEsterni() ? $risposta->assertOk() : $risposta->assertForbidden();
    }

    public function test_l_esterno_vede_registro_e_storia_ma_non_scrive(): void
    {
        $esterno = $this->utenteCon('Amministratore', ['scade_il' => now()->addMonth()->toDateString()], ['esterno' => true]);

        $this->assertEqualsCanonicalizing(
            [Permesso::Vedere, Permesso::Note, Permesso::VedereRegistroModifiche, Permesso::VedereStoriaMovimento],
            array_values(array_filter(Permesso::cases(), fn (Permesso $p) => $esterno->puo($p))),
        );
    }

    public function test_un_esterno_resta_in_sola_lettura_anche_con_un_ruolo_da_tesoriere(): void
    {
        $esterno = $this->utenteCon('Amministratore', ['scade_il' => now()->addMonth()->toDateString()], ['esterno' => true]);

        $this->actingAs($esterno)->get('/prova/vedere')->assertOk();
        $this->actingAs($esterno)->get('/prova/note')->assertOk();
        $this->actingAs($esterno)->get('/prova/importare_e_confermare')->assertForbidden();
        $this->actingAs($esterno)->get('/prova/gestire_utenti')->assertForbidden();
        $this->actingAs($esterno)->get('/prova/inserire_movimenti_cassa')->assertForbidden();
    }
}
