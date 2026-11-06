<?php

namespace Tests\Feature;

use App\Models\Assegnazione;
use App\Models\Ruolo;
use App\Models\User;
use App\Permesso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GestioneUtentiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Accesso appena fatto: la riautenticazione ha i suoi test in RiautenticazioneTest.
        $this->withSession(['auth.password_confirmed_at' => now()->unix()]);
    }

    private function amministratore(): User
    {
        return $this->utenteCon('Amministratore', [], ['name' => 'Anna Bianchi']);
    }

    private function ruolo(string $nome): Ruolo
    {
        return Ruolo::where('nome', $nome)->firstOrFail();
    }

    /** @return iterable<string, array{string, string}> */
    public static function pagine(): iterable
    {
        yield 'elenco utenti' => ['get', '/utenti'];
        yield 'elenco in PDF' => ['get', '/utenti/autorizzati.pdf'];
        yield 'elenco ruoli' => ['get', '/ruoli'];
        yield 'nuovo ruolo' => ['get', '/ruoli/nuovo'];
        yield 'crea ruolo' => ['post', '/ruoli'];
        yield 'modifica ruolo' => ['get', '/ruoli/{ruolo}'];
        yield 'salva ruolo' => ['put', '/ruoli/{ruolo}'];
        yield 'aggiungi esterno' => ['post', '/utenti/esterni'];
        yield 'assegna ruolo' => ['post', '/utenti/{utente}/assegnazioni'];
        yield 'revoca assegnazione' => ['delete', '/assegnazioni/{assegnazione}'];
    }

    #[DataProvider('pagine')]
    public function test_la_gestione_e_solo_per_chi_ha_il_permesso(string $metodo, string $uri): void
    {
        // Gli id veri: il binding dei modelli risponde 404 prima del controllo dei permessi.
        $assegnazione = $this->assegna(User::factory()->create(), 'Lettore esterno');
        $uri = strtr($uri, ['{ruolo}' => $assegnazione->ruolo_id, '{utente}' => $assegnazione->user_id, '{assegnazione}' => $assegnazione->id]);

        $this->call($metodo, $uri)->assertRedirect(route('accesso'));
        $this->actingAs($this->utenteCon('Tesoriere'))->call($metodo, $uri)->assertForbidden();
        $this->actingAs($this->utenteCon('Consigliere'))->call($metodo, $uri)->assertForbidden();
        $this->actingAs($this->utenteCon('Lettore esterno'))->call($metodo, $uri)->assertForbidden();
    }

    public function test_l_elenco_mostra_persone_ruoli_scadenze_e_ambito(): void
    {
        $admin = $this->amministratore();
        $this->utenteCon('Consigliere', ['scade_il' => '2027-03-31'], ['name' => 'Carla Neri']);
        $this->utenteCon('Consigliere', ['conti' => [3, 4]], ['name' => 'Dario Gialli']);

        $this->actingAs($admin)->get('/utenti')->assertOk()
            ->assertSee('Anna Bianchi')->assertSee('Amministratore')
            ->assertSee('Carla Neri')->assertSee('31/03/2027')
            ->assertSee('Dario Gialli')->assertSee('2 conti');
    }

    public function test_si_crea_un_ruolo_spuntando_permessi_dall_elenco_fisso(): void
    {
        $this->actingAs($this->amministratore())
            ->post('/ruoli', ['nome' => 'Revisore', 'permessi' => ['vedere', 'vedere_registro_modifiche']])
            ->assertRedirect(route('ruoli'));

        $this->assertEqualsCanonicalizing(['vedere', 'vedere_registro_modifiche'], $this->ruolo('Revisore')->permessi);
    }

    public function test_un_ruolo_non_accetta_permessi_fuori_dall_elenco_ne_nomi_doppi(): void
    {
        $this->actingAs($this->amministratore())
            ->post('/ruoli', ['nome' => 'Strano', 'permessi' => ['vedere', 'fare_tutto']])->assertSessionHasErrors('permessi.1');
        $this->post('/ruoli', ['nome' => 'Tesoriere', 'permessi' => ['vedere']])->assertSessionHasErrors('nome');
        $this->post('/ruoli', ['nome' => '', 'permessi' => ['vedere']])->assertSessionHasErrors('nome');
        $this->assertDatabaseMissing('ruoli', ['nome' => 'Strano']);
    }

    public function test_i_ruoli_predefiniti_si_modificano(): void
    {
        $tesoriere = $this->ruolo('Tesoriere');

        $this->actingAs($this->amministratore())->get('/ruoli/'.$tesoriere->id)->assertOk()
            ->assertSee(Permesso::GestireRegole->etichetta(), false);
        $this->put('/ruoli/'.$tesoriere->id, ['nome' => 'Tesoriere', 'permessi' => ['vedere', 'note']])->assertRedirect(route('ruoli'));

        $this->assertEqualsCanonicalizing(['vedere', 'note'], $tesoriere->fresh()->permessi);
    }

    public function test_un_ruolo_modificato_cambia_subito_i_permessi_di_chi_lo_ha(): void
    {
        $consigliere = $this->utenteCon('Consigliere');
        $this->assertTrue($consigliere->puo(Permesso::InserireMovimentiCassa));

        $this->actingAs($this->amministratore())->put('/ruoli/'.$this->ruolo('Consigliere')->id, ['nome' => 'Consigliere', 'permessi' => ['vedere']]);

        $this->assertFalse($consigliere->fresh()->puo(Permesso::InserireMovimentiCassa));
    }

    public function test_si_assegna_un_ruolo_con_scadenza(): void
    {
        $persona = User::factory()->create();

        $this->actingAs($this->amministratore())
            ->post("/utenti/{$persona->id}/assegnazioni", ['ruolo_id' => $this->ruolo('Consigliere')->id, 'scade_il' => now()->addYear()->toDateString()])
            ->assertRedirect(route('utenti'));

        $assegnazione = $persona->assegnazioni()->firstOrFail();
        $this->assertTrue($assegnazione->tutti_i_conti);
        $this->assertSame(now()->addYear()->toDateString(), $assegnazione->scade_il->toDateString());
        $this->assertTrue($persona->fresh()->puo(Permesso::InserireMovimentiCassa));
    }

    public function test_la_scadenza_di_un_interno_e_facoltativa_ma_non_nel_passato(): void
    {
        $persona = User::factory()->create();
        $this->actingAs($this->amministratore());

        $this->post("/utenti/{$persona->id}/assegnazioni", ['ruolo_id' => $this->ruolo('Consigliere')->id, 'scade_il' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors('scade_il');
        $this->post("/utenti/{$persona->id}/assegnazioni", ['ruolo_id' => $this->ruolo('Consigliere')->id])->assertRedirect(route('utenti'));

        $this->assertNull($persona->assegnazioni()->firstOrFail()->scade_il);
    }

    public function test_una_persona_puo_avere_piu_assegnazioni(): void
    {
        $persona = $this->utenteCon('Lettore esterno');

        $this->actingAs($this->amministratore())
            ->post("/utenti/{$persona->id}/assegnazioni", ['ruolo_id' => $this->ruolo('Consigliere')->id])->assertRedirect(route('utenti'));

        $this->assertCount(2, $persona->assegnazioni);
    }

    public function test_si_revoca_un_assegnazione(): void
    {
        $assegnazione = $this->assegna($persona = User::factory()->create(), 'Consigliere');

        $this->actingAs($this->amministratore())->delete("/assegnazioni/{$assegnazione->id}")->assertRedirect(route('utenti'));

        $this->assertFalse($persona->fresh()->puo(Permesso::Vedere));
    }

    // Ultimo Amministratore

    public function test_non_si_revoca_l_assegnazione_dell_ultimo_amministratore(): void
    {
        $admin = $this->amministratore();

        $this->actingAs($admin)->delete('/assegnazioni/'.$admin->assegnazioni()->value('id'))->assertSessionHasErrors('amministratori');

        $this->assertTrue($admin->fresh()->puo(Permesso::GestireUtenti));
    }

    public function test_non_si_toglie_la_gestione_utenti_al_ruolo_dell_ultimo_amministratore(): void
    {
        $admin = $this->amministratore();
        $ruolo = $this->ruolo('Amministratore');
        $permessi = array_values(array_diff($ruolo->permessi, ['gestire_utenti']));

        $this->actingAs($admin)->put('/ruoli/'.$ruolo->id, ['nome' => 'Amministratore', 'permessi' => $permessi])->assertSessionHasErrors('amministratori');

        $this->assertContains('gestire_utenti', $ruolo->fresh()->permessi);
    }

    public function test_con_due_amministratori_se_ne_puo_togliere_uno(): void
    {
        $primo = $this->amministratore();
        $this->utenteCon('Amministratore');

        $this->actingAs($primo)->delete('/assegnazioni/'.$primo->assegnazioni()->value('id'))->assertRedirect(route('utenti'));

        $this->assertDatabaseCount('assegnazioni', 1);
    }

    public function test_un_amministratore_scaduto_o_esterno_non_conta(): void
    {
        $admin = $this->amministratore();
        $this->utenteCon('Amministratore', ['scade_il' => now()->subDay()->toDateString()]);
        $this->utenteCon('Amministratore', ['scade_il' => now()->addDay()->toDateString()], ['esterno' => true]);

        $this->actingAs($admin)->delete('/assegnazioni/'.$admin->assegnazioni()->value('id'))->assertSessionHasErrors('amministratori');
    }

    public function test_una_scadenza_a_oggi_non_aggira_la_guardia_sull_ultimo_amministratore(): void
    {
        $admin = $this->amministratore();
        $permanente = $admin->assegnazioni()->firstOrFail();
        $this->assegna($admin, 'Amministratore', ['scade_il' => now()->toDateString()]);

        $this->actingAs($admin)->delete("/assegnazioni/{$permanente->id}")->assertSessionHasErrors('amministratori');

        $this->assertDatabaseHas('assegnazioni', ['id' => $permanente->id]);
        $this->get('/utenti')->assertSee('un solo Amministratore');
    }

    public function test_la_guardia_vale_anche_se_tutti_gli_amministratori_hanno_una_scadenza(): void
    {
        $fra1mese = ['scade_il' => now()->addMonth()->toDateString()];
        $primo = $this->utenteCon('Amministratore', $fra1mese);
        $secondo = $this->utenteCon('Amministratore', $fra1mese);
        $ruolo = $this->ruolo('Amministratore');

        $this->actingAs($primo)->put('/ruoli/'.$ruolo->id, ['nome' => 'Amministratore', 'permessi' => ['vedere']])->assertSessionHasErrors('amministratori');
        $this->assertContains('gestire_utenti', $ruolo->fresh()->permessi);

        $this->delete('/assegnazioni/'.$primo->assegnazioni()->value('id'))->assertRedirect(route('utenti')); // ne resta uno
        $this->actingAs($secondo)->delete('/assegnazioni/'.$secondo->assegnazioni()->value('id'))->assertSessionHasErrors('amministratori');
    }

    public function test_un_ruolo_rinominato_che_ha_la_gestione_utenti_resta_il_ruolo_dell_amministratore(): void
    {
        $admin = $this->amministratore();
        $this->ruolo('Amministratore')->update(['nome' => 'Presidente']);

        $this->actingAs($admin)->delete('/assegnazioni/'.$admin->assegnazioni()->value('id'))->assertSessionHasErrors('amministratori');
    }

    public function test_con_un_solo_amministratore_l_app_lo_segnala(): void
    {
        $admin = $this->amministratore();

        $this->actingAs($admin)->get('/utenti')->assertSee('un solo Amministratore');
        $this->actingAs($admin)->get(route('home'))->assertSee('un solo Amministratore');

        $this->utenteCon('Amministratore');
        $this->actingAs($admin)->get('/utenti')->assertDontSee('un solo Amministratore');
    }

    public function test_l_app_segnala_anche_se_nessun_amministratore_e_senza_scadenza(): void
    {
        $fra1mese = ['scade_il' => now()->addMonth()->toDateString()];
        $unico = $this->utenteCon('Amministratore', $fra1mese);

        $this->actingAs($unico)->get(route('home'))->assertSee('Nessun Amministratore è senza scadenza');

        $this->utenteCon('Amministratore', $fra1mese);
        $this->actingAs($unico)->get(route('home'))->assertSee('Nessun Amministratore è senza scadenza');
    }

    public function test_l_avviso_dell_unico_amministratore_non_si_vede_agli_altri(): void
    {
        $this->amministratore();

        $this->actingAs($this->utenteCon('Tesoriere'))->get(route('home'))->assertDontSee('un solo Amministratore');
    }

    // Esterni

    private function esterno(array $dati = []): array
    {
        return $dati + [
            'name' => 'Paolo Verdi', 'email' => 'paolo@studio.example', 'ruolo_id' => $this->ruolo('Lettore esterno')->id,
            'scade_il' => now()->addMonths(6)->toDateString(), 'nomina_firmata_il' => '2026-11-02',
        ];
    }

    public function test_si_aggiunge_un_esterno_con_scadenza_e_nomina_firmata(): void
    {
        $this->actingAs($this->amministratore())->post('/utenti/esterni', $this->esterno())->assertRedirect(route('utenti'));

        $esterno = User::firstWhere('email', 'paolo@studio.example');
        $this->assertTrue($esterno->esterno);
        $this->assertNull($esterno->google_id);
        $assegnazione = $esterno->assegnazioni()->firstOrFail();
        $this->assertSame('2026-11-02', $assegnazione->nomina_firmata_il->toDateString());
        $this->assertTrue($esterno->puo(Permesso::Vedere));
        $this->assertFalse($esterno->puo(Permesso::GestireUtenti));
        $this->get('/utenti')->assertSee('Paolo Verdi')->assertSee('esterno')->assertSee('nomina firmata il 02/11/2026');
    }

    public function test_la_scadenza_di_un_esterno_e_obbligatoria(): void
    {
        $this->actingAs($this->amministratore())->post('/utenti/esterni', $this->esterno(['scade_il' => '']))->assertSessionHasErrors('scade_il');
        $this->post('/utenti/esterni', $this->esterno(['scade_il' => now()->subDay()->toDateString()]))->assertSessionHasErrors('scade_il');
        $this->assertDatabaseMissing('users', ['email' => 'paolo@studio.example']);
    }

    public function test_un_esterno_non_puo_avere_l_indirizzo_del_workspace_ne_uno_gia_presente(): void
    {
        User::factory()->create(['email' => 'gia@studio.example']);
        $this->actingAs($this->amministratore());

        $this->post('/utenti/esterni', $this->esterno(['email' => 'paolo@associazione.example']))->assertSessionHasErrors('email');
        $this->post('/utenti/esterni', $this->esterno(['email' => 'gia@studio.example']))->assertSessionHasErrors('email');
        $this->post('/utenti/esterni', $this->esterno(['email' => 'non-una-email']))->assertSessionHasErrors('email');
    }

    public function test_un_altro_ruolo_a_un_esterno_non_gli_da_la_scrittura(): void
    {
        $this->actingAs($this->amministratore())
            ->post('/utenti/esterni', $this->esterno(['ruolo_id' => $this->ruolo('Tesoriere')->id]))->assertRedirect(route('utenti'));

        $esterno = User::firstWhere('email', 'paolo@studio.example');
        $this->assertTrue($esterno->puo(Permesso::Vedere));
        $this->assertFalse($esterno->puo(Permesso::ImportareEConfermare));
    }

    public function test_un_assegnazione_a_un_esterno_richiede_la_scadenza(): void
    {
        $esterno = $this->utenteCon('Lettore esterno', ['scade_il' => now()->addMonth()->toDateString()], ['esterno' => true]);

        $this->actingAs($this->amministratore())
            ->post("/utenti/{$esterno->id}/assegnazioni", ['ruolo_id' => $this->ruolo('Lettore esterno')->id])->assertSessionHasErrors('scade_il');
    }

    // Elenco degli autorizzati

    public function test_l_elenco_degli_autorizzati_si_esporta_in_pdf_datato(): void
    {
        $this->travelTo('2026-11-06 10:00');
        $this->utenteCon('Consigliere', ['scade_il' => '2027-03-31'], ['name' => 'Carla Neri']);

        $risposta = $this->actingAs($this->amministratore())->get('/utenti/autorizzati.pdf');

        $risposta->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('elenco-autorizzati-2026-11-06.pdf', $risposta->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $risposta->getContent());
    }

    public function test_l_elenco_degli_autorizzati_ha_persone_ruoli_ambito_scadenze_e_nomine(): void
    {
        $this->travelTo('2026-11-06 10:00');
        $this->utenteCon('Consigliere', ['scade_il' => '2027-03-31', 'conti' => [3]], ['name' => 'Carla Neri']);
        $this->utenteCon('Lettore esterno', ['scade_il' => '2027-01-15', 'nomina_firmata_il' => '2026-11-02'], ['name' => 'Paolo Verdi', 'esterno' => true]);
        $this->utenteCon('Tesoriere', ['scade_il' => '2026-10-01'], ['name' => 'Ugo Scaduti']);

        $html = view('autorizzati', ['assegnazioni' => Assegnazione::attive()->with('user', 'ruolo')->get(), 'data' => now()])->render();

        $this->assertStringContainsString('Elenco degli autorizzati', $html);
        $this->assertStringContainsString('6 novembre 2026', $html);
        $this->assertStringContainsString('Carla Neri', $html);
        $this->assertStringContainsString('1 conto', $html);
        $this->assertStringContainsString('31/03/2027', $html);
        $this->assertStringContainsString('Paolo Verdi', $html);
        $this->assertStringContainsString('02/11/2026', $html);
        $this->assertStringNotContainsString('Ugo Scaduti', $html); // un'assegnazione scaduta non autorizza più
    }
}
