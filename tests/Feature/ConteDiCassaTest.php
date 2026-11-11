<?php

namespace Tests\Feature;

use App\Models\ContaDiCassa;
use App\Models\Conto;
use App\Models\Giroconto;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** La conta di cassa, la sua differenza e la chiusura di una cassa (#50). Dati inventati. */
class ConteDiCassaTest extends TestCase
{
    use RefreshDatabase;

    private Conto $festa;

    private Conto $principale;

    private User $tesoriere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-20 10:00');
        $this->tesoriere = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        // Il saldo dell'app della festa è 100,00 (fondo cassa 50,00 più un incasso di 50,00).
        $this->festa = Conto::factory()->create(['nome' => 'Cassa Festa', 'aperto_il' => '2026-05-01', 'saldo_iniziale' => 5000]);
        $this->principale = Conto::factory()->create(['nome' => 'Cassa contanti', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 20000]);
        Movimento::factory()->confermato()->create(['conto_id' => $this->festa->id, 'data' => '2026-05-10', 'importo' => 5000, 'verso' => 'entrata', 'inserito_da' => $this->tesoriere->id]);
    }

    private function conta(array $dati = [], ?User $utente = null, ?Conto $conto = null)
    {
        return $this->actingAs($utente ?? $this->tesoriere)->post('/conti/'.($conto ?? $this->festa)->id.'/conte', $dati + ['data' => '2026-05-12', 'importo' => '100,00']);
    }

    private function riconciliato(?Conto $conto = null): ?string
    {
        return ($conto ?? $this->festa)->fresh()->riconciliatoFinoAl()?->toDateString();
    }

    public function test_una_conta_senza_differenze_e_la_data_di_riconciliazione_della_cassa(): void
    {
        $this->conta()->assertRedirect("/conti/{$this->festa->id}/conte")->assertSessionHasNoErrors();

        $conta = ContaDiCassa::firstOrFail();
        $this->assertSame('2026-05-12', $conta->data->toDateString());
        $this->assertSame(10000, $conta->importo_contato);
        $this->assertSame(10000, $conta->saldo_app);
        $this->assertSame(0, $conta->differenza);
        $this->assertSame($this->tesoriere->id, $conta->contata_da);
        $this->assertNull($conta->correzione_id);
        $this->assertSame('2026-05-12', $this->riconciliato());
        $this->actingAs($this->tesoriere)->get('/conti')->assertSeeText('Riconciliato fino al 12/05/2026');
        $riga = RegistroModifica::where('azione', 'conta')->firstOrFail();
        $this->assertSame('conto', $riga->oggetto_tipo);
        $this->assertSame($this->festa->id, $riga->oggetto_id);
        $this->assertSame(10000, $riga->dopo['importo_contato']);
    }

    public function test_senza_conta_la_cassa_non_e_riconciliata(): void
    {
        $this->assertNull($this->riconciliato());
        $this->actingAs($this->tesoriere)->get('/conti')->assertSeeText('Non riconciliato');
    }

    public function test_la_conta_confronta_con_il_saldo_dell_app_alla_data_della_conta(): void
    {
        $this->conta(['data' => '2026-05-05', 'importo' => '50,00'])->assertSessionHasNoErrors(); // prima dell'incasso del 10

        $this->assertSame(5000, ContaDiCassa::firstOrFail()->saldo_app);
        $this->assertSame('2026-05-05', $this->riconciliato());
    }

    public function test_una_differenza_senza_spiegazione_si_rifiuta_dicendo_di_quanto(): void
    {
        $this->conta(['importo' => '97,50'])->assertSessionHasErrors('spiegazione');

        $this->assertSame(0, ContaDiCassa::count());
        $this->assertSame(0, Movimento::where('descrizione', 'like', 'Correzione%')->count());
        $this->assertStringContainsString('2,50', session('errors')->first('spiegazione'));
    }

    public function test_la_differenza_si_spiega_con_un_movimento_di_correzione_e_la_cassa_quadra(): void
    {
        $this->conta(['importo' => '97,50', 'correggi' => '1'])->assertSessionHasNoErrors();

        $conta = ContaDiCassa::firstOrFail();
        $this->assertSame(-250, $conta->differenza);
        $correzione = Movimento::findOrFail($conta->correzione_id);
        $this->assertSame($this->festa->id, $correzione->conto_id);
        $this->assertSame('uscita', $correzione->verso);
        $this->assertSame(250, $correzione->importo);
        $this->assertSame('2026-05-12', $correzione->data->toDateString());
        $this->assertSame('da_classificare', $correzione->stato);
        $this->assertTrue($correzione->manuale);
        $this->assertSame($this->tesoriere->id, $correzione->inserito_da);
        $this->assertSame(9750, $this->festa->saldoAl('2026-05-12'));
        $this->assertSame('2026-05-12', $this->riconciliato());
        $this->assertSame(1, RegistroModifica::where('oggetto_tipo', 'movimento')->where('oggetto_id', $correzione->id)->where('azione', 'inserito')->count());
    }

    public function test_un_contante_in_piu_si_corregge_con_un_entrata(): void
    {
        $this->conta(['importo' => '101,00', 'correggi' => '1'])->assertSessionHasNoErrors();

        $correzione = Movimento::findOrFail(ContaDiCassa::firstOrFail()->correzione_id);
        $this->assertSame('entrata', $correzione->verso);
        $this->assertSame(100, $correzione->importo);
    }

    public function test_la_differenza_spiegata_solo_con_una_nota_non_riconcilia(): void
    {
        $this->conta(['importo' => '97,50', 'spiegazione' => 'Mancano 2,50 di resto, da cercare'])->assertSessionHasNoErrors();

        $conta = ContaDiCassa::firstOrFail();
        $this->assertSame(-250, $conta->differenza);
        $this->assertSame('Mancano 2,50 di resto, da cercare', $conta->spiegazione);
        $this->assertNull($conta->correzione_id);
        $this->assertSame(0, Movimento::where('conto_id', $this->festa->id)->where('descrizione', 'like', 'Correzione%')->count());
        $this->assertNull($this->riconciliato());
    }

    public function test_se_la_correzione_si_elimina_la_cassa_non_e_piu_riconciliata(): void
    {
        $this->conta(['importo' => '97,50', 'correggi' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('2026-05-12', $this->riconciliato());

        Movimento::findOrFail(ContaDiCassa::firstOrFail()->correzione_id)->delete(); // i manuali si eliminano anche confermati (#16)

        $this->assertNull($this->riconciliato());
    }

    public function test_un_movimento_con_data_antecedente_arrivato_dopo_la_conta_la_fa_arretrare(): void
    {
        $this->conta(['data' => '2026-05-12', 'importo' => '100,00'])->assertSessionHasNoErrors();
        Movimento::factory()->create(['conto_id' => $this->festa->id, 'data' => '2026-05-11', 'importo' => 300, 'verso' => 'uscita']);

        $this->assertNull($this->riconciliato());
    }

    public function test_la_data_di_riconciliazione_e_quella_dell_ultima_conta_senza_differenze(): void
    {
        $this->conta(['data' => '2026-05-12', 'importo' => '100,00'])->assertSessionHasNoErrors();
        $this->conta(['data' => '2026-05-15', 'importo' => '90,00', 'spiegazione' => 'Da cercare'])->assertSessionHasNoErrors();

        $this->assertSame('2026-05-12', $this->riconciliato());
    }

    public function test_il_modello_ammette_una_conta_congiunta_con_due_nomi(): void
    {
        $nuova = $this->utenteCon('Tesoriere', [], ['name' => 'Nino Bianchi']);

        $this->conta(['contata_con' => $nuova->id])->assertSessionHasNoErrors();

        $conta = ContaDiCassa::with('contataDa', 'contataCon')->firstOrFail();
        $this->assertSame('Tina Verdi', $conta->contataDa->name);
        $this->assertSame('Nino Bianchi', $conta->contataCon->name);
        $this->conta(['contata_con' => $this->tesoriere->id])->assertSessionHasErrors('contata_con'); // la stessa persona non vale due nomi
    }

    public function test_la_pagina_della_conta_mostra_saldo_e_storia_e_si_usa_dal_telefono(): void
    {
        $this->conta(['importo' => '97,50', 'spiegazione' => 'Da cercare']);

        $this->actingAs($this->tesoriere)->get("/conti/{$this->festa->id}/conte")->assertOk()
            ->assertSeeText('Cassa Festa')->assertSeeText('100,00')->assertSeeText('97,50')->assertSeeText('−2,50')->assertSeeText('Da cercare')
            ->assertSee('modulo-stretto', false)->assertSee('inputmode="decimal"', false);
        $this->actingAs($this->tesoriere)->get('/conti')->assertSee("/conti/{$this->festa->id}/conte", false);
    }

    public function test_la_conta_non_ha_data_futura_ne_precedente_all_apertura(): void
    {
        $this->conta(['data' => '2026-05-21'])->assertSessionHasErrors('data');
        $this->conta(['data' => '2026-04-30'])->assertSessionHasErrors('data');
        $this->assertSame(0, ContaDiCassa::count());
    }

    public function test_la_conta_vale_per_le_casse_aperte(): void
    {
        $banca = Conto::factory()->create(['tipo' => 'banca', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi']);
        $chiusa = Conto::factory()->create(['aperto_il' => '2026-01-01', 'chiuso_il' => '2026-03-01']);

        $this->conta([], null, $banca)->assertSessionHasErrors('conto');
        $this->conta(['data' => '2026-02-01'], null, $chiusa)->assertSessionHasErrors('conto');
        $this->assertSame(0, ContaDiCassa::count());
    }

    public function test_la_conta_e_del_tesoriere_sul_conto_del_suo_ambito(): void
    {
        $consigliere = $this->utenteCon('Consigliere');
        $altroConto = $this->utenteCon('Tesoriere', ['conti' => [$this->principale->id]]);
        $lettore = $this->utenteCon('Lettore esterno');

        $this->conta([], $consigliere)->assertForbidden();
        $this->conta([], $altroConto)->assertForbidden();
        $this->conta([], $lettore)->assertForbidden();
        // Leggere è per tutti (l'ambito limita la scrittura, non la lettura): la pagina c'è, il modulo no.
        $this->actingAs($consigliere)->get("/conti/{$this->festa->id}/conte")->assertOk()->assertDontSee('conta-modulo', false);
        $this->assertSame(0, ContaDiCassa::count());
    }

    /** Il versamento in banca come lo vede la cassa: un'uscita e l'entrata sul conto, abbinate in un giroconto confermato (#47). */
    private function versa(int $importo, string $data): void
    {
        $banca = Conto::firstWhere('tipo', 'banca') ?? Conto::factory()->create(['tipo' => 'banca', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01']);
        $uscita = Movimento::factory()->confermato()->create(['conto_id' => $this->festa->id, 'data' => $data, 'importo' => $importo, 'verso' => 'uscita', 'descrizione' => 'Versamento su banca']);
        $entrata = Movimento::factory()->confermato()->create(['conto_id' => $banca->id, 'data' => $data, 'importo' => $importo, 'verso' => 'entrata', 'manuale' => false, 'descrizione' => 'VERSAMENTO CONTANTI']);
        Giroconto::create(['uscita_id' => $uscita->id, 'entrata_id' => $entrata->id, 'regola' => 'versamento', 'confermato_da' => $this->tesoriere->id, 'confermato_il' => now()]);
    }

    private function chiudi(string $data = '2026-05-14')
    {
        return $this->actingAs($this->tesoriere)->post("/conti/{$this->festa->id}/chiusura", ['chiuso_il' => $data]);
    }

    public function test_una_cassa_si_chiude_dopo_una_conta_e_con_il_versamento_come_giroconto(): void
    {
        $this->conta(['data' => '2026-05-12', 'importo' => '100,00'])->assertSessionHasNoErrors();
        $this->versa(10000, '2026-05-13');

        $this->chiudi()->assertRedirect('/conti')->assertSessionHasNoErrors();

        $this->assertSame('2026-05-14', $this->festa->fresh()->chiuso_il->toDateString());
        $this->assertSame(0, $this->festa->saldoAl('2026-05-14'));
    }

    public function test_il_versamento_dello_stesso_giorno_della_conta_non_la_invalida(): void
    {
        // Si conta e poi si versa, tutto il 12: la conta precede il versamento, e il saldo dopo è zero.
        $this->conta(['data' => '2026-05-12', 'importo' => '100,00'])->assertSessionHasNoErrors();
        $this->versa(10000, '2026-05-12');

        $this->assertSame('2026-05-12', $this->riconciliato());
        $this->chiudi('2026-05-12')->assertSessionHasNoErrors();
        $this->assertNotNull($this->festa->fresh()->chiuso_il);
    }

    public function test_la_cassa_non_si_chiude_prima_dell_ultima_conta(): void
    {
        $this->versa(10000, '2026-05-12');
        $this->conta(['data' => '2026-05-15', 'importo' => '0'])->assertSessionHasNoErrors();

        $this->chiudi('2026-05-13')->assertSessionHasErrors('conto');
        $this->assertNull($this->festa->fresh()->chiuso_il);
    }

    public function test_i_movimenti_di_una_cassa_chiusa_non_si_eliminano(): void
    {
        $this->conta(['importo' => '97,50', 'correggi' => '1'])->assertSessionHasNoErrors();
        $correzione = Movimento::findOrFail(ContaDiCassa::firstOrFail()->correzione_id);
        $correzione->forceFill(['stato' => 'confermato'])->save(); // classificata e confermata: alla chiusura nessun movimento è da classificare
        $this->versa(9750, '2026-05-13');
        $this->chiudi()->assertSessionHasNoErrors();

        $this->actingAs($this->tesoriere)->post("/movimenti/{$correzione->id}/eliminazione", ['motivazione' => 'Errore'])->assertSessionHasErrors('movimento');
        $this->assertNull($correzione->fresh()->eliminato_il);
    }

    public function test_il_secondo_nome_non_e_l_account_di_emergenza(): void
    {
        $emergenza = User::factory()->create(['emergenza' => true]);

        $this->conta(['contata_con' => $emergenza->id])->assertSessionHasErrors('contata_con');
        $this->assertSame(0, ContaDiCassa::count());
    }

    public function test_la_cassa_con_un_saldo_diverso_da_zero_non_si_chiude(): void
    {
        $this->conta(['data' => '2026-05-12', 'importo' => '100,00'])->assertSessionHasNoErrors();

        $this->chiudi()->assertSessionHasErrors('conto');
        $this->assertStringContainsString('100,00', session('errors')->first('conto'));
        $this->assertNull($this->festa->fresh()->chiuso_il);
    }

    public function test_la_cassa_senza_conta_non_si_chiude(): void
    {
        $this->versa(10000, '2026-05-13');

        $this->chiudi()->assertSessionHasErrors('conto');
        $this->assertNull($this->festa->fresh()->chiuso_il);
    }

    public function test_una_conta_con_differenza_non_basta_per_chiudere(): void
    {
        $this->conta(['data' => '2026-05-12', 'importo' => '97,50', 'spiegazione' => 'Da cercare'])->assertSessionHasNoErrors();
        $this->versa(10000, '2026-05-13');

        $this->chiudi()->assertSessionHasErrors('conto');
        $this->assertNull($this->festa->fresh()->chiuso_il);
    }

    public function test_dopo_l_ultima_conta_non_ci_sono_che_metà_di_giroconto(): void
    {
        $this->conta(['data' => '2026-05-12', 'importo' => '100,00'])->assertSessionHasNoErrors();
        Movimento::factory()->create(['conto_id' => $this->festa->id, 'data' => '2026-05-13', 'importo' => 4000, 'verso' => 'uscita']); // una spesa, non un versamento
        $this->versa(6000, '2026-05-13');

        $this->chiudi()->assertSessionHasErrors('conto');
        $this->assertNull($this->festa->fresh()->chiuso_il);
    }

    public function test_una_conta_a_zero_dopo_il_versamento_basta_per_chiudere(): void
    {
        // La cassa si svuota col versamento e poi si conta zero: l'ultima conta è dopo il giroconto.
        $this->versa(10000, '2026-05-12');
        $this->conta(['data' => '2026-05-13', 'importo' => '0'])->assertSessionHasNoErrors();

        $this->chiudi()->assertSessionHasNoErrors();
        $this->assertNotNull($this->festa->fresh()->chiuso_il);
    }

    public function test_un_versamento_scritto_a_mano_e_mai_abbinato_non_azzera_la_cassa(): void
    {
        // L'uscita «Versamento in banca» è da classificare: non è un giroconto e non è una spesa confermata.
        Movimento::factory()->create(['conto_id' => $this->festa->id, 'data' => '2026-05-11', 'importo' => 10000, 'verso' => 'uscita', 'descrizione' => 'Versamento in banca', 'manuale' => true]);
        $this->conta(['data' => '2026-05-12', 'importo' => '0'])->assertSessionHasNoErrors();

        $this->chiudi()->assertSessionHasErrors('conto');
        $this->assertStringContainsString('giroconto', session('errors')->first('conto'));
        $this->assertNull($this->festa->fresh()->chiuso_il);
    }

    public function test_una_correzione_non_azzera_la_cassa(): void
    {
        $this->conta(['data' => '2026-05-12', 'importo' => '0', 'correggi' => '1'])->assertSessionHasNoErrors();

        $this->chiudi()->assertSessionHasErrors('conto');
        $this->assertNull($this->festa->fresh()->chiuso_il);
    }

    public function test_una_cassa_che_ha_speso_tutto_in_spese_confermate_si_chiude_senza_giroconto(): void
    {
        Movimento::factory()->confermato()->create(['conto_id' => $this->festa->id, 'data' => '2026-05-11', 'importo' => 10000, 'verso' => 'uscita']);
        $this->conta(['data' => '2026-05-12', 'importo' => '0'])->assertSessionHasNoErrors();

        $this->chiudi()->assertSessionHasNoErrors();
        $this->assertNotNull($this->festa->fresh()->chiuso_il);
    }

    public function test_il_permesso_si_rilegge_dopo_il_blocco_del_conto(): void
    {
        // Una revoca arrivata tra la richiesta e il blocco del conto (gli assegnamenti caricati dalla rotta sono di prima).
        $limitato = $this->utenteCon('Tesoriere', ['conti' => [$this->festa->id]]);
        \DB::beforeStartingTransaction(fn () => $limitato->assegnazioni()->delete());

        $this->conta([], $limitato)->assertForbidden();
        $this->assertSame(0, ContaDiCassa::count());
    }
}
