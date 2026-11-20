<?php

namespace Tests\Feature;

use App\Classificazione;
use App\Giroconti;
use App\Import\Foglio;
use App\Models\Attivita;
use App\Models\Conto;
use App\Models\ConversioneCausale;
use App\Models\Giroconto;
use App\Models\ImportConflitto;
use App\Models\ImportEstratto;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\Tag;
use App\Models\Tipologia;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\FogliInventati;
use Tests\TestCase;
use ZipArchive;

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

    public function test_il_file_caricato_si_apre_da_una_cartella_sotto_storage_e_non_resta_li_nemmeno_se_il_foglio_non_e_valido(): void
    {
        $disco = Storage::disk('local');
        $disco->put('foglio-tmp/rimasto-da-un-processo-morto', 'nomi di persona');
        touch($disco->path('foglio-tmp/rimasto-da-un-processo-morto'), time() - 2 * 3600);
        $disco->put('foglio-tmp/di-un-caricamento-in-corso', 'x');

        $this->assertSame(['Cassa', 'Mappatura'], collect(array_keys(Foglio::leggi(self::foglio())))->sort()->values()->all());
        // Uno zip che non è un foglio passa il controllo del tipo e arriva al lettore, che lo rifiuta dopo aver scritto il file.
        $percorso = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($percorso, ZipArchive::OVERWRITE);
        $zip->addFromString('nota.txt', 'niente fogli qui');
        $zip->close();
        $this->carica(file_get_contents($percorso), nome: 'cassa.xlsx')->assertSessionHasErrors('estratto');
        unlink($percorso);

        $this->assertSame(['foglio-tmp/di-un-caricamento-in-corso'], $disco->files('foglio-tmp')); // i resti oltre l'ora si tolgono, il file letto non resta
    }

    public function test_nel_foglio_una_riga_in_conflitto_non_si_importa_a_parte_perche_la_chiave_sarebbe_doppia(): void
    {
        $this->importa(self::foglio());
        $righe = self::righe();
        $righe[0][2] = 'Bolletta luce di gennaio'; // stessa chiave, altra descrizione
        $this->carica(self::foglio($righe))->assertSessionHasNoErrors();
        $import = ImportEstratto::latest('id')->firstOrFail();
        $this->post("/import/{$import->id}/conferma")->assertSessionHasNoErrors();
        $conflitto = ImportConflitto::firstOrFail();

        $this->get("/import/{$import->id}")->assertSee('Tieni il movimento già presente')->assertDontSee('Importa anche questa riga');
        $this->post("/import/conflitti/{$conflitto->id}/decisione", ['decisione' => 'importa_a_parte'])->assertSessionHasErrors('estratto');
        $this->assertSame(4, Movimento::count());
        $this->assertNull($conflitto->refresh()->decisione);

        // Tenere il presente funziona, e il foglio ricaricato continua a confermarsi (il saldo finale torna).
        $this->post("/import/conflitti/{$conflitto->id}/decisione", ['decisione' => 'tieni_esistente'])->assertSessionHasNoErrors();
        $this->importa(self::foglio($righe));
        $this->assertSame(4, Movimento::count());
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
        // Il confronto mese per mese non conta la riga sparita: gennaio torna, e non nasce un secondo dubbio sullo stesso movimento.
        $this->assertSame(1, $tessere->note()->dubbioAperto()->count());
        $this->get("/import/{$import->id}")->assertSee('Quote tessere');
    }

    public function test_una_scheda_che_la_cassa_non_legge_non_rifiuta_il_foglio_con_colonne_oltre_il_tetto(): void
    {
        $largo = [['a', [null, 59], 'saldo']]; // un valore oltre la 60ª colonna
        $cassa = [['Data', 'Nuova causale', 'Descrizione', 'Entrate', 'Uscite'], ['15/01/2026', 'Tesseramento ord', 'Quote tessere', 120]];

        $this->carica(self::ods(['Mappatura' => $largo, 'Cassa' => $cassa]), nome: 'cassa.ods')->assertSessionHasNoErrors();
        $this->carica(self::ods(['Mappatura' => [['x']], 'Cassa' => $largo]), nome: 'cassa.ods')->assertSessionHasErrors();
        $this->assertStringContainsString('«Cassa»', session('errors')->first());
        $this->carica(self::ods(['Mappatura' => [['x']]]), nome: 'cassa.ods')->assertSessionHasErrors();
        $this->assertStringContainsString('non c’è la scheda «Cassa»', session('errors')->first());
    }

    public function test_la_voce_ammessa_della_tabella_si_controlla_sull_anno_dei_movimenti_della_cassa_e_non_su_quello_di_oggi(): void
    {
        $this->importa(self::foglio()); // movimenti del 2026
        $this->travelTo('2027-01-10 10:00'); // le voci del Modello D ci sono solo per il 2026

        $this->salvaRiga('Assicurazioni', ['tipologia_id' => Tipologia::where('nome', 'Utenze')->value('id'), 'area' => 'E'])->assertSessionHasNoErrors();

        $this->assertSame('Utenze', $this->riga('Assicurazioni')->tipologia->nome);
    }

    public function test_la_voce_ammessa_si_controlla_solo_sugli_anni_dei_movimenti_che_portano_quella_causale(): void
    {
        $import = $this->importa(self::foglio()); // Assicurazioni e Tesseramento ord, del 2026: le voci ci sono solo per il 2026
        Movimento::factory()->create(['conto_id' => $this->cassa->id, 'import_estratto_id' => $import->id, 'data' => '2025-12-20', 'causale' => 'Tesseramento ord', 'manuale' => false]);
        $utenze = Tipologia::where('nome', 'Utenze')->value('id');

        // Il 2025 è dei movimenti di un'altra causale: per Assicurazioni non conta.
        $this->salvaRiga('Assicurazioni', ['tipologia_id' => $utenze, 'area' => 'E'])->assertSessionHasNoErrors();
        // Per Tesseramento ord sì, e il messaggio dice l'anno.
        $this->salvaRiga('Tesseramento ord', ['tipologia_id' => Tipologia::where('nome', 'Erogazioni liberali')->value('id'), 'area' => 'A'])->assertSessionHasErrors('tipologia_id');
        $this->assertStringContainsString('2025', session('errors')->first('tipologia_id'));
    }

    public function test_senza_movimenti_con_quella_causale_la_voce_non_si_controlla_per_anno(): void
    {
        $this->travelTo('2027-01-10 10:00'); // nessun import: non c'è un anno da controllare, e quello di oggi non ha le voci

        $this->salvaRiga('Assicurazioni', ['tipologia_id' => Tipologia::where('nome', 'Utenze')->value('id'), 'area' => 'E'])->assertSessionHasNoErrors();
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

    public function test_la_data_di_passaggio_fa_rifiutare_le_righe_successive(): void
    {
        $this->travelTo('2026-02-05 10:00'); // il passaggio è nel futuro: il foglio è ancora in uso
        $this->actingAs($this->tesoriere)->post("/conti/{$this->cassa->id}/passaggio", ['passaggio_il' => '2026-02-10'])->assertSessionHasNoErrors();
        $this->assertSame('2026-02-10', $this->cassa->refresh()->passaggio_il->toDateString());

        $this->carica(self::foglio())->assertSessionHasErrors('estratto'); // c'è una riga del 20/02
        $this->assertStringContainsString('10/02/2026', session('errors')->first('estratto'));
        $this->assertSame(0, ImportEstratto::count());

        $this->carica(self::foglio(array_slice(self::righe(), 0, 3)))->assertSessionHasNoErrors(); // fino al 3/02 va bene
        $this->assertSame(1, ImportEstratto::count());
    }

    public function test_la_conferma_rifa_il_controllo_del_passaggio_fissato_dopo_l_anteprima(): void
    {
        $this->travelTo('2026-02-05 10:00');
        $this->carica(self::foglio())->assertSessionHasNoErrors();
        $import = ImportEstratto::firstOrFail();

        $this->post("/conti/{$this->cassa->id}/passaggio", ['passaggio_il' => '2026-02-10'])->assertSessionHasNoErrors();
        $this->post("/import/{$import->id}/conferma")->assertSessionHasErrors('estratto');

        $this->assertSame(0, Movimento::count());
    }

    public function test_passata_la_data_di_passaggio_il_foglio_e_chiuso_e_non_si_riapre(): void
    {
        $this->actingAs($this->tesoriere)->post("/conti/{$this->cassa->id}/passaggio", ['passaggio_il' => '2026-05-31'])->assertSessionHasNoErrors(); // oggi è il 2/06

        $this->carica(self::foglio(array_slice(self::righe(), 0, 2)))->assertSessionHasErrors('estratto');
        $this->assertStringContainsString('data di passaggio è passata', session('errors')->first('estratto'));
        $this->get("/conti/{$this->cassa->id}/import")->assertOk()->assertSee('il foglio è chiuso')->assertDontSee('name="estratto"', false);
        $this->post("/conti/{$this->cassa->id}/passaggio", ['passaggio_il' => '2026-12-31'])->assertSessionHasErrors('passaggio_il');
        $this->assertSame('2026-05-31', $this->cassa->refresh()->passaggio_il->toDateString());
    }

    public function test_il_passaggio_non_puo_precedere_i_movimenti_gia_importati_e_lo_fissa_solo_chi_importa(): void
    {
        $this->importa(self::foglio());

        $this->post("/conti/{$this->cassa->id}/passaggio", ['passaggio_il' => '2026-02-10'])->assertSessionHasErrors('passaggio_il'); // c'è il 20/02
        $this->assertNull($this->cassa->refresh()->passaggio_il);

        $this->actingAs($this->utenteCon('Consigliere'))->post("/conti/{$this->cassa->id}/passaggio", ['passaggio_il' => '2026-12-31'])->assertForbidden();
    }

    private function riga(string $causale): ConversioneCausale
    {
        return ConversioneCausale::where('chiave', mb_strtolower($causale))->firstOrFail();
    }

    private function salvaRiga(string $causale, array $dati, ?User $utente = null)
    {
        return $this->actingAs($utente ?? $this->tesoriere)->put('/conversione-causali/'.$this->riga($causale)->id, $dati);
    }

    public function test_la_pagina_della_tabella_si_vede_ma_la_modifica_solo_chi_gestisce_i_dati_di_base(): void
    {
        $this->actingAs($this->tesoriere)->get('/conversione-causali')->assertOk()->assertSee('Assicurazioni')->assertSee('Salva e riapplica');

        $consigliere = $this->utenteCon('Consigliere');
        $this->actingAs($consigliere)->get('/conversione-causali')->assertOk()->assertSee('Assicurazioni')->assertDontSee('Salva e riapplica');
        $this->salvaRiga('Assicurazioni', ['tipologia_id' => Tipologia::where('nome', 'Utenze')->value('id'), 'area' => 'E'], $consigliere)->assertForbidden();
    }

    public function test_una_riga_cambiata_si_riapplica_ai_movimenti_da_classificare_e_proposti_ma_non_alle_scelte_a_mano(): void
    {
        $this->importa(self::foglio([
            [new DateTimeImmutable('2026-01-10'), 'Assicurazioni', 'Polizza A', null, null, 10, 490],
            [new DateTimeImmutable('2026-01-11'), 'Assicurazioni', 'Polizza B', null, null, 10, 480],
            [new DateTimeImmutable('2026-01-12'), 'Causale mai vista', 'Cosa strana', null, null, 10, 470],
        ]));
        $utenze = Tipologia::where('nome', 'Utenze')->value('id');
        $proposto = Movimento::where('descrizione', 'Polizza A')->firstOrFail();
        $dellaPersona = Movimento::where('descrizione', 'Polizza B')->firstOrFail();
        Classificazione::salva($this->tesoriere, $dellaPersona, null, [['tipologia_id' => Tipologia::where('nome', 'Servizi vari')->value('id'), 'area' => 'E']]);

        $this->salvaRiga('Assicurazioni', ['tipologia_id' => $utenze, 'area' => 'E'])->assertSessionHasNoErrors()->assertRedirect('/conversione-causali');

        $this->assertSame('Utenze', $proposto->refresh()->imputazioni()->with('tipologia')->first()->tipologia->nome);
        $this->assertSame('proposto', $proposto->stato);
        $this->assertSame('Servizi vari', $dellaPersona->refresh()->imputazioni()->with('tipologia')->first()->tipologia->nome); // una scelta a mano non si tocca

        // La causale mai vista si aggiunge: il movimento che la aspettava riceve la proposta e il suo dubbio della tabella si chiude.
        $strana = Movimento::where('descrizione', 'Cosa strana')->firstOrFail();
        $this->assertTrue($strana->inDubbio());
        $this->post('/conversione-causali', ['causale' => '-> Causale mai vista', 'verso' => 'uscita', 'tipologia_id' => Tipologia::where('nome', 'Materiali e beni di consumo')->value('id'), 'area' => 'E'])
            ->assertSessionHasNoErrors();
        $strana->refresh();
        $this->assertSame('proposto', $strana->stato);
        $this->assertFalse($strana->inDubbio());
        $this->assertNotNull($strana->conversione_causale_id);
    }

    public function test_una_riga_cambiata_non_riapplica_ai_movimenti_di_un_esercizio_depositato(): void
    {
        $this->importa(self::foglio([[new DateTimeImmutable('2026-01-10'), 'Assicurazioni', 'Polizza A', null, null, 10, 490]]));
        $movimento = Movimento::where('descrizione', 'Polizza A')->firstOrFail();
        $prima = $movimento->imputazioni()->count();
        \App\Models\DatiEsercizio::create(['esercizio' => 2026, 'criterio_riparto' => 'ricavi', 'figurativi_dichiarati' => false, 'stato' => 'depositato']); // #60

        $this->salvaRiga('Assicurazioni', ['tipologia_id' => Tipologia::where('nome', 'Utenze')->value('id'), 'area' => 'E'])->assertSessionHasNoErrors();

        $this->assertSame($prima, $movimento->refresh()->imputazioni()->count());
        $this->assertSame('da_classificare', $movimento->stato);
    }

    public function test_i_confermati_restano_come_sono_e_si_elencano_da_correggere(): void
    {
        $import = $this->importa(self::foglio());
        $confermato = Movimento::where('data', '2026-01-10')->firstOrFail();
        Classificazione::conferma($this->tesoriere, $confermato, Classificazione::versione($confermato));

        $this->salvaRiga('Assicurazioni', ['tipologia_id' => Tipologia::where('nome', 'Utenze')->value('id'), 'area' => 'E'])->assertSessionHasNoErrors();

        $this->assertSame('Assicurazioni', $confermato->refresh()->imputazioni()->with('tipologia')->first()->tipologia->nome);
        $this->assertSame('confermato', $confermato->stato);
        $this->get('/conversione-causali')->assertSee('Confermati da correggere')->assertSee('Bolletta luce')->assertSee("/import/{$import->id}/revisione", false)
            ->assertSee('Un movimento già confermato è da correggere');
    }

    public function test_una_riga_sbagliata_si_rifiuta_con_gli_stessi_controlli_delle_regole(): void
    {
        $this->salvaRiga('Assicurazioni', ['tipologia_id' => Tipologia::where('nome', 'Quote associative')->value('id'), 'area' => 'A'])->assertSessionHasErrors('tipologia_id'); // è un'entrata
        $this->salvaRiga('Assicurazioni', ['tipologia_id' => Tipologia::where('nome', 'Utenze')->value('id')])->assertSessionHasErrors('area'); // senza area né iniziativa
        $this->salvaRiga('Prelievo', ['tipologia_id' => Tipologia::where('nome', 'Utenze')->value('id'), 'area' => 'E'])->assertSessionHasErrors('tabella'); // un giroconto non si classifica
        $this->post('/conversione-causali', ['causale' => 'assicurazioni', 'verso' => 'uscita'])->assertSessionHasErrors('causale'); // c'è già

        $this->assertSame('Assicurazioni', $this->riga('Assicurazioni')->tipologia->nome);
    }

    public function test_una_riga_senza_tipologia_riporta_da_classificare_con_un_dubbio_i_movimenti_proposti(): void
    {
        $this->importa(self::foglio());

        $this->salvaRiga('Assicurazioni', ['tipologia_id' => null, 'area' => null])->assertSessionHasNoErrors();

        $movimento = Movimento::where('data', '2026-01-10')->firstOrFail();
        $this->assertSame(['da_classificare', 0, true, null], [$movimento->stato, $movimento->imputazioni()->count(), $movimento->inDubbio(), $movimento->conversione_causale_id]);
    }

    public function test_passata_la_data_di_passaggio_la_tabella_e_chiusa(): void
    {
        $this->cassa->update(['passaggio_il' => '2026-05-31']); // oggi è il 2/06

        $this->actingAs($this->tesoriere)->get('/conversione-causali')->assertOk()->assertSee('chiusa insieme all’import')->assertDontSee('Salva e riapplica');
        $this->salvaRiga('Assicurazioni', ['tipologia_id' => Tipologia::where('nome', 'Utenze')->value('id'), 'area' => 'E'])->assertSessionHasErrors('tabella');
        $this->assertSame('Assicurazioni', $this->riga('Assicurazioni')->tipologia->nome);
    }

    public function test_una_tipologia_usata_dalla_tabella_non_si_cancella_si_archivia(): void
    {
        $this->assertTrue(Tipologia::where('nome', 'Assicurazioni')->firstOrFail()->usata());
        $this->assertTrue(Iniziativa::where('nome', 'Carnevale')->firstOrFail()->usata());
        $this->assertTrue(Tag::where('nome', 'Microprogetto')->firstOrFail()->usata());
        $this->assertTrue(Attivita::where('nome', 'Sponsorizzazioni e pubblicità')->firstOrFail()->usata());
    }

    public function test_riclassificare_a_mano_un_confermato_della_tabella_lo_toglie_dai_confermati_da_correggere(): void
    {
        $this->importa(self::foglio());
        $confermato = Movimento::where('data', '2026-01-10')->firstOrFail();
        Classificazione::conferma($this->tesoriere, $confermato, Classificazione::versione($confermato));
        $this->assertNotNull($confermato->refresh()->conversione_causale_id);

        Classificazione::salva($this->tesoriere, $confermato, null, [['tipologia_id' => Tipologia::where('nome', 'Utenze')->value('id'), 'area' => 'E']]);

        $this->assertNull($confermato->refresh()->conversione_causale_id);
        $this->assertSame('confermato', $confermato->stato);
        $this->get('/conversione-causali')->assertDontSee('Bolletta luce');
    }

    public function test_la_conferma_rifa_il_controllo_del_foglio_chiuso_dopo_l_anteprima(): void
    {
        $this->carica(self::foglio())->assertSessionHasNoErrors();
        $import = ImportEstratto::firstOrFail();

        $this->post("/conti/{$this->cassa->id}/passaggio", ['passaggio_il' => '2026-05-31'])->assertSessionHasNoErrors(); // oggi è il 2/06: il foglio si chiude
        $this->post("/import/{$import->id}/conferma")->assertSessionHasErrors('estratto');

        $this->assertSame(0, Movimento::count());
        $this->assertSame('anteprima', $import->refresh()->stato);
    }

    public function test_un_foglio_con_le_ultime_righe_senza_saldo_o_fuori_ordine_si_conferma_se_il_saldo_torna(): void
    {
        $righe = self::righe();
        $righe[2][6] = null; // senza saldo: l'ultimo scritto è 539,50 e dopo ci sono +200 e -10
        $righe[3][6] = null;
        $this->importa(self::foglio($righe));

        $righe = [...self::righe(), [new DateTimeImmutable('2026-01-12'), 'Tesseramento ord', 'Scritta in fondo', null, 10, null, 739.5]]; // fuori ordine di data: l'ultima riga ha il saldo 739,50
        $this->importa(self::foglio($righe));
        $this->assertSame(5, Movimento::count());
        // Il saldo di gennaio del foglio (739,50) conta anche febbraio: con le righe fuori ordine non si confronta, e non nasce un dubbio falso.
        $this->assertSame(0, Movimento::whereHas('note', fn ($q) => $q->where('testo', 'like', 'Controllo del foglio%'))->count());
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
