<?php

namespace Tests\Feature;

use App\Classificazione;
use App\Giroconti;
use App\Models\Giroconto;
use App\Models\Conto;
use App\Models\ConversioneCausale;
use App\Models\ImportConflitto;
use App\Models\ImportEstratto;
use App\Models\Movimento;
use App\Models\Tipologia;
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
            [new DateTimeImmutable('2026-01-10'), '-> Assicurazioni', 'Bolletta luce', 'F1', null, 80.5, 419.5],
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
        $this->assertSame([8050, 'uscita', 'Bolletta luce', 'Assicurazioni'], [$utenze->importo, $utenze->verso, $utenze->descrizione, $utenze->causale]);
        $this->assertFalse($utenze->manuale);
        $this->assertSame(50000 - 8050 + 12000 + 20000 - 1000, $this->cassa->saldoAl('2026-12-31'));

        $this->carica(self::foglio())->assertSessionHasNoErrors();
        $this->get('/import/'.ImportEstratto::latest('id')->value('id'))->assertSeeInOrder(['Nuovi', '0'])->assertSeeInOrder(['Già presenti', '4']);
    }

    public function test_un_foglio_con_una_riga_in_piu_porta_solo_quella(): void
    {
        $this->importa(self::foglio());

        $this->carica(self::foglio([...self::righe(), [new DateTimeImmutable('2026-03-05'), 'Assicurazioni', 'Altra bolletta', null, null, 20, 709.5]]));
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

    public function test_la_causale_della_tabella_diventa_una_proposta_con_la_sua_fonte(): void
    {
        $this->importa(self::foglio());

        $assicurazioni = Movimento::where('data', '2026-01-10')->firstOrFail();
        $this->assertSame('proposto', $assicurazioni->stato);
        $this->assertSame(ConversioneCausale::where('chiave', 'assicurazioni')->value('id'), $assicurazioni->conversione_causale_id);
        $this->assertNull($assicurazioni->proposto_da);
        $imputazione = $assicurazioni->imputazioni()->with('tipologia')->firstOrFail();
        $this->assertSame(['Assicurazioni', 'E', 8050], [$imputazione->tipologia->nome, $imputazione->area->value, $imputazione->importo]);
        $this->assertStringContainsString('Assicurazioni', $assicurazioni->fonteProposta());
        $this->assertFalse($assicurazioni->inDubbio());

        $quote = Movimento::where('data', '2026-01-15')->firstOrFail();
        $this->assertSame(['proposto', 'Quote associative', 'A'], [$quote->stato, $quote->imputazioni()->with('tipologia')->first()->tipologia->nome, $quote->imputazioni()->first()->area->value]);
    }

    public function test_causale_assente_dalla_tabella_o_senza_tipologia_o_col_segno_sbagliato_resta_da_classificare_con_un_dubbio(): void
    {
        $righe = [
            [new DateTimeImmutable('2026-01-10'), 'Causale mai vista', 'Cosa strana', null, null, 10, 490],
            [new DateTimeImmutable('2026-01-11'), 'Si può fare NO', 'Voce senza tipologia', null, null, 10, 480],
            [new DateTimeImmutable('2026-01-12'), 'Assicurazioni', 'Storno incassato', null, 5, null, 485],
            [new DateTimeImmutable('2026-01-13'), null, 'Senza causale', null, null, 5, 480],
        ];

        $this->importa(self::foglio($righe));

        foreach (Movimento::orderBy('data')->get() as $movimento) {
            $this->assertSame('da_classificare', $movimento->stato, $movimento->descrizione);
            $this->assertSame(0, $movimento->imputazioni()->count());
            $this->assertTrue($movimento->inDubbio(), $movimento->descrizione);
        }
        $this->assertStringContainsString('non è nella tabella', Movimento::where('data', '2026-01-10')->first()->note()->value('testo'));
        $this->assertStringContainsString('non ha una tipologia', Movimento::where('data', '2026-01-11')->first()->note()->value('testo'));
        $this->assertStringContainsString('segno', Movimento::where('data', '2026-01-12')->first()->note()->value('testo'));
        $this->assertStringContainsString('non dice la causale', Movimento::where('data', '2026-01-13')->first()->note()->value('testo'));
    }

    public function test_la_proposta_della_tabella_resta_dopo_un_salvataggio_delle_regole(): void
    {
        $this->importa(self::foglio());
        $proposto = Movimento::where('data', '2026-01-10')->firstOrFail();

        $this->actingAs($this->tesoriere)->post('/regole', [
            'nome' => 'Altro', 'testo' => 'zzz', 'tipologia_id' => Tipologia::where('nome', 'Utenze')->value('id'), 'area' => 'E',
        ])->assertSessionHasNoErrors();

        $proposto->refresh();
        $this->assertSame('proposto', $proposto->stato);
        $this->assertNotNull($proposto->conversione_causale_id);
        $this->assertSame(1, $proposto->imputazioni()->count());
    }

    public function test_salvare_a_mano_una_proposta_della_tabella_ne_fa_una_scelta_della_persona(): void
    {
        $this->importa(self::foglio());
        $proposto = Movimento::where('data', '2026-01-10')->firstOrFail();
        $riga = ['tipologia_id' => Tipologia::where('nome', 'Utenze')->value('id'), 'area' => 'E'];

        Classificazione::salva($this->tesoriere, $proposto, null, [$riga]);

        $proposto->refresh();
        $this->assertNull($proposto->conversione_causale_id);
        $this->assertSame($this->tesoriere->id, $proposto->proposto_da);
    }

    public function test_un_prelievo_del_foglio_e_un_giroconto_proposto_in_dubbio_finche_non_e_abbinato_alla_banca(): void
    {
        $import = $this->importa(self::foglio());
        $prelievo = Movimento::where('data', '2026-02-03')->firstOrFail();

        $this->assertSame('da_classificare', $prelievo->stato);
        $this->assertSame(0, $prelievo->imputazioni()->count());
        $this->assertTrue($prelievo->inDubbio());
        $this->assertStringContainsString('Giroconto da abbinare', $prelievo->note()->value('testo'));
        $proposta = Giroconti::proposte()->first(fn ($p) => $p['movimento']->is($prelievo));
        $this->assertSame('attesa', $proposta['tipo']); // la metà deve arrivare dall'estratto della banca
        $this->get("/import/{$import->id}/revisione?passo=giroconti")->assertOk()->assertSee('Prelievo dal c/c');

        // Arriva la metà della banca: si abbina, il dubbio si chiude e le due metà escono dalle entrate e dalle uscite.
        $meta = $this->movimentoDellaBanca('2026-02-04', 20000, 'uscita', 'PRELIEVO CONTANTI SPORTELLO');
        $this->post("/import/{$import->id}/giroconti", ['movimento_a' => $prelievo->id, 'movimento_b' => $meta->id, 'dubbi_visti' => $prelievo->dubbiAperti->pluck('id')->all()])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Giroconto::di($prelievo->refresh()));
        $this->assertFalse($prelievo->inDubbio());
        $this->assertSame(0, Movimento::senzaGiroconto()->where('data', '2026-02-03')->count());
    }

    public function test_la_banca_non_propone_di_scrivere_la_meta_sulla_cassa_del_foglio_finche_il_foglio_e_in_uso(): void
    {
        $this->importa(self::foglio());
        $versamento = $this->movimentoDellaBanca('2026-03-02', 5000, 'entrata', 'VERSAMENTO CONTANTI');

        $proposta = Giroconti::proposte()->first(fn ($p) => $p['movimento']->is($versamento));
        $this->assertSame('meta', $proposta['tipo']);
        $this->assertTrue($proposta['conti']->isEmpty(), 'La cassa del foglio ha già la sua metà nel foglio: scriverla qui la raddoppierebbe.');

        $this->cassa->update(['passaggio_il' => '2026-05-31']); // passato il passaggio, la cassa si registra nell'app
        $this->assertSame([$this->cassa->id], Giroconti::proposte()->first(fn ($p) => $p['movimento']->is($versamento))['conti']->pluck('id')->all());
    }

    private function movimentoDellaBanca(string $data, int $importo, string $verso, string $descrizione): Movimento
    {
        $banca = Conto::firstWhere('tipo', 'banca') ?? Conto::factory()->create(['tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01']);

        return Movimento::factory()->create([
            'conto_id' => $banca->id, 'manuale' => false, 'inserito_da' => $this->tesoriere->id, 'data' => $data, 'importo' => $importo, 'verso' => $verso,
            'descrizione' => $descrizione, 'causale' => explode(' ', $descrizione)[0],
        ]);
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
