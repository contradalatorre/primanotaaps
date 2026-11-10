<?php

namespace Tests\Feature;

use App\Models\Conto;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Ruolo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class MovimentiTest extends TestCase
{
    use RefreshDatabase;

    private Conto $festa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-20 10:00');
        $this->festa = Conto::factory()->create(['nome' => 'Cassa Festa', 'aperto_il' => '2026-05-01']);
    }

    private function dati(array $sovrascrivi = []): array
    {
        return $sovrascrivi + ['data' => '2026-05-10', 'verso' => 'uscita', 'importo' => '12,50', 'descrizione' => 'Ghiaccio per la festa'];
    }

    private function movimentoDi(User $autore, array $attributi = []): Movimento
    {
        return Movimento::factory()->create($attributi + ['conto_id' => $this->festa->id, 'inserito_da' => $autore->id]);
    }

    public function test_un_consigliere_inserisce_un_movimento_di_cassa_che_parte_da_classificare(): void
    {
        $consigliere = $this->utenteCon('Consigliere', [], ['name' => 'Dario Gialli']);

        $this->actingAs($consigliere)->post("/conti/{$this->festa->id}/movimenti", $this->dati(['valuta_originale' => '14 USD']))
            ->assertRedirect("/movimenti?conto={$this->festa->id}");

        $movimento = Movimento::firstOrFail();
        $this->assertSame($this->festa->id, $movimento->conto_id);
        $this->assertSame('2026-05-10', $movimento->data->toDateString());
        $this->assertSame(1250, $movimento->importo);
        $this->assertSame('uscita', $movimento->verso);
        $this->assertSame('Ghiaccio per la festa', $movimento->descrizione);
        $this->assertSame('14 USD', $movimento->valuta_originale);
        $this->assertSame('da_classificare', $movimento->stato);
        $this->assertSame($consigliere->id, $movimento->inserito_da);
        $this->assertTrue($movimento->manuale);
    }

    public function test_l_elenco_mostra_chi_ha_inserito_il_movimento_e_quando(): void
    {
        $this->movimentoDi($this->utenteCon('Consigliere', [], ['name' => 'Dario Gialli']));

        $this->actingAs($this->utenteCon('Lettore esterno'))->get('/movimenti')->assertOk()
            ->assertSee('Inserito da Dario Gialli il 20/05/2026')->assertSee('Da classificare')
            ->assertSee('Ghiaccio per la festa')->assertSee('12,50')->assertSee('Cassa Festa');
    }

    public function test_il_modulo_e_usabile_dal_telefono(): void
    {
        $html = $this->actingAs($this->utenteCon('Consigliere'))->get("/conti/{$this->festa->id}/movimenti/nuovo")->assertOk()->getContent();

        $this->assertStringContainsString('name="viewport" content="width=device-width, initial-scale=1"', $html);
        $this->assertStringContainsString('inputmode="decimal"', $html); // tastierino numerico
        $this->assertStringNotContainsString('<table', $html);
    }

    public function test_l_inserimento_finisce_nel_registro_delle_modifiche(): void
    {
        $consigliere = $this->utenteCon('Consigliere');
        $this->actingAs($consigliere)->post("/conti/{$this->festa->id}/movimenti", $this->dati());

        $riga = RegistroModifica::firstOrFail();
        $this->assertSame($consigliere->id, $riga->user_id);
        $this->assertSame('movimento', $riga->oggetto_tipo);
        $this->assertSame(Movimento::firstOrFail()->id, $riga->oggetto_id);
        $this->assertSame('inserito', $riga->azione);
        $this->assertNull($riga->prima);
        $this->assertSame(1250, $riga->dopo['importo']);
    }

    public function test_senza_il_permesso_non_si_inserisce(): void
    {
        $this->actingAs($this->utenteCon('Lettore esterno'))->post("/conti/{$this->festa->id}/movimenti", $this->dati())->assertForbidden();
        $esterno = $this->utenteCon('Consigliere', ['scade_il' => today()->addMonth()->toDateString()], ['esterno' => true]);
        $this->actingAs($esterno)->post("/conti/{$this->festa->id}/movimenti", $this->dati())->assertForbidden();
        $this->assertSame(0, Movimento::count());
    }

    public function test_l_ambito_limita_i_conti_su_cui_si_scrive_ma_non_quelli_che_si_vedono(): void
    {
        $altra = Conto::factory()->create(['nome' => 'Cassa Carnevale', 'aperto_il' => '2026-05-01']);
        $consigliere = $this->utenteCon('Consigliere', ['conti' => [$this->festa->id]]);
        $this->movimentoDi($this->utenteCon('Tesoriere'), ['conto_id' => $altra->id, 'descrizione' => 'Coriandoli']);

        $this->actingAs($consigliere)->get("/conti/{$altra->id}/movimenti/nuovo")->assertForbidden();
        $this->actingAs($consigliere)->post("/conti/{$altra->id}/movimenti", $this->dati())->assertForbidden();
        $this->actingAs($consigliere)->post("/conti/{$this->festa->id}/movimenti", $this->dati())->assertRedirect();
        $this->assertSame(1, Movimento::where('conto_id', $this->festa->id)->count());
        $this->assertSame(0, Movimento::where('inserito_da', $consigliere->id)->where('conto_id', $altra->id)->count());
        // vede anche i movimenti fuori dal suo ambito
        $this->actingAs($consigliere)->get('/movimenti')->assertSee('Coriandoli');
    }

    public function test_si_scrive_solo_sulle_casse_aperte(): void
    {
        $banca = Conto::factory()->create(['tipo' => 'banca', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi']);
        $chiusa = Conto::factory()->create(['aperto_il' => '2026-05-01', 'chiuso_il' => '2026-05-15']);
        $tesoriere = $this->utenteCon('Tesoriere');

        $this->actingAs($tesoriere)->post("/conti/{$banca->id}/movimenti", $this->dati())->assertSessionHasErrors('conto');
        $this->actingAs($tesoriere)->post("/conti/{$chiusa->id}/movimenti", $this->dati())->assertSessionHasErrors('conto');
        $this->assertSame(0, Movimento::count());
    }

    public function test_i_dati_del_movimento_sono_validati(): void
    {
        $consigliere = $this->utenteCon('Consigliere');
        $url = "/conti/{$this->festa->id}/movimenti";

        $this->actingAs($consigliere)->post($url, $this->dati(['importo' => '0']))->assertSessionHasErrors('importo');
        $this->actingAs($consigliere)->post($url, $this->dati(['importo' => '-5']))->assertSessionHasErrors('importo');
        $this->actingAs($consigliere)->post($url, $this->dati(['importo' => '12,345']))->assertSessionHasErrors('importo');
        $this->actingAs($consigliere)->post($url, $this->dati(['importo' => 'dodici']))->assertSessionHasErrors('importo');
        $this->actingAs($consigliere)->post($url, $this->dati(['verso' => 'giroconto']))->assertSessionHasErrors('verso');
        $this->actingAs($consigliere)->post($url, $this->dati(['descrizione' => '']))->assertSessionHasErrors('descrizione');
        $this->actingAs($consigliere)->post($url, $this->dati(['data' => '2026-04-30']))->assertSessionHasErrors('data'); // prima dell'apertura
        $this->actingAs($consigliere)->post($url, $this->dati(['data' => '2026-05-21']))->assertSessionHasErrors('data'); // nel futuro
        $this->assertSame(0, Movimento::count());
    }

    public function test_l_importo_si_scrive_all_italiana(): void
    {
        $this->actingAs($this->utenteCon('Consigliere'))->post("/conti/{$this->festa->id}/movimenti", $this->dati(['importo' => '1.234,50', 'verso' => 'entrata']));

        $this->assertSame(123450, Movimento::firstOrFail()->importo);
        $this->assertSame('entrata', Movimento::firstOrFail()->verso);
    }

    public function test_l_autore_modifica_il_proprio_movimento_e_la_modifica_resta_nel_registro(): void
    {
        $autore = $this->utenteCon('Consigliere');
        $movimento = $this->movimentoDi($autore);

        $this->actingAs($autore)->get("/movimenti/{$movimento->id}/modifica")->assertOk()->assertSee('Ghiaccio per la festa');
        $this->actingAs($autore)->put("/movimenti/{$movimento->id}", $this->dati(['importo' => '15,00', 'descrizione' => 'Ghiaccio e sale']))
            ->assertRedirect();

        $this->assertSame(1500, $movimento->fresh()->importo);
        $this->assertSame('Ghiaccio e sale', $movimento->fresh()->descrizione);
        $riga = RegistroModifica::where('azione', 'modificato')->firstOrFail();
        $this->assertSame(1250, $riga->prima['importo']);
        $this->assertSame(1500, $riga->dopo['importo']);
        $this->assertSame($autore->id, $riga->user_id);
    }

    public function test_non_si_modifica_il_movimento_di_un_altro_ne_uno_confermato_ne_fuori_ambito(): void
    {
        $autore = $this->utenteCon('Consigliere');
        $altro = $this->utenteCon('Consigliere');
        $proprio = $this->movimentoDi($autore);
        $confermato = $this->movimentoDi($autore, ['stato' => 'confermato']);

        $this->actingAs($altro)->put("/movimenti/{$proprio->id}", $this->dati())->assertForbidden();
        $this->actingAs($this->utenteCon('Tesoriere'))->put("/movimenti/{$proprio->id}", $this->dati())->assertForbidden();
        $this->actingAs($autore)->put("/movimenti/{$confermato->id}", $this->dati())->assertForbidden();
        $this->actingAs($autore)->get("/movimenti/{$confermato->id}/modifica")->assertForbidden();

        // l'autore ha ancora il movimento ma non più il conto nel suo ambito
        $fuori = $this->utenteCon('Consigliere', ['conti' => [Conto::factory()->create()->id]]);
        $suo = $this->movimentoDi($fuori);
        $this->actingAs($fuori)->put("/movimenti/{$suo->id}", $this->dati())->assertForbidden();
        $this->actingAs($fuori)->post("/movimenti/{$suo->id}/ritiro")->assertForbidden();
        $this->assertSame(1250, $proprio->fresh()->importo);
    }

    public function test_su_un_conto_chiuso_nemmeno_l_autore_modifica_o_ritira(): void
    {
        $autore = $this->utenteCon('Consigliere'); // su tutti i conti: la chiusura non gli toglie l'assegnazione
        $movimento = $this->movimentoDi($autore);
        $this->festa->update(['chiuso_il' => '2026-05-15']);

        $this->actingAs($autore)->put("/movimenti/{$movimento->id}", $this->dati())->assertForbidden();
        $this->actingAs($autore)->post("/movimenti/{$movimento->id}/ritiro")->assertForbidden();
        $this->assertSame(1, Movimento::count());
    }

    public function test_un_movimento_importato_non_si_modifica_nemmeno_dall_autore(): void
    {
        $autore = $this->utenteCon('Consigliere');
        $importato = $this->movimentoDi($autore, ['manuale' => false]);

        $this->actingAs($autore)->put("/movimenti/{$importato->id}", $this->dati())->assertForbidden();
    }

    public function test_l_autore_ritira_il_proprio_movimento_che_sparisce_dall_elenco_ma_resta_nel_registro(): void
    {
        $autore = $this->utenteCon('Consigliere');
        $movimento = $this->movimentoDi($autore);

        $this->actingAs($autore)->post("/movimenti/{$movimento->id}/ritiro")->assertRedirect();

        $this->assertSoftDeleted($movimento);
        $this->assertSame($autore->id, Movimento::withTrashed()->findOrFail($movimento->id)->eliminato_da);
        $this->actingAs($autore)->get('/movimenti')->assertDontSee('Ghiaccio per la festa');
        $riga = RegistroModifica::where('azione', 'ritirato')->firstOrFail();
        $this->assertSame(1250, $riga->prima['importo']);
        $this->assertNull($riga->dopo);
    }

    public function test_non_si_ritira_il_movimento_di_un_altro_ne_uno_confermato(): void
    {
        $autore = $this->utenteCon('Consigliere');
        $confermato = $this->movimentoDi($autore, ['stato' => 'confermato']);
        $proprio = $this->movimentoDi($autore);

        $this->actingAs($autore)->post("/movimenti/{$confermato->id}/ritiro")->assertForbidden();
        $this->actingAs($this->utenteCon('Consigliere'))->post("/movimenti/{$proprio->id}/ritiro")->assertForbidden();
        $this->assertSame(2, Movimento::count());
    }

    /** Il Tesoriere conferma tra il controllo dei permessi e il blocco della riga: l'autore non deve più poter scrivere. */
    private function confermaNelMentre(Movimento $movimento): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');
        DB::beforeStartingTransaction(fn () => Movimento::whereKey($movimento->id)->update(['stato' => 'confermato', 'confermato_da' => $tesoriere->id]));
    }

    public function test_se_nel_frattempo_il_movimento_e_confermato_l_autore_non_lo_modifica(): void
    {
        $autore = $this->utenteCon('Consigliere');
        $movimento = $this->movimentoDi($autore);
        $this->actingAs($autore);
        $this->confermaNelMentre($movimento);

        $this->put("/movimenti/{$movimento->id}", $this->dati(['importo' => '99,00']))->assertForbidden();

        $this->assertNotSame(9900, $movimento->refresh()->importo);
        $this->assertSame(0, RegistroModifica::where('azione', 'modificato')->count());
    }

    public function test_se_nel_frattempo_il_movimento_e_confermato_l_autore_non_lo_ritira(): void
    {
        $autore = $this->utenteCon('Consigliere');
        $movimento = $this->movimentoDi($autore);
        $this->actingAs($autore);
        $this->confermaNelMentre($movimento);

        $this->post("/movimenti/{$movimento->id}/ritiro")->assertForbidden();

        $this->assertNull($movimento->refresh()->eliminato_il);
        $this->assertSame(0, RegistroModifica::where('azione', 'ritirato')->count());
    }

    public function test_un_movimento_ritirato_non_si_ritira_due_volte(): void
    {
        $autore = $this->utenteCon('Consigliere');
        $movimento = $this->movimentoDi($autore);
        $this->actingAs($autore)->post("/movimenti/{$movimento->id}/ritiro");

        $this->actingAs($autore)->post("/movimenti/{$movimento->id}/ritiro")->assertNotFound();
        $this->assertSame(1, RegistroModifica::where('azione', 'ritirato')->count());
    }

    public function test_se_nel_frattempo_l_autore_lo_ritira_il_tesoriere_non_scrive_una_seconda_eliminazione(): void
    {
        $autore = $this->utenteCon('Consigliere');
        $movimento = $this->movimentoDi($autore);
        // L'autore ritira tra la lettura del movimento e il blocco della riga.
        DB::beforeStartingTransaction(fn () => Movimento::whereKey($movimento->id)->update(['eliminato_da' => $autore->id, 'eliminato_il' => now()]));

        $this->actingAs($this->utenteCon('Tesoriere'))->post("/movimenti/{$movimento->id}/eliminazione", ['motivazione' => 'Doppione'])->assertNotFound();

        $this->assertSame($autore->id, Movimento::withTrashed()->findOrFail($movimento->id)->eliminato_da);
        $this->assertNull(Movimento::withTrashed()->findOrFail($movimento->id)->motivazione_eliminazione);
        $this->assertSame(0, RegistroModifica::where('azione', 'eliminato')->count());
    }

    public function test_se_nel_frattempo_il_conto_si_chiude_il_movimento_non_si_inserisce(): void
    {
        $consigliere = $this->utenteCon('Consigliere');
        DB::beforeStartingTransaction(fn () => Conto::whereKey($this->festa->id)->update(['chiuso_il' => '2026-05-05']));

        $this->actingAs($consigliere)->post("/conti/{$this->festa->id}/movimenti", $this->dati(['data' => '2026-05-10']))->assertSessionHasErrors('conto');

        $this->assertSame(0, Movimento::count());
    }

    public function test_se_nel_frattempo_il_conto_si_chiude_il_movimento_non_si_salva(): void
    {
        $autore = $this->utenteCon('Consigliere');
        $movimento = $this->movimentoDi($autore);
        DB::beforeStartingTransaction(fn () => Conto::whereKey($this->festa->id)->update(['chiuso_il' => '2026-05-12']));

        $this->actingAs($autore)->put("/movimenti/{$movimento->id}", $this->dati(['importo' => '99,00']))->assertForbidden();

        $this->assertNotSame(9900, $movimento->refresh()->importo);
    }

    public function test_il_tesoriere_elimina_un_movimento_manuale_anche_confermato_con_motivazione(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');
        $movimento = $this->movimentoDi($this->utenteCon('Consigliere'), ['stato' => 'confermato']);

        $this->actingAs($tesoriere)->post("/movimenti/{$movimento->id}/eliminazione", ['motivazione' => 'Doppione della ricevuta 12'])->assertRedirect();

        $this->assertSoftDeleted($movimento);
        $eliminato = Movimento::withTrashed()->findOrFail($movimento->id);
        $this->assertSame($tesoriere->id, $eliminato->eliminato_da);
        $this->assertSame('Doppione della ricevuta 12', $eliminato->motivazione_eliminazione);
        $riga = RegistroModifica::where('azione', 'eliminato')->firstOrFail();
        $this->assertSame('Doppione della ricevuta 12', $riga->motivazione);
        $this->assertSame('confermato', $riga->prima['stato']);
        $this->actingAs($tesoriere)->get('/movimenti')->assertDontSee('Ghiaccio per la festa');
    }

    public function test_l_eliminazione_vuole_la_motivazione_e_solo_i_movimenti_manuali(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');
        $manuale = $this->movimentoDi($this->utenteCon('Consigliere'));
        $importato = $this->movimentoDi($this->utenteCon('Consigliere'), ['manuale' => false]);

        $this->actingAs($tesoriere)->post("/movimenti/{$manuale->id}/eliminazione", ['motivazione' => ''])->assertSessionHasErrors('motivazione');
        $this->actingAs($tesoriere)->post("/movimenti/{$importato->id}/eliminazione", ['motivazione' => 'No'])->assertForbidden();
        $this->assertSame(2, Movimento::count());
    }

    public function test_chi_non_ha_il_permesso_non_elimina_nemmeno_il_proprio_movimento_confermato(): void
    {
        $autore = $this->utenteCon('Consigliere');
        $movimento = $this->movimentoDi($autore, ['stato' => 'confermato']);

        $this->actingAs($autore)->post("/movimenti/{$movimento->id}/eliminazione", ['motivazione' => 'Errore'])->assertForbidden();
        $this->actingAs($this->utenteCon('Lettore esterno'))->post("/movimenti/{$movimento->id}/eliminazione", ['motivazione' => 'Errore'])->assertForbidden();
        $this->assertSame(1, Movimento::count());
    }

    public function test_il_registro_completo_e_per_tesoriere_e_amministratore(): void
    {
        $consigliere = $this->utenteCon('Consigliere', [], ['name' => 'Dario Gialli']);
        $this->actingAs($consigliere)->post("/conti/{$this->festa->id}/movimenti", $this->dati());

        foreach (['Tesoriere', 'Amministratore'] as $ruolo) {
            $this->actingAs($this->utenteCon($ruolo))->get('/registro')->assertOk()
                ->assertSee('Dario Gialli')->assertSee('ha inserito')->assertSee('Ghiaccio per la festa');
        }
        foreach (['Consigliere', 'Lettore esterno'] as $ruolo) {
            $this->actingAs($this->utenteCon($ruolo))->get('/registro')->assertForbidden();
        }
    }

    public function test_il_registro_chiama_le_regole_per_nome_e_le_filtra(): void
    {
        $tina = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        RegistroModifica::create(['user_id' => $tina->id, 'oggetto_tipo' => 'regola', 'oggetto_id' => 7, 'azione' => 'modificata', 'prima' => ['nome' => 'Bollette'], 'dopo' => ['nome' => 'Utenze']]);
        $this->actingAs($tina)->post("/conti/{$this->festa->id}/movimenti", $this->dati(['descrizione' => 'Solo movimento']));

        $this->actingAs($tina)->get('/registro')->assertSee('ha modificato la regola n. 7')->assertDontSee('ha modificato il movimento n. 7');
        $this->actingAs($tina)->get('/registro?oggetto=regola')->assertSee('ha modificato la regola n. 7')->assertDontSee('Solo movimento');
    }

    public function test_il_registro_si_filtra_per_oggetto_e_per_persona(): void
    {
        $dario = $this->utenteCon('Consigliere', [], ['name' => 'Dario Gialli']);
        $carla = $this->utenteCon('Tesoriere', [], ['name' => 'Carla Neri']);
        $this->actingAs($dario)->post("/conti/{$this->festa->id}/movimenti", $this->dati(['descrizione' => 'Solo di Dario']));
        $this->actingAs($carla)->post('/conti', ['tipo' => 'cassa', 'nome' => 'Cassa Nuova', 'formato_import' => 'manuale', 'aperto_il' => '2026-05-01', 'saldo_iniziale' => '0']);

        $this->actingAs($carla)->get('/registro?oggetto=conto')->assertSee('Cassa Nuova')->assertDontSee('Solo di Dario');
        $this->actingAs($carla)->get("/registro?utente={$dario->id}")->assertSee('Solo di Dario')->assertDontSee('Cassa Nuova');
    }

    public function test_la_storia_del_movimento_e_per_i_ruoli_interni(): void
    {
        $autore = $this->utenteCon('Consigliere', [], ['name' => 'Dario Gialli']);
        $this->actingAs($autore)->post("/conti/{$this->festa->id}/movimenti", $this->dati());
        $movimento = Movimento::firstOrFail();
        $this->actingAs($autore)->put("/movimenti/{$movimento->id}", $this->dati(['importo' => '15,00']));

        foreach (['Consigliere', 'Tesoriere', 'Amministratore'] as $ruolo) {
            $this->actingAs($this->utenteCon($ruolo))->get("/movimenti/{$movimento->id}/storia")->assertOk()
                ->assertSee('ha inserito')->assertSee('ha modificato')->assertSee('12,50 € → 15,00 €');
        }
        $this->actingAs($this->utenteCon('Lettore esterno'))->get("/movimenti/{$movimento->id}/storia")->assertForbidden();
    }

    public function test_la_storia_di_un_movimento_eliminato_resta_consultabile_e_senza_le_righe_degli_altri(): void
    {
        $autore = $this->utenteCon('Consigliere');
        $movimento = $this->movimentoDi($autore);
        $altro = $this->movimentoDi($autore, ['descrizione' => 'Altro movimento']);
        $this->actingAs($autore)->post("/movimenti/{$altro->id}/ritiro");
        $this->actingAs($autore)->post("/movimenti/{$movimento->id}/ritiro");

        $this->actingAs($autore)->get("/movimenti/{$movimento->id}/storia")->assertOk()->assertSee('ha ritirato')
            ->assertSee('eliminato')->assertSee("il movimento n. {$movimento->id}")->assertDontSee("il movimento n. {$altro->id} ");
    }

    public function test_un_esterno_con_il_permesso_nel_ruolo_vede_la_storia_ma_non_scrive(): void
    {
        $revisore = Ruolo::create(['nome' => 'Revisore', 'permessi' => ['vedere', 'note', 'vedere_storia_movimento', 'vedere_registro_modifiche']]);
        $esterno = $this->utenteCon($revisore, ['scade_il' => today()->addMonth()->toDateString()], ['esterno' => true]);
        $movimento = $this->movimentoDi($this->utenteCon('Consigliere'));

        $this->actingAs($esterno)->get("/movimenti/{$movimento->id}/storia")->assertOk();
        $this->actingAs($esterno)->get('/registro')->assertOk();
        $this->actingAs($esterno)->post("/movimenti/{$movimento->id}/eliminazione", ['motivazione' => 'x'])->assertForbidden();
    }

    public function test_il_registro_e_in_sola_aggiunta(): void
    {
        $riga = RegistroModifica::registra($this->utenteCon('Tesoriere'), $this->festa, 'creato', null, ['nome' => 'Cassa Festa']);

        $this->expectException(LogicException::class);
        $riga->update(['azione' => 'altro']);
    }

    public function test_una_riga_del_registro_non_si_cancella(): void
    {
        $riga = RegistroModifica::registra($this->utenteCon('Tesoriere'), $this->festa, 'creato', null, ['nome' => 'Cassa Festa']);

        try {
            $riga->delete();
            $this->fail('La riga del registro non doveva cancellarsi.');
        } catch (LogicException) {
            $this->assertSame(1, RegistroModifica::count());
        }
    }

    public function test_l_account_di_emergenza_scrive_nel_registro_come_un_amministratore(): void
    {
        $emergenza = User::factory()->create(['emergenza' => true]);

        $this->withSession(['auth.password_confirmed_at' => now()->unix()])->actingAs($emergenza)->post("/conti/{$this->festa->id}/movimenti", $this->dati())->assertRedirect();

        $this->assertSame($emergenza->id, RegistroModifica::firstOrFail()->user_id);
    }
}
