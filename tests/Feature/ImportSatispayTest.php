<?php

namespace Tests\Feature;

use App\Models\Conto;
use App\Models\ImportEstratto;
use App\Models\Movimento;
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
        $this->assertNull(Movimento::where('descrizione', 'like', '%0002%')->firstOrFail()->commissione, 'Senza commissione non c’è niente da registrare.');
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

        $this->carica($contenuto)->assertSessionHasErrors(['estratto' => fn ($messaggio) => str_contains($messaggio[0], 'REFUND_TO_BUSINESS') && str_contains($messaggio[0], 'tx-demo-0005')]);
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
        $this->assertSame(0, ImportEstratto::count());
    }

    public function test_una_commissione_oltre_l_incasso_e_rifiutata(): void
    {
        $this->carica(str_replace('15.00,0.14', '0.10,0.14', $this->fixture()))->assertSessionHasErrors('estratto');
    }
}
