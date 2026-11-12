<?php

namespace Tests\Feature;

use App\Esercizio\Prospetto;
use App\Esercizio\Verifiche;
use App\Models\Conto;
use App\Models\DatiEsercizio;
use App\Models\ImpostazioniAssociazione;
use App\Models\Imputazione;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\RigaFigurativa;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Dati dell'esercizio e test di secondarietà a schermo (#53): pagine, permessi, registro, home. Dati inventati. */
class SecondarietaPaginaTest extends TestCase
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

    private function imputa(string $verso, int $importo, string $tipologia, string $area, string $stato = 'confermato'): Imputazione
    {
        $movimento = Movimento::factory()->create(['conto_id' => $this->cassa->id, 'verso' => $verso, 'importo' => $importo, 'data' => '2026-03-01', 'inserito_da' => $this->tesoriere->id, 'stato' => $stato]
            + ($stato === 'confermato' ? ['confermato_il' => now(), 'confermato_da' => $this->tesoriere->id] : []));

        return Imputazione::create(['movimento_id' => $movimento->id, 'importo' => $importo, 'area' => $area, 'tipologia_id' => Tipologia::where('nome', $tipologia)->firstOrFail()->id]);
    }

    /** 90,00 di sponsorizzazioni (B) su 250,00 di entrate e 200,00 di uscite: il 30% non regge (36%), il 66% sì (45%). */
    private function esercizioConB(): void
    {
        $this->imputa('entrata', 9000, 'Sponsorizzazioni e pubblicità', 'B');
        $this->imputa('entrata', 16000, 'Quote associative', 'A');
        $this->imputa('uscita', 20000, 'Utenze', 'E');
    }

    private function lettore(): User
    {
        return $this->utenteCon('Lettore esterno', ['scade_il' => now()->addMonth()], ['email' => 'revisore@esterno.example']);
    }

    public function test_la_pagina_dice_esito_margine_e_margine_spendibile_su_entrambi_i_criteri_con_i_dati_di_oggi(): void
    {
        $this->esercizioConB();

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')->assertOk()
            ->assertSeeText('Provvisorio – aggiornato al 20/12/2026 09:30')
            ->assertSeeText('Rispettato')->assertSeeText('Non rispettato')
            // Il 30% è superato di 15,00 € (6 punti); il 66% è al 45% con 42,00 € di margine, e altre 42,00 € di entrate B lo tengono.
            ->assertSeeText('45,00%')->assertSeeText('36,00%')
            ->assertSeeText('42,00 € (21,00 punti)')->assertSeeText('-15,00 € (-6,00 punti)')
            ->assertSeeText('altre 42,00 € di entrate B tengono il criterio b')
            ->assertSeeText('Servono 21,43 € di entrate B in meno per rientrare nel criterio a');
    }

    public function test_non_dice_mai_test_superato(): void
    {
        $this->esercizioConB();

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')->assertDontSeeText('test superato')->assertDontSeeText('non superato');
    }

    public function test_il_criterio_proposto_e_il_piu_largo_e_va_confermato(): void
    {
        $this->esercizioConB();

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')->assertSeeText('Il criterio proposto è il b (66%)')->assertSeeText('Non è ancora confermato');
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/test/criterio', ['criterio' => 'b'])->assertRedirect('/esercizi/2026/test');

        $this->assertSame('b', DatiEsercizio::per(2026)->criterio_secondarieta);
        $riga = RegistroModifica::where('oggetto_tipo', 'dati_esercizio')->sole();
        $this->assertSame([null, 'b'], [$riga->prima['criterio_secondarieta'], $riga->dopo['criterio_secondarieta']]);
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')->assertSeeText('Criterio confermato dal Tesoriere: b (66%)');
    }

    public function test_un_criterio_confermato_peggiore_del_proposto_si_segnala(): void
    {
        $this->esercizioConB();
        DatiEsercizio::bloccata($this->tesoriere, 2026)->update(['criterio_secondarieta' => 'a']);

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')
            ->assertSeeText('Il criterio confermato (a) è «Non rispettato», mentre il b è «Rispettato»');
    }

    public function test_confermare_il_criterio_e_di_chi_gestisce_l_esercizio_e_accetta_solo_a_o_b(): void
    {
        $this->actingAs($this->lettore())->post('/esercizi/2026/test/criterio', ['criterio' => 'a'])->assertForbidden();
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/test/criterio', ['criterio' => 'c'])->assertSessionHasErrors('criterio');
        $this->assertNull(DatiEsercizio::where('esercizio', 2026)->first());
    }

    public function test_senza_figurativi_dichiarati_l_esito_e_prudente_con_l_avviso_e_il_link_per_inserirli(): void
    {
        $this->esercizioConB();

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')
            ->assertSeeText('Dato mancante: i costi figurativi non sono stati dichiarati')
            ->assertSee('href="'.route('dati-esercizio', 2026).'"', false);
        $this->actingAs($this->lettore())->get('/esercizi/2026/test')->assertSeeText('Dato mancante')->assertDontSee(route('dati-esercizio', 2026), false);

        DatiEsercizio::bloccata($this->tesoriere, 2026)->update(['figurativi_dichiarati' => true]);

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')->assertDontSeeText('Dato mancante');
    }

    public function test_i_figurativi_inseriti_entrano_nel_66_e_la_pagina_non_ne_mostra_la_descrizione(): void
    {
        $this->esercizioConB();
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/dati/figurativi', ['tipo' => 'costo', 'area' => 'A', 'descrizione' => 'Gruppo cucina, 100 ore di Mario Rossi', 'importo' => '1.000,00'])->assertSessionHasNoErrors();

        $this->assertSame(100000, Verifiche::secondarieta(2026)['criteri']['b']['denominatore']['parti'][1]['importo']);
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')->assertOk()->assertDontSeeText('Mario Rossi');
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d')->assertOk()->assertDontSeeText('Mario Rossi');
    }

    public function test_l_interruttore_include_le_proposte_senza_salvare_niente_e_dice_cosa_attende(): void
    {
        $this->imputa('entrata', 10000, 'Quote associative', 'A');
        $this->imputa('entrata', 5000, 'Sponsorizzazioni e pubblicità', 'B', 'proposto');

        $senza = $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')->assertSeeText('In attesa di conferma: 1 entrata per 50,00 €')->assertSeeText('e 0 uscite per 0,00 €')
            ->assertSeeText('Solo le imputazioni confermate');
        $senza->assertDontSeeText('33,33%');

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test?proposte=1')->assertSeeText('Con anche le imputazioni proposte')->assertSeeText('33,33%');
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')->assertSeeText('Solo le imputazioni confermate');
        $this->assertSame('proposto', Imputazione::where('area', 'B')->first()->movimento->stato, 'la proposta resta proposta');
    }

    public function test_la_formula_porta_alle_imputazioni_degli_addendi_e_le_cifre_tornano(): void
    {
        $this->esercizioConB();
        $modello = Prospetto::modelloD(2026);
        $link = route('esercizio.imputazioni', ['anno' => 2026, 'chiavi' => implode(',', $modello['aree']['B']['entrate']['totale']['chiavi']), 'titolo' => 'Entrate B (attività diverse)']);

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')->assertSee('href="'.e($link).'"', false)
            ->assertSeeText('Il calcolo, con i numeri')->assertSeeText('Base normativa e manuale')->assertSeeText('DM Lavoro 19 maggio 2021 n. 107')
            ->assertSee('href="'.route('manuale').'#sezione-19"', false);
        $this->actingAs($this->tesoriere)->get($link)->assertOk()->assertSeeText('90,00');
    }

    public function test_la_soglia_vicino_si_cambia_dalle_impostazioni_ed_e_registrata(): void
    {
        $this->imputa('entrata', 2000, 'Sponsorizzazioni e pubblicità', 'B');
        $this->imputa('entrata', 8000, 'Quote associative', 'A');
        $admin = $this->utenteCon('Amministratore');

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')->assertDontSeeText('Vicino alla soglia'); // 20% su 30%: 67% del limite, sotto l'80%
        $this->actingAs($admin)->put('/impostazioni', ['dal_esercizio' => 2026, 'denominazione' => 'Contrada La Torre APS', 'soglia_vicino' => 60])->assertSessionHasNoErrors();

        $this->assertSame(60, ImpostazioniAssociazione::per(2026)->soglia_vicino);
        $this->assertSame(60, RegistroModifica::where('oggetto_tipo', 'impostazioni')->sole()->dopo['soglia_vicino']);
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')->assertSeeText('Vicino alla soglia');
        $this->actingAs($admin)->put('/impostazioni', ['dal_esercizio' => 2026, 'denominazione' => 'X', 'soglia_vicino' => 100])->assertSessionHasErrors('soglia_vicino');
    }

    public function test_l_avviso_in_home_compare_solo_se_vicino_alla_soglia_o_non_rispettato(): void
    {
        $this->imputa('entrata', 1000, 'Sponsorizzazioni e pubblicità', 'B');
        $this->imputa('entrata', 9000, 'Quote associative', 'A');
        $this->actingAs($this->tesoriere)->get('/')->assertOk()->assertDontSee('home-test-secondarieta');

        $this->imputa('entrata', 3000, 'Sponsorizzazioni e pubblicità', 'B');
        $this->actingAs($this->tesoriere)->get('/')->assertSee('home-test-secondarieta')
            ->assertSeeText('Non rispettato')->assertSeeText('soglia superata di')
            ->assertSee('href="'.route('test-esercizio', 2026).'"', false);
    }

    public function test_i_dati_dell_esercizio_si_salvano_con_registro_e_si_leggono_solo_con_il_permesso(): void
    {
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/dati')->assertOk()->assertSeeText('Dati dell’esercizio 2026');
        $this->actingAs($this->tesoriere)->put('/esercizi/2026/dati', ['dipendenti_medi' => 3, 'attivo' => '12.345,67', 'criterio_riparto' => 'costi_diretti', 'figurativi_dichiarati' => '1'])
            ->assertRedirect('/esercizi/2026/dati')->assertSessionHasNoErrors();

        $dati = DatiEsercizio::per(2026);
        $this->assertSame([3, 1234567, 'costi_diretti', true], [$dati->dipendenti_medi, $dati->attivo, $dati->criterio_riparto, $dati->figurativi_dichiarati]);
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/dati')->assertSee('value="12.345,67"', false);
        $this->actingAs($this->tesoriere)->get('/registro')->assertOk()->assertSeeText('i dati dell’esercizio 2026');

        // Salvare senza cambiare niente non riempie il registro.
        $this->actingAs($this->tesoriere)->put('/esercizi/2026/dati', ['dipendenti_medi' => 3, 'attivo' => '12.345,67', 'criterio_riparto' => 'costi_diretti', 'figurativi_dichiarati' => '1']);
        $this->assertSame(1, RegistroModifica::where('oggetto_tipo', 'dati_esercizio')->count());

        $this->actingAs($this->tesoriere)->put('/esercizi/2026/dati', ['criterio_riparto' => 'a caso'])->assertSessionHasErrors('criterio_riparto');
        $this->actingAs($this->tesoriere)->put('/esercizi/2026/dati', ['criterio_riparto' => 'ricavi', 'attivo' => 'tanto'])->assertSessionHasErrors('attivo');
        $this->actingAs($this->lettore())->get('/esercizi/2026/dati')->assertForbidden();
        $this->actingAs($this->lettore())->put('/esercizi/2026/dati', ['criterio_riparto' => 'ricavi'])->assertForbidden();
    }

    public function test_le_righe_figurative_si_aggiungono_e_si_eliminano_e_il_registro_non_ha_le_descrizioni(): void
    {
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/dati/figurativi', ['tipo' => 'costo', 'area' => 'B', 'descrizione' => 'Squadra montaggio, 40 ore di Mario Rossi', 'importo' => '600,00'])->assertSessionHasNoErrors();

        $riga = RigaFigurativa::sole();
        $this->assertSame([2026, 'costo', 'B', 60000], [$riga->esercizio, $riga->tipo, $riga->area, $riga->importo]);
        // La descrizione la legge chi gestisce l'esercizio, qui; non il registro, che lo leggono altri (storia 143).
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/dati')->assertSeeText('Squadra montaggio')->assertSeeText('600,00 €');
        $this->actingAs($this->utenteCon('Amministratore'))->get('/registro')->assertOk()->assertSeeText('una riga figurativa dell’esercizio 2026')->assertDontSeeText('Mario Rossi');
        $this->assertStringNotContainsString('Mario Rossi', json_encode(RegistroModifica::where('oggetto_tipo', 'riga_figurativa')->get()->toArray()));

        $this->actingAs($this->tesoriere)->delete('/esercizi/2026/dati/figurativi/'.$riga->id)->assertRedirect('/esercizi/2026/dati');
        $this->assertSame(0, RigaFigurativa::count());
        $this->actingAs($this->tesoriere)->delete('/esercizi/2026/dati/figurativi/'.$riga->id)->assertNotFound();
        $this->assertSame(['creata', 'eliminata'], RegistroModifica::where('oggetto_tipo', 'riga_figurativa')->orderBy('id')->pluck('azione')->all());
    }

    public function test_una_riga_figurativa_non_valida_si_rifiuta(): void
    {
        foreach ([['tipo' => 'stima', 'area' => 'A', 'importo' => '10'], ['tipo' => 'costo', 'area' => 'E', 'importo' => '10'], ['tipo' => 'costo', 'area' => 'A', 'importo' => '0'], ['tipo' => 'costo', 'area' => 'A', 'importo' => '-5'], ['tipo' => 'costo', 'area' => 'A', 'importo' => 'molto']] as $caso) {
            $this->actingAs($this->tesoriere)->post('/esercizi/2026/dati/figurativi', $caso + ['descrizione' => 'Gruppo'])->assertSessionHasErrors();
        }
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/dati/figurativi', ['tipo' => 'costo', 'area' => 'A', 'importo' => '10'])->assertSessionHasErrors('descrizione');
        $this->assertSame(0, RigaFigurativa::count());
        $this->actingAs($this->lettore())->post('/esercizi/2026/dati/figurativi', ['tipo' => 'costo', 'area' => 'A', 'descrizione' => 'x', 'importo' => '10'])->assertForbidden();
    }

    public function test_la_pagina_dei_test_e_il_suo_indirizzo_corto_e_gli_anni_impossibili(): void
    {
        $this->actingAs($this->tesoriere)->get('/test')->assertRedirect('/esercizi/2026/test');
        $this->actingAs($this->tesoriere)->get('/esercizi/9999/test')->assertOk();
        $this->actingAs($this->tesoriere)->get('/esercizi/0000/test')->assertNotFound();
        $this->actingAs($this->tesoriere)->get('/esercizi/0000/dati')->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/esercizi/2026/test')->assertForbidden();
        $this->actingAs($this->lettore())->get('/esercizi/2026/test')->assertOk();
    }
}
