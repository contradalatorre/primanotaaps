<?php

namespace Tests\Feature;

use App\Forzature;
use App\Models\Assegnazione;
use App\Models\Conto;
use App\Models\ImportEstratto;
use App\Models\ImpostazioniAssociazione;
use App\Models\Imputazione;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Natura commerciale calcolata, sempre visibile, e forzatura motivata (#52). Dati inventati. */
class NaturaCommercialeTest extends TestCase
{
    use RefreshDatabase;

    private User $tesoriere;

    private Conto $cassa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-20 09:30');
        $this->tesoriere = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa contanti', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
    }

    private function entrata(string $tipologia, string $area, int $importo = 10000, array $movimento = []): Imputazione
    {
        $m = Movimento::factory()->confermato()->create($movimento + ['conto_id' => $this->cassa->id, 'verso' => 'entrata', 'importo' => $importo, 'data' => '2026-03-01', 'inserito_da' => $this->tesoriere->id]);

        return Imputazione::create(['movimento_id' => $m->id, 'importo' => $importo, 'area' => $area, 'tipologia_id' => Tipologia::where('nome', $tipologia)->value('id')]);
    }

    private function uscita(string $tipologia, string $area, int $importo = 5000): Imputazione
    {
        $m = Movimento::factory()->confermato()->create(['conto_id' => $this->cassa->id, 'verso' => 'uscita', 'importo' => $importo, 'data' => '2026-03-02', 'inserito_da' => $this->tesoriere->id]);

        return Imputazione::create(['movimento_id' => $m->id, 'importo' => $importo, 'area' => $area, 'tipologia_id' => Tipologia::where('nome', $tipologia)->value('id')]);
    }

    public function test_la_natura_calcolata_si_vede_su_ogni_imputazione_nel_modello_di_calcolo(): void
    {
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B');
        $quote = $this->entrata('Quote associative', 'A');
        $uscita = $this->uscita('Utenze', 'E');

        $this->assertTrue($sponsor->natura()->commerciale);
        $this->assertFalse($quote->natura()->commerciale);
        $this->assertNull($uscita->natura()->commerciale);
        $this->assertSame($sponsor->natura()->commerciale, $sponsor->naturaEffettiva());
        $this->assertFalse($sponsor->forzata());
    }

    public function test_l_affiliazione_dell_esercizio_decommercializza_la_somministrazione_ai_soci(): void
    {
        $cena = $this->entrata('Somministrazione ai soci', 'A', 4000, ['tipo_controparte' => 'socio']);
        $this->assertTrue($cena->natura()->commerciale);

        ImpostazioniAssociazione::where('dal_esercizio', 2026)->update(['affiliazione_rete' => true]); // la riga del 2026 c'è dalla migrazione

        $this->assertFalse($cena->refresh()->natura()->commerciale);
        $this->assertSame('somministrazione-ai-soci-affiliata', $cena->natura()->regola);
    }

    public function test_la_natura_e_sempre_visibile_nella_classificazione_nell_elenco_e_nelle_imputazioni(): void
    {
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B');
        $quote = $this->entrata('Quote associative', 'A');
        $this->actingAs($this->tesoriere);

        $this->get("/movimenti/{$sponsor->movimento_id}/classificazione")->assertOk()
            ->assertSee('Natura commerciale: commerciale', false)->assertSee('art. 85 c. 3 CTS', false);
        $this->get("/movimenti/{$quote->movimento_id}/classificazione")->assertSee('Natura commerciale: non commerciale', false);
        $this->get('/movimenti?conto='.$this->cassa->id)->assertOk()->assertSee('Natura commerciale: commerciale', false);
        $this->get('/esercizi/2026/imputazioni?chiavi=entrate:B3')->assertOk()->assertSee('Natura commerciale: commerciale', false);
    }

    public function test_la_natura_si_vede_nella_revisione_dell_import(): void
    {
        $import = ImportEstratto::create(['conto_id' => $this->cassa->id, 'user_id' => $this->tesoriere->id, 'stato' => 'confermato', 'periodo_dal' => '2026-03-01',
            'periodo_al' => '2026-03-31', 'saldo_iniziale' => 0, 'saldo_finale' => 10000, 'righe' => 1, 'confermato_il' => now()]);
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B', 10000, ['import_estratto_id' => $import->id, 'manuale' => false]);

        $this->actingAs($this->tesoriere)->get("/import/{$import->id}/revisione?movimento={$sponsor->movimento_id}")->assertOk()
            ->assertSee('Natura commerciale: commerciale', false)->assertDontSee('arriva con il calcolo dell’esercizio', false);
    }

    private function forza(array $imputazioni, array $dati = []): TestResponse
    {
        return $this->actingAs($this->tesoriere)->post('/imputazioni/natura', $dati + ['imputazioni' => collect($imputazioni)->map->id->all(), 'natura' => 'non_commerciale', 'motivazione' => 'Lo dice il commercialista (mail del 3/3)']);
    }

    public function test_il_tesoriere_forza_piu_imputazioni_con_motivazione_e_il_calcolato_resta_visibile(): void
    {
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B');
        $altra = $this->entrata('Sponsorizzazioni e pubblicità', 'B', 7000);

        $this->forza([$sponsor, $altra])->assertSessionHasNoErrors()->assertRedirect();

        $sponsor->refresh();
        $this->assertTrue($sponsor->forzata());
        $this->assertFalse($sponsor->naturaEffettiva());
        $this->assertTrue($sponsor->natura()->commerciale); // il calcolato non si tocca
        $this->assertSame($this->tesoriere->id, $sponsor->forzatura_da);
        $this->assertSame('2026-12-20 09:30:00', $sponsor->forzatura_il->format('Y-m-d H:i:s'));
        $this->assertSame('Lo dice il commercialista (mail del 3/3)', $sponsor->forzatura_motivazione);
        $this->assertFalse($altra->refresh()->naturaEffettiva());

        $this->get("/movimenti/{$sponsor->movimento_id}/classificazione")->assertOk()
            ->assertSee('Natura commerciale: non commerciale', false)->assertSee('Forzata')->assertSee('calcolata: commerciale', false)
            ->assertSee('Tina Verdi')->assertSee('20/12/2026')->assertSee('Lo dice il commercialista', false);
    }

    public function test_la_forzatura_finisce_nel_registro_con_la_motivazione_e_chi_l_ha_fatta(): void
    {
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B');

        $this->forza([$sponsor]);

        $riga = RegistroModifica::where('azione', 'natura_forzata')->firstOrFail();
        $this->assertSame($this->tesoriere->id, $riga->user_id);
        $this->assertSame($sponsor->movimento_id, $riga->oggetto_id);
        $this->assertSame('Lo dice il commercialista (mail del 3/3)', $riga->motivazione);
        $this->assertStringNotContainsString('natura forzata', $riga->prima['imputazioni']);
        $this->assertStringContainsString('natura forzata: non commerciale', $riga->dopo['imputazioni']);
        $this->get('/registro')->assertOk()->assertSee('ha forzato la natura commerciale del')->assertSee('Lo dice il commercialista', false);
    }

    public function test_senza_motivazione_o_senza_scelta_non_si_forza(): void
    {
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B');

        $this->forza([$sponsor], ['motivazione' => '  '])->assertSessionHasErrors('motivazione');
        $this->forza([$sponsor], ['natura' => 'boh'])->assertSessionHasErrors('natura');
        $this->forza([], ['imputazioni' => []])->assertSessionHasErrors('imputazioni');

        $this->assertFalse($sponsor->refresh()->forzata());
        $this->assertSame(0, RegistroModifica::where('azione', 'natura_forzata')->count());
    }

    public function test_chi_non_classifica_non_forza(): void
    {
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B');
        $consigliere = $this->utenteCon('Consigliere');

        $this->actingAs($consigliere)->post('/imputazioni/natura', ['imputazioni' => [$sponsor->id], 'natura' => 'non_commerciale', 'motivazione' => 'x'])->assertForbidden();
        $this->actingAs($consigliere)->post('/imputazioni/natura/toglie', ['imputazioni' => [$sponsor->id]])->assertForbidden();
        $this->assertFalse($sponsor->refresh()->forzata());
    }

    public function test_il_permesso_revocato_dopo_il_controllo_della_rotta_non_basta_a_riga_bloccata(): void
    {
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B');
        $utente = $this->utenteCon('Tesoriere');
        $this->assertTrue($utente->can('importare_e_confermare')); // il controllo della rotta carica le assegnazioni
        Assegnazione::where('user_id', $utente->id)->delete(); // revoca nel frattempo

        try {
            Forzature::forza($utente, [$sponsor->id], false, 'x');
            $this->fail('La forzatura doveva essere rifiutata.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertFalse($sponsor->refresh()->forzata());
    }

    public function test_un_id_che_non_c_e_piu_rifiuta_tutto(): void
    {
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B');
        $sparita = $this->entrata('Sponsorizzazioni e pubblicità', 'B', 7000);
        $idSparito = $sparita->id;
        $sparita->delete(); // come dopo una riclassificazione: la riga si riscrive con un altro id

        $this->forza([$sponsor], ['imputazioni' => [$sponsor->id, $idSparito]])->assertSessionHasErrors('imputazioni');

        $this->assertFalse($sponsor->refresh()->forzata());
    }

    public function test_una_forzatura_e_una_difformita_e_non_vale_per_le_uscite(): void
    {
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B');
        $quote = $this->entrata('Quote associative', 'A');
        $uscita = $this->uscita('Utenze', 'E');

        $this->forza([$sponsor], ['natura' => 'commerciale'])->assertSessionHasErrors('imputazioni'); // lo è già
        $this->forza([$uscita])->assertSessionHasErrors('imputazioni');
        // Tutto o niente: la quota si potrebbe forzare, ma con l'uscita nella richiesta non si scrive nulla.
        $this->forza([$quote, $uscita], ['natura' => 'commerciale'])->assertSessionHasErrors('imputazioni');

        $this->assertSame(0, Imputazione::whereNotNull('natura_forzata')->count());
        $this->assertSame(0, RegistroModifica::where('azione', 'natura_forzata')->count());
    }

    public function test_il_corrispettivo_dell_area_a_si_forza_anche_al_valore_calcolato_per_tenerlo_fermo_dal_2_bis(): void
    {
        $corrispettivo = $this->entrata('Corrispettivi da terzi', 'A');
        $this->assertFalse($corrispettivo->natura()->commerciale);
        $this->assertTrue($corrispettivo->natura()->dipendeDal2bis);

        $this->forza([$corrispettivo])->assertSessionHasNoErrors();

        $this->assertFalse($corrispettivo->refresh()->naturaEffettiva());
        $this->assertTrue($corrispettivo->forzata());
    }

    public function test_togliere_la_forzatura_riporta_il_calcolato_e_si_scrive_nel_registro(): void
    {
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B');
        $this->forza([$sponsor]);

        $this->actingAs($this->tesoriere)->post('/imputazioni/natura/toglie', ['imputazioni' => [$sponsor->id]])->assertSessionHasNoErrors();

        $sponsor->refresh();
        $this->assertFalse($sponsor->forzata());
        $this->assertNull($sponsor->forzatura_motivazione);
        $this->assertTrue($sponsor->naturaEffettiva());
        $this->assertSame(1, RegistroModifica::where('azione', 'forzatura_tolta')->count());
        $this->actingAs($this->tesoriere)->post('/imputazioni/natura/toglie', ['imputazioni' => [$sponsor->id]])->assertSessionHasErrors('imputazioni'); // niente da togliere
    }

    public function test_la_forzatura_segue_la_riclassificazione_e_segnala_cosa_e_cambiato(): void
    {
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B');
        $this->forza([$sponsor]);

        $this->actingAs($this->tesoriere)->put("/movimenti/{$sponsor->movimento_id}/classificazione", [
            'tipo_controparte' => 'socio', 'imputazioni' => [['tipologia_id' => $sponsor->tipologia_id, 'area' => 'B']],
        ])->assertSessionHasNoErrors();

        $nuova = Movimento::find($sponsor->movimento_id)->imputazioni()->firstOrFail();
        $this->assertNotSame($sponsor->id, $nuova->id); // la riga si è riscritta
        $this->assertFalse($nuova->naturaEffettiva());
        $this->assertSame(['socio o terzo'], $nuova->forzaturaDaRivedere());
        $this->get("/movimenti/{$nuova->movimento_id}/classificazione")->assertSee('Da rivedere')->assertSee('socio o terzo');
    }

    /** Il movimento di 100 euro in due righe (60 e 40) della stessa tipologia B; forza la riga di `$forzata` euro. */
    private function movimentoInDueRighe(int $forzata): Movimento
    {
        $m = $this->entrata('Sponsorizzazioni e pubblicità', 'B')->movimento;
        $this->riclassifica($m, ['60,00', '40,00']);
        $this->forza([$m->imputazioni()->where('importo', $forzata * 100)->firstOrFail()])->assertSessionHasNoErrors();

        return $m;
    }

    /** @param list<string> $importi */
    private function riclassifica(Movimento $m, array $importi): void
    {
        $tipologia = Tipologia::where('nome', 'Sponsorizzazioni e pubblicità')->value('id');
        $this->actingAs($this->tesoriere)->put("/movimenti/{$m->id}/classificazione", [
            'imputazioni' => collect($importi)->map(fn ($importo) => ['importo' => $importo, 'tipologia_id' => $tipologia, 'area' => 'B'])->all(),
        ])->assertSessionHasNoErrors();
    }

    public function test_la_forzatura_resta_alla_riga_dello_stesso_importo_se_le_righe_si_invertono(): void
    {
        $m = $this->movimentoInDueRighe(60);

        $this->riclassifica($m, ['40,00', '60,00']);

        $righe = $m->imputazioni()->orderBy('id')->get();
        $this->assertSame([4000, 6000], $righe->pluck('importo')->all());
        $this->assertFalse($righe[0]->forzata());
        $this->assertTrue($righe[1]->forzata());
        $this->assertSame([], $righe[1]->forzaturaDaRivedere());
    }

    public function test_la_forzatura_di_una_riga_che_non_c_e_piu_resta_da_rivedere_sull_importo(): void
    {
        $m = $this->movimentoInDueRighe(40);

        $this->riclassifica($m, ['100,00']);

        $riga = $m->imputazioni()->firstOrFail();
        $this->assertTrue($riga->forzata());
        $this->assertSame(['importo'], $riga->forzaturaDaRivedere());
        $this->get('/esercizi/2026/forzature')->assertSee('Da rivedere');
        $this->get('/esercizi/2026/modello-d')->assertSee('1 da rivedere', false);
    }

    public function test_con_due_righe_dello_stesso_importo_la_forzatura_resta_a_quella_con_gli_stessi_ingressi(): void
    {
        $m = $this->entrata('Sponsorizzazioni e pubblicità', 'B', 10000)->movimento;
        $righe = [['importo' => '50,00', 'tipologia_id' => Tipologia::where('nome', 'Sponsorizzazioni e pubblicità')->value('id'), 'area' => 'B'],
            ['importo' => '50,00', 'tipologia_id' => Tipologia::where('nome', 'Quote associative')->value('id'), 'area' => 'A']];
        $salva = fn () => $this->actingAs($this->tesoriere)->put("/movimenti/{$m->id}/classificazione", ['imputazioni' => $righe])->assertSessionHasNoErrors();
        $salva();
        $quota = $m->imputazioni()->orderBy('id')->get()[1];
        $this->forza([$quota], ['natura' => 'commerciale'])->assertSessionHasNoErrors();

        $salva(); // le stesse righe, nello stesso ordine

        $righeDopo = $m->imputazioni()->orderBy('id')->get();
        $this->assertFalse($righeDopo[0]->forzata());
        $this->assertTrue($righeDopo[1]->forzata());
        $this->assertSame([], $righeDopo[1]->forzaturaDaRivedere());
    }

    public function test_le_forzature_sono_elencate_e_segnalate_quando_cambiano_i_dati_del_calcolo(): void
    {
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B');
        $altra = $this->entrata('Sponsorizzazioni e pubblicità', 'B', 7000);
        $this->forza([$sponsor, $altra]);
        $this->get('/esercizi/2026/forzature')->assertOk()->assertSee('Lo dice il commercialista', false)->assertDontSee('Da rivedere');
        $this->get('/esercizi/2026/modello-d')->assertSee('2 forzature della natura commerciale', false)->assertDontSee('da rivedere');

        ImpostazioniAssociazione::where('dal_esercizio', 2026)->update(['affiliazione_rete' => true]); // la riga del 2026 c'è dalla migrazione

        $this->get('/esercizi/2026/forzature')->assertSee('Da rivedere')->assertSee('affiliazione a una rete nazionale', false);
        $this->get('/esercizi/2026/modello-d')->assertSee('2 da rivedere', false);
        $this->get('/esercizi/2026/imputazioni?chiavi=entrate:B3')->assertSee('Da rivedere');
        $this->get('/esercizi/2025/forzature')->assertOk()->assertSee('Nessuna forzatura');
    }

    public function test_riforzare_riconferma_con_i_dati_di_oggi(): void
    {
        $sponsor = $this->entrata('Sponsorizzazioni e pubblicità', 'B');
        $this->forza([$sponsor]);
        ImpostazioniAssociazione::where('dal_esercizio', 2026)->update(['affiliazione_rete' => true]); // la riga del 2026 c'è dalla migrazione
        $this->assertNotSame([], $sponsor->refresh()->forzaturaDaRivedere());

        $this->forza([$sponsor], ['motivazione' => 'Confermato dopo l’affiliazione'])->assertSessionHasNoErrors();

        $this->assertSame([], $sponsor->refresh()->forzaturaDaRivedere());
        $this->assertSame('Confermato dopo l’affiliazione', $sponsor->forzatura_motivazione);
    }

    public function test_dal_pagina_delle_imputazioni_si_forza_e_il_lettore_non_vede_il_modulo(): void
    {
        $this->entrata('Sponsorizzazioni e pubblicità', 'B');

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/imputazioni?chiavi=entrate:B3')->assertSee('name="imputazioni[]"', false)->assertSee('Forza la natura');
        $this->actingAs($this->utenteCon('Lettore esterno'))->get('/esercizi/2026/imputazioni?chiavi=entrate:B3')->assertOk()
            ->assertDontSee('name="imputazioni[]"', false)->assertSee('Natura commerciale: commerciale', false);
    }
}
