<?php

namespace Tests\Feature;

use App\Models\Conto;
use App\Models\ImportConflitto;
use App\Models\ImportEstratto;
use App\Models\Movimento;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\FogliInventati;
use Tests\TestCase;

/** L'import ripetibile della cassa dal foglio (#64, #19): fogli inventati, caricati come fa il Tesoriere. */
class ImportFoglioCassaTest extends TestCase
{
    use FogliInventati, RefreshDatabase;

    private Conto $cassa;

    private User $tesoriere;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-06-02 10:00');
        $this->cassa = Conto::factory()->create(['tipo' => 'cassa', 'nome' => 'Cassa contanti', 'formato_import' => 'foglio_cassa', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 50000]);
        $this->tesoriere = $this->utenteCon('Tesoriere');
    }

    /** Le righe di un foglio di prova: [data, nuova causale, descrizione, riferimento, entrate, uscite, saldo]. Il saldo iniziale è 500,00 €. */
    private static function righe(): array
    {
        return [
            [new DateTimeImmutable('2026-01-10'), '-> Utenze', 'Bolletta luce', 'F1', null, 80.5, 419.5],
            ['15/01/2026', 'Tesseramento ord', 'Quote tessere', null, 120, null, 539.5],
            [new DateTimeImmutable('2026-02-03'), '→ Prelievo', 'Prelievo dal c/c', null, 200, null, 739.5],
            [new DateTimeImmutable('2026-02-20'), 'Causale mai vista', 'Cosa strana', null, null, 10, 729.5],
        ];
    }

    private static function foglio(?array $righe = null, string $formato = 'xlsx'): string
    {
        $righe ??= self::righe();

        return self::$formato(['Mappatura' => [['x']], 'Cassa' => [
            ['Cassa 2026'], ['Data', 'Nuova causale', 'Descrizione', 'Rif. documento', 'Entrate', 'Uscite', 'Saldo'],
            [null, null, 'Saldo iniziale', null, null, null, 500], ...$righe, ['Totale', null, null, null, 320, 90.5],
        ]]);
    }

    private function carica(string $contenuto, ?User $utente = null, string $nome = 'cassa.xlsx')
    {
        return $this->actingAs($utente ?? $this->tesoriere)->post("/conti/{$this->cassa->id}/import", [
            'estratto' => UploadedFile::fake()->createWithContent($nome, $contenuto),
        ]);
    }

    private function importa(string $contenuto): ImportEstratto
    {
        $this->carica($contenuto)->assertSessionHasNoErrors();
        $import = ImportEstratto::latest('id')->firstOrFail();
        $this->post("/import/{$import->id}/conferma")->assertSessionHasNoErrors();

        return $import->refresh();
    }

    public function test_il_foglio_si_carica_e_l_anteprima_non_salva_nulla(): void
    {
        $this->carica(self::foglio())->assertRedirect();
        $import = ImportEstratto::firstOrFail();

        $this->get("/import/{$import->id}")->assertOk()
            ->assertSeeInOrder(['Periodo', '10/01/2026', '20/02/2026'])
            ->assertSeeInOrder(['Nuovi', '4'])
            ->assertSeeInOrder(['Già presenti', '0']);
        $this->assertSame(0, Movimento::count());
    }

    public function test_la_conferma_importa_le_righe_da_classificare_e_il_foglio_non_raddoppia(): void
    {
        $this->importa(self::foglio());

        $this->assertSame(4, Movimento::count());
        $utenze = Movimento::where('data', '2026-01-10')->firstOrFail();
        $this->assertSame([8050, 'uscita', 'Bolletta luce', 'Utenze'], [$utenze->importo, $utenze->verso, $utenze->descrizione, $utenze->causale]);
        $this->assertFalse($utenze->manuale);
        $this->assertSame(50000 - 8050 + 12000 + 20000 - 1000, $this->cassa->saldoAl('2026-12-31'));

        $this->carica(self::foglio())->assertSessionHasNoErrors();
        $this->get('/import/'.ImportEstratto::latest('id')->value('id'))->assertSeeInOrder(['Nuovi', '0'])->assertSeeInOrder(['Già presenti', '4']);
    }

    public function test_un_foglio_con_una_riga_in_piu_porta_solo_quella(): void
    {
        $this->importa(self::foglio());

        $this->carica(self::foglio([...self::righe(), [new DateTimeImmutable('2026-03-05'), 'Utenze', 'Altra bolletta', null, null, 20, 709.5]]));
        $this->get('/import/'.ImportEstratto::latest('id')->value('id'))->assertSeeInOrder(['Nuovi', '1'])->assertSeeInOrder(['Già presenti', '4']);
    }

    public function test_una_riga_corretta_nel_foglio_e_un_conflitto_e_non_si_sovrascrive(): void
    {
        $this->importa(self::foglio());
        $righe = self::righe();
        $righe[0][2] = 'Bolletta luce di gennaio'; // stessa chiave (data, importo, riferimento), altra descrizione

        $this->carica(self::foglio($righe))->assertSessionHasNoErrors();
        $import = ImportEstratto::latest('id')->firstOrFail();
        $this->get("/import/{$import->id}")->assertSeeInOrder(['Nuovi', '0'])->assertSeeInOrder(['In conflitto', '1']);
        $this->post("/import/{$import->id}/conferma")->assertSessionHasNoErrors();

        $this->assertSame(1, ImportConflitto::count());
        $this->assertSame('Bolletta luce', Movimento::where('data', '2026-01-10')->value('descrizione'));
        $this->get("/import/{$import->id}")->assertSee('Bolletta luce di gennaio');
    }

    public function test_una_riga_sparita_dal_foglio_si_segnala_con_un_dubbio_e_non_si_cancella(): void
    {
        $this->importa(self::foglio());
        $righe = self::righe();
        unset($righe[1]); // la riga delle tessere non c'è più, e i saldi del foglio ripartono da lì
        $righe = array_values($righe);
        $righe[1][6] = 619.5;
        $righe[2][6] = 609.5;
        $this->carica(self::foglio($righe))->assertSessionHasNoErrors();
        $import = ImportEstratto::latest('id')->firstOrFail();

        $this->get("/import/{$import->id}")->assertSee('Righe non più nel foglio')->assertSee('Quote tessere');
        $this->post("/import/{$import->id}/conferma")->assertSessionHasNoErrors();

        $this->assertSame(4, Movimento::count());
        $tessere = Movimento::where('descrizione', 'Quote tessere')->firstOrFail();
        $this->assertNull($tessere->eliminato_il);
        $this->assertTrue($tessere->inDubbio());
        $this->get("/import/{$import->id}")->assertSee('Quote tessere');
    }

    public function test_un_saldo_finale_che_non_torna_blocca_la_conferma_e_non_entra_nulla(): void
    {
        $righe = self::righe();
        $righe[3][6] = 700; // il foglio dice 700 invece di 729,50

        $this->carica(self::foglio($righe))->assertSessionHasNoErrors();
        $import = ImportEstratto::firstOrFail();
        $this->get("/import/{$import->id}")->assertSee('non torna con quello dell’app');
        $this->post("/import/{$import->id}/conferma")->assertSessionHasErrors('estratto');

        $this->assertSame('anteprima', $import->refresh()->stato);
        $this->assertSame(0, Movimento::count());
    }

    public function test_un_mese_che_non_torna_non_blocca_ma_diventa_un_dubbio_sul_suo_ultimo_movimento(): void
    {
        $righe = self::righe();
        $righe[1][6] = 540; // gennaio: il saldo di fine mese del foglio sbaglia di 50 centesimi, ma il saldo finale torna

        $import = $this->importa(self::foglio($righe));

        $this->assertSame(4, Movimento::count());
        $this->assertFalse(Movimento::where('data', '2026-01-10')->firstOrFail()->inDubbio());
        $ultimoDiGennaio = Movimento::where('data', '2026-01-15')->firstOrFail();
        $this->assertTrue($ultimoDiGennaio->inDubbio());
        $this->assertStringContainsString('gennaio 2026', $ultimoDiGennaio->note()->value('testo'));
        $this->get("/import/{$import->id}")->assertSee('gennaio 2026')->assertSee('Non torna');
    }

    public function test_i_mesi_che_tornano_si_mostrano_senza_dubbi(): void
    {
        $import = $this->importa(self::foglio());

        $this->get("/import/{$import->id}")->assertSee('Controlli del foglio')->assertSee('torna con quello dell’app')->assertDontSee('Non torna');
        $this->assertSame(0, Movimento::whereHas('note')->whereIn('data', ['2026-01-10', '2026-01-15'])->count());
    }

    public function test_si_accettano_xlsx_e_ods_e_si_rifiuta_un_csv(): void
    {
        $this->carica(self::foglio(null, 'ods'), nome: 'cassa.ods')->assertSessionHasNoErrors();
        $this->assertSame(1, ImportEstratto::count());

        $this->carica("data,causale\n2026-01-01,x\n", nome: 'cassa.csv')->assertSessionHasErrors('estratto');
        $this->assertSame(1, ImportEstratto::count());
    }

    public function test_senza_il_permesso_di_importare_non_si_carica(): void
    {
        $this->carica(self::foglio(), $this->utenteCon('Consigliere'))->assertForbidden();
    }
}
