<?php

namespace Tests\Feature;

use App\Classificazione;
use App\Models\Conto;
use App\Models\Giroconto;
use App\Models\ImportConflitto;
use App\Models\ImportEstratto;
use App\Models\Movimento;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Import dell'estratto PayPal (#48). I file sono inventati, nel formato descritto da #11 e #14: virgola, BOM, intestazioni in italiano. */
class ImportPayPalTest extends TestCase
{
    use RefreshDatabase;

    private const INTESTAZIONI = '"Data","Ora","Fuso orario","Descrizione","Valuta","Lordo","Tariffa","Netto","Saldo","Codice transazione","Indirizzo email mittente","Nome","Nome banca","Conto bancario"';

    private Conto $paypal;

    private User $tesoriere;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-07-01 10:00');
        $this->paypal = Conto::factory()->create([
            'tipo' => 'paypal', 'nome' => 'PayPal', 'formato_import' => 'paypal_csv',
            'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 5000,
        ]);
        $this->tesoriere = $this->utenteCon('Tesoriere');
    }

    private function fixture(string $nome = 'marzo-2026'): string
    {
        return file_get_contents(base_path("tests/fixtures/paypal/$nome.csv"));
    }

    /**
     * Un estratto con il BOM, a partire da righe [data, descrizione, lordo, tariffa, netto, saldo, codice, nome];
     * le colonne da scartare (email, banca, conto) ci sono sempre.
     */
    private function estratto(array $righe, string $valuta = 'EUR', string $intestazioni = self::INTESTAZIONI): string
    {
        $celle = fn (array $r) => implode(',', array_map(fn ($c) => '"'.$c.'"', [
            $r[0], '10:00:00', 'CET', $r[1], $valuta, $r[2], $r[3], $r[4], $r[5], $r[6], 'chi.finto@example.invalid', $r[7] ?? '', 'Banca di Prova', 'Conto 000111222',
        ]));

        return "\xEF\xBB\xBF".implode("\r\n", [$intestazioni, ...array_map($celle, $righe)])."\r\n";
    }

    private function donazione(string $data, string $lordo, string $tariffa, string $netto, string $saldo, string $codice = 'TEST00000000000A2', string $nome = 'Donatrice Finta'): array
    {
        return [$data, 'Pagamento donazione', $lordo, $tariffa, $netto, $saldo, $codice, $nome];
    }

    private function carica(string $contenuto, ?Conto $conto = null)
    {
        return $this->actingAs($this->tesoriere)->post('/conti/'.($conto ?? $this->paypal)->id.'/import', [
            'estratto' => UploadedFile::fake()->createWithContent('estratto.csv', $contenuto),
        ]);
    }

    private function importa(string $contenuto): ImportEstratto
    {
        $this->carica($contenuto)->assertSessionHasNoErrors();
        $import = ImportEstratto::latest('id')->firstOrFail();
        $this->post("/import/{$import->id}/conferma")->assertSessionHasNoErrors();

        return $import->refresh();
    }

    private function rifiutato(string $contenuto, string $messaggio, ?Conto $conto = null): void
    {
        $this->carica($contenuto, $conto)->assertSessionHasErrors(['estratto' => $messaggio]);
        $this->assertSame(0, ImportEstratto::count());
    }

    private function tipologia(string $nome): int
    {
        return Tipologia::where('nome', $nome)->value('id');
    }

    private function movimento(string $codice): Movimento
    {
        return Movimento::where('chiave', $codice)->firstOrFail();
    }

    public function test_il_tesoriere_carica_il_csv_e_vede_l_anteprima_con_i_saldi_ricavati_dalla_colonna_saldo(): void
    {
        $risposta = $this->carica($this->fixture());

        $import = ImportEstratto::firstOrFail();
        $risposta->assertRedirect("/import/{$import->id}");
        $this->actingAs($this->tesoriere)->get("/import/{$import->id}")->assertOk()
            ->assertSeeInOrder(['Periodo', '03/03/2026', '28/03/2026'])
            ->assertSeeInOrder(['Righe', '5'])
            ->assertSeeInOrder(['Saldo iniziale', '50,00'])
            ->assertSeeInOrder(['Saldo finale', '63,05'])
            ->assertSeeInOrder(['Saldo dell’app alla stessa data', '50,00'])
            ->assertSeeInOrder(['Nuovi', '5']);
        $this->assertSame(0, Movimento::count(), 'Finché non si conferma non entra nulla.');
    }

    public function test_si_leggono_le_forme_del_file_vero_migliaia_e_windows_1252(): void
    {
        $contenuto = $this->estratto([$this->donazione('1/4/2026', '1.234,56', '-0,56', '1.234,00', '1.284,00', 'TEST00000000000B1', 'Società Finta')]);
        $this->carica(mb_convert_encoding(substr($contenuto, 3), 'Windows-1252', 'UTF-8'))->assertSessionHasNoErrors();

        $import = ImportEstratto::firstOrFail();
        $this->assertSame([5000, 128400], [$import->saldo_iniziale, $import->saldo_finale]);
        $this->post("/import/{$import->id}/conferma")->assertSessionHasNoErrors();
        $movimento = Movimento::firstOrFail();
        $this->assertSame(['Società Finta', 123400, 56], [$movimento->descrizione, $movimento->importo, $movimento->commissione]);
    }

    public function test_la_conferma_fa_entrare_movimenti_al_netto_con_causale_controparte_e_codice_come_chiave(): void
    {
        $this->importa($this->fixture());

        $this->assertSame(5, Movimento::count());
        $donazione = $this->movimento('TEST00000000000A2');
        $this->assertSame('entrata', $donazione->verso);
        $this->assertSame(1915, $donazione->importo, 'Il movimento è al netto: entra nel conto quello che PayPal accredita.');
        $this->assertSame(85, $donazione->commissione);
        $this->assertSame('Pagamento donazione', $donazione->causale);
        $this->assertSame('Donatrice Finta', $donazione->descrizione);
        $this->assertSame('2026-03-12', $donazione->data->toDateString());
        $this->assertNull($donazione->data_valuta);
        $this->assertSame(16915, $donazione->saldo_parziale);
        $this->assertSame('da_classificare', $donazione->stato);
        $this->assertFalse($donazione->manuale);
        $uscita = $this->movimento('TEST00000000000A4');
        $this->assertSame(['uscita', 3000, 0], [$uscita->verso, $uscita->importo, $uscita->commissione]);
        // Senza Nome la descrizione è il tipo di operazione.
        $this->assertSame("Trasferimento avviato dall'utente", $this->movimento('TEST00000000000A5')->descrizione);
        $this->assertSame('2026-03-28', $this->paypal->riconciliatoFinoAl()->toDateString(), 'Il saldo dell’app torna con quello del file.');
    }

    public function test_si_tengono_solo_le_colonne_elencate_email_banca_e_conto_non_entrano_mai(): void
    {
        $this->carica($this->fixture());
        $import = ImportEstratto::firstOrFail();
        $anteprima = Storage::disk('local')->get($import->percorso);
        $this->post("/import/{$import->id}/conferma");

        $archivio = $anteprima.json_encode(DB::table('movimenti')->get()).json_encode(DB::table('registro_modifiche')->get()).json_encode(DB::table('import_estratti')->get());
        foreach (['example.invalid', 'Banca di Prova', 'Conto 000111222'] as $scartato) {
            $this->assertStringNotContainsString($scartato, $archivio);
        }
    }

    public function test_un_file_di_un_altro_conto_e_rifiutato_nei_due_sensi(): void
    {
        $banca = Conto::factory()->create(['tipo' => 'banca', 'formato_import' => 'credit_agricole_csv', 'aperto_il' => '2026-01-01']);

        $this->rifiutato($this->fixture(), 'Il file non è un estratto di Crédit Agricole (Nowbanking): nella prima riga mancano le colonne Data Op., Data Val., Causale, Descrizione, Importo, Saldo Parziale.', $banca);
        $this->rifiutato(file_get_contents(base_path('tests/fixtures/credit-agricole/aprile-2026.csv')), 'Il file non è un estratto di PayPal (CSV con le intestazioni in italiano): nella prima riga mancano le colonne Data, Descrizione, Valuta, Lordo, Tariffa, Netto, Saldo, Codice transazione, Nome.');
    }

    public function test_un_file_con_netto_o_saldo_incoerenti_e_rifiutato(): void
    {
        $this->rifiutato($this->estratto([$this->donazione('1/4/2026', '20,00', '-0,85', '19,00', '69,00')]),
            'Riga 2: il netto (19,00) non è il lordo (20,00) più la tariffa (-0,85). Il file è stato modificato.');
        $this->rifiutato($this->estratto([
            $this->donazione('1/4/2026', '20,00', '-0,85', '19,15', '69,15', 'TEST00000000000C1'),
            $this->donazione('2/4/2026', '10,00', '0,00', '10,00', '80,00', 'TEST00000000000C2'),
        ]), 'Riga 3: il saldo progressivo non torna (dopo questo movimento dovrebbe essere 79,15, nel file è 80,00). Il file è incompleto, è stato modificato o non è in ordine di data.');
    }

    public function test_le_righe_che_l_app_non_sa_ancora_leggere_si_rifiutano_con_un_messaggio(): void
    {
        $tariffa = 'Riga 2: una tariffa su un pagamento in uscita o restituita non è prevista. Scrivi al gruppo che cura l’app, con un esempio inventato della riga.';
        $this->rifiutato($this->estratto([['1/4/2026', 'Rimborso', '-20,00', '0,85', '-19,15', '30,85', 'TEST00000000000D1']]), $tariffa);
        $this->rifiutato($this->estratto([['1/4/2026', 'Pagamento', '-20,00', '-0,85', '-20,85', '29,15', 'TEST00000000000D1']]), $tariffa);
        $this->rifiutato($this->estratto([$this->donazione('1/4/2026', '20,00', '0,00', '20,00', '70,00')], 'USD'), 'Riga 2: la valuta («USD») non è l’euro: le conversioni di valuta non sono ancora previste.');
        $this->rifiutato($this->estratto([$this->donazione('1/4/2026', '20,00', '0,00', '20,00', '70,00', '')]), 'Riga 2: manca il codice transazione, o è troppo lungo.');
        $this->rifiutato($this->estratto([$this->donazione('1/4/2026', 'venti', '0,00', '20,00', '70,00')]), 'Riga 2: il lordo («venti») non è un importo in euro.');
        $this->rifiutato($this->estratto([$this->donazione('31/2/2026', '20,00', '0,00', '20,00', '70,00')]), 'Riga 2: la data («31/2/2026») non è nella forma g/m/aaaa.');
        $this->rifiutato($this->estratto([]), 'Il file non contiene movimenti.');
        $this->rifiutato($this->estratto([
            $this->donazione('1/4/2026', '20,00', '0,00', '20,00', '70,00', 'TEST00000000000D1'),
            $this->donazione('2/4/2026', '20,00', '0,00', '20,00', '90,00', 'TEST00000000000D1'),
        ]), 'Riga 3: il codice transazione «TEST00000000000D1» compare già alla riga 2.');
    }

    public function test_la_regola_propone_il_lordo_e_la_commissione_in_d1_e_si_conferma(): void
    {
        $this->actingAs($this->tesoriere)->post('/regole', [
            'nome' => 'Donazioni PayPal', 'testo' => 'Donatrice', 'tipologia_id' => $this->tipologia('Erogazioni liberali'), 'area' => 'A',
        ])->assertSessionHasNoErrors();
        $this->importa($this->fixture());

        $movimento = $this->movimento('TEST00000000000A2');
        $this->assertSame('proposto', $movimento->stato);
        $imputazioni = $movimento->imputazioni()->orderBy('id')->get();
        $this->assertSame([2000, -85], $imputazioni->pluck('importo')->all(), 'Lordo in entrata e tariffa in uscita: la somma è il netto.');
        $this->assertSame([$this->tipologia('Erogazioni liberali'), $this->tipologia('Commissioni e oneri bancari')], $imputazioni->pluck('tipologia_id')->all());
        $this->assertSame(['A', 'D'], $imputazioni->map(fn ($i) => $i->area->value)->all());
        $this->assertSame(['Entrate A4', 'Uscite D1'], $imputazioni->map->etichettaVoce()->all());

        $this->post("/movimenti/{$movimento->id}/conferma", ['versione' => Classificazione::versione($movimento)])->assertSessionHasNoErrors();
        $this->assertSame('confermato', $movimento->refresh()->stato);
    }

    public function test_a_mano_si_classifica_il_lordo_e_la_commissione_si_aggiunge_da_sola(): void
    {
        $this->importa($this->fixture());
        $movimento = $this->movimento('TEST00000000000A3');
        $quote = ['tipologia_id' => $this->tipologia('Quote associative'), 'area' => 'A'];
        $liberali = ['tipologia_id' => $this->tipologia('Erogazioni liberali'), 'area' => 'A'];

        $this->actingAs($this->tesoriere)->get("/movimenti/{$movimento->id}/classificazione")->assertOk()
            ->assertSeeText('la somma deve essere 25,00 €')->assertSeeText('Commissioni e oneri bancari');
        // Il lordo diviso in due imputazioni.
        $this->put("/movimenti/{$movimento->id}/classificazione", ['imputazioni' => [['importo' => '10,00'] + $quote, ['importo' => '15,00'] + $liberali]])->assertSessionHasNoErrors();
        $this->assertSame([1000, 1500, -110], $movimento->imputazioni()->orderBy('id')->pluck('importo')->all());

        // Una somma che non è il lordo si rifiuta (il netto non basta).
        $this->put("/movimenti/{$movimento->id}/classificazione", ['imputazioni' => [['importo' => '10,00'] + $quote, ['importo' => '13,90'] + $liberali]])->assertSessionHasErrors('imputazioni');

        // Una sola imputazione prende tutto il lordo; la pagina riaperta mostra la commissione a parte e non la rimanda.
        $this->put("/movimenti/{$movimento->id}/classificazione", ['imputazioni' => [$quote]])->assertSessionHasNoErrors();
        $this->assertSame([2500, -110], $movimento->imputazioni()->orderBy('id')->pluck('importo')->all());
        $this->get("/movimenti/{$movimento->id}/classificazione")->assertOk()->assertSee('name="imputazioni[1][importo]"', false)->assertDontSee('name="imputazioni[2][importo]"', false);
        $this->post("/movimenti/{$movimento->id}/conferma", ['versione' => Classificazione::versione($movimento->refresh())])->assertSessionHasNoErrors();
    }

    public function test_la_revisione_imposta_l_iniziativa_senza_perdere_la_commissione(): void
    {
        $this->actingAs($this->tesoriere)->post('/regole', ['nome' => 'Donazioni PayPal', 'testo' => 'Donatrice', 'tipologia_id' => $this->tipologia('Erogazioni liberali'), 'area' => 'A']);
        $import = $this->importa($this->fixture());
        $movimento = $this->movimento('TEST00000000000A2');
        $iniziativa = \App\Models\Iniziativa::where('nome', 'Carnevale')->firstOrFail();

        $this->post("/import/{$import->id}/iniziativa", ['iniziativa_id' => $iniziativa->id, 'movimenti' => [$movimento->id => Classificazione::versione($movimento)]])->assertSessionHasNoErrors();

        $imputazioni = $movimento->imputazioni()->orderBy('id')->get();
        $this->assertSame([2000, -85], $imputazioni->pluck('importo')->all());
        $this->assertSame([$iniziativa->id, null], $imputazioni->pluck('iniziativa_id')->all());
    }

    public function test_un_movimento_senza_commissione_ha_una_sola_imputazione(): void
    {
        $this->importa($this->fixture());
        $movimento = $this->movimento('TEST00000000000A4');

        $this->actingAs($this->tesoriere)->put("/movimenti/{$movimento->id}/classificazione", ['imputazioni' => [
            ['tipologia_id' => $this->tipologia('Utenze'), 'area' => 'E'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame([3000], $movimento->imputazioni()->pluck('importo')->all());
    }

    public function test_i_giroconti_con_la_banca_si_propongono_nei_due_sensi(): void
    {
        $banca = Conto::factory()->create(['tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01']);
        $dellaBanca = fn (string $verso, int $importo, string $data, string $descrizione) => Movimento::factory()->create([
            'conto_id' => $banca->id, 'verso' => $verso, 'importo' => $importo, 'data' => $data, 'descrizione' => $descrizione, 'inserito_da' => $this->tesoriere->id,
        ]);
        $versamento = $dellaBanca('uscita', 10000, '2026-03-02', 'BONIFICO A FAVORE DI PAYPAL');
        $accredito = $dellaBanca('entrata', 10000, '2026-03-30', 'ACCREDITO PAYPAL');
        $altro = $dellaBanca('entrata', 1915, '2026-03-12', 'ALTRO ACCREDITO');

        $this->carica($this->fixture())->assertSessionHasNoErrors();
        $import = ImportEstratto::firstOrFail();
        $this->get("/import/{$import->id}")->assertSeeInOrder(['Giroconti proposti', '2']);
        $this->post("/import/{$import->id}/conferma");

        $this->get("/import/{$import->id}/revisione?passo=giroconti")->assertOk()
            ->assertSee('data-passo="giroconti" data-conteggio="2"', false)
            ->assertSeeText('BONIFICO A FAVORE DI PAYPAL')->assertSeeText('ACCREDITO PAYPAL')->assertDontSeeText('ALTRO ACCREDITO');
        $this->post("/import/{$import->id}/giroconti", ['movimento_a' => $this->movimento('TEST00000000000A1')->id, 'movimento_b' => $versamento->id])->assertSessionHasNoErrors();
        $this->post("/import/{$import->id}/giroconti", ['movimento_a' => $this->movimento('TEST00000000000A5')->id, 'movimento_b' => $accredito->id])->assertSessionHasNoErrors();
        $this->assertSame(2, Giroconto::count());
        $this->assertSame('da_classificare', $altro->refresh()->stato);
    }

    public function test_lo_stesso_file_due_volte_non_duplica_e_un_codice_con_dati_diversi_resta_in_conflitto(): void
    {
        $this->importa($this->fixture());
        $this->carica($this->fixture());
        $this->get('/import/'.ImportEstratto::latest('id')->first()->id)
            ->assertSeeInOrder(['Nuovi', '0'])->assertSeeInOrder(['Già presenti', '5'])->assertSeeInOrder(['In conflitto', '0']);

        // Stesso codice, importo diverso: non è un doppione, e non si sovrascrive nulla.
        $this->importa($this->estratto([$this->donazione('12/3/2026', '30,00', '-1,00', '29,00', '79,00')]));
        $this->assertSame(1915, $this->movimento('TEST00000000000A2')->importo);
        $this->assertSame(1, ImportConflitto::count());
        $this->assertSame(5, Movimento::count());
    }

    public function test_un_conflitto_si_puo_importare_a_parte_una_volta_sola(): void
    {
        $this->importa($this->fixture());
        $diverso = $this->estratto([$this->donazione('12/3/2026', '30,00', '-1,00', '29,00', '79,00')]);
        $this->importa($diverso);
        $conflitto = ImportConflitto::firstOrFail();

        $this->post("/import/conflitti/{$conflitto->id}/decisione", ['decisione' => 'importa_a_parte'])->assertSessionHasNoErrors();

        $this->assertSame([1915, 2900], Movimento::where('chiave', 'TEST00000000000A2')->orderBy('id')->pluck('importo')->all());
        $this->carica($diverso);
        $this->get('/import/'.ImportEstratto::latest('id')->first()->id)->assertSeeInOrder(['Già presenti', '1'])->assertSeeInOrder(['In conflitto', '0']);
    }

    public function test_un_conflitto_tenuto_non_si_riapre_ma_un_importo_diverso_si(): void
    {
        $this->importa($this->fixture());
        $diverso = $this->estratto([$this->donazione('12/3/2026', '30,00', '-1,00', '29,00', '79,00')]);
        $this->importa($diverso);
        $this->post('/import/conflitti/'.ImportConflitto::firstOrFail()->id.'/decisione', ['decisione' => 'tieni_esistente']);

        $this->carica($diverso);
        $this->get('/import/'.ImportEstratto::latest('id')->first()->id)->assertSeeInOrder(['Già presenti', '1'])->assertSeeInOrder(['In conflitto', '0']);
        $this->carica($this->estratto([$this->donazione('12/3/2026', '40,00', '-1,00', '39,00', '89,00')]));
        $this->get('/import/'.ImportEstratto::latest('id')->first()->id)->assertSeeInOrder(['In conflitto', '1']);
    }
}
