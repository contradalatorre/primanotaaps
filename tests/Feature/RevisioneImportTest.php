<?php

namespace Tests\Feature;

use App\Classificazione;
use App\Models\Conto;
use App\Models\ImportEstratto;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Tag;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** La schermata di revisione dei movimenti di un import (#41). Dati inventati. */
class RevisioneImportTest extends TestCase
{
    use RefreshDatabase;

    private Conto $banca;

    private ImportEstratto $import;

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
        // saldo finale del file: 1.000,00 + 50,00 − 12,50 − 30,00 − 8,00 = 999,50 (vedi `scenario()`)
        $this->import = $this->nuovoImport(99950);
    }

    private function nuovoImport(int $saldoFinale): ImportEstratto
    {
        return ImportEstratto::create([
            'conto_id' => $this->banca->id, 'user_id' => $this->tesoriere->id, 'stato' => 'confermato', 'periodo_dal' => '2026-05-01',
            'periodo_al' => '2026-05-31', 'saldo_iniziale' => 100000, 'saldo_finale' => $saldoFinale, 'righe' => 4, 'confermato_il' => now(),
        ]);
    }

    /** Un movimento importato: da classificare, a meno che non si dia la tipologia (allora proposto) o `$conferma`. */
    private function movimento(string $descrizione, int $importo, string $verso = 'uscita', ?string $tipologia = null, ?string $iniziativa = null, bool $conferma = false, ?ImportEstratto $import = null): Movimento
    {
        $movimento = Movimento::factory()->create([
            'conto_id' => $this->banca->id, 'import_estratto_id' => ($import ?? $this->import)->id, 'manuale' => false, 'inserito_da' => $this->tesoriere->id,
            'descrizione' => $descrizione, 'importo' => $importo, 'verso' => $verso, 'data' => '2026-05-10', 'causale' => 'PAGAMENTO TRAMITE POS',
        ]);
        if ($tipologia || $iniziativa) {
            Classificazione::salva($this->tesoriere, $movimento, null, [[
                'tipologia_id' => $tipologia ? Tipologia::where('nome', $tipologia)->value('id') : null,
                'iniziativa_id' => $iniziativa ? Iniziativa::where('nome', $iniziativa)->value('id') : null,
            ]]);
        }
        if ($conferma) {
            Classificazione::conferma($this->tesoriere, $movimento, Classificazione::versione($movimento->refresh()));
        }

        return $movimento->refresh();
    }

    /**
     * Quattro movimenti: due proposte pronte, una da decidere, una confermata.
     *
     * @return array{ghiaccio: Movimento, gazebo: Movimento, contributo: Movimento, bolletta: Movimento}
     */
    private function scenario(): array
    {
        return [
            'ghiaccio' => $this->movimento('Ghiaccio per la festa', 1250, 'uscita', 'Materiali e beni di consumo', 'Carnevale'),
            'gazebo' => $this->movimento('Noleggio gazebo', 3000, 'uscita', 'Affitti e noleggi', 'Carnevale'),
            'contributo' => $this->movimento('Contributo del Comune', 5000, 'entrata'),
            'bolletta' => $this->movimento('Bolletta della luce', 800, 'uscita', 'Utenze', 'Carnevale', conferma: true),
        ];
    }

    public function test_la_schermata_dice_cosa_resta_da_fare_e_con_quanti_passi(): void
    {
        $this->scenario();

        $this->actingAs($this->tesoriere)->get("/import/{$this->import->id}/revisione")
            ->assertOk()
            ->assertSeeText('Restano 1 movimento da decidere e 2 proposte pronte.')
            ->assertSeeText('Conferma 2 proposte pronte')
            ->assertSeeText('Giroconti')->assertSeeText('Proposte pronte')->assertSeeText('Da decidere')->assertSeeText('Tutti')
            ->assertSee('data-passo="pronte" data-conteggio="2"', false)
            ->assertSee('data-passo="decidere" data-conteggio="1"', false)
            ->assertSee('data-passo="tutti" data-conteggio="4"', false)
            ->assertSee('aria-valuenow="1" aria-valuemax="4"', false);
    }

    public function test_la_catena_del_saldo_si_confronta_con_l_estratto(): void
    {
        $this->scenario();

        $this->actingAs($this->tesoriere)->get("/import/{$this->import->id}/revisione")
            ->assertSeeText('1.000,00')->assertSeeText('50,00')->assertSeeText('50,50')->assertSeeText('999,50')
            ->assertSeeText('Uguale all’estratto');

        $diverso = $this->nuovoImport(99000);
        $this->actingAs($this->tesoriere)->get("/import/{$diverso->id}/revisione")->assertSeeText('Diverso dall’estratto')->assertSeeText('990,00');
    }

    /** Quello che mandano i pulsanti della pagina: id del movimento → versione che l'utente ha davanti. */
    private function versioni(Movimento ...$movimenti): array
    {
        return collect($movimenti)->mapWithKeys(fn (Movimento $m) => [$m->id => Classificazione::versione($m->refresh())])->all();
    }

    private function confermaVia(array $dati, ?ImportEstratto $import = null)
    {
        return $this->actingAs($this->tesoriere)->post('/import/'.($import ?? $this->import)->id.'/conferme', $dati);
    }

    public function test_la_conferma_di_un_movimento_porta_al_successivo_e_lascia_traccia(): void
    {
        $s = $this->scenario();

        $this->confermaVia(['movimenti' => $this->versioni($s['ghiaccio']), 'passo' => 'pronte', 'dopo' => $s['gazebo']->id])
            ->assertRedirect(route('import.revisione', [$this->import, 'passo' => 'pronte', 'movimento' => $s['gazebo']->id]))
            ->assertSessionHas('ok', '1 movimento confermato.');

        $ghiaccio = $s['ghiaccio']->refresh();
        $this->assertSame('confermato', $ghiaccio->stato);
        $this->assertSame($this->tesoriere->id, $ghiaccio->confermato_da);
        $this->assertSame('proposto', $s['gazebo']->refresh()->stato);
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'movimento', 'oggetto_id' => $ghiaccio->id, 'azione' => 'confermato']);
    }

    public function test_senza_il_permesso_o_con_un_import_non_confermato_la_schermata_non_si_apre(): void
    {
        $this->scenario();
        $consigliere = $this->utenteCon('Consigliere');
        $this->actingAs($consigliere)->get("/import/{$this->import->id}/revisione")->assertForbidden();

        $this->import->update(['stato' => 'anteprima']);
        $this->actingAs($this->tesoriere)->get("/import/{$this->import->id}/revisione")->assertNotFound();
    }

    public function test_la_conferma_di_gruppo_e_quella_di_tutte_le_proposte_pronte(): void
    {
        $s = $this->scenario();
        $utenze = $this->movimento('Bolletta del gas', 900, 'uscita', 'Utenze', 'Carnevale');

        // la pagina offre i pulsanti: del gruppo, e delle proposte pronte
        $this->actingAs($this->tesoriere)->get("/import/{$this->import->id}/revisione?passo=pronte")
            ->assertSee('id="selezione"', false)->assertSee('form="selezione"', false) // le caselle dei selezionati puntano al loro modulo
            ->assertSeeText('Conferma il gruppo')->assertSee('<span class="testo-conferma"></span>', false) // la ✓ della riga non prende il nome del gruppo
            ->assertSeeText('Utenze · Carnevale')->assertSeeText('Materiali e beni di consumo · Carnevale');

        $this->confermaVia(['movimenti' => $this->versioni($utenze), 'passo' => 'pronte'])
            ->assertRedirect(route('import.revisione', [$this->import, 'passo' => 'pronte']))->assertSessionHas('ok', '1 movimento confermato.');
        $this->assertSame('confermato', $utenze->refresh()->stato);
        $this->assertSame('proposto', $s['ghiaccio']->refresh()->stato);

        $this->confermaVia(['movimenti' => $this->versioni($s['ghiaccio'], $s['gazebo'])])->assertSessionHas('ok', '2 movimenti confermati.');
        $this->assertSame(['confermato', 'confermato'], [$s['ghiaccio']->refresh()->stato, $s['gazebo']->refresh()->stato]);
        $this->assertSame(4, RegistroModifica::where('azione', 'confermato')->count());
    }

    public function test_con_un_movimento_che_non_si_conferma_non_se_ne_conferma_nessuno(): void
    {
        $s = $this->scenario();

        $this->confermaVia(['movimenti' => $this->versioni($s['ghiaccio'], $s['contributo'])])
            ->assertSessionHasErrors('conferma');

        $this->assertSame('proposto', $s['ghiaccio']->refresh()->stato);
        $this->assertStringContainsString('Contributo del Comune', session('errors')->first('conferma'));
    }

    public function test_una_conferma_su_una_versione_superata_si_rifiuta(): void
    {
        $s = $this->scenario();
        $vista = $this->versioni($s['ghiaccio']);
        Classificazione::salva($this->tesoriere, $s['ghiaccio'], null, [['tipologia_id' => Tipologia::where('nome', 'Utenze')->value('id'), 'iniziativa_id' => Iniziativa::where('nome', 'Carnevale')->value('id')]]);

        $this->confermaVia(['movimenti' => $vista])->assertSessionHasErrors('conferma');
        $this->assertSame('proposto', $s['ghiaccio']->refresh()->stato);
    }

    public function test_non_si_confermano_movimenti_di_un_altro_import_e_nemmeno_se_si_e_senza_permesso(): void
    {
        $s = $this->scenario();
        $altro = $this->nuovoImport(99950);
        $estraneo = $this->movimento('Altro estratto', 700, 'uscita', 'Utenze', 'Carnevale', import: $altro);

        $this->confermaVia(['movimenti' => $this->versioni($s['ghiaccio'], $estraneo)])->assertNotFound();
        $this->assertSame('proposto', $s['ghiaccio']->refresh()->stato);
        $this->assertSame('proposto', $estraneo->refresh()->stato);

        $this->actingAs($this->utenteCon('Consigliere'))->post("/import/{$this->import->id}/conferme", ['movimenti' => $this->versioni($s['ghiaccio'])])->assertForbidden();
    }

    public function test_si_imposta_l_iniziativa_ai_selezionati_senza_toccare_il_resto(): void
    {
        $s = $this->scenario();
        $festa = Iniziativa::where('nome', 'Festa della Torre')->value('id');

        $this->actingAs($this->tesoriere)->post("/import/{$this->import->id}/iniziativa", [
            'movimenti' => $this->versioni($s['ghiaccio'], $s['contributo']), 'iniziativa_id' => $festa, 'passo' => 'decidere',
        ])->assertRedirect(route('import.revisione', [$this->import, 'passo' => 'decidere']))->assertSessionHas('ok', 'Iniziativa impostata su 2 movimenti.');

        $ghiaccio = $s['ghiaccio']->refresh()->load('imputazioni');
        $this->assertSame($festa, $ghiaccio->imputazioni->sole()->iniziativa_id);
        $this->assertSame(Tipologia::where('nome', 'Materiali e beni di consumo')->value('id'), $ghiaccio->imputazioni->sole()->tipologia_id);
        $contributo = $s['contributo']->refresh()->load('imputazioni');
        $this->assertSame($festa, $contributo->imputazioni->sole()->iniziativa_id);
        $this->assertSame(5000, $contributo->imputazioni->sole()->importo);
        $this->assertSame('proposto', $contributo->stato);
    }

    public function test_la_versione_che_la_pagina_manda_e_quella_che_la_conferma_accetta(): void
    {
        $s = $this->scenario();
        // Due imputazioni con due tag ciascuna: l'ordine dei dati caricati non deve cambiare la versione.
        $diviso = $this->movimento('Fattura divisa', 200000);
        $tag = Tag::orderBy('id')->pluck('id')->all();
        $tipologia = fn (string $nome) => Tipologia::where('nome', $nome)->value('id');
        $carnevale = Iniziativa::where('nome', 'Carnevale')->value('id');
        Classificazione::salva($this->tesoriere, $diviso, null, [
            ['importo' => '1.500,00', 'tipologia_id' => $tipologia('Utenze'), 'iniziativa_id' => $carnevale, 'tag' => $tag],
            ['importo' => '500,00', 'tipologia_id' => $tipologia('Affitti e noleggi'), 'iniziativa_id' => $carnevale, 'tag' => array_reverse($tag)],
        ]);

        $pagina = $this->actingAs($this->tesoriere)->get("/import/{$this->import->id}/revisione?passo=pronte")->getContent();
        preg_match_all('/name="movimenti\[(\d+)\]" value="([0-9a-f]{64})"/', $pagina, $trovati, PREG_SET_ORDER);
        $this->assertNotEmpty($trovati);
        $mandati = [];
        foreach ($trovati as [, $id, $versione]) {
            $mandati[(int) $id] = $versione; // la stessa versione compare ovunque nella pagina: riga e gruppo
        }
        $this->assertEqualsCanonicalizing([$s['ghiaccio']->id, $s['gazebo']->id, $diviso->id], array_keys($mandati));

        $this->confermaVia(['movimenti' => $mandati])->assertSessionHasNoErrors();
        $this->assertSame(0, Movimento::where('import_estratto_id', $this->import->id)->whereIn('stato', ['proposto', 'da_classificare'])->where('id', '!=', $s['contributo']->id)->count());
    }

    /** La pagina manda l'impronta delle proposte pronte, non un campo per proposta: oltre `max_input_vars` PHP taglierebbe in silenzio. */
    private function improntaDallaPagina(): string
    {
        $pagina = $this->actingAs($this->tesoriere)->get("/import/{$this->import->id}/revisione?passo=pronte")->getContent();
        preg_match('#<form[^>]*action="[^"]*/conferme-pronte"[^>]*>(.*?)</form>#s', $pagina, $modulo);
        $this->assertNotEmpty($modulo, 'manca il modulo di conferma delle proposte pronte');
        $this->assertStringNotContainsString('name="movimenti[', $modulo[1]);
        preg_match('/name="impronta" value="([0-9a-f]{64})"/', $modulo[1], $impronta);
        $this->assertNotEmpty($impronta);

        return $impronta[1];
    }

    public function test_l_azione_primaria_conferma_le_pronte_con_la_sola_impronta(): void
    {
        $s = $this->scenario();
        $impronta = $this->improntaDallaPagina();

        $this->actingAs($this->tesoriere)->post("/import/{$this->import->id}/conferme-pronte", ['impronta' => $impronta])
            ->assertSessionHasNoErrors()->assertSessionHas('ok', '2 movimenti confermati.');
        $this->assertSame(['confermato', 'confermato'], [$s['ghiaccio']->refresh()->stato, $s['gazebo']->refresh()->stato]);
    }

    public function test_l_impronta_di_un_insieme_cambiato_si_rifiuta_e_non_conferma_niente(): void
    {
        $s = $this->scenario();
        $impronta = $this->improntaDallaPagina();
        // dopo che l'utente ha visto la pagina un'altra proposta diventa pronta
        $this->movimento('Bolletta del gas', 900, 'uscita', 'Utenze', 'Carnevale');
        $post = fn (string $i) => $this->actingAs($this->tesoriere)->post("/import/{$this->import->id}/conferme-pronte", ['impronta' => $i]);

        $post($impronta)->assertSessionHasErrors('conferma');
        $post(str_repeat('0', 64))->assertSessionHasErrors('conferma');
        $post('corta')->assertSessionHasErrors('impronta');
        $this->assertSame(['proposto', 'proposto'], [$s['ghiaccio']->refresh()->stato, $s['gazebo']->refresh()->stato]);
        $this->assertSame(0, RegistroModifica::where('oggetto_id', $s['ghiaccio']->id)->where('azione', 'confermato')->count());
    }

    public function test_la_transazione_blocca_tutti_i_movimenti_prima_di_leggerne_uno(): void
    {
        // MySQL (REPEATABLE READ) fotografa alla prima lettura semplice: se si blocca un movimento alla volta,
        // dal secondo in poi si decide su dati vecchi. Quindi, dopo conto e import, un solo lock su tutti gli id,
        // e nessuna lettura semplice prima. Il test gira già in una transazione: si controlla la sequenza delle query.
        $s = $this->scenario();
        $ids = [$s['ghiaccio']->id, $s['gazebo']->id];
        $dati = ['movimenti' => $this->versioni($s['ghiaccio'], $s['gazebo'])];
        DB::enableQueryLog();
        $this->confermaVia($dati)->assertSessionHasNoErrors();

        $query = DB::getQueryLog();
        $inizio = array_key_first(array_filter($query, fn ($q) => str_contains($q['query'], 'from `conti`') && str_contains($q['query'], 'for update')));
        $this->assertNotNull($inizio);
        $lock = null;
        foreach (array_slice($query, $inizio) as $q) {
            if (! str_contains($q['query'], 'for update')) {
                $this->fail('Lettura semplice prima del lock di tutti i movimenti: '.$q['query']);
            }
            if (str_contains($q['query'], 'from `movimenti`') && count(array_intersect($ids, $q['bindings'])) === count($ids)) {
                $lock = $q;
                break;
            }
        }
        $this->assertNotNull($lock, 'nessun lock unico su tutti i movimenti');
        $this->assertStringContainsString('order by `id`', $lock['query']);
    }

    public function test_un_passo_che_non_e_una_stringa_non_rompe_la_pagina(): void
    {
        $this->scenario();
        $this->actingAs($this->tesoriere)->get("/import/{$this->import->id}/revisione?passo[]=x&movimento[]=1")
            ->assertOk()->assertSee('data-passo="pronte" data-conteggio="2"', false);
    }

    public function test_l_iniziativa_su_un_movimento_diviso_tiene_gli_importi(): void
    {
        $diviso = $this->movimento('Fattura divisa', 200000);
        $tipologia = fn (string $nome) => Tipologia::where('nome', $nome)->value('id');
        $carnevale = Iniziativa::where('nome', 'Carnevale')->value('id');
        Classificazione::salva($this->tesoriere, $diviso, null, [
            ['importo' => '1.500,00', 'tipologia_id' => $tipologia('Utenze'), 'iniziativa_id' => $carnevale],
            ['importo' => '500,00', 'tipologia_id' => $tipologia('Affitti e noleggi'), 'iniziativa_id' => $carnevale],
        ]);
        $festa = Iniziativa::where('nome', 'Festa della Torre')->value('id');

        $this->actingAs($this->tesoriere)->post("/import/{$this->import->id}/iniziativa", ['movimenti' => $this->versioni($diviso), 'iniziativa_id' => $festa])
            ->assertSessionHasNoErrors();

        $imputazioni = $diviso->refresh()->imputazioni()->orderBy('id')->get();
        $this->assertSame([150000, 50000], $imputazioni->pluck('importo')->all());
        $this->assertSame([$festa, $festa], $imputazioni->pluck('iniziativa_id')->all());
    }

    public function test_l_iniziativa_su_una_versione_superata_si_rifiuta(): void
    {
        $s = $this->scenario();
        $vista = $this->versioni($s['ghiaccio']);
        Classificazione::salva($this->tesoriere, $s['ghiaccio'], null, [['tipologia_id' => Tipologia::where('nome', 'Utenze')->value('id'), 'iniziativa_id' => Iniziativa::where('nome', 'Carnevale')->value('id')]]);

        $this->actingAs($this->tesoriere)->post("/import/{$this->import->id}/iniziativa", ['movimenti' => $vista, 'iniziativa_id' => Iniziativa::where('nome', 'Festa della Torre')->value('id')])
            ->assertSessionHasErrors('conferma');
    }

    public function test_il_dettaglio_mostra_fonte_voce_e_natura(): void
    {
        $s = $this->scenario();

        $this->actingAs($this->tesoriere)->get("/import/{$this->import->id}/revisione?passo=pronte&movimento={$s['gazebo']->id}")
            ->assertOk()->assertSeeText('Proposta da Tina Verdi')->assertSeeText('Uscite A1')->assertSeeText('Natura')
            ->assertSeeText('Da calcolare')->assertSee('data-successivo', false);
    }

    public function test_la_pagina_dell_import_confermato_porta_alla_revisione(): void
    {
        $this->actingAs($this->tesoriere)->get("/import/{$this->import->id}")->assertSee(route('import.revisione', $this->import), false);
    }

    public function test_senza_tipologia_la_spunta_e_spenta_con_il_suo_perche(): void
    {
        $this->scenario();

        $this->actingAs($this->tesoriere)->get("/import/{$this->import->id}/revisione?passo=decidere")
            ->assertSee('aria-disabled="true"', false)->assertSeeText('Per confermare serve la tipologia');
    }
}
