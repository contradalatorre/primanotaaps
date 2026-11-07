<?php

namespace Tests\Feature;

use App\Classificazione;
use App\Models\Conto;
use App\Models\ImportEstratto;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
