<?php

namespace Tests\Feature;

use App\Models\Conto;
use App\Models\RegistroModifica;
use App\Models\Ruolo;
use App\Models\User;
use App\Permesso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContiTest extends TestCase
{
    use RefreshDatabase;

    private function dati(array $sovrascrivi = []): array
    {
        return $sovrascrivi + [
            'tipo' => 'cassa', 'nome' => 'Cassa Festa', 'formato_import' => 'manuale',
            'riga_modello_d' => 'cassa', 'aperto_il' => '2026-05-01', 'saldo_iniziale' => '150,00',
        ];
    }

    public function test_il_tesoriere_crea_un_conto_con_tutti_i_suoi_dati(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');

        $this->actingAs($tesoriere)->post('/conti', $this->dati(['chiuso_il' => null]))->assertRedirect('/conti');

        $conto = Conto::firstOrFail();
        $this->assertSame('cassa', $conto->tipo);
        $this->assertSame('Cassa Festa', $conto->nome);
        $this->assertSame('manuale', $conto->formato_import);
        $this->assertSame('cassa', $conto->riga_modello_d);
        $this->assertSame('2026-05-01', $conto->aperto_il->toDateString());
        $this->assertNull($conto->chiuso_il);
        $this->assertSame(15000, $conto->saldo_iniziale);
    }

    public function test_la_creazione_finisce_nel_registro_delle_modifiche(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');
        $this->actingAs($tesoriere)->post('/conti', $this->dati());

        $riga = RegistroModifica::firstOrFail();
        $this->assertSame($tesoriere->id, $riga->user_id);
        $this->assertSame('conto', $riga->oggetto_tipo);
        $this->assertSame('creato', $riga->azione);
        $this->assertNull($riga->prima);
        $this->assertSame('Cassa Festa', $riga->dopo['nome']);
    }

    public function test_paypal_e_satispay_finiscono_nei_depositi_se_non_si_dice_altro(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'))->post('/conti', $this->dati([
            'tipo' => 'paypal', 'nome' => 'PayPal', 'formato_import' => 'paypal_csv', 'riga_modello_d' => '',
        ]))->assertRedirect('/conti');

        $this->assertSame('depositi', Conto::firstOrFail()->riga_modello_d);
    }

    public function test_solo_chi_ha_il_permesso_crea_o_modifica_conti(): void
    {
        $conto = Conto::factory()->create();

        foreach (['Consigliere', 'Lettore esterno'] as $ruolo) {
            $utente = $this->utenteCon($ruolo);
            $this->actingAs($utente)->get('/conti/nuovo')->assertForbidden();
            $this->actingAs($utente)->post('/conti', $this->dati())->assertForbidden();
            $this->actingAs($utente)->put("/conti/{$conto->id}", $this->dati())->assertForbidden();
            $this->actingAs($utente)->post("/conti/{$conto->id}/chiusura", ['chiuso_il' => today()->toDateString()])->assertForbidden();
        }
        $this->assertSame(1, Conto::count());
    }

    public function test_tutti_vedono_l_elenco_dei_conti(): void
    {
        Conto::factory()->create(['nome' => 'Cassa Festa', 'saldo_iniziale' => 123456]);

        $this->actingAs($this->utenteCon('Lettore esterno'))->get('/conti')->assertOk()
            ->assertSee('Cassa Festa')->assertSee('1.234,56');
    }

    public function test_il_formato_deve_essere_quello_del_tipo_di_conto(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'))->post('/conti', $this->dati(['formato_import' => 'paypal_csv']))
            ->assertSessionHasErrors('formato_import');
        $this->assertSame(0, Conto::count());
    }

    public function test_il_nome_e_unico_e_il_saldo_e_in_euro(): void
    {
        Conto::factory()->create(['nome' => 'Cassa Festa']);
        $tesoriere = $this->utenteCon('Tesoriere');

        $this->actingAs($tesoriere)->post('/conti', $this->dati())->assertSessionHasErrors('nome');
        $this->actingAs($tesoriere)->post('/conti', $this->dati(['nome' => 'Altra', 'saldo_iniziale' => '12,345']))
            ->assertSessionHasErrors('saldo_iniziale');
    }

    public function test_si_modifica_un_conto_e_la_modifica_resta_nel_registro(): void
    {
        $conto = Conto::factory()->create(['nome' => 'Cassa Festa', 'saldo_iniziale' => 0]);
        $tesoriere = $this->utenteCon('Tesoriere');

        $this->actingAs($tesoriere)->put("/conti/{$conto->id}", $this->dati(['nome' => 'Cassa Festa 2026', 'saldo_iniziale' => '20,50']))
            ->assertRedirect('/conti');

        $this->assertSame('Cassa Festa 2026', $conto->fresh()->nome);
        $this->assertSame(2050, $conto->fresh()->saldo_iniziale);
        $riga = RegistroModifica::where('azione', 'modificato')->firstOrFail();
        $this->assertSame('Cassa Festa', $riga->prima['nome']);
        $this->assertSame('Cassa Festa 2026', $riga->dopo['nome']);
    }

    public function test_un_conto_chiuso_non_si_modifica(): void
    {
        $conto = Conto::factory()->create(['chiuso_il' => '2026-06-01']);

        $this->actingAs($this->utenteCon('Tesoriere'))->put("/conti/{$conto->id}", $this->dati(['nome' => 'Altro']))
            ->assertSessionHasErrors();
        $this->assertNotSame('Altro', $conto->fresh()->nome);
    }

    public function test_alla_chiusura_le_assegnazioni_su_quel_conto_decadono_e_chi_resta_senza_conti_e_avvisato(): void
    {
        $festa = Conto::factory()->create(['nome' => 'Cassa Festa']);
        $altra = Conto::factory()->create(['nome' => 'Cassa Carnevale']);
        $solo = $this->utenteCon('Consigliere', ['conti' => [$festa->id]], ['name' => 'Dario Gialli']);
        $due = $this->utenteCon('Consigliere', ['conti' => [$festa->id, $altra->id]], ['name' => 'Carla Neri']);
        $tutti = $this->utenteCon('Consigliere');
        $tesoriere = $this->utenteCon('Tesoriere');

        $this->actingAs($tesoriere)->post("/conti/{$festa->id}/chiusura", ['chiuso_il' => today()->toDateString()])
            ->assertRedirect('/conti')
            ->assertSessionHas('avviso', fn ($testo) => str_contains($testo, 'Dario Gialli') && ! str_contains($testo, 'Carla Neri'));

        $this->assertSame(today()->toDateString(), $festa->fresh()->chiuso_il->toDateString());
        $this->assertDatabaseMissing('assegnazione_conto', ['conto_id' => $festa->id]);
        $this->assertDatabaseHas('assegnazione_conto', ['conto_id' => $altra->id]);
        // «nessun conto» non diventa «tutti i conti»
        $this->assertFalse($solo->fresh()->puo(Permesso::InserireMovimentiCassa));
        $this->assertFalse($solo->fresh()->puo(Permesso::InserireMovimentiCassa, $altra->id));
        $this->assertTrue($due->fresh()->puo(Permesso::InserireMovimentiCassa, $altra->id));
        $this->assertTrue($tutti->fresh()->puo(Permesso::InserireMovimentiCassa, $altra->id));
        $this->assertSame('chiuso', RegistroModifica::latest('id')->firstOrFail()->azione);
    }

    public function test_l_amministratore_continua_a_vedere_l_avviso_finche_la_persona_resta_senza_conti(): void
    {
        $festa = Conto::factory()->create();
        $this->utenteCon('Consigliere', ['conti' => [$festa->id]], ['name' => 'Dario Gialli']);
        $admin = $this->utenteCon('Amministratore');
        $this->actingAs($this->utenteCon('Tesoriere'))->post("/conti/{$festa->id}/chiusura", ['chiuso_il' => today()->toDateString()]);

        $this->actingAs($admin)->get('/utenti')->assertSee('Dario Gialli')->assertSee('senza conti', false);
        $this->actingAs($admin)->get('/')->assertSee('senza conti', false);
    }

    public function test_la_chiusura_non_puo_essere_nel_futuro_ne_prima_dell_apertura(): void
    {
        $conto = Conto::factory()->create(['aperto_il' => '2026-05-01']);
        $tesoriere = $this->utenteCon('Tesoriere');

        $this->actingAs($tesoriere)->post("/conti/{$conto->id}/chiusura", ['chiuso_il' => today()->addDay()->toDateString()])
            ->assertSessionHasErrors('chiuso_il');
        $this->actingAs($tesoriere)->post("/conti/{$conto->id}/chiusura", ['chiuso_il' => '2026-04-30'])
            ->assertSessionHasErrors('chiuso_il');
        $this->assertNull($conto->fresh()->chiuso_il);
    }

    public function test_l_assegnazione_puo_limitarsi_a_certi_conti(): void
    {
        $festa = Conto::factory()->create();
        $altra = Conto::factory()->create();
        $consigliere = User::factory()->create();
        $admin = $this->utenteCon('Amministratore');
        $ruolo = Ruolo::where('nome', 'Consigliere')->firstOrFail();
        $this->withSession(['auth.password_confirmed_at' => now()->unix()]);

        $this->actingAs($admin)->post("/utenti/{$consigliere->id}/assegnazioni", ['ruolo_id' => $ruolo->id, 'conti' => [$festa->id]])
            ->assertRedirect('/utenti');

        $this->assertTrue($consigliere->fresh()->puo(Permesso::InserireMovimentiCassa, $festa->id));
        $this->assertFalse($consigliere->fresh()->puo(Permesso::InserireMovimentiCassa, $altra->id));
        $this->actingAs($admin)->get('/utenti')->assertSee('Cassa', false)->assertSee($festa->nome);
    }

    public function test_senza_conti_scelti_l_assegnazione_vale_su_tutti_e_un_conto_chiuso_non_si_sceglie(): void
    {
        $chiuso = Conto::factory()->create(['chiuso_il' => '2026-06-01']);
        $consigliere = User::factory()->create();
        $admin = $this->utenteCon('Amministratore');
        $ruolo = Ruolo::where('nome', 'Consigliere')->firstOrFail();
        $this->withSession(['auth.password_confirmed_at' => now()->unix()]);

        $this->actingAs($admin)->post("/utenti/{$consigliere->id}/assegnazioni", ['ruolo_id' => $ruolo->id, 'conti' => [$chiuso->id]])
            ->assertSessionHasErrors('conti.0');
        $this->actingAs($admin)->post("/utenti/{$consigliere->id}/assegnazioni", ['ruolo_id' => $ruolo->id])->assertRedirect('/utenti');

        $this->assertTrue($consigliere->fresh()->assegnazioni()->firstOrFail()->tutti_i_conti);
    }
}
