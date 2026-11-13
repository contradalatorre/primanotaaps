<?php

namespace Tests\Feature;

use App\Models\Conto;
use App\Models\DatiEsercizio;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\RigaFigurativa;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Il test dell'area A, il test dell'ente, il pareggio per iniziativa e l'annotazione in calce, a schermo (#54). Dati inventati. */
class TestNaturaPaginaTest extends TestCase
{
    use RefreshDatabase;

    private User $tesoriere;

    private Conto $cassa;

    private Iniziativa $festa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-20 09:30');
        $this->tesoriere = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa contanti', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
        $this->festa = Iniziativa::where('nome', 'Festa della Torre')->firstOrFail();
    }

    private function imputa(string $verso, int $importo, string $tipologia, string $area, array $altro = [], string $stato = 'confermato'): Imputazione
    {
        $movimento = Movimento::factory()->create([
            'conto_id' => $this->cassa->id, 'verso' => $verso, 'importo' => $importo, 'data' => '2026-03-01', 'inserito_da' => $this->tesoriere->id, 'stato' => $stato,
        ] + ($stato === 'confermato' ? ['confermato_il' => now(), 'confermato_da' => $this->tesoriere->id] : []) + ($altro['movimento'] ?? []));

        return Imputazione::create(['movimento_id' => $movimento->id, 'importo' => $importo, 'area' => $area, 'tipologia_id' => Tipologia::where('nome', $tipologia)->firstOrFail()->id, 'iniziativa_id' => $altro['iniziativa'] ?? null]);
    }

    /** 100,00 di corrispettivi da terzi e 60,00 di quote contro `$costiA` di utenze in area A. */
    private function esercizio(int $costiA): void
    {
        $this->imputa('entrata', 10000, 'Corrispettivi da terzi', 'A', ['iniziativa' => $this->festa->id]);
        $this->imputa('entrata', 6000, 'Quote associative', 'A');
        $this->imputa('uscita', $costiA, 'Utenze', 'A');
    }

    private function pagina(string $query = '')
    {
        return $this->actingAs($this->tesoriere)->get('/esercizi/2026/test'.$query)->assertOk();
    }

    public function test_il_2_bis_rispettato_lascia_non_commerciali_i_corrispettivi_e_l_ente_regge(): void
    {
        $this->esercizio(costiA: 12000);

        $this->pagina()
            ->assertSeeText('Corrispettivi dell’area A: 100,00 €')->assertSeeText('costi effettivi 120,00 €')
            ->assertDontSeeText('ha reso commerciali')
            ->assertSeeText('Entrate non commerciali 160,00 €')->assertSeeText('proventi commerciali 0,00 €');
    }

    public function test_il_2_bis_non_rispettato_rende_commerciali_i_corrispettivi_con_avviso_e_l_ente_ne_risente(): void
    {
        $this->esercizio(costiA: 8000);
        $this->imputa('entrata', 7000, 'Sponsorizzazioni e pubblicità', 'B');

        $this->pagina()
            ->assertSeeText('Il test dell’area A ha reso commerciali 1 imputazione per 100,00 €')
            // Ordine: il corrispettivo è diventato commerciale, e col resto (le quote sono 60,00 €) l'ente non regge. Le sponsorizzazioni sono neutre.
            ->assertSeeText('proventi commerciali 100,00 €')->assertSeeText('Entrate non commerciali 60,00 €')->assertSeeText('neutre 70,00 €');
    }

    public function test_cambiando_i_dati_tra_due_richieste_l_esito_segue(): void
    {
        $this->esercizio(costiA: 8000);
        $this->pagina()->assertSeeText('ha reso commerciali 1 imputazione');

        $this->imputa('uscita', 5000, 'Utenze', 'A');

        $this->pagina()->assertDontSeeText('ha reso commerciali');
    }

    public function test_la_tolleranza_del_6_per_cento_si_spiega_e_non_rende_commerciale(): void
    {
        $this->imputa('entrata', 10300, 'Corrispettivi da terzi', 'A');
        $this->imputa('uscita', 10000, 'Utenze', 'A');

        $this->pagina()
            ->assertSeeText('Entro la tolleranza del 6%')
            ->assertSeeText('al massimo 3 esercizi consecutivi dal 2026')->assertSeeText('ne sono stati usati 0, ne restano 3')
            ->assertDontSeeText('ha reso commerciali');
    }

    public function test_dal_2027_la_tolleranza_si_conta_dagli_esiti_approvati_e_senza_il_2026_approvato_si_dice(): void
    {
        $this->actingAs($this->tesoriere)->get('/esercizi/2027/test')->assertOk()->assertSeeText('L’esercizio 2026 non ha un esito approvato');
    }

    public function test_la_forzatura_prevale_sul_2_bis_e_il_corrispettivo_resta_non_commerciale(): void
    {
        $this->esercizio(costiA: 8000);
        $corrispettivo = Imputazione::whereHas('tipologia', fn ($t) => $t->where('nome', 'Corrispettivi da terzi'))->firstOrFail();
        $this->actingAs($this->tesoriere)->post('/imputazioni/natura', ['imputazioni' => [$corrispettivo->id], 'natura' => 'non_commerciale', 'motivazione' => 'Parere del commercialista del 3/3'])->assertSessionHasNoErrors();

        $this->pagina()->assertSeeText('Non rispettato')->assertDontSeeText('ha reso commerciali')->assertSeeText('proventi commerciali 0,00 €');
    }

    public function test_i_contributi_b2_sono_commerciali_nel_test_dell_ente(): void
    {
        $this->imputa('entrata', 6000, 'Contributi da privati', 'B');
        $this->imputa('entrata', 5000, 'Quote associative', 'A');

        $this->pagina()->assertSeeText('proventi commerciali 60,00 €')->assertSeeText('Entrate non commerciali 50,00 €');
    }

    public function test_il_valore_normale_dai_dati_dell_esercizio_pesa_tra_le_non_commerciali(): void
    {
        $this->imputa('entrata', 6000, 'Contributi da privati', 'B');
        $this->imputa('entrata', 5000, 'Quote associative', 'A');
        RigaFigurativa::create(['esercizio' => 2026, 'tipo' => 'provento', 'area' => 'A', 'descrizione' => 'Sala data gratis dal Comune', 'importo' => 3000]);

        $this->pagina()->assertSeeText('Entrate non commerciali 80,00 €')->assertSeeText('di cui valore normale delle prestazioni gratuite 30,00 €')->assertDontSeeText('Sala data gratis');
    }

    public function test_il_pareggio_per_iniziativa_e_informativo_e_ha_l_ancora(): void
    {
        $this->esercizio(costiA: 12000);
        $this->imputa('uscita', 4500, 'Acquisti per la somministrazione', 'A', ['iniziativa' => $this->festa->id]);

        $this->pagina()
            ->assertSee('id="pareggio-iniziativa-'.$this->festa->id.'"', false)
            ->assertSeeText('Festa della Torre')->assertSeeText('corrispettivi 100,00 €')->assertSeeText('costi diretti 45,00 €')->assertSeeText('55,00 € sopra i costi diretti')
            ->assertSeeText('non è il test di legge');
    }

    public function test_il_link_dei_conti_per_iniziativa_arriva_all_ancora_del_pareggio(): void
    {
        $this->esercizio(costiA: 12000);
        $ancora = '#pareggio-iniziativa-'.$this->festa->id;

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/iniziative')->assertOk()->assertSee('/esercizi/2026/test'.$ancora, false);
        $this->pagina()->assertSee('id="pareggio-iniziativa-'.$this->festa->id.'"', false);
    }

    public function test_l_iniziativa_in_area_a_solo_con_le_proposte_ha_il_link_e_l_ancora_con_l_interruttore(): void
    {
        $this->imputa('entrata', 8000, 'Corrispettivi da terzi', 'A', ['iniziativa' => $this->festa->id], 'proposto');
        $ancora = 'pareggio-iniziativa-'.$this->festa->id;

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/iniziative?proposte=1')->assertOk()->assertSee('/esercizi/2026/test?proposte=1#'.$ancora, false);
        $this->pagina('?proposte=1')->assertSee('id="'.$ancora.'"', false);
        $this->pagina()->assertDontSee('id="'.$ancora.'"', false);
    }

    public function test_la_natura_di_un_corrispettivo_dell_area_a_rimanda_al_test(): void
    {
        $this->esercizio(costiA: 8000);
        $corrispettivo = Imputazione::whereHas('tipologia', fn ($t) => $t->where('nome', 'Corrispettivi da terzi'))->firstOrFail();

        $this->actingAs($this->tesoriere)->get("/movimenti/{$corrispettivo->movimento_id}/classificazione")->assertOk()
            ->assertSee('/esercizi/2026/test#test-area-a', false);
    }

    public function test_l_annotazione_e_precompilata_dagli_esiti(): void
    {
        $this->imputa('entrata', 9000, 'Sponsorizzazioni e pubblicità', 'B');
        $this->imputa('entrata', 16000, 'Quote associative', 'A');
        $this->imputa('uscita', 20000, 'Utenze', 'E');

        $this->pagina()->assertSeeText('Annotazione in calce al rendiconto')
            ->assertSeeText('Secondarietà delle attività diverse')->assertSeeText('criterio b (66%)')
            ->assertSeeText('Strumentalità:')->assertSeeText('Costi promiscui:')->assertSeeText('quota dei ricavi');
    }

    public function test_il_tesoriere_modifica_l_annotazione_e_la_ripristina(): void
    {
        $this->actingAs($this->tesoriere)->put('/esercizi/2026/test/annotazione', ['testo' => "Testo scritto dal Tesoriere.\r\nSeconda riga."])->assertRedirect('/esercizi/2026/test');

        $this->assertSame("Testo scritto dal Tesoriere.\nSeconda riga.", DatiEsercizio::per(2026)->annotazione_in_calce);
        $riga = RegistroModifica::where('oggetto_tipo', 'dati_esercizio')->latest('id')->firstOrFail();
        $this->assertNull($riga->prima['annotazione_in_calce']);
        $this->assertSame("Testo scritto dal Tesoriere.\nSeconda riga.", $riga->dopo['annotazione_in_calce']);
        $this->pagina()->assertSeeText('Testo scritto dal Tesoriere.')->assertSeeText('Testo modificato a mano');

        $this->actingAs($this->tesoriere)->put('/esercizi/2026/test/annotazione', ['ripristina' => '1'])->assertRedirect('/esercizi/2026/test');

        $this->assertNull(DatiEsercizio::per(2026)->annotazione_in_calce);
        $this->pagina()->assertDontSeeText('Testo scritto dal Tesoriere.')->assertSeeText('Strumentalità:');
    }

    public function test_salvare_il_testo_proposto_senza_cambiarlo_non_lo_fissa(): void
    {
        $proposto = $this->pagina()->viewData('annotazione')['proposta'];

        $this->actingAs($this->tesoriere)->put('/esercizi/2026/test/annotazione', ['testo' => str_replace("\n", "\r\n", $proposto)])->assertSessionHasNoErrors();

        $this->assertNull(DatiEsercizio::per(2026)->annotazione_in_calce);
        $this->assertSame(0, RegistroModifica::where('oggetto_tipo', 'dati_esercizio')->count());
    }

    public function test_l_annotazione_la_modifica_solo_chi_gestisce_l_esercizio(): void
    {
        $lettore = $this->utenteCon('Lettore esterno', ['scade_il' => now()->addMonth()], ['email' => 'revisore@esterno.example']);

        $this->actingAs($lettore)->put('/esercizi/2026/test/annotazione', ['testo' => 'Intruso'])->assertForbidden();
        $this->actingAs($lettore)->get('/esercizi/2026/test')->assertOk()->assertSeeText('Annotazione in calce')->assertDontSee('name="testo"', false);
        $this->assertNull(DatiEsercizio::where('esercizio', 2026)->first());
    }

    public function test_un_testo_troppo_lungo_si_rifiuta(): void
    {
        $this->actingAs($this->tesoriere)->put('/esercizi/2026/test/annotazione', ['testo' => str_repeat('a', 5001)])->assertSessionHasErrors('testo');
    }
}
