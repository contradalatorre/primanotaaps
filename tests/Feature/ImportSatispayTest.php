<?php

namespace Tests\Feature;

use App\Giroconti;
use App\Models\Conto;
use App\Models\Giroconto;
use App\Models\ImportEstratto;
use App\Models\Movimento;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Import del report transazioni Satispay (#49). I file sono inventati; le colonne vanno ancora confermate su un export vero. */
class ImportSatispayTest extends TestCase
{
    use RefreshDatabase;

    private Conto $satispay;

    private User $tesoriere;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-06-02 10:00');
        $this->satispay = Conto::factory()->create([
            'tipo' => 'satispay', 'nome' => 'Satispay', 'formato_import' => 'satispay_csv',
            'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 1000,
        ]);
        $this->tesoriere = $this->utenteCon('Tesoriere');
    }

    private function fixture(string $nome = 'transazioni-maggio-2026'): string
    {
        return file_get_contents(base_path("tests/fixtures/satispay/$nome.csv"));
    }

    private function carica(string $contenuto, ?User $utente = null)
    {
        return $this->actingAs($utente ?? $this->tesoriere)->post("/conti/{$this->satispay->id}/import", [
            'estratto' => UploadedFile::fake()->createWithContent('transazioni.csv', $contenuto),
        ]);
    }

    private function importa(string $contenuto): ImportEstratto
    {
        $this->carica($contenuto)->assertSessionHasNoErrors();
        $import = ImportEstratto::latest('id')->firstOrFail();
        $this->post("/import/{$import->id}/conferma")->assertSessionHasNoErrors();

        return $import->refresh();
    }

    public function test_l_anteprima_non_ha_saldi_e_non_salva_nulla(): void
    {
        $this->carica($this->fixture())->assertRedirect();
        $import = ImportEstratto::firstOrFail();

        $this->get("/import/{$import->id}")->assertOk()
            ->assertSeeInOrder(['Periodo', '03/05/2026', '14/05/2026'])
            ->assertSeeInOrder(['Righe', '4'])
            ->assertSeeInOrder(['Nuovi', '4'])
            ->assertDontSeeText('Saldo iniziale')
            ->assertDontSeeText('non è quello che l’app ha');
        $this->assertNull($import->saldo_iniziale);
        $this->assertNull($import->saldo_finale);
        $this->assertSame(0, Movimento::count());
    }

    public function test_la_revisione_di_un_import_senza_saldi_si_apre_senza_confronto_con_l_estratto(): void
    {
        $import = $this->importa($this->fixture());

        $this->get("/import/{$import->id}/revisione")->assertOk()->assertSeeText('Saldo al 14/05/2026')
            ->assertDontSeeText('Uguale all’estratto')->assertDontSeeText('Diverso dall’estratto');
    }

    public function test_ogni_incasso_entra_al_netto_con_la_sua_commissione(): void
    {
        $this->importa($this->fixture());

        $this->assertSame(4, Movimento::count());
        $primo = Movimento::where('data', '2026-05-03')->orderBy('id')->firstOrFail();
        $this->assertSame(1486, $primo->importo, 'Il movimento è quello che entra davvero: 15,00 − 0,14.');
        $this->assertSame(14, $primo->commissione);
        $this->assertSame('entrata', $primo->verso);
        $this->assertSame('da_classificare', $primo->stato);
        $this->assertStringContainsString('tx-demo-0001', $primo->descrizione);
        $this->assertSame(0, Movimento::where('descrizione', 'like', '%0002%')->firstOrFail()->commissione, 'Senza commissione non c’è niente da registrare.');
        $this->assertSame(1000 + 1486 + 800 + 1981 + 1238, $this->satispay->saldoAl('2026-05-31'), 'Saldo = incassi − commissioni (i versamenti sono uscite).');
    }

    public function test_le_colonne_che_non_servono_non_si_conservano(): void
    {
        $this->importa(str_replace('Banchetto di prova,,15.00,0.14,TO_BUSINESS,,', 'Banchetto di prova,CC-9,15.00,0.14,TO_BUSINESS,ORD-5551,GRP-77', $this->fixture()));

        $tutto = json_encode(Movimento::all()->toArray());
        foreach (['Banchetto di prova', 'CC-9', 'ORD-5551', 'GRP-77'] as $scartato) {
            $this->assertStringNotContainsString($scartato, $tutto);
        }
    }

    public function test_la_data_e_quella_italiana_non_quella_utc(): void
    {
        // 22:30 UTC del 31 maggio sono le 00:30 del 1 giugno in Italia (ora legale).
        $this->importa(str_replace('2026-05-14T08:00:41.789Z', '2026-05-31T22:30:00.000Z', $this->fixture()));

        $this->assertSame('2026-06-01', Movimento::where('descrizione', 'like', '%0004%')->firstOrFail()->data->toDateString());
    }

    public function test_un_tipo_di_transazione_mai_visto_e_segnalato_e_non_importato(): void
    {
        $contenuto = $this->fixture()."tx-demo-0005,2026-05-20T09:00:00.000Z,Banchetto di prova,,5.00,0.00,REFUND_TO_BUSINESS,,\n";

        $this->carica($contenuto)->assertSessionHasErrors('estratto');
        $this->assertStringContainsString('REFUND_TO_BUSINESS', session('errors')->first('estratto'));
        $this->assertStringContainsString('tx-demo-0005', session('errors')->first('estratto'));
        $this->assertSame(0, ImportEstratto::count());
        $this->assertSame(0, Movimento::count());
    }

    public function test_lo_stesso_file_non_raddoppia_gli_incassi_e_uno_sovrapposto_porta_solo_i_nuovi(): void
    {
        $this->importa($this->fixture());

        $sovrapposto = $this->fixture()."tx-demo-0006,2026-05-21T09:00:00.000Z,Banchetto di prova,,10.00,0.10,TO_BUSINESS,,\n";
        $this->carica($sovrapposto)->assertSessionHasNoErrors();
        $import = ImportEstratto::latest('id')->firstOrFail();
        $this->get("/import/{$import->id}")->assertSeeInOrder(['Nuovi', '1'])->assertSeeInOrder(['Già presenti', '4']);
        $this->post("/import/{$import->id}/conferma");

        $this->assertSame(5, Movimento::count());
    }

    public function test_un_file_che_non_e_il_report_satispay_e_rifiutato(): void
    {
        $this->carica("Data Op.;Data Val.;Causale;Descrizione;Importo;Saldo Parziale\r\n;;Saldo Iniziale;;;1.000,00\r\n")->assertSessionHasErrors('estratto');
        $this->carica("transaction_id,transaction_date,total_amount\ntx-demo-0001,2026-05-03T10:15:22.123Z,15.00\n")->assertSessionHasErrors('estratto');
        $this->carica(str_replace('15.00,0.14', '15,0x,0.14', $this->fixture()))->assertSessionHasErrors('estratto');
        $this->carica(str_replace('tx-demo-0001', str_repeat('x', 41), $this->fixture()))->assertSessionHasErrors('estratto');
        $this->carica(str_replace('15.00,0.14', '1500,14', $this->fixture()))->assertSessionHasErrors('estratto'); // centesimi o euro? non si indovina
        $this->carica(str_replace('15.00,0.14', '1500,0.14', $this->fixture()))->assertSessionHasErrors('estratto');
        $this->assertSame(0, ImportEstratto::count());
    }

    public function test_una_commissione_oltre_l_incasso_e_rifiutata(): void
    {
        $this->carica(str_replace('15.00,0.14', '0.10,0.14', $this->fixture()))->assertSessionHasErrors('estratto');
    }

    public function test_l_incasso_si_classifica_al_lordo_e_la_commissione_va_in_d1(): void
    {
        $this->importa($this->fixture());
        $primo = Movimento::where('descrizione', 'like', '%0001%')->firstOrFail();

        $this->put("/movimenti/{$primo->id}/classificazione", ['imputazioni' => [['tipologia_id' => Tipologia::where('nome', 'Erogazioni liberali')->value('id'), 'area' => 'A']]])->assertSessionHasNoErrors();

        $imputazioni = $primo->imputazioni()->orderBy('id')->get();
        $this->assertSame([1500, -14], $imputazioni->pluck('importo')->all(), 'Lordo in entrata e commissione in uscita: la somma è il netto che entra.');
        $this->assertSame(['Entrate A4', 'Uscite D1'], $imputazioni->map->etichettaVoce()->all());
        $senza = Movimento::where('descrizione', 'like', '%0002%')->firstOrFail();
        $this->put("/movimenti/{$senza->id}/classificazione", ['imputazioni' => [['tipologia_id' => Tipologia::where('nome', 'Erogazioni liberali')->value('id'), 'area' => 'A']]])->assertSessionHasNoErrors();
        $this->assertSame([800], $senza->imputazioni()->pluck('importo')->all(), 'Senza commissione non c’è la riga in D1.');
    }

    private function banca(): Conto
    {
        return Conto::factory()->create(['tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01']);
    }

    public function test_l_accredito_in_banca_con_ordinante_satispay_propone_la_meta_in_uscita_su_satispay(): void
    {
        $this->importa($this->fixture()); // sul conto Satispay: 10,00 + 55,05 = 65,05 €
        $banca = $this->banca();
        $import = ImportEstratto::create(['conto_id' => $banca->id, 'user_id' => $this->tesoriere->id, 'stato' => 'confermato', 'periodo_dal' => '2026-05-01',
            'periodo_al' => '2026-05-31', 'saldo_iniziale' => 0, 'saldo_finale' => 40000, 'righe' => 1, 'confermato_il' => now()]);
        $accredito = Movimento::factory()->create(['conto_id' => $banca->id, 'import_estratto_id' => $import->id, 'manuale' => false, 'inserito_da' => $this->tesoriere->id,
            'causale' => 'BONIFICO A VOSTRO FAVORE', 'descrizione' => 'ORD. SATISPAY S.P.A. VERSAMENTO INCASSI', 'importo' => 40000, 'verso' => 'entrata', 'data' => '2026-05-20']);

        $this->get("/import/{$import->id}/revisione?passo=giroconti")->assertOk()->assertSeeText('Versamento da Satispay')->assertSeeText('Registra e conferma');
        $this->post("/import/{$import->id}/giroconti/meta", ['movimento' => $accredito->id, 'conto_id' => $this->satispay->id])->assertSessionHasNoErrors();

        $meta = Movimento::where('conto_id', $this->satispay->id)->where('verso', 'uscita')->sole();
        $this->assertSame([40000, '2026-05-20', 'Versamento su Conto corrente'], [$meta->importo, $meta->data->toDateString(), $meta->descrizione]);
        $this->assertSame(1000 + 5505 - 40000, $this->satispay->saldoAl('2026-05-31'));
        $this->assertSame(1, Giroconto::count());
    }

    public function test_un_versamento_di_contanti_resta_alla_cassa(): void
    {
        $versamento = Movimento::factory()->create(['conto_id' => $this->banca()->id, 'manuale' => false, 'causale' => 'VERSAMENTI', 'descrizione' => 'VERSAMENTO CONTANTI ESEMPIO', 'verso' => 'entrata', 'importo' => 5000]);

        $this->assertSame('cassa', Giroconti::regolaDi($versamento->load('conto'))['con']);
    }

    private function riepilogo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('riepilogo.csv', $this->fixture('riepilogo-maggio-2026'));
    }

    public function test_il_riepilogo_mensile_si_confronta_con_il_netto_dell_app_e_non_salva_niente(): void
    {
        $this->importa($this->fixture());
        $this->actingAs($this->tesoriere);
        $prima = Movimento::count();

        $this->post("/conti/{$this->satispay->id}/riepilogo", ['riepilogo' => $this->riepilogo()])->assertSessionHasNoErrors()->assertRedirect("/conti/{$this->satispay->id}/import");
        $this->get("/conti/{$this->satispay->id}/import")->assertSeeText('05/2026')->assertSeeText('Totale netto: file 55,05 €, app 55,05 € · coincide')
            ->assertSeeText('Commissioni: file 0,45 €, app 0,45 € · coincide')->assertSeeText('Transazioni: file 4, app 4 · coincide');
        $this->assertSame($prima, Movimento::count());
    }

    public function test_un_riepilogo_che_non_torna_dice_di_quanto(): void
    {
        $this->importa(str_replace("tx-demo-0004,2026-05-14T08:00:41.789Z,Banchetto di prova,,12.50,0.12,TO_BUSINESS,,\n", '', $this->fixture()));

        $this->post("/conti/{$this->satispay->id}/riepilogo", ['riepilogo' => $this->riepilogo()]);
        $this->get("/conti/{$this->satispay->id}/import")->assertSeeText('Totale netto: file 55,05 €, app 42,67 € · non coincide (differenza -12,38 €)')
            ->assertSeeText('Transazioni: file 4, app 3 · non coincide');
    }

    public function test_un_riepilogo_sbagliato_si_rifiuta(): void
    {
        foreach (["data,totale netto\n05-2026,55.05\n", "data,numero transazioni,totale lordo,resi,totale netto,commissioni\n13-2026,4,55.50,0.00,55.05,0.45\n"] as $contenuto) {
            $this->actingAs($this->tesoriere)->post("/conti/{$this->satispay->id}/riepilogo", ['riepilogo' => UploadedFile::fake()->createWithContent('r.csv', $contenuto)])->assertSessionHasErrors('riepilogo');
        }
    }

    public function test_il_saldo_dichiarato_che_coincide_non_ha_niente_da_spiegare(): void
    {
        $this->importa($this->fixture());
        $this->post("/conti/{$this->satispay->id}/saldi-dichiarati", ['data' => '2026-05-31', 'importo' => '65,05'])->assertSessionHasNoErrors();

        $this->assertSame(6505, $this->satispay->saldiDichiarati()->sole()->importo);
        $this->get("/conti/{$this->satispay->id}/import")->assertSeeText('31/05/2026: 65,05 €')->assertSeeText('Coincide con l’app');
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'conto', 'oggetto_id' => $this->satispay->id, 'azione' => 'saldo_dichiarato']);
        $this->assertSame('2026-05-31', $this->satispay->riconciliatoFinoAl()->toDateString(), 'Senza saldi nell’estratto, il conto è riconciliato fino all’ultimo saldo dichiarato che coincide.');
    }

    public function test_una_differenza_resta_da_spiegare_finche_non_si_spiega(): void
    {
        $this->importa($this->fixture());
        $this->post("/conti/{$this->satispay->id}/saldi-dichiarati", ['data' => '2026-05-31', 'importo' => '100,00'])->assertSessionHasNoErrors();

        $this->get("/conti/{$this->satispay->id}/import")->assertSeeText('Differenza da spiegare')->assertSeeText('L’app ha 34,95 € in meno.');

        $this->post("/conti/{$this->satispay->id}/saldi-dichiarati", ['data' => '2026-05-31', 'importo' => '100,00', 'spiegazione' => 'Un rimborso fatto dall’app Satispay, non nel report.'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->satispay->saldiDichiarati()->count(), 'La stessa data si corregge, non si duplica.');
        $this->get("/conti/{$this->satispay->id}/import")->assertSeeText('Differenza spiegata')->assertSeeText('Un rimborso fatto dall’app Satispay');
    }

    public function test_il_saldo_dichiarato_con_dati_sbagliati_si_rifiuta(): void
    {
        foreach ([['data' => '2026-07-01', 'importo' => '10,00'], ['data' => '2025-12-31', 'importo' => '10,00'], ['data' => '2026-05-31', 'importo' => 'dieci'], ['data' => '2026-05-31', 'importo' => '']] as $dati) {
            $this->actingAs($this->tesoriere)->post("/conti/{$this->satispay->id}/saldi-dichiarati", $dati)->assertSessionHasErrors();
        }
        $this->assertSame(0, $this->satispay->saldiDichiarati()->count());
    }

    public function test_riepilogo_e_saldo_dichiarato_sono_di_chi_importa_e_solo_del_conto_satispay(): void
    {
        $consigliere = $this->utenteCon('Consigliere');
        $this->actingAs($consigliere)->post("/conti/{$this->satispay->id}/riepilogo", ['riepilogo' => $this->riepilogo()])->assertForbidden();
        $this->actingAs($consigliere)->post("/conti/{$this->satispay->id}/saldi-dichiarati", ['data' => '2026-05-31', 'importo' => '10,00'])->assertForbidden();
        $this->actingAs($this->tesoriere)->post("/conti/{$this->banca()->id}/saldi-dichiarati", ['data' => '2026-05-31', 'importo' => '10,00'])->assertNotFound();
    }
}
