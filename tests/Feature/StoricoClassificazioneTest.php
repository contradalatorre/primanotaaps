<?php

namespace Tests\Feature;

use App\Classificazione;
use App\Models\Conto;
use App\Models\EsempioClassificazione;
use App\Models\ImportEstratto;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Regola;
use App\Models\Tag;
use App\Models\Ruolo;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Le proposte dallo storico (#43): cascata dopo le regole, fonte, protezione delle scelte e suggerimento di regola. Dati inventati. */
class StoricoClassificazioneTest extends TestCase
{
    use RefreshDatabase;

    private Conto $banca;

    private Conto $cassa;

    private User $tesoriere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-01 10:00');
        $this->tesoriere = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->banca = Conto::factory()->create([
            'tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv',
            'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 120000,
        ]);
        // I precedenti stanno su un altro conto, così il saldo dell'estratto di aprile resta quello del file.
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa storica']);
    }

    private function tipologia(string $nome): int
    {
        return Tipologia::where('nome', $nome)->value('id');
    }

    private function iniziativa(string $nome): int
    {
        return Iniziativa::where('nome', $nome)->value('id');
    }

    private function movimento(string $descrizione, array $altri = []): Movimento
    {
        return Movimento::factory()->create($altri + [
            'conto_id' => $this->cassa->id, 'manuale' => false, 'inserito_da' => $this->tesoriere->id,
            'descrizione' => $descrizione, 'importo' => 5830, 'verso' => 'uscita', 'causale' => 'PAGAMENTO UTENZE', 'data' => '2026-03-22',
        ]);
    }

    /** Un movimento già classificato e confermato: il precedente da cui lo storico impara. */
    private function precedente(string $descrizione, array $altri = [], string $tipologia = 'Utenze', string $iniziativa = 'Carnevale'): Movimento
    {
        $movimento = $this->movimento($descrizione, $altri);
        Classificazione::salva($this->tesoriere, $movimento, null, [['tipologia_id' => $this->tipologia($tipologia), 'iniziativa_id' => $this->iniziativa($iniziativa)]]);
        Classificazione::conferma($this->tesoriere, $movimento, Classificazione::versione($movimento->refresh()));

        return $movimento->refresh();
    }

    private function importaAprile(): ImportEstratto
    {
        Storage::fake('local');
        $this->actingAs($this->tesoriere)->post("/conti/{$this->banca->id}/import", ['estratto' => UploadedFile::fake()->createWithContent('aprile.csv', file_get_contents(base_path('tests/fixtures/credit-agricole/aprile-2026.csv')))]);
        $import = ImportEstratto::firstOrFail();
        $this->post("/import/{$import->id}/conferma")->assertRedirect();

        return $import;
    }

    private function bolletta(): Movimento
    {
        return Movimento::where('descrizione', 'like', '%FORNITORE ENERGIA%')->where('conto_id', $this->banca->id)->firstOrFail();
    }

    private function nomeTipologia(Movimento $movimento): ?string
    {
        return $movimento->imputazioni()->with('tipologia')->first()?->tipologia->nome;
    }

    private function dati(array $altri = []): array
    {
        return $altri + ['nome' => 'Energia', 'testo' => 'FORNITORE ENERGIA', 'tipologia_id' => $this->tipologia('Materiali e beni di consumo'), 'iniziativa_id' => $this->iniziativa('Carnevale')];
    }

    public function test_le_regole_vengono_prima_dello_storico_e_quando_non_corrispondono_piu_torna_lo_storico(): void
    {
        $origine = $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000987654');
        $this->importaAprile();
        $bolletta = $this->bolletta();
        $this->assertSame($origine->id, $bolletta->storico_movimento_id);

        // Una regola nuova che la prende vince sullo storico, e la proposta dello storico se ne va.
        $this->actingAs($this->tesoriere)->post('/regole', $this->dati())->assertSessionHasNoErrors();
        $regola = Regola::firstOrFail();
        $bolletta->refresh();
        $this->assertSame($regola->id, $bolletta->regola_id);
        $this->assertNull($bolletta->storico_movimento_id);
        $this->assertSame('Materiali e beni di consumo', $this->nomeTipologia($bolletta));

        // Se la regola non la prende più, non si torna da classificare: tocca di nuovo allo storico.
        $this->actingAs($this->tesoriere)->put("/regole/{$regola->id}", $this->dati(['testo' => 'ACQUEDOTTO']))->assertSessionHasNoErrors();
        $bolletta->refresh();
        $this->assertNull($bolletta->regola_id);
        $this->assertSame($origine->id, $bolletta->storico_movimento_id);
        $this->assertSame('proposto', $bolletta->stato);
        $this->assertSame('Utenze', $this->nomeTipologia($bolletta));
    }

    public function test_se_il_precedente_viene_riclassificato_la_proposta_lo_segue(): void
    {
        $origine = $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000987654');
        $this->importaAprile();
        $this->assertSame('Utenze', $this->nomeTipologia($this->bolletta()));

        // Il precedente resta confermato ma cambia classificazione: la proposta non resta quella vecchia.
        Classificazione::salva($this->tesoriere, $origine, null, [['tipologia_id' => $this->tipologia('Materiali e beni di consumo'), 'iniziativa_id' => $this->iniziativa('Carnevale')]]);
        $this->actingAs($this->tesoriere)->post('/regole', $this->dati(['nome' => 'Per ricalcolare', 'testo' => 'ZZZ']))->assertSessionHasNoErrors();

        $this->assertSame('Materiali e beni di consumo', $this->nomeTipologia($this->bolletta()));
    }

    public function test_salvare_una_regola_che_non_c_entra_non_riscrive_le_proposte_dello_storico(): void
    {
        $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000987654');
        $this->importaAprile();
        $bolletta = $this->bolletta();
        $prima = RegistroModifica::where('oggetto_tipo', 'movimento')->where('oggetto_id', $bolletta->id)->count();

        $this->actingAs($this->tesoriere)->post('/regole', $this->dati(['nome' => 'Altra', 'testo' => 'ZZZ']))->assertRedirect()
            ->assertSessionHas('ok', 'Regola salvata. Nessuna proposta è cambiata.');

        $this->assertSame($prima, RegistroModifica::where('oggetto_tipo', 'movimento')->where('oggetto_id', $bolletta->id)->count());
    }

    public function test_le_condizioni_suggerite_contengono_una_parola_della_controparte(): void
    {
        // Fra prefisso e nome cambia qualcosa: la frase comune più lunga sarebbe solo la carta, che prende ogni pagamento.
        foreach (['03/03', '04/04', '05/05'] as $i => $giorno) {
            $this->precedente('CARTA CA DEBIT VISA *1234 DEL '.$giorno.' ESSELUNGA', ['causale' => 'PAGAMENTO TRAMITE POS', 'data' => '2026-0'.($i + 1).'-10']);
        }
        $pagina = $this->actingAs($this->tesoriere)->get('/regole')->assertOk()->assertSee('Crea una regola');
        preg_match('#href="([^"]+)"[^>]*>Crea una regola#', $pagina->getContent(), $collegamento);
        $this->get(html_entity_decode($collegamento[1]))->assertOk()->assertSee('name="testo" value="ESSELUNGA"', false);
    }

    public function test_l_anteprima_dice_che_un_movimento_lasciato_dalla_regola_passa_allo_storico(): void
    {
        $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000987654');
        $this->importaAprile();
        $this->actingAs($this->tesoriere)->post('/regole', $this->dati())->assertSessionHasNoErrors();
        $regola = Regola::firstOrFail();

        $this->put('/regole/anteprima', $this->dati(['regola' => $regola->id, 'testo' => 'ACQUEDOTTO']))->assertOk()
            ->assertSeeInOrder(['Non prenderebbe più', 'FORNITORE ENERGIA', 'passa allo storico'])->assertDontSee('torna da classificare');
    }

    public function test_confermati_e_scelte_a_mano_non_si_toccano_e_salvare_una_proposta_dello_storico_ne_fa_una_scelta_a_mano(): void
    {
        $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000987654');
        $this->importaAprile();
        $bolletta = $this->bolletta();
        $this->assertNotNull($bolletta->storico_movimento_id);

        // Salvata com'è, la proposta dello storico diventa una scelta della persona.
        Classificazione::salva($this->tesoriere, $bolletta, null, [['tipologia_id' => $this->tipologia('Utenze'), 'iniziativa_id' => $this->iniziativa('Carnevale')]]);
        $bolletta->refresh();
        $this->assertNull($bolletta->storico_movimento_id);
        $this->assertSame('Proposta da Tina Verdi', $bolletta->load('proponente', 'regola', 'storicoMovimento', 'storicoEsempio')->fonteProposta());

        // Né una regola né un secondo import la riprendono.
        $this->actingAs($this->tesoriere)->post('/regole', $this->dati())->assertSessionHasNoErrors();
        $this->assertNull($bolletta->refresh()->regola_id);
        $this->assertSame('Utenze', $this->nomeTipologia($bolletta));

        // Un confermato resta com'è.
        Classificazione::conferma($this->tesoriere, $bolletta, Classificazione::versione($bolletta));
        $this->actingAs($this->tesoriere)->post('/regole', $this->dati(['nome' => 'Altra', 'testo' => 'ENERGIA']))->assertSessionHasNoErrors();
        $this->assertSame('confermato', $bolletta->refresh()->stato);
        $this->assertSame('Utenze', $this->nomeTipologia($bolletta));
    }

    public function test_un_esempio_senza_movimento_propone_come_un_movimento_ma_senza_collegamento(): void
    {
        $esempio = EsempioClassificazione::create([
            'verso' => 'uscita', 'causale' => 'PAGAMENTO UTENZE', 'descrizione' => 'SDD A : FORNITORE ENERGIA ESEMPIO', 'data' => '2025-04-22',
            'tipologia_id' => $this->tipologia('Utenze'), 'iniziativa_id' => $this->iniziativa('Carnevale'), 'area' => 'A',
        ]);

        $import = $this->importaAprile();

        $bolletta = $this->bolletta();
        $this->assertSame($esempio->id, $bolletta->storico_esempio_id);
        $this->assertNull($bolletta->storico_movimento_id);
        $this->assertSame('Storico: come l’esempio del 22/04/2025', $bolletta->load('proponente', 'regola', 'storicoMovimento', 'storicoEsempio')->fonteProposta());
        $this->assertSame('Utenze', $this->nomeTipologia($bolletta));
        $this->get("/import/{$import->id}/revisione?passo=pronte&movimento={$bolletta->id}")->assertOk()->assertSee('Storico: come l’esempio del 22/04/2025')->assertDontSee('class="link">Storico', false);
    }

    public function test_se_l_origine_della_proposta_sparisce_il_movimento_non_e_piu_pronto_e_il_prossimo_giro_lo_riporta_da_classificare(): void
    {
        $esempio = EsempioClassificazione::create([
            'verso' => 'uscita', 'causale' => 'PAGAMENTO UTENZE', 'descrizione' => 'SDD A : FORNITORE ENERGIA ESEMPIO', 'data' => '2025-04-22',
            'tipologia_id' => $this->tipologia('Utenze'), 'iniziativa_id' => $this->iniziativa('Carnevale'), 'area' => 'A',
        ]);
        $import = $this->importaAprile();
        $bolletta = $this->bolletta();
        $this->assertSame($esempio->id, $bolletta->storico_esempio_id);

        $esempio->delete(); // la chiave esterna mette a null la fonte: la proposta resta, senza origine
        $this->get("/import/{$import->id}/revisione?passo=pronte")->assertOk()->assertDontSee('FORNITORE ENERGIA');

        $this->post('/regole', $this->dati(['testo' => 'NIENTE DI QUESTO']))->assertSessionHasNoErrors(); // un giro di assegnazione che non c'entra con la bolletta
        $bolletta->refresh();
        $this->assertSame('da_classificare', $bolletta->stato);
        $this->assertSame(0, $bolletta->imputazioni()->count());
        $this->assertSame('proposta_dello_storico_tolta', RegistroModifica::where('oggetto_tipo', 'movimento')->where('oggetto_id', $bolletta->id)->latest('id')->value('azione'));
    }

    public function test_se_del_precedente_cambiano_solo_i_tag_la_proposta_li_segue(): void
    {
        $microprogetto = Tag::where('nome', 'Microprogetto')->value('id');
        $clt = Tag::where('nome', 'Gruppo CLT')->value('id');
        $origine = $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000987654');
        $riga = fn (int $tag) => [['tipologia_id' => $this->tipologia('Utenze'), 'iniziativa_id' => $this->iniziativa('Carnevale'), 'tag' => [$tag]]];
        Classificazione::salva($this->tesoriere, $origine, null, $riga($microprogetto));
        $this->importaAprile();
        $this->assertSame([$microprogetto], $this->bolletta()->imputazioni()->first()->tag()->pluck('tag.id')->all());

        Classificazione::salva($this->tesoriere, $origine, null, $riga($clt));
        $this->actingAs($this->tesoriere)->post('/regole', $this->dati(['nome' => 'Per ricalcolare', 'testo' => 'ZZZ']))->assertSessionHasNoErrors();

        $this->assertSame([$clt], $this->bolletta()->imputazioni()->first()->tag()->pluck('tag.id')->all());
    }

    public function test_lo_storico_non_si_legge_se_le_regole_prendono_tutti_i_candidati(): void
    {
        $this->movimento('ACQUISTO MATERIALE DA FORNITORE ESEMPIO');
        $letture = 0;
        DB::listen(function ($query) use (&$letture) {
            $letture += str_contains($query->sql, 'esempi_classificazione') ? 1 : 0;
        });

        $this->actingAs($this->tesoriere)->post('/regole', $this->dati(['testo' => 'ACQUISTO MATERIALE']))->assertSessionHasNoErrors();
        $this->assertSame('proposto', Movimento::firstOrFail()->stato); // preso dalla regola
        $this->assertSame(0, $letture);

        $this->put('/regole/anteprima', $this->dati(['regola' => Regola::firstOrFail()->id, 'testo' => 'MATERIALE']))->assertOk();
        $this->assertSame(0, $letture);
    }

    public function test_lo_storico_guarda_verso_causale_e_somiglianza_della_descrizione(): void
    {
        // Stessa causale, stessa controparte, altro verso: no. Un'altra controparte con la stessa carta: no.
        $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO', ['verso' => 'entrata'], 'Quote associative', 'Carnevale');
        $this->precedente('SDD A : ALTRO FORNITORE GAS ESEMPIO');
        $this->importaAprile();
        $this->assertSame('da_classificare', $this->bolletta()->stato);

        // Le varianti mensili della stessa controparte (date, numeri, IBAN diversi) combaciano; fra due uguali vince la più recente.
        $vecchio = $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000111111', ['data' => '2026-01-20'], 'Materiali e beni di consumo');
        $recente = $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000222222', ['data' => '2026-03-20']);
        $this->actingAs($this->tesoriere)->post('/regole', $this->dati(['nome' => 'Per ricalcolare', 'testo' => 'ZZZ']))->assertSessionHasNoErrors();
        $this->assertSame($recente->id, $this->bolletta()->storico_movimento_id);
        $this->assertNotSame($vecchio->id, $this->bolletta()->storico_movimento_id);
    }

    public function test_un_precedente_con_un_elemento_archiviato_non_propone(): void
    {
        $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO');
        Iniziativa::where('nome', 'Carnevale')->update(['archiviata_il' => now()]);

        $this->importaAprile();

        $this->assertSame('da_classificare', $this->bolletta()->stato);
    }

    public function test_le_proposte_dallo_storico_si_confermano_in_blocco(): void
    {
        $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000987654');
        $import = $this->importaAprile();
        $bolletta = $this->bolletta();

        $pagina = $this->get("/import/{$import->id}/revisione?passo=pronte")->assertOk()->assertSee('Storico: come il movimento del 22/03/2026');
        preg_match('#<form[^>]*action="[^"]*/conferme-pronte"[^>]*>(.*?)</form>#s', $pagina->getContent(), $modulo);
        preg_match('/name="impronta" value="([0-9a-f]{64})"/', $modulo[1], $impronta);
        $this->post("/import/{$import->id}/conferme-pronte", ['impronta' => $impronta[1]])->assertSessionHasNoErrors();

        $this->assertSame('confermato', $bolletta->refresh()->stato);
    }

    public function test_dopo_tre_conferme_coerenti_l_app_propone_di_creare_una_regola_con_le_condizioni_compilate(): void
    {
        $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000111111', ['data' => '2026-01-20']);
        $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000222222', ['data' => '2026-02-20']);
        $this->actingAs($this->tesoriere)->get('/regole')->assertOk()->assertDontSee('Crea una regola');

        // Una terza conferma con un'altra classificazione non rende coerente il gruppo.
        $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000333333', ['data' => '2026-03-20'], 'Materiali e beni di consumo');
        $this->get('/regole')->assertOk()->assertDontSee('Crea una regola');

        $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000444444', ['data' => '2026-04-20']);
        $pagina = $this->get('/regole')->assertOk()->assertSee('Dopo 3 conferme coerenti')->assertSee('Crea una regola')->assertSee('Utenze');
        preg_match('#href="([^"]+)"[^>]*>Crea una regola#', $pagina->getContent(), $collegamento);
        $modulo = $this->get(html_entity_decode($collegamento[1]))->assertOk();
        $modulo->assertSee('value="SDD A : FORNITORE ENERGIA ESEMPIO"', false)->assertSee('value="PAGAMENTO UTENZE"', false);
        $this->assertMatchesRegularExpression('#<option value="'.$this->tipologia('Utenze').'" selected#', $modulo->getContent());
        $this->assertMatchesRegularExpression('#<option value="'.$this->iniziativa('Carnevale').'" selected#', $modulo->getContent());
        $this->assertMatchesRegularExpression('#<option value="uscita" selected#', $modulo->getContent());
        $this->assertSame(0, Regola::count(), 'La regola non si crea da sola.');

        // Salvato il modulo così com'è, la regola esiste e il suggerimento sparisce.
        $this->post('/regole', ['nome' => 'Energia', 'testo' => 'SDD A : FORNITORE ENERGIA ESEMPIO', 'causale' => 'PAGAMENTO UTENZE', 'verso' => 'uscita',
            'tipologia_id' => $this->tipologia('Utenze'), 'iniziativa_id' => $this->iniziativa('Carnevale'), 'area' => 'A'])->assertSessionHasNoErrors();
        $this->get('/regole')->assertOk()->assertDontSee('Crea una regola');
    }

    public function test_il_suggerimento_compare_anche_nella_revisione_e_solo_a_chi_gestisce_le_regole(): void
    {
        foreach (['2026-01-20', '2026-02-20', '2026-03-20'] as $i => $data) {
            $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X054281110100000011111'.$i, ['data' => $data]);
        }
        $import = $this->importaAprile();

        $this->get("/import/{$import->id}/revisione")->assertOk()->assertSee('Dopo 3 conferme coerenti');

        // Chi conferma ma non gestisce le regole non può crearle: non vede il suggerimento.
        $operatore = $this->utenteCon(Ruolo::create(['nome' => 'Operatore', 'permessi' => ['vedere', 'importare_e_confermare']]));
        $this->actingAs($operatore)->get("/import/{$import->id}/revisione")->assertOk()->assertDontSee('Dopo 3 conferme coerenti');
    }

    public function test_dopo_un_import_lo_storico_propone_come_il_movimento_confermato_simile_e_ne_dice_la_fonte(): void
    {
        $origine = $this->precedente('SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000987654');

        $this->importaAprile();

        $bolletta = $this->bolletta();
        $this->assertSame('proposto', $bolletta->stato);
        $this->assertNull($bolletta->regola_id);
        $this->assertSame($origine->id, $bolletta->storico_movimento_id);
        $this->assertSame('Storico: come il movimento del 22/03/2026', $bolletta->load('proponente', 'regola', 'storicoMovimento', 'storicoEsempio')->fonteProposta());
        $this->assertSame('Utenze', $this->nomeTipologia($bolletta));
        $this->assertSame('Carnevale', $bolletta->imputazioni()->with('iniziativa')->first()->iniziativa->nome);
        // Gli altri movimenti dell'estratto non somigliano a nessun precedente.
        $this->assertSame(4, Movimento::where('stato', 'da_classificare')->count());
        // La pagina di revisione mostra la fonte e il collegamento al movimento di origine.
        $import = ImportEstratto::firstOrFail();
        $this->get("/import/{$import->id}/revisione?passo=pronte&movimento={$bolletta->id}")->assertOk()
            ->assertSee('Storico: come il movimento del 22/03/2026')->assertSee('class="link">Storico: come il movimento del 22/03/2026', false)->assertSee(route('movimenti.classificazione', $origine), false);
    }
}
