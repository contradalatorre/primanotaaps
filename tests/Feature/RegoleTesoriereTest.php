<?php

namespace Tests\Feature;

use App\Classificazione;
use App\Models\Conto;
use App\Models\DatiEsercizio;
use App\Models\ImportEstratto;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Regola;
use App\Models\Tipologia;
use App\Models\User;
use App\Regole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Le regole del tesoriere (#42): condizioni, priorità, anteprima e protezione di confermati e scelte a mano. Dati inventati. */
class RegoleTesoriereTest extends TestCase
{
    use RefreshDatabase;

    private Conto $banca;

    private User $tesoriere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-01 10:00');
        $this->tesoriere = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->banca = Conto::factory()->create([
            'tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv',
            'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 100000,
        ]);
    }

    private function tipologia(string $nome): int
    {
        return Tipologia::where('nome', $nome)->value('id');
    }

    private function iniziativa(string $nome): int
    {
        return Iniziativa::where('nome', $nome)->value('id');
    }

    private function movimento(string $descrizione, int $importo = 1250, string $verso = 'uscita', array $altri = []): Movimento
    {
        return Movimento::factory()->create($altri + [
            'conto_id' => $this->banca->id, 'manuale' => false, 'inserito_da' => $this->tesoriere->id,
            'descrizione' => $descrizione, 'importo' => $importo, 'verso' => $verso, 'causale' => 'PAGAMENTO TRAMITE POS',
        ]);
    }

    /** I campi del modulo di una regola: di base «descrizione contiene ENEL» → Utenze · Carnevale. */
    private function dati(array $altri = []): array
    {
        return $altri + [
            'nome' => 'Bollette', 'testo' => 'ENEL', 'tipologia_id' => $this->tipologia('Utenze'), 'iniziativa_id' => $this->iniziativa('Carnevale'),
        ];
    }

    private function creaRegola(array $altri = []): Regola
    {
        $this->actingAs($this->tesoriere)->post('/regole', $this->dati($altri))->assertSessionHasNoErrors();

        return Regola::where('nome', $altri['nome'] ?? 'Bollette')->firstOrFail();
    }

    public function test_la_prima_regola_che_corrisponde_vince_e_riordinare_cambia_il_vincitore(): void
    {
        $movimento = $this->movimento('Bolletta ENEL maggio');
        $utenze = $this->creaRegola(['nome' => 'Utenze luce']);
        $materiali = $this->creaRegola(['nome' => 'Spese generiche', 'tipologia_id' => $this->tipologia('Materiali e beni di consumo')]);

        // Il salvataggio applica la regola ai movimenti che c'erano già: la prima della lista prende il movimento.
        $this->assertSame('Regola: Utenze luce', $movimento->refresh()->fonteProposta());
        $this->assertSame($utenze->id, $movimento->regola_id);
        $this->assertSame('proposto', $movimento->stato);

        $this->actingAs($this->tesoriere)->post('/regole/ordine', ['ordine' => [$materiali->id, $utenze->id]])->assertSessionHasNoErrors();

        $this->assertSame($materiali->id, $movimento->refresh()->regola_id);
        $this->assertSame('Materiali e beni di consumo', $movimento->imputazioni()->with('tipologia')->first()->tipologia->nome);
    }

    public function test_confermati_e_scelte_a_mano_non_si_toccano_mai(): void
    {
        $confermato = $this->movimento('Bolletta ENEL aprile');
        Classificazione::salva($this->tesoriere, $confermato, null, [['tipologia_id' => $this->tipologia('Affitti e noleggi'), 'iniziativa_id' => $this->iniziativa('Carnevale')]]);
        Classificazione::conferma($this->tesoriere, $confermato, Classificazione::versione($confermato->refresh()));
        $amano = $this->movimento('Bolletta ENEL marzo');
        Classificazione::salva($this->tesoriere, $amano, null, [['tipologia_id' => $this->tipologia('Affitti e noleggi'), 'iniziativa_id' => $this->iniziativa('Carnevale')]]);
        $libero = $this->movimento('Bolletta ENEL giugno');

        $this->creaRegola();
        // Anche riordinando o modificando la regola, la scelta a mano e il confermato restano.
        $regola = Regola::firstOrFail();
        $this->actingAs($this->tesoriere)->put("/regole/{$regola->id}", $this->dati(['tipologia_id' => $this->tipologia('Materiali e beni di consumo')]))->assertSessionHasNoErrors();

        $this->assertSame('confermato', $confermato->refresh()->stato);
        $this->assertNull($confermato->regola_id);
        $this->assertSame('Affitti e noleggi', $confermato->imputazioni()->with('tipologia')->first()->tipologia->nome);
        $this->assertSame('proposto', $amano->refresh()->stato);
        $this->assertNull($amano->regola_id);
        $this->assertSame('Affitti e noleggi', $amano->imputazioni()->with('tipologia')->first()->tipologia->nome);
        $this->assertSame($regola->id, $libero->refresh()->regola_id);
        $this->assertSame('Materiali e beni di consumo', $libero->imputazioni()->with('tipologia')->first()->tipologia->nome);
    }

    public function test_salvare_a_mano_una_proposta_di_regola_ne_fa_una_scelta_che_le_regole_non_riprendono(): void
    {
        $movimento = $this->movimento('Bolletta ENEL maggio');
        $regola = $this->creaRegola();
        $this->assertSame($regola->id, $movimento->refresh()->regola_id);

        Classificazione::salva($this->tesoriere, $movimento, null, [['tipologia_id' => $this->tipologia('Utenze'), 'iniziativa_id' => $this->iniziativa('Carnevale')]]);
        $this->assertNull($movimento->refresh()->regola_id);
        $this->assertSame('Proposta da Tina Verdi', $movimento->load('proponente', 'regola')->fonteProposta());

        $this->actingAs($this->tesoriere)->put("/regole/{$regola->id}", $this->dati(['tipologia_id' => $this->tipologia('Materiali e beni di consumo')]));
        $this->assertSame('Utenze', $movimento->imputazioni()->with('tipologia')->first()->tipologia->nome);
    }

    public function test_una_regola_che_non_corrisponde_piu_toglie_la_sua_proposta(): void
    {
        $movimento = $this->movimento('Bolletta ENEL maggio');
        $regola = $this->creaRegola();
        $this->assertSame('proposto', $movimento->refresh()->stato);

        $this->actingAs($this->tesoriere)->put("/regole/{$regola->id}", $this->dati(['testo' => 'ACQUEDOTTO']))->assertSessionHasNoErrors();

        $movimento->refresh();
        $this->assertSame('da_classificare', $movimento->stato);
        $this->assertNull($movimento->regola_id);
        $this->assertSame(0, $movimento->imputazioni()->count());
    }

    public function test_l_anteprima_dice_cosa_prende_e_cosa_toglie_senza_salvare(): void
    {
        $enel = $this->movimento('Bolletta ENEL maggio', 1250);
        $acqua = $this->movimento('Bolletta ACQUEDOTTO maggio', 1250);
        $libero = $this->movimento('Cena sociale', 3000);
        // «Bollette» sta sopra (non prende ancora nulla), «Tutto a 12,50» sotto prende i due movimenti da 12,50.
        $bollette = $this->creaRegola(['nome' => 'Bollette', 'testo' => 'LUCE']);
        $generica = $this->creaRegola(['nome' => 'Tutto a 12,50', 'testo' => null, 'importo_min' => '12,50', 'importo_max' => '12,50', 'tipologia_id' => $this->tipologia('Materiali e beni di consumo')]);
        $this->assertSame($generica->id, $enel->refresh()->regola_id);

        // Cambiando «Bollette» in «ENEL» prenderebbe il movimento ENEL, togliendolo a «Tutto a 12,50».
        $risposta = $this->actingAs($this->tesoriere)->put('/regole/anteprima', $this->dati(['regola' => $bollette->id, 'nome' => 'Bollette', 'testo' => 'ENEL']))->assertSessionHasNoErrors()->assertOk();

        $risposta->assertSeeInOrder(['Prenderebbe', '1', 'movimento', 'Bolletta ENEL maggio', 'lo toglie alla regola «Tutto a 12,50»']);
        $risposta->assertDontSee('Cena sociale')->assertDontSee('ACQUEDOTTO');
        $this->assertSame('LUCE', $bollette->refresh()->testo, 'L’anteprima non salva nulla.');
        $this->assertSame($generica->id, $enel->refresh()->regola_id);
        $this->assertSame($generica->id, $acqua->refresh()->regola_id);
        $this->assertSame('da_classificare', $libero->refresh()->stato);

        // Una regola nuova che prende un movimento già di «Tutto a 12,50» non lo prenderebbe: sta in fondo.
        $this->actingAs($this->tesoriere)->post('/regole/anteprima', $this->dati(['nome' => 'Nuova', 'testo' => 'ACQUEDOTTO']))->assertOk()->assertSee('Prenderebbe')->assertDontSee('ACQUEDOTTO maggio');

        // Modificando «Tutto a 12,50» in modo che non prenda più nulla, l'anteprima elenca quello che lascia.
        $this->actingAs($this->tesoriere)->put('/regole/anteprima', $this->dati(['regola' => $generica->id, 'nome' => 'Tutto a 12,50', 'testo' => null, 'importo_min' => '99,00', 'importo_max' => '99,00']))
            ->assertSeeInOrder(['Non prenderebbe più', '2', 'Bolletta ENEL maggio', 'torna da classificare', 'Bolletta ACQUEDOTTO maggio', 'torna da classificare']);
    }

    /** #59: le regole propongono solo sui movimenti di esercizi aperti; dopo l'approvazione (o con il deposito) l'esercizio non si tocca. */
    public function test_le_regole_non_toccano_i_movimenti_di_un_esercizio_approvato(): void
    {
        // Voci e predefiniti delle iniziative ci sono per il 2026 e, copiati, per il 2027: senza la voce di quell'anno la regola non potrebbe proporre comunque.
        DB::statement('insert into voci_modello_d (esercizio, area, tipologia_id, voce) select 2027, area, tipologia_id, voce from voci_modello_d where esercizio = 2026');
        DB::statement('insert into iniziativa_esercizio (iniziativa_id, esercizio, area, attivita_id) select iniziativa_id, 2027, area, attivita_id from iniziativa_esercizio where esercizio = 2026');
        $chiuso = $this->movimento('Bolletta ENEL dicembre', 1250, 'uscita', ['data' => '2026-12-01']);
        $aperto = $this->movimento('Bolletta ENEL maggio', 1250, 'uscita', ['data' => '2027-05-01']);
        $dati = DatiEsercizio::create(['esercizio' => 2026, 'criterio_riparto' => 'ricavi', 'figurativi_dichiarati' => false, 'stato' => 'approvato', 'versione' => 1]);

        $this->assertSame([$aperto->id], Regole::anteprima($this->dati(), null)['presi']->pluck('movimento.id')->all(), 'L’anteprima non nomina il movimento del 2026');
        $regola = $this->creaRegola();

        $this->assertSame($regola->id, $aperto->refresh()->regola_id);
        $this->assertSame('da_classificare', $chiuso->refresh()->stato, 'Il 2026 è approvato: la regola non lo propone');
        $this->assertNull($chiuso->regola_id);

        $dati->update(['stato' => 'depositato']);
        $this->actingAs($this->tesoriere)->put("/regole/{$regola->id}", $this->dati())->assertSessionHasNoErrors();
        $this->assertSame('da_classificare', $chiuso->refresh()->stato, 'Nemmeno dopo il deposito');

        $dati->update(['stato' => 'aperto']);
        // Una regola identica non riscrive proposte: la si cambia davvero (un'altra tipologia).
        $this->actingAs($this->tesoriere)->put("/regole/{$regola->id}", $this->dati(['tipologia_id' => $this->tipologia('Materiali e beni di consumo')]))->assertSessionHasNoErrors();
        $this->assertSame($regola->id, $chiuso->refresh()->regola_id, 'Riaperto, torna ad avere le proposte delle regole');
    }

    public function test_l_anteprima_non_conta_come_presi_i_movimenti_che_sono_gia_della_regola(): void
    {
        $this->movimento('Bolletta ENEL maggio');
        $regola = $this->creaRegola();

        $this->actingAs($this->tesoriere)->put('/regole/anteprima', $this->dati(['regola' => $regola->id]))->assertOk()
            ->assertSee('Prenderebbe')->assertSee('0')->assertDontSee('Bolletta ENEL maggio')->assertDontSee('Non prenderebbe più');
    }

    public function test_il_socio_o_terzo_scelto_a_mano_resta_quando_la_regola_non_corrisponde_piu(): void
    {
        $movimento = $this->movimento('Bolletta ENEL maggio');
        $movimento->forceFill(['tipo_controparte' => 'terzo'])->save();
        $regola = $this->creaRegola(['tipo_controparte' => 'socio']);
        $this->assertSame('terzo', $movimento->refresh()->tipo_controparte, 'la scelta a mano vince sulla regola');

        $this->actingAs($this->tesoriere)->put("/regole/{$regola->id}", $this->dati(['testo' => 'ACQUEDOTTO']))->assertSessionHasNoErrors();
        $this->assertSame('terzo', $movimento->refresh()->tipo_controparte);

        $altro = $this->movimento('Bolletta ACQUEDOTTO');
        $this->actingAs($this->tesoriere)->put("/regole/{$regola->id}", $this->dati(['testo' => 'ACQUEDOTTO', 'tipo_controparte' => 'socio']));
        $this->assertSame('socio', $altro->refresh()->tipo_controparte);
        $this->actingAs($this->tesoriere)->put("/regole/{$regola->id}", $this->dati(['testo' => 'ZZZ', 'tipo_controparte' => 'socio']));
        $this->assertNull($altro->refresh()->tipo_controparte, 'quello messo dalla regola se ne va con lei');
    }

    public function test_cambiando_il_socio_o_terzo_di_una_regola_le_sue_proposte_lo_seguono(): void
    {
        $movimento = $this->movimento('Bolletta ENEL maggio');
        $regola = $this->creaRegola(['tipo_controparte' => 'socio']);
        $this->assertSame('socio', $movimento->refresh()->tipo_controparte);

        $this->actingAs($this->tesoriere)->put("/regole/{$regola->id}", $this->dati(['tipo_controparte' => 'terzo']))->assertSessionHasNoErrors();
        $this->assertSame('terzo', $movimento->refresh()->tipo_controparte, 'quello messo dalla regola segue la regola');

        $this->actingAs($this->tesoriere)->put("/regole/{$regola->id}", $this->dati())->assertSessionHasNoErrors();
        $this->assertNull($movimento->refresh()->tipo_controparte, 'tolto dalla regola, se ne va');
    }

    public function test_togliendo_il_socio_o_terzo_a_una_regola_la_scelta_a_mano_resta(): void
    {
        $aMano = $this->movimento('Bolletta ENEL giugno');
        $aMano->forceFill(['tipo_controparte' => 'terzo'])->save();
        $regola = $this->creaRegola(['tipo_controparte' => 'socio']);

        $this->actingAs($this->tesoriere)->put("/regole/{$regola->id}", $this->dati())->assertSessionHasNoErrors();
        $this->assertSame('terzo', $aMano->refresh()->tipo_controparte);
    }

    public function test_rinominare_una_regola_non_riscrive_le_sue_proposte(): void
    {
        $movimento = $this->movimento('Bolletta ENEL maggio');
        $regola = $this->creaRegola();
        $azioni = fn () => RegistroModifica::where('oggetto_tipo', 'movimento')->where('oggetto_id', $movimento->id)->count();
        $prima = $azioni();

        $this->actingAs($this->tesoriere)->put("/regole/{$regola->id}", $this->dati(['nome' => 'Energia']))->assertSessionHasNoErrors();
        $this->assertSame($prima, $azioni());
        $this->assertSame('Energia', $regola->refresh()->nome);

        $this->actingAs($this->tesoriere)->put("/regole/{$regola->id}", $this->dati(['nome' => 'Energia', 'tipologia_id' => $this->tipologia('Materiali e beni di consumo')]))->assertSessionHasNoErrors();
        $this->assertSame($prima + 1, $azioni());
    }

    public function test_l_anteprima_non_mostra_i_confermati(): void
    {
        $confermato = $this->movimento('Bolletta ENEL aprile');
        Classificazione::salva($this->tesoriere, $confermato, null, [['tipologia_id' => $this->tipologia('Utenze'), 'iniziativa_id' => $this->iniziativa('Carnevale')]]);
        Classificazione::conferma($this->tesoriere, $confermato, Classificazione::versione($confermato->refresh()));

        $this->actingAs($this->tesoriere)->post('/regole/anteprima', $this->dati())->assertOk()->assertSee('Prenderebbe')->assertDontSee('Bolletta ENEL aprile');
    }

    public function test_condizioni_sono_tutte_da_soddisfare_e_il_testo_non_e_un_espressione_regolare(): void
    {
        $punto = $this->movimento('Bolletta en.l maggio', 1500);
        $regex = $this->movimento('Bolletta ENEL maggio', 1500);
        // «en.l» è testo semplice: il punto non vale «qualunque lettera». Resta fuori anche chi non rispetta il resto.
        $fuoriImporto = $this->movimento('Bolletta EN.L maggio', 2500);
        $fuoriVerso = $this->movimento('Rimborso EN.L', 1500, 'entrata');
        $fuoriCausale = $this->movimento('Bolletta EN.L giugno', 1500, 'uscita', ['causale' => 'BONIFICO']);
        $this->creaRegola(['nome' => 'Precisa', 'testo' => 'en.l', 'verso' => 'uscita', 'importo_min' => '10,00', 'importo_max' => '20,00', 'causale' => 'pagamento tramite pos', 'conto_id' => $this->banca->id]);

        $this->assertSame('Regola: Precisa', $punto->refresh()->load('regola')->fonteProposta());
        $this->assertNull($regex->refresh()->regola_id);
        foreach ([$fuoriImporto, $fuoriVerso, $fuoriCausale] as $escluso) {
            $this->assertNull($escluso->refresh()->regola_id, $escluso->descrizione);
        }
    }

    public function test_il_periodo_di_validita_conta(): void
    {
        $dentro = $this->movimento('Bolletta ENEL', 1250, 'uscita', ['data' => '2026-03-10']);
        $fuori = $this->movimento('Bolletta ENEL', 1250, 'uscita', ['data' => '2026-05-10']);

        $regola = $this->creaRegola(['valida_dal' => '2026-03-01', 'valida_al' => '2026-03-31']);

        $this->assertSame($regola->id, $dentro->refresh()->regola_id);
        $this->assertNull($fuori->refresh()->regola_id);
    }

    public function test_una_regola_non_si_salva_senza_condizioni_ne_effetti_ne_con_importi_incoerenti(): void
    {
        $this->actingAs($this->tesoriere);
        $this->post('/regole', ['nome' => 'Vuota', 'tipologia_id' => $this->tipologia('Utenze'), 'iniziativa_id' => $this->iniziativa('Carnevale')])->assertSessionHasErrors('condizioni');
        $this->post('/regole', ['nome' => 'Senza effetti', 'testo' => 'ENEL'])->assertSessionHasErrors('iniziativa_id');
        $this->post('/regole', $this->dati(['importo_min' => '20,00', 'importo_max' => '10,00']))->assertSessionHasErrors('importo_max');
        $this->post('/regole', $this->dati(['importo_min' => 'molto']))->assertSessionHasErrors('importo_min');
        // Il verso della regola e quello della tipologia devono andare d'accordo.
        $this->post('/regole', $this->dati(['verso' => 'entrata']))->assertSessionHasErrors('tipologia_id');
        $this->assertSame(0, Regola::count());
    }

    public function test_le_regole_non_impostano_mai_la_natura_commerciale(): void
    {
        $this->assertFalse(Schema::hasColumn('regole', 'natura_commerciale'));
        // Un campo mandato in più viene ignorato: non c'è dove metterlo.
        $this->creaRegola(['natura_commerciale' => 'commerciale']);
        $this->assertArrayNotHasKey('natura_commerciale', Regola::firstOrFail()->getAttributes());
    }

    public function test_il_nome_e_unico(): void
    {
        $this->creaRegola();
        $this->actingAs($this->tesoriere)->post('/regole', $this->dati())->assertSessionHasErrors('nome');
    }

    public function test_le_regole_con_una_scelta_archiviata_sono_segnalate_e_non_propongono(): void
    {
        $movimento = $this->movimento('Bolletta ENEL maggio');
        $regola = $this->creaRegola();
        $this->assertSame($regola->id, $movimento->refresh()->regola_id);
        $this->actingAs($this->tesoriere)->get('/regole')->assertOk()->assertDontSee('da correggere');

        Iniziativa::where('nome', 'Carnevale')->update(['archiviata_il' => now()]);
        $this->actingAs($this->tesoriere)->get('/regole')->assertOk()->assertSeeInOrder(['Bollette', 'da correggere']);
        $nuovo = $this->movimento('Bolletta ENEL giugno');
        // La regola salvata di nuovo (qui senza cambiare nulla) non propone più con un'iniziativa archiviata.
        $this->actingAs($this->tesoriere)->put("/regole/{$regola->id}", $this->dati())->assertSessionHasNoErrors();
        $this->assertNull($nuovo->refresh()->regola_id);
        $this->assertNull($movimento->refresh()->regola_id);
    }

    public function test_le_proposte_di_regola_dicono_la_fonte_e_si_confermano_in_blocco(): void
    {
        Storage::fake('local');
        $this->banca->update(['saldo_iniziale' => 120000]);
        $regola = $this->creaRegola(['nome' => 'Energia', 'testo' => 'FORNITORE ENERGIA']);

        $this->actingAs($this->tesoriere)->post("/conti/{$this->banca->id}/import", ['estratto' => UploadedFile::fake()->createWithContent('aprile.csv', file_get_contents(base_path('tests/fixtures/credit-agricole/aprile-2026.csv')))]);
        $import = ImportEstratto::firstOrFail();
        $this->post("/import/{$import->id}/conferma")->assertRedirect();

        // Le regole si applicano subito dopo l'import.
        $bolletta = Movimento::where('descrizione', 'like', '%FORNITORE ENERGIA%')->firstOrFail();
        $this->assertSame($regola->id, $bolletta->regola_id);
        $this->assertSame('proposto', $bolletta->stato);
        $this->assertSame(4, Movimento::where('stato', 'da_classificare')->count());

        $pagina = $this->get("/import/{$import->id}/revisione?passo=pronte")->assertOk()->assertSee('Regola: Energia');
        preg_match('#<form[^>]*action="[^"]*/conferme-pronte"[^>]*>(.*?)</form>#s', $pagina->getContent(), $modulo);
        preg_match('/name="impronta" value="([0-9a-f]{64})"/', $modulo[1], $impronta);
        $this->post("/import/{$import->id}/conferme-pronte", ['impronta' => $impronta[1]])->assertSessionHasNoErrors();

        $this->assertSame('confermato', $bolletta->refresh()->stato);
        // E una regola nuova, adesso, non tocca più il movimento confermato.
        $this->actingAs($this->tesoriere)->post('/regole', $this->dati(['nome' => 'Altra', 'testo' => 'ENERGIA', 'tipologia_id' => $this->tipologia('Materiali e beni di consumo')]))->assertSessionHasNoErrors();
        $this->assertSame($regola->id, $bolletta->refresh()->regola_id);
        $this->assertSame('Utenze', $bolletta->imputazioni()->with('tipologia')->first()->tipologia->nome);
    }

    public function test_solo_chi_gestisce_le_regole_le_vede_e_le_scrive(): void
    {
        $consigliere = $this->utenteCon('Consigliere');
        $regola = $this->creaRegola();

        $this->actingAs($consigliere)->get('/regole')->assertForbidden();
        $this->post('/regole', $this->dati(['nome' => 'Mia']))->assertForbidden();
        $this->put("/regole/{$regola->id}", $this->dati())->assertForbidden();
        $this->post('/regole/ordine', ['ordine' => [$regola->id]])->assertForbidden();
        $this->post('/regole/anteprima', $this->dati())->assertForbidden();
        $this->assertSame(1, Regola::count());
    }

    public function test_un_ordine_incompleto_si_rifiuta(): void
    {
        $a = $this->creaRegola(['nome' => 'A']);
        $b = $this->creaRegola(['nome' => 'B']);

        $this->actingAs($this->tesoriere)->post('/regole/ordine', ['ordine' => [$a->id]])->assertSessionHasErrors('ordine');
        $this->post('/regole/ordine', ['ordine' => [$a->id, $a->id]])->assertSessionHasErrors('ordine');
        $this->assertSame([$a->id, $b->id], Regola::orderBy('posizione')->pluck('id')->all());
    }

    public function test_salvataggio_e_riordino_finiscono_nel_registro_delle_modifiche(): void
    {
        $a = $this->creaRegola(['nome' => 'A']);
        $b = $this->creaRegola(['nome' => 'B']);
        $this->post('/regole/ordine', ['ordine' => [$b->id, $a->id]]);

        $this->assertSame(['creata', 'creata', 'riordinata', 'riordinata'], RegistroModifica::where('oggetto_tipo', 'regola')->orderBy('id')->pluck('azione')->all());
    }
}
