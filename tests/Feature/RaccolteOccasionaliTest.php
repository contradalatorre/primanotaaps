<?php

namespace Tests\Feature;

use App\Esercizio\Deposito;
use App\Esercizio\Prospetto;
use App\Esercizio\Raccolte;
use App\Models\Attivita;
use App\Models\Conto;
use App\Models\DatiEsercizio;
use App\Models\ImpostazioniAssociazione;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RaccoltaOccasionale;
use App\Models\Tipologia;
use App\Models\User;
use App\Models\VersioneEsercizio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Le raccolte fondi occasionali dal database (#61, #18): voce C1 o C2 dall'attività, una raccolta per iniziativa, quadratura con C2. Dati inventati. */
class RaccolteOccasionaliTest extends TestCase
{
    use RefreshDatabase;

    private User $tesoriere;

    private Conto $cassa;

    private Conto $banca;

    private Iniziativa $notte;

    private Iniziativa $banchetto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-20 09:30');
        $this->tesoriere = $this->utenteCon('Tesoriere');
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa contanti', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
        $this->banca = Conto::factory()->create(['tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
        $this->notte = Iniziativa::create(['nome' => 'Notte delle lanterne']);
        $this->banchetto = Iniziativa::create(['nome' => 'Banchetto di primavera']);
    }

    private function occasionale(): Attivita
    {
        return Attivita::where('raccolta', 'occasionale')->firstOrFail();
    }

    /** Un'imputazione in area C, sul conto dato: l'attività è quella occasionale se non si dice altro. */
    private function imputaC(string $tipologia, int $importo, ?Iniziativa $iniziativa, array $extra = [], ?Conto $conto = null, string $verso = 'entrata'): Imputazione
    {
        $m = Movimento::factory()->confermato()->create(['conto_id' => ($conto ?? $this->cassa)->id, 'verso' => $verso, 'importo' => $importo, 'data' => '2026-05-10', 'inserito_da' => $this->tesoriere->id]);

        return Imputazione::create($extra + [
            'movimento_id' => $m->id, 'importo' => $importo, 'area' => 'C', 'attivita_id' => $this->occasionale()->id, 'iniziativa_id' => $iniziativa?->id,
            'tipologia_id' => Tipologia::where('nome', $tipologia)->firstOrFail()->id,
        ]);
    }

    public function test_le_attivita_di_raccolta_e_le_voci_c_sono_nei_dati_iniziali(): void
    {
        $this->assertSame(['Raccolta fondi abituale' => 'abituale', 'Raccolta fondi occasionale' => 'occasionale'], Attivita::where('area', 'C')->pluck('raccolta', 'nome')->all());
        $this->assertContains('liberalita', Tipologia::where('nome', 'Erogazioni liberali')->firstOrFail()->proprieta);
        $voce = fn (string $t) => DB::table('voci_modello_d')->where(['esercizio' => 2026, 'area' => 'C', 'tipologia_id' => Tipologia::where('nome', $t)->value('id')])->value('voce');
        $this->assertSame('C1', $voce('Erogazioni liberali'));
        $this->assertSame('C1', $voce('Materiale di comunicazione'));
        $this->assertNull($voce('Quote associative')); // una quota non è una raccolta fondi
        $this->assertSame('liberalita_monetarie', DB::table('righe_raccolta')->where(['esercizio' => 2026, 'tipologia_id' => Tipologia::where('nome', 'Erogazioni liberali')->value('id')])->value('riga'));
    }

    public function test_la_proprieta_liberalita_non_segna_da_rivedere_le_forzature_gia_fatte(): void
    {
        $i = $this->imputaC('Erogazioni liberali', 1000, $this->notte);

        $this->assertContains('liberalita', $i->tipologia->proprieta);
        $this->assertNotContains('liberalita', $i->ingressiNatura()['proprieta']);
    }

    public function test_in_area_c_la_voce_dipende_dall_attivita_c1_abituale_c2_occasionale(): void
    {
        $this->imputaC('Erogazioni liberali', 10000, $this->notte);
        $this->imputaC('Erogazioni liberali', 3000, null, ['attivita_id' => Attivita::where('raccolta', 'abituale')->value('id')]);
        $this->imputaC('Materiale di comunicazione', 2000, $this->notte, [], null, 'uscita');

        $m = Prospetto::modelloD(2026);

        $c = $m['aree']['C'];
        $this->assertSame(3000, $c['entrate']['righe'][0]['importo']);  // C1
        $this->assertSame(10000, $c['entrate']['righe'][1]['importo']); // C2
        $this->assertSame(2000, $c['uscite']['righe'][1]['importo']);   // C2 uscite
        $this->assertSame(0, $m['senzaRiga']['entrate']);
        $this->assertSame('C2', Imputazione::where('importo', 10000)->firstOrFail()->voce());
        $this->assertSame('C1', Imputazione::where('importo', 3000)->firstOrFail()->voce());
    }

    public function test_una_raccolta_per_iniziativa_con_il_denaro_diviso_tra_cassa_e_conti(): void
    {
        $this->imputaC('Erogazioni liberali', 30000, $this->notte);
        $this->imputaC('Erogazioni liberali', 20000, $this->notte, [], $this->banca);
        $this->imputaC('Corrispettivi da terzi', 700, $this->notte);
        $this->imputaC('Affitti e noleggi', 4000, $this->notte, [], null, 'uscita');
        $this->imputaC('Erogazioni liberali', 5000, $this->banchetto);

        $r = Raccolte::calcola(2026);

        $this->assertSame(['Banchetto di primavera', 'Notte delle lanterne'], array_column($r['raccolte'], 'nome'));
        $notte = $r['raccolte'][1];
        $this->assertSame(50000, $notte['proventi']['liberalita_monetarie']);
        $this->assertSame(700, $notte['proventi']['altri_proventi']);
        $this->assertSame(4000, $notte['oneri']['righe']['noleggi_affitti']);
        $this->assertSame(['contanti' => 30700, 'conti' => 20000, 'totale' => 50700], $notte['relazione']['denaro']);
        $this->assertTrue($r['quadratura']['quadra']);
        $this->assertSame([], $r['bloccanti']);
        $this->assertSame(55700, $r['quadratura']['entrate']['c2']);
    }

    public function test_una_c2_senza_iniziativa_o_senza_riga_dello_schema_rompe_la_quadratura_e_si_segnala(): void
    {
        $this->imputaC('Erogazioni liberali', 10000, $this->notte);
        $this->imputaC('Erogazioni liberali', 2500, null);
        DB::table('righe_raccolta')->where('tipologia_id', Tipologia::where('nome', 'Materiale di comunicazione')->value('id'))->delete();
        $this->imputaC('Materiale di comunicazione', 900, $this->notte, [], null, 'uscita');

        $r = Raccolte::calcola(2026);

        $this->assertFalse($r['quadratura']['quadra']);
        $this->assertSame(2500, $r['quadratura']['entrate']['senzaIniziativa']);
        $this->assertSame(900, $r['quadratura']['uscite']['senzaRiga']);
        $this->assertCount(2, $r['quadratura']['messaggi']);
        $this->assertSame($r['quadratura']['messaggi'], array_slice($r['bloccanti'], 0, 2));
        $this->assertSame(2, $r['senzaRiga']); // quella senza iniziativa e quella senza riga
    }

    public function test_un_iniziativa_con_i_dati_della_raccolta_ma_senza_imputazioni_compare_lo_stesso(): void
    {
        RaccoltaOccasionale::create(['iniziativa_id' => $this->banchetto->id, 'esercizio' => 2026, 'occasione' => 'Giornata del volontariato']);

        $r = Raccolte::calcola(2026);

        $this->assertSame('Giornata del volontariato', $r['raccolte'][0]['relazione']['campi']['occasione']);
        $this->assertSame(0, $r['raccolte'][0]['risultato']);
    }

    private function come(User $utente): static
    {
        $this->flushSession();

        return $this->actingAs($utente)->withSession(['auth.password_confirmed_at' => now()->unix()]);
    }

    /** Una raccolta che quadra: 500,00 di offerte e 120,00 di spese, il dato per l'approvazione. */
    private function raccoltaCheQuadra(): void
    {
        ImpostazioniAssociazione::updateOrCreate(['dal_esercizio' => 2026], ['denominazione' => 'Contrada La Torre', 'codice_fiscale' => '00000000000', 'numero_runts' => '12345']);
        $this->imputaC('Erogazioni liberali', 50000, $this->notte);
        $this->imputaC('Materiale di comunicazione', 12000, $this->notte, [], null, 'uscita');
    }

    private function approva(): TestResponse
    {
        $this->travelTo('2027-04-20 10:00');

        return $this->come($this->tesoriere)->post('/esercizi/2026/approvazione', ['data' => '2027-04-20']);
    }

    public function test_non_si_approva_se_la_somma_delle_raccolte_non_e_la_voce_c2(): void
    {
        $this->raccoltaCheQuadra();
        $this->imputaC('Erogazioni liberali', 2500, null); // C2 senza iniziativa: in nessuna raccolta

        $this->approva()->assertSessionHasErrors('raccolte');

        $this->assertSame('aperto', DatiEsercizio::per(2026)->stato);
        $this->assertSame(0, VersioneEsercizio::where('esercizio', 2026)->count());
    }

    public function test_non_si_approva_senza_la_motivazione_di_una_raccolta_oltre_il_90_per_cento_e_con_la_motivazione_si(): void
    {
        $this->raccoltaCheQuadra();
        $this->imputaC('Materiale di comunicazione', 40000, $this->notte, [], null, 'uscita'); // spese 520,00 su entrate 500,00: risultato negativo

        $this->approva()->assertSessionHasErrors('raccolte');
        $this->assertSame('aperto', DatiEsercizio::per(2026)->stato);

        RaccoltaOccasionale::create(['iniziativa_id' => $this->notte->id, 'esercizio' => 2026, 'motivazione' => 'Allestimento del palco più costoso del previsto']);
        $this->approva()->assertSessionHasNoErrors();

        $versione = VersioneEsercizio::vigente(2026);
        $this->assertSame(['Notte delle lanterne'], array_column($versione->istantanea['raccolte']['raccolte'], 'nome'));
        $this->assertSame(-2000, $versione->istantanea['raccolte']['raccolte'][0]['risultato']);
    }

    public function test_il_pdf_di_deposito_allega_una_raccolta_per_iniziativa_dopo_il_modello_d(): void
    {
        $this->raccoltaCheQuadra();
        $this->imputaC('Erogazioni liberali', 5000, $this->banchetto);
        $this->approva()->assertSessionHasNoErrors();

        $documenti = Deposito::documenti(Deposito::istantanea(2026));

        $this->assertSame(['Modello D · Rendiconto per cassa 2026', 'Raccolta fondi occasionale · Banchetto di primavera · 2026', 'Raccolta fondi occasionale · Notte delle lanterne · 2026'], array_column($documenti, 'titolo'));
        $r = $this->come($this->tesoriere)->get('/esercizi/2026/deposito.pdf')->assertOk();
        $this->assertStringContainsString('<pdfaid:part>1</pdfaid:part>', $r->getContent());
        $this->assertStringContainsString('<pdfaid:conformance>B</pdfaid:conformance>', $r->getContent());
    }

    public function test_senza_raccolte_il_pdf_di_deposito_e_il_solo_modello_d(): void
    {
        ImpostazioniAssociazione::updateOrCreate(['dal_esercizio' => 2026], ['denominazione' => 'Contrada La Torre', 'codice_fiscale' => '00000000000', 'numero_runts' => '12345']);
        $this->approva()->assertSessionHasNoErrors();

        $this->assertSame(['Modello D · Rendiconto per cassa 2026'], array_column(Deposito::documenti(Deposito::istantanea(2026)), 'titolo'));
    }

    public function test_un_istantanea_di_prima_delle_raccolte_si_stampa_ancora(): void
    {
        ImpostazioniAssociazione::updateOrCreate(['dal_esercizio' => 2026], ['denominazione' => 'Contrada La Torre', 'codice_fiscale' => '00000000000', 'numero_runts' => '12345']);
        $this->approva()->assertSessionHasNoErrors();
        $istantanea = Deposito::istantanea(2026);
        unset($istantanea['prospetti']['raccolte']);

        $this->assertStringStartsWith('%PDF-', Deposito::pdf($istantanea));
    }

    public function test_l_elenco_di_controllo_segnala_le_imputazioni_senza_riga_della_raccolta(): void
    {
        $this->raccoltaCheQuadra();
        DB::table('righe_raccolta')->where('tipologia_id', Tipologia::where('nome', 'Materiale di comunicazione')->value('id'))->delete();

        $this->come($this->tesoriere)->get('/esercizi/2026/approvazione')->assertOk()->assertSeeText('1 imputazione delle raccolte fondi senza riga dello schema');
    }

    public function test_un_iniziativa_predefinita_in_area_c_occasionale_compare_prima_di_avere_movimenti(): void
    {
        DB::table('iniziativa_esercizio')->insert(['iniziativa_id' => $this->notte->id, 'esercizio' => 2026, 'area' => 'C', 'attivita_id' => $this->occasionale()->id]);
        DB::table('iniziativa_esercizio')->insert(['iniziativa_id' => $this->banchetto->id, 'esercizio' => 2026, 'area' => 'C', 'attivita_id' => Attivita::where('raccolta', 'abituale')->value('id')]);

        $this->assertSame(['Notte delle lanterne'], array_column(Raccolte::calcola(2026)['raccolte'], 'nome'));
    }
}
