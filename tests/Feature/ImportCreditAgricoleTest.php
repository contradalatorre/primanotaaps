<?php

namespace Tests\Feature;

use App\Models\Conto;
use App\Models\ImportEstratto;
use App\Models\ImportConflitto;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Import dell'estratto Crédit Agricole (#40). I file sono inventati, nel formato descritto da #11 e #14. */
class ImportCreditAgricoleTest extends TestCase
{
    use RefreshDatabase;

    private Conto $banca;

    private User $tesoriere;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-07-01 10:00');
        $this->banca = Conto::factory()->create([
            'tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv',
            'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 120000,
        ]);
        $this->tesoriere = $this->utenteCon('Tesoriere');
    }

    private function fixture(string $nome = 'aprile-2026'): string
    {
        return file_get_contents(base_path("tests/fixtures/credit-agricole/$nome.csv"));
    }

    /** Un estratto di un mese (con le righe di saldo nel formato vero) a partire da righe «data;valuta;causale;descrizione;importo». */
    private function estratto(string $saldoIniziale, array $righe, string $saldoFinale, string $intestazione = 'Data Op.;Data Val.;Causale;Descrizione;Importo;Saldo Parziale'): string
    {
        return implode("\r\n", [$intestazione, ";;Saldo Iniziale;;;$saldoIniziale", ...$righe, ";;Saldo Contabile;;;$saldoFinale"])."\r\n";
    }

    private function carica(string $contenuto, ?Conto $conto = null, ?User $utente = null, string $nome = 'estratto.csv')
    {
        return $this->actingAs($utente ?? $this->tesoriere)->post('/conti/'.($conto ?? $this->banca)->id.'/import', [
            'estratto' => UploadedFile::fake()->createWithContent($nome, $contenuto),
        ]);
    }

    public function test_il_tesoriere_carica_il_file_e_vede_l_anteprima_prima_di_salvare(): void
    {
        $risposta = $this->carica($this->fixture());

        $import = ImportEstratto::firstOrFail();
        $risposta->assertRedirect("/import/{$import->id}");
        $this->actingAs($this->tesoriere)->get("/import/{$import->id}")->assertOk()
            ->assertSeeInOrder(['Periodo', '03/04/2026', '28/04/2026'])
            ->assertSeeInOrder(['Righe', '5'])
            ->assertSeeInOrder(['Saldo iniziale', '1.200,00'])
            ->assertSeeInOrder(['Saldo finale', '3.616,80'])
            ->assertSeeInOrder(['Nuovi', '5'])
            ->assertSeeInOrder(['Già presenti', '0'])
            ->assertSeeInOrder(['In conflitto', '0']);
        $this->assertSame(0, Movimento::count(), 'Finché non si conferma non entra nulla.');
    }

    public function test_si_leggono_le_forme_dell_estratto_vero(): void
    {
        // Senza punto delle migliaia, CRLF, BOM, righe vuote in coda e Windows-1252 per le lettere accentate.
        $contenuto = $this->estratto('1200,00', [
            '03/04/2026;03/04/2026;PAGAMENTO TRAMITE POS;CAFFÈ ESEMPIO;\'-1200,50;-0,50',
        ], '-0,50');
        $contenuto = "\xEF\xBB\xBF".mb_convert_encoding($contenuto, 'Windows-1252', 'UTF-8')."\r\n\r\n";

        $this->carica($contenuto)->assertSessionHasNoErrors();
        $import = ImportEstratto::firstOrFail();
        $this->post("/import/{$import->id}/conferma")->assertRedirect();

        $movimento = Movimento::firstOrFail();
        $this->assertSame('CAFFÈ ESEMPIO', $movimento->descrizione);
        $this->assertSame(120050, $movimento->importo);
        $this->assertSame('uscita', $movimento->verso);
    }

    public function test_un_file_che_non_e_l_estratto_del_conto_e_rifiutato(): void
    {
        $paypal = "\"Data\",\"Ora\",\"Fuso orario\",\"Descrizione\",\"Valuta\",\"Lordo\",\"Tariffa\",\"Netto\"\n\"3/4/2026\",\"10:00:00\",\"CET\",\"Pagamento\",\"EUR\",\"10,00\",\"-0,50\",\"9,50\"\n";

        $this->carica($paypal)->assertSessionHasErrors('estratto');
        $this->assertSame(0, ImportEstratto::count());
    }

    public function test_un_estratto_non_si_carica_su_un_conto_di_un_altro_formato(): void
    {
        $cassa = Conto::factory()->create(['tipo' => 'cassa']);
        $paypal = Conto::factory()->create(['tipo' => 'paypal', 'formato_import' => 'paypal_csv', 'riga_modello_d' => 'depositi']);

        foreach ([$cassa, $paypal] as $conto) {
            $this->carica($this->fixture(), $conto)->assertSessionHasErrors('estratto');
        }
        $this->assertSame(0, ImportEstratto::count());
    }

    public function test_su_un_conto_chiuso_non_si_importa(): void
    {
        $this->banca->update(['chiuso_il' => '2026-04-30']);

        $this->carica($this->fixture())->assertSessionHasErrors('estratto');
    }

    public function test_un_file_con_il_saldo_progressivo_incoerente_e_rifiutato(): void
    {
        $contenuto = str_replace('1.157,10', '1.157,11', $this->fixture());

        $this->carica($contenuto)->assertSessionHasErrors('estratto');
        $this->assertSame(0, ImportEstratto::count());
    }

    public function test_un_saldo_finale_che_non_chiude_il_conto_e_rifiutato(): void
    {
        $contenuto = str_replace('30/04/2026;;Saldo Contabile;;;3.616,80', '30/04/2026;;Saldo Contabile;;;3.616,81', $this->fixture());

        $this->carica($contenuto)->assertSessionHasErrors('estratto');
    }

    public function test_un_importo_illeggibile_o_una_data_impossibile_rifiutano_il_file(): void
    {
        $this->carica(str_replace("'-42,90", "'-42,9x", $this->fixture()))->assertSessionHasErrors('estratto');
        $this->carica(str_replace('08/04/2026;08/04/2026', '31/02/2026;08/04/2026', $this->fixture()))->assertSessionHasErrors('estratto');
        $this->assertSame(0, ImportEstratto::count());
    }

    public function test_un_file_senza_movimenti_o_senza_la_riga_del_saldo_e_rifiutato(): void
    {
        $this->carica($this->estratto('1.200,00', [], '1.200,00'))->assertSessionHasErrors('estratto');
        $senzaSaldoIniziale = preg_replace('/^01\/04\/2026;;Saldo Iniziale.*\R/m', '', $this->fixture());
        $this->carica($senzaSaldoIniziale)->assertSessionHasErrors('estratto');
    }

    public function test_i_movimenti_prima_dell_apertura_del_conto_rifiutano_il_file(): void
    {
        $this->banca->update(['aperto_il' => '2026-04-10']);

        $this->carica($this->fixture())->assertSessionHasErrors('estratto');
    }

    public function test_un_file_troppo_grande_o_non_di_testo_e_rifiutato(): void
    {
        $this->carica(str_repeat('a', 3 * 1024 * 1024))->assertSessionHasErrors('estratto');
        $this->carica("PK\x03\x04".random_bytes(2000))->assertSessionHasErrors('estratto');
        $this->assertSame(0, ImportEstratto::count());
    }

    public function test_il_file_resta_fuori_da_public_con_un_nome_che_non_dipende_da_quello_caricato(): void
    {
        $this->carica($this->fixture(), nome: '../../public/index.php.csv');

        $import = ImportEstratto::firstOrFail();
        $this->assertTrue(Storage::disk('local')->exists($import->percorso));
        $this->assertStringNotContainsString('index', $import->percorso);
        $this->assertStringNotContainsString('..', $import->percorso);
    }

    public function test_si_tengono_solo_le_colonne_elencate_e_gli_iban_si_mascherano_alle_ultime_4_cifre(): void
    {
        $contenuto = $this->estratto('1.200,00', [
            '03/04/2026;03/04/2026;GIROCONTO/BONIFICO;BONIFICO A IT60X0542811101000000123456 E IT60 X054 2811 1010 0000 0123 456 SALDO;\'-10,00;1.190,00',
        ], '1.190,00', 'Data Op.;Data Val.;Causale;Descrizione;Importo;Saldo Parziale');
        $contenuto = str_replace('Saldo Parziale', 'Saldo Parziale;Riferimento interno', $contenuto);
        $contenuto = preg_replace('/;(1\.[0-9,]+)(\r?)$/m', ';$1;segreto@esempio.test$2', $contenuto);

        $this->carica($contenuto)->assertSessionHasNoErrors();
        $conservato = fn () => collect(Storage::disk('local')->allFiles())->map(fn ($f) => Storage::disk('local')->get($f))->implode('');
        $this->assertStringNotContainsString('segreto@esempio.test', $conservato());
        $this->assertStringNotContainsString('0542811', $conservato());

        $this->post('/import/'.ImportEstratto::firstOrFail()->id.'/conferma');

        $this->assertSame('BONIFICO A '.str_repeat('*', 23).'3456 E '.str_repeat('*', 23).'3456 SALDO', Movimento::firstOrFail()->descrizione);
        $this->assertStringNotContainsString('segreto@esempio.test', json_encode(Movimento::all()->toArray()));
    }

    private function importa(string $contenuto): ImportEstratto
    {
        $this->carica($contenuto)->assertSessionHasNoErrors();
        $import = ImportEstratto::latest('id')->firstOrFail();
        $this->post("/import/{$import->id}/conferma")->assertSessionHasNoErrors();

        return $import->refresh();
    }

    private function anteprima(string $contenuto)
    {
        $this->carica($contenuto)->assertSessionHasNoErrors();

        return $this->get('/import/'.ImportEstratto::latest('id')->firstOrFail()->id);
    }

    /** Un estratto che si sovrappone ad aprile: le righe del 22 e del 28 uguali, una nuova il 5 maggio. */
    private function sovrapposto(): string
    {
        return $this->estratto('1.175,10', [
            '22/04/2026;22/04/2026;PAGAMENTO UTENZE;SDD A : FORNITORE ENERGIA ESEMPIO IT60X0542811101000000123456;\'-58,30;1.116,80',
            '28/04/2026;28/04/2026;GIROCONTO/BONIFICO;ORD: DITTA ESEMPIO SRL SPONSORIZZAZIONE FESTA;2.500,00;3.616,80',
            '05/05/2026;05/05/2026;PAGAMENTO TRAMITE POS;CARTA CA DEBIT VISA *1234 CARTOLERIA ESEMPIO;\'-100,00;3.516,80',
        ], '3.516,80');
    }

    public function test_la_conferma_fa_entrare_i_movimenti_da_classificare_non_manuali(): void
    {
        $import = $this->importa($this->fixture());

        $this->assertSame('confermato', $import->stato);
        $this->assertSame(5, Movimento::count());
        $pos = Movimento::where('descrizione', 'like', 'CARTA CA DEBIT%')->firstOrFail();
        $this->assertSame($this->banca->id, $pos->conto_id);
        $this->assertSame('2026-04-03', $pos->data->toDateString());
        $this->assertSame(4290, $pos->importo);
        $this->assertSame('uscita', $pos->verso);
        $this->assertSame('PAGAMENTO TRAMITE POS', $pos->causale);
        $this->assertSame(115710, $pos->saldo_parziale);
        $this->assertSame('da_classificare', $pos->stato);
        $this->assertFalse($pos->manuale);
        $this->assertSame($this->tesoriere->id, $pos->inserito_da);
        $this->assertSame($import->id, $pos->import_estratto_id);
        $this->assertSame('2026-04-14', Movimento::where('causale', 'COMMISSIONI/SPESE')->firstOrFail()->data_valuta->toDateString());
        $this->assertSame('entrata', Movimento::where('importo', 250000)->firstOrFail()->verso);
        // il file in attesa non serve più
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_la_conferma_finisce_nel_registro_delle_modifiche_e_non_si_ripete(): void
    {
        $import = $this->importa($this->fixture());
        $this->post("/import/{$import->id}/conferma")->assertRedirect();

        $this->assertSame(5, Movimento::count());
        $this->assertSame(5, RegistroModifica::where('oggetto_tipo', 'movimento')->where('azione', 'importato')->count());
        $riga = RegistroModifica::where('oggetto_tipo', 'import')->firstOrFail();
        $this->assertSame('confermato', $riga->azione);
        $this->assertSame(5, $riga->dopo['nuovi']);
        $this->actingAs($this->tesoriere)->get('/registro')->assertOk()->assertSeeInOrder(['ha confermato', 'l’import n. '.$import->id]);
    }

    public function test_un_periodo_sovrapposto_salta_i_movimenti_gia_presenti_e_li_conta(): void
    {
        $this->importa($this->fixture());

        $this->anteprima($this->sovrapposto())->assertOk()
            ->assertSeeInOrder(['Nuovi', '1'])->assertSeeInOrder(['Già presenti', '2'])->assertSeeInOrder(['In conflitto', '0']);
        $this->post('/import/'.ImportEstratto::latest('id')->first()->id.'/conferma');

        $this->assertSame(6, Movimento::count());
        // lo stesso file ricaricato non aggiunge nulla
        $this->anteprima($this->sovrapposto())->assertSeeInOrder(['Nuovi', '0'])->assertSeeInOrder(['Già presenti', '3']);
    }

    public function test_la_stessa_chiave_con_dati_diversi_resta_in_conflitto_senza_sovrascrivere_nulla(): void
    {
        $this->importa($this->fixture());
        $prima = Movimento::where('causale', 'COMMISSIONI/SPESE')->firstOrFail();
        // stessa data operazione, importo, descrizione e saldo parziale; cambia solo la data valuta
        $variante = str_replace('15/04/2026;14/04/2026;COMMISSIONI', '15/04/2026;15/04/2026;COMMISSIONI', $this->fixture());

        $this->anteprima($variante)->assertSeeInOrder(['Nuovi', '0'])->assertSeeInOrder(['Già presenti', '4'])->assertSeeInOrder(['In conflitto', '1']);
        $import = ImportEstratto::latest('id')->firstOrFail();
        $this->post("/import/{$import->id}/conferma");

        $this->assertSame(5, Movimento::count(), 'La riga in conflitto non entra finché nessuno decide.');
        $conflitto = ImportConflitto::firstOrFail();
        $this->assertNull($conflitto->decisione);
        $this->assertSame($prima->id, $conflitto->movimento_id);
        $this->get("/import/{$import->id}")->assertSee('Tieni il movimento già presente')->assertSee('Importa anche questa riga');

        $this->post("/import/conflitti/{$conflitto->id}/decisione", ['decisione' => 'tieni_esistente'])->assertSessionHasNoErrors();
        $this->assertSame('tieni_esistente', $conflitto->refresh()->decisione);
        $this->assertSame($this->tesoriere->id, $conflitto->deciso_da);
        $this->assertSame(5, Movimento::count());
        $this->assertSame('2026-04-14', $prima->refresh()->data_valuta->toDateString(), 'I dati originali non si modificano.');
        $this->post("/import/conflitti/{$conflitto->id}/decisione", ['decisione' => 'importa_a_parte'])->assertSessionHasErrors('estratto');
        // deciso una volta, il conflitto non si riapre se si ricarica lo stesso file
        $this->anteprima($variante)->assertSeeInOrder(['Già presenti', '5'])->assertSeeInOrder(['In conflitto', '0']);
    }

    public function test_su_un_conto_chiuso_un_conflitto_non_si_importa_a_parte(): void
    {
        $this->importa($this->fixture());
        $this->importa(str_replace('15/04/2026;14/04/2026;COMMISSIONI', '15/04/2026;15/04/2026;COMMISSIONI', $this->fixture()));
        $this->banca->update(['chiuso_il' => '2026-06-30']);

        $this->post('/import/conflitti/'.ImportConflitto::firstOrFail()->id.'/decisione', ['decisione' => 'importa_a_parte'])->assertSessionHasErrors('estratto');

        $this->assertSame(5, Movimento::count());
    }

    public function test_un_conflitto_si_puo_importare_a_parte_e_poi_il_file_risulta_presente(): void
    {
        $this->importa($this->fixture());
        $variante = str_replace('15/04/2026;14/04/2026;COMMISSIONI', '15/04/2026;15/04/2026;COMMISSIONI', $this->fixture());
        $import = $this->importa($variante);

        $this->post('/import/conflitti/'.ImportConflitto::firstOrFail()->id.'/decisione', ['decisione' => 'importa_a_parte']);

        $this->assertSame(6, Movimento::count());
        $nuovo = Movimento::where('import_estratto_id', $import->id)->firstOrFail();
        $this->assertSame('2026-04-15', $nuovo->data_valuta->toDateString());
        $this->assertSame('da_classificare', $nuovo->stato);
        $this->anteprima($variante)->assertSeeInOrder(['Nuovi', '0'])->assertSeeInOrder(['Già presenti', '5'])->assertSeeInOrder(['In conflitto', '0']);
    }

    public function test_un_buco_tra_due_estratti_segnala_il_periodo_mancante_e_il_conto_resta_non_riconciliato(): void
    {
        $this->importa($this->fixture());
        $giugno = $this->estratto('4.000,00', ['15/06/2026;15/06/2026;PAGAMENTO TRAMITE POS;CARTA CA DEBIT VISA *1234 BAR ESEMPIO;\'-50,00;3.950,00'], '3.950,00');

        $this->anteprima($giugno)->assertOk()->assertSee('non è quello che l’app ha alla stessa data')
            ->assertSee('dal 29/04/2026 al 15/06/2026');
        $this->post('/import/'.ImportEstratto::latest('id')->first()->id.'/conferma');

        // l'estratto di giugno non quadra con l'app: il conto è riconciliato solo fino ad aprile
        $this->get("/conti/{$this->banca->id}/import")->assertSee('riconciliato fino al 28/04/2026');
        $this->get('/conti')->assertSee('Riconciliato fino al 28/04/2026');

        // coperto il buco, il conto è riconciliato fino a giugno
        $maggio = $this->estratto('3.616,80', ['10/05/2026;10/05/2026;GIROCONTO/BONIFICO;ORD: MARIO ESEMPIO SALDO QUOTA;383,20;4.000,00'], '4.000,00');
        $this->anteprima($maggio)->assertDontSee('non è quello che l’app ha alla stessa data');
        $this->post('/import/'.ImportEstratto::latest('id')->first()->id.'/conferma');
        $this->get("/conti/{$this->banca->id}/import")->assertSee('riconciliato fino al 15/06/2026');
    }

    public function test_senza_nessun_estratto_confermato_il_conto_non_e_riconciliato(): void
    {
        $this->actingAs($this->tesoriere)->get("/conti/{$this->banca->id}/import")->assertSee('non è riconciliato');
        $this->get('/conti')->assertSee('Non riconciliato');
    }

    public function test_un_import_si_annulla_per_intero_e_il_file_si_puo_ricaricare(): void
    {
        $import = $this->importa($this->fixture());

        $this->post("/import/{$import->id}/annullamento")->assertSessionHasNoErrors();

        $this->assertSame('annullato', $import->refresh()->stato);
        $this->assertSame(0, Movimento::count());
        $this->assertSame(5, Movimento::onlyTrashed()->where('eliminato_da', $this->tesoriere->id)->count());
        $this->assertSame(5, RegistroModifica::where('azione', 'eliminato')->where('motivazione', 'Import annullato')->count());
        $this->assertSame('annullato', RegistroModifica::where('oggetto_tipo', 'import')->latest('id')->first()->azione);
        $this->assertNull($this->banca->riconciliatoFinoAl());
        $this->anteprima($this->fixture())->assertSeeInOrder(['Nuovi', '5'])->assertSeeInOrder(['Già presenti', '0']);
    }

    public function test_un_import_con_un_movimento_confermato_non_si_annulla(): void
    {
        $import = $this->importa($this->fixture());
        Movimento::firstOrFail()->forceFill(['stato' => 'confermato', 'confermato_da' => $this->tesoriere->id, 'confermato_il' => now()])->save();

        $this->get("/import/{$import->id}")->assertDontSee('Annulla l’import')->assertSee('non si annulla più');
        $this->post("/import/{$import->id}/annullamento")->assertSessionHasErrors('estratto');

        $this->assertSame('confermato', $import->refresh()->stato);
        $this->assertSame(5, Movimento::count());
    }

    public function test_un_import_in_anteprima_si_scarta_senza_far_entrare_nulla(): void
    {
        $this->carica($this->fixture());
        $import = ImportEstratto::firstOrFail();

        $this->post("/import/{$import->id}/scarto")->assertRedirect("/conti/{$this->banca->id}/import");

        $this->assertSame(0, ImportEstratto::count());
        $this->assertSame(0, Movimento::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_le_anteprime_abbandonate_da_piu_di_un_giorno_si_puliscono_al_caricamento_successivo(): void
    {
        $this->carica($this->fixture());
        $vecchia = ImportEstratto::firstOrFail();
        $this->travel(2)->days();

        $this->carica($this->fixture());

        $this->assertNull(ImportEstratto::find($vecchia->id));
        $this->assertFalse(Storage::disk('local')->exists($vecchia->percorso));
        $this->assertSame(1, ImportEstratto::count());
    }

    public function test_chi_non_ha_il_permesso_di_importare_non_carica_il_file(): void
    {
        foreach (['Consigliere', 'Lettore esterno'] as $ruolo) {
            $this->carica($this->fixture(), utente: $this->utenteCon($ruolo))->assertForbidden();
            $this->actingAs($this->utenteCon($ruolo))->get("/conti/{$this->banca->id}/import")->assertForbidden();
        }
        $this->assertSame(0, ImportEstratto::count());
    }

    public function test_conferma_annullamento_e_decisioni_sono_solo_di_chi_importa(): void
    {
        $import = $this->importa($this->fixture());
        $this->importa(str_replace('15/04/2026;14/04/2026;COMMISSIONI', '15/04/2026;15/04/2026;COMMISSIONI', $this->fixture()));
        $consigliere = $this->utenteCon('Consigliere');

        $this->actingAs($consigliere);
        $this->get("/import/{$import->id}")->assertForbidden();
        $this->post("/import/{$import->id}/conferma")->assertForbidden();
        $this->post("/import/{$import->id}/annullamento")->assertForbidden();
        $this->post("/import/{$import->id}/scarto")->assertForbidden();
        $this->post('/import/conflitti/'.ImportConflitto::firstOrFail()->id.'/decisione', ['decisione' => 'tieni_esistente'])->assertForbidden();
        $this->assertSame('confermato', $import->refresh()->stato);
    }

    public function test_l_elenco_dei_movimenti_dice_che_sono_importati_e_con_quale_causale(): void
    {
        $this->importa($this->fixture());

        $this->actingAs($this->tesoriere)->get("/movimenti?conto={$this->banca->id}")->assertOk()
            ->assertSee('Importato da')->assertSee('PAGAMENTO TRAMITE POS')->assertSee('Da classificare');
    }
}
