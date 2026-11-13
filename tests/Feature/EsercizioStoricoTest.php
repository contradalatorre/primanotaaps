<?php

namespace Tests\Feature;

use App\Esercizio\Verifiche;
use App\Import\EsempiDalFoglio;
use App\Models\Attivita;
use App\Models\Conto;
use App\Models\ConversioneCausale;
use App\Models\EsempioClassificazione;
use App\Models\EsercizioStorico;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Tag;
use App\Models\Tipologia;
use App\Models\User;
use App\Storico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\FogliInventati;
use Tests\TestCase;
use Tests\Unit\RendicontoFoglioTest;

/** Il caricamento dell'esercizio storico dal foglio approvato (#63, #18): anteprima, abbinamento manuale, conferma, blocco. Fogli inventati. */
class EsercizioStoricoTest extends TestCase
{
    use FogliInventati, RefreshDatabase;

    private User $tesoriere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-20 09:30');
        $this->tesoriere = $this->utenteCon('Tesoriere');
    }

    /** Un foglio 2025 inventato: quote 1.000,00, utenze 300,00, una riga da abbinare a mano e l'avanzo da investimenti sbagliato come nel foglio vero. */
    private function foglio(string $formato = 'xlsx', array $schede = []): UploadedFile
    {
        $righe = RendicontoFoglioTest::foglio(
            ['entrate:A1' => 1000, 'uscite:E2' => 300, 'uscite:I1' => 50],
            ['avanzo_investimenti' => 50.0], // dovrebbe essere −50,00
            ['cassa' => 120, 'depositi' => 530],
        );
        array_splice($righe, 5, 0, [['Spese postali varie', 12.5]]); // nella sezione delle uscite A, senza numero: non si riconosce

        return UploadedFile::fake()->createWithContent("foglio-2025.$formato", self::$formato(['RUNTS_Rendiconto' => $righe] + $schede));
    }

    private function carica(?UploadedFile $file = null, ?User $utente = null)
    {
        return $this->actingAs($utente ?? $this->tesoriere)->post('/esercizi/2025/storico', ['foglio' => $file ?? $this->foglio()]);
    }

    private function anteprimaUrl($risposta): string
    {
        $risposta->assertRedirectContains('/esercizi/2025/storico/anteprima/');

        return $risposta->headers->get('Location');
    }

    public function test_il_caricamento_mostra_l_anteprima_con_i_totali_ricalcolati_e_le_differenze_col_foglio(): void
    {
        $pagina = $this->actingAs($this->tesoriere)->get($this->anteprimaUrl($this->carica()))->assertOk();

        $pagina->assertSeeText('1.000,00')->assertSeeText('Spese postali varie')->assertSeeText('12,50')
            ->assertSee('value="120,00"', false)->assertSee('value="530,00"', false)
            // L'app ricalcola l'avanzo da investimenti (−50,00) e segnala che il foglio ne scrive +50,00.
            ->assertSeeText('Avanzo/disavanzo da investimenti')->assertSeeText('nel foglio 50,00')->assertSeeText('ricalcolato -50,00');
        $this->assertSame(0, EsercizioStorico::count()); // finché non si conferma non entra nulla
    }

    public function test_funziona_anche_con_un_ods(): void
    {
        $this->actingAs($this->tesoriere)->get($this->anteprimaUrl($this->carica($this->foglio('ods'))))->assertOk()->assertSeeText('1.000,00');
    }

    public function test_un_file_che_non_e_un_foglio_o_senza_il_foglio_del_rendiconto_si_rifiuta(): void
    {
        $this->carica(UploadedFile::fake()->createWithContent('x.xlsx', 'non è un foglio'))->assertSessionHasErrors('foglio');
        $this->carica(UploadedFile::fake()->createWithContent('x.ods', self::ods(['Cassa' => [['a']]])))->assertSessionHasErrors('foglio');
    }

    public function test_i_figurativi_del_foglio_precompilano_l_anteprima_e_non_entrano_tra_le_voci(): void
    {
        $righe = RendicontoFoglioTest::foglio(['entrate:A1' => 1000], extra: ['cassa' => 1, 'depositi' => 2]);
        array_push($righe, ['Cassa e banca'], ['Costi figurativi'], ['1) Da attività di interesse generale', 40], ['Proventi figurativi'], ['2) Da attività diverse', 5]);
        $file = UploadedFile::fake()->createWithContent('foglio-2025.xlsx', self::xlsx(['RUNTS_Rendiconto' => $righe]));

        $this->actingAs($this->tesoriere)->get($this->anteprimaUrl($this->carica($file)))
            ->assertSee('name="figurativi[costi][A]" inputmode="decimal" maxlength="30" value="40,00"', false)
            ->assertSee('name="figurativi[proventi][B]" inputmode="decimal" maxlength="30" value="5,00"', false)
            ->assertSee('name="figurativi[costi][B]" inputmode="decimal" maxlength="30" value="0"', false);
    }

    public function test_la_conferma_salva_con_l_abbinamento_manuale_i_figurativi_e_i_saldi_e_poi_blocca(): void
    {
        $url = $this->anteprimaUrl($this->carica());
        $storage = Storage::disk('local');

        $this->actingAs($this->tesoriere)->post($url, [
            'abbinamenti' => ['6' => 'uscite:E2'], 'saldo_cassa' => '120,00', 'saldo_depositi' => '530,00',
            'figurativi' => ['costi' => ['A' => '40,00', 'B' => '0'], 'proventi' => ['A' => '', 'B' => '5']],
        ])->assertRedirect('/esercizi/2025/storico')->assertSessionHas('ok');

        $storico = EsercizioStorico::where('esercizio', 2025)->firstOrFail();
        $this->assertEqualsCanonicalizing(['entrate:A1' => 100000, 'uscite:E2' => 31250, 'uscite:I1' => 5000], $storico->importi); // il JSON di MySQL riordina le chiavi
        $this->assertSame([12000, 53000], [$storico->saldo_cassa, $storico->saldo_depositi]);
        $this->assertEqualsCanonicalizing(['costi' => ['A' => 4000, 'B' => 0], 'proventi' => ['A' => 0, 'B' => 500]], $storico->figurativi);
        $m = $storico->prospetto();
        $this->assertSame(100000 - 31250, $m['gestione']['avanzo']['importo']);
        $this->assertSame(-5000, $m['investimenti']['avanzo']['importo']);
        $this->assertContains('avanzo_investimenti', array_column($storico->differenze, 'chiave'));
        $r = RegistroModifica::where('oggetto_tipo', 'esercizio_storico')->firstOrFail();
        $this->assertSame([$this->tesoriere->id, 'caricato'], [$r->user_id, $r->azione]);
        $this->assertSame([], $storage->files('estratti')); // l'anteprima è sparita

        // Un secondo caricamento dello stesso esercizio non c'è: è bloccato.
        $this->actingAs($this->tesoriere)->post('/esercizi/2025/storico', ['foglio' => $this->foglio()])->assertSessionHasErrors('esercizio');
    }

    private function storicoCaricato(): EsercizioStorico
    {
        $this->actingAs($this->tesoriere)->post($this->anteprimaUrl($this->carica()), ['abbinamenti' => ['6' => 'uscite:E2'], 'saldo_cassa' => '120,00', 'saldo_depositi' => '530,00'])->assertSessionHas('ok');

        return EsercizioStorico::firstOrFail();
    }

    public function test_solo_chi_gestisce_i_dati_dell_esercizio_carica_e_l_anteprima_e_di_chi_l_ha_caricata(): void
    {
        $consigliere = $this->utenteCon('Consigliere');
        $this->carica(null, $consigliere)->assertForbidden();

        $url = $this->anteprimaUrl($this->carica());
        $altro = $this->utenteCon('Tesoriere');
        $this->actingAs($altro)->get($url)->assertNotFound();
        $this->actingAs($altro)->post($url, ['saldo_cassa' => '1', 'saldo_depositi' => '1'])->assertNotFound();

        // Il permesso si rilegge alla conferma, a riga bloccata: una revoca arrivata dopo l'anteprima ferma la conferma.
        $this->tesoriere->assegnazioni()->delete();
        $this->actingAs($this->tesoriere)->post($url, ['saldo_cassa' => '1', 'saldo_depositi' => '1'])->assertForbidden();
        $this->assertSame(0, EsercizioStorico::count());
    }

    public function test_un_esercizio_con_movimenti_non_puo_essere_storico(): void
    {
        $cassa = Conto::factory()->create(['aperto_il' => '2025-01-01', 'saldo_iniziale' => 0]);
        Movimento::factory()->create(['conto_id' => $cassa->id, 'data' => '2025-06-01', 'importo' => 100, 'verso' => 'entrata']);

        $this->carica()->assertSessionHasErrors('esercizio');
    }

    public function test_storico_solo_un_anno_precedente_al_primo_esercizio_con_movimenti(): void
    {
        // Oggi è il 20/12/2026 e nell'app non ci sono movimenti: il 2026 è l'esercizio in corso, non uno storico.
        $this->actingAs($this->tesoriere)->post('/esercizi/2026/storico', ['foglio' => $this->foglio()])->assertSessionHasErrors('esercizio');

        $url = $this->anteprimaUrl($this->carica());
        $cassa = Conto::factory()->create(['aperto_il' => '2024-01-01', 'saldo_iniziale' => 0]);
        Movimento::factory()->create(['conto_id' => $cassa->id, 'data' => '2024-06-01', 'importo' => 100, 'verso' => 'entrata']); // dopo l'anteprima: il 2025 viene dopo il primo movimento
        $this->actingAs($this->tesoriere)->post($url, ['saldo_cassa' => '1', 'saldo_depositi' => '1'])->assertSessionHasErrors('esercizio');
        $this->assertSame(0, EsercizioStorico::count());
    }

    public function test_saldi_e_figurativi_scritti_male_si_rifiutano_alla_conferma(): void
    {
        $url = $this->anteprimaUrl($this->carica());

        $this->actingAs($this->tesoriere)->post($url, ['saldo_cassa' => 'tanti', 'saldo_depositi' => '530,00'])->assertSessionHasErrors('saldo_cassa');
        $this->actingAs($this->tesoriere)->post($url, ['saldo_cassa' => '1', 'saldo_depositi' => '1', 'figurativi' => ['costi' => ['A' => '-5']]])->assertSessionHasErrors('figurativi.costi.A');
        $this->actingAs($this->tesoriere)->post($url, ['saldo_cassa' => '1', 'saldo_depositi' => '1', 'abbinamenti' => ['6' => 'uscite:Z9']])->assertSessionHasErrors('abbinamenti');
        $this->actingAs($this->tesoriere)->post($url, ['saldo_cassa' => '1', 'saldo_depositi' => '1', 'abbinamenti' => ['99' => 'uscite:E2']])->assertSessionHasErrors('abbinamenti');
        $this->assertSame(0, EsercizioStorico::count());
    }

    /** Le schede Cassa e Banca del foglio 2025: gli esempi di classificazione, con causali della tabella di conversione e un nome e un'email che non devono entrare. */
    private function schede(): array
    {
        return [
            'Cassa' => [['Data', 'Descrizione', 'Nuova causale', 'Entrate', 'Uscite', 'Saldo'], [null, 'Saldo iniziale', null, null, null, 100],
                ['2025-03-01', 'Bolletta luce di marzo', '-> Assicurazioni', null, 80.5, 19.5], ['2025-04-02', 'Rimborso a Giulia Bianchi', 'Causale che non esiste', null, 10, 9.5],
                ['2025-04-03', 'Polizza volontari (rossi.mario@example.org)', '-> Assicurazioni', null, 30, 0]], // una causale valida: l'email non deve entrare, e la riga non si scarta prima
            'Banca' => [['Data', 'Causale', 'Descrizione', 'Nuova causale', 'Importo'], ['2025-05-06', 'BONIFICO ENTRATA (bianchi.g@example.org)', 'Quota associativa 2025', 'Tesseramento ord', 20]],
        ];
    }

    public function test_dalle_schede_dei_conti_vengono_gli_esempi_di_classificazione_e_non_i_movimenti(): void
    {
        $url = $this->anteprimaUrl($this->carica($this->foglio('xlsx', $this->schede())));
        $this->actingAs($this->tesoriere)->get($url)->assertSeeText('si ricavano 3 esempi di classificazione')->assertDontSeeText('senza nomi')->assertSeeText('i nomi di persona li toglierà il mascheratore')->assertSeeText('1 riga scartata: con una causale che la tabella di conversione non conosce');

        $this->actingAs($this->tesoriere)->post($url, ['saldo_cassa' => '120,00', 'saldo_depositi' => '530,00'])->assertSessionHas('ok');

        $esempi = EsempioClassificazione::orderBy('data')->get();
        $this->assertSame(['Bolletta luce di marzo', 'Polizza volontari', 'Quota associativa 2025'], $esempi->pluck('descrizione')->all()); // l'email è tolta, e con lei le parentesi rimaste vuote
        $this->assertSame([null, null, 'BONIFICO ENTRATA'], $esempi->pluck('causale')->all());
        $this->assertSame(['uscita', 'uscita', 'entrata'], $esempi->pluck('verso')->all());
        $this->assertSame([8050, 3000, 2000], $esempi->pluck('importo')->all());
        $assicurazioni = Tipologia::where('nome', 'Assicurazioni')->value('id');
        $this->assertSame([$assicurazioni, $assicurazioni, Tipologia::where('nome', 'Quote associative')->value('id')], $esempi->pluck('tipologia_id')->all());
        $this->assertSame(['E', 'E', 'A'], $esempi->pluck('area')->all());
        $this->assertStringNotContainsString('Giulia', json_encode(EsempioClassificazione::all()));
        $this->assertStringNotContainsString('@', json_encode(EsempioClassificazione::all()));
        $this->assertSame(0, Movimento::count()); // nessun movimento: nessun rendiconto
        $this->assertSame(3, RegistroModifica::where('oggetto_tipo', 'esercizio_storico')->firstOrFail()->dopo['esempi']);
    }

    public function test_gli_esempi_alimentano_lo_storico_delle_proposte_su_un_movimento_del_2026(): void
    {
        $url = $this->anteprimaUrl($this->carica($this->foglio('xlsx', $this->schede())));
        $this->actingAs($this->tesoriere)->post($url, ['saldo_cassa' => '120,00', 'saldo_depositi' => '530,00']);
        $cassa = Conto::factory()->create(['aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
        $movimento = Movimento::factory()->create(['conto_id' => $cassa->id, 'verso' => 'uscita', 'importo' => 9000, 'data' => '2026-03-02', 'descrizione' => 'Bolletta luce di marzo', 'causale' => null, 'stato' => 'da_classificare']);

        $precedente = Storico::migliore(Storico::archivio(), $movimento);

        $this->assertNotNull($precedente?->esempio);
        $this->assertSame(Tipologia::where('nome', 'Assicurazioni')->value('id'), $precedente->riga['tipologia_id']);
    }

    public function test_la_tabella_di_conversione_e_quella_del_repo(): void
    {
        $file = fopen(base_path('docs/import-foglio/conversione-causali.csv'), 'r');
        $intestazioni = fgetcsv($file, escape: '');
        $csv = [];
        while (($riga = fgetcsv($file, escape: '')) !== false) {
            $csv[] = array_combine($intestazioni, $riga);
        }
        fclose($file);

        $this->assertSame(count($csv), ConversioneCausale::count());
        foreach ($csv as $r) {
            $c = ConversioneCausale::where('chiave', EsempiDalFoglio::chiave($r['causale']))->firstOrFail();
            $this->assertSame(
                [$r['verso'], $r['tipologia'] ?: null, $r['area'] ?: null, $r['attivita'] === 'i' ? 'Lettera i (art. 5 CTS)' : ($r['attivita'] ?: null), $r['iniziativa'] ?: null, $r['tag'] ?: null],
                [$c->verso, Tipologia::find($c->tipologia_id)?->nome, $c->area, Attivita::find($c->attivita_id)?->nome, Iniziativa::find($c->iniziativa_id)?->nome, Tag::find($c->tag_id)?->nome],
                $r['causale'],
            );
        }
    }

    private function storico(array $importi, array $extra = []): EsercizioStorico
    {
        return EsercizioStorico::create($extra + ['esercizio' => 2025, 'importi' => $importi, 'saldo_cassa' => 0, 'saldo_depositi' => 0, 'figurativi' => ['costi' => [], 'proventi' => []], 'differenze' => []]);
    }

    public function test_il_modello_d_2026_mostra_la_colonna_2025_riga_per_riga_e_senza_storico_non_la_mostra(): void
    {
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d')->assertDontSeeText('Esercizio 2025 (€)')->assertSeeText('Esercizio storico 2025 non caricato');

        $this->storico(['entrate:A1' => 123456, 'uscite:E2' => 4000, 'uscite:Imposte' => 700], ['saldo_cassa' => 80000, 'saldo_depositi' => 250000, 'figurativi' => ['costi' => ['A' => 9900], 'proventi' => []]]);
        $pagina = $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d')->assertOk();

        $pagina->assertSeeText('Esercizio 2025 (€)')
            ->assertSeeText('1.234,56')->assertSeeText('Cassa e banca')->assertSeeText('800,00')->assertSeeText('2.500,00')->assertSeeText('3.300,00')
            ->assertSeeText('99,00') // il costo figurativo del 2025
            ->assertSeeText('1.194,56'); // avanzo complessivo 2025: 1.234,56 − 40,00 − 7,00
    }

    public function test_il_confronto_del_2026_usa_lo_storico_per_la_colonna_2025_e_dichiara_non_disponibile_lo_stesso_periodo(): void
    {
        $this->storico(['entrate:A1' => 123456, 'uscite:E2' => 4000]);

        $colonne = Verifiche::pagina(2026)['confronto'];

        $this->assertSame(['2024', '2025', '2025, stesso periodo (dal 1/1 al 20/12): non disponibile, esercizio storico', '2026 (in corso)'], array_column($colonne, 'etichetta'));
        $this->assertSame([true, false, true, true], array_column($colonne, 'vuota')); // il 2024 e il 2026 senza movimenti
        $this->assertSame([123456, 4000], [$colonne[1]['entrate'], $colonne[1]['uscite']]);
        $this->assertTrue($colonne[1]['senzaEsiti']); // il foglio non ha le imputazioni per attività: niente test
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')->assertOk()->assertSeeText('non disponibile, esercizio storico')->assertSeeText('1.234,56 €');
    }

    public function test_la_pagina_dell_esercizio_storico_mostra_i_suoi_importi_e_non_il_quadro_dei_movimenti(): void
    {
        $this->storico(['entrate:A1' => 123456]);

        $this->actingAs($this->tesoriere)->get('/esercizi/2025/modello-d')->assertOk()->assertSeeText('Esercizio storico')->assertSeeText('1.234,56')
            ->assertDontSeeText('Impedimenti')->assertDontSeeText('Non quadra');
    }

    public function test_le_soglie_del_2026_leggono_le_entrate_dell_esercizio_storico_2025(): void
    {
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')->assertSeeText('L’esercizio 2025 non ha movimenti confermati nell’app');

        $this->storico(['entrate:A1' => 500000]); // 5.000,00 € di entrate

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/test')
            ->assertSeeText('L’esercizio 2025 è sotto i 60.000 euro (entrate 5.000,00 €): potete redigere il Modello E')
            ->assertDontSeeText('non ha movimenti confermati nell’app');
    }

    public function test_dopo_il_blocco_lo_modifica_solo_l_amministratore_con_motivazione_e_resta_nel_registro(): void
    {
        $storico = $this->storicoCaricato();
        $amministratore = $this->utenteCon('Amministratore');
        $campi = ['importi' => ['entrate:A1' => '1.100,00', 'uscite:E2' => '312,50', 'uscite:I1' => '50,00'], 'saldo_cassa' => '120,00', 'saldo_depositi' => '600,00', 'figurativi' => ['costi' => ['A' => '0', 'B' => '0'], 'proventi' => ['A' => '0', 'B' => '0']]];

        $this->actingAs($this->tesoriere)->get('/esercizi/2025/storico/modifica')->assertForbidden();
        $this->actingAs($this->tesoriere)->put('/esercizi/2025/storico/modifica', $campi + ['motivazione' => 'Ci provo'])->assertForbidden();
        $this->actingAs($amministratore)->get('/esercizi/2025/storico/modifica')->assertOk()->assertSee('value="1.000,00"', false);
        // Senza motivazione non si modifica.
        $this->actingAs($amministratore)->put('/esercizi/2025/storico/modifica', $campi)->assertSessionHasErrors('motivazione');
        $this->assertSame(100000, $storico->fresh()->importi['entrate:A1']);

        $this->actingAs($amministratore)->put('/esercizi/2025/storico/modifica', $campi + ['motivazione' => 'Quote corrette dal verbale'])->assertRedirect('/esercizi/2025/storico');

        $this->assertSame(110000, $storico->fresh()->importi['entrate:A1']);
        $this->assertSame(60000, $storico->fresh()->saldo_depositi);
        $r = RegistroModifica::where('oggetto_tipo', 'esercizio_storico')->where('azione', 'modificato')->firstOrFail();
        $this->assertSame([$amministratore->id, 'Quote corrette dal verbale'], [$r->user_id, $r->motivazione]);
        $this->assertSame([100000, 110000], [$r->prima['entrate:A1'], $r->dopo['entrate:A1']]);
        $this->actingAs($amministratore)->get('/registro')->assertOk()->assertSeeText('Quote corrette dal verbale');
    }
}
