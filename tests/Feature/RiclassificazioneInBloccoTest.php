<?php

namespace Tests\Feature;

use App\Classificazione;
use App\Models\Attivita;
use App\Models\Conto;
use App\Models\ConversioneCausale;
use App\Models\DatiEsercizio;
use App\Models\ImportEstratto;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Regola;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La riclassificazione in blocco (#62, #12, #15): cambio di area di un'iniziativa, di proprietà di una tipologia, di righe della tabella delle voci, con anteprima
 * dell'effetto, riautenticazione, esercizi depositati fuori e movimenti cambiati a mano elencati. Dati inventati.
 */
class RiclassificazioneInBloccoTest extends TestCase
{
    use RefreshDatabase;

    private User $tesoriere;

    private Conto $cassa;

    private Iniziativa $festa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2027-04-20 10:00');
        $this->tesoriere = $this->utenteCon('Tesoriere');
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa contanti', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
        $this->festa = Iniziativa::where('nome', 'Festa della Torre')->firstOrFail();
    }

    private function come(?User $utente = null, bool $riautenticato = true): static
    {
        $this->flushSession(); // la sessione dei test sopravvive tra una richiesta e l'altra: l'accesso recente di prima non deve valere

        return $this->actingAs($utente ?? $this->tesoriere)->withSession($riautenticato ? ['auth.password_confirmed_at' => now()->unix()] : []);
    }

    /** Un movimento a mano con una sola imputazione; senza `$campi` è della Festa della Torre in area A, lettera i (i predefiniti del 2026). */
    private function imputa(string $tipologia, int $importo = 10000, array $campi = [], string $data = '2026-05-10', string $stato = 'confermato', array $movimento = []): Imputazione
    {
        $tip = Tipologia::where('nome', $tipologia)->firstOrFail();
        $m = Movimento::factory()->create($movimento + ['conto_id' => $this->cassa->id, 'verso' => $tip->verso, 'importo' => $importo, 'data' => $data, 'inserito_da' => $this->tesoriere->id, 'stato' => $stato]);

        return Imputazione::create($campi + [
            'movimento_id' => $m->id, 'importo' => $importo, 'area' => 'A', 'tipologia_id' => $tip->id, 'iniziativa_id' => $this->festa->id,
            'attivita_id' => Attivita::where('nome', 'Lettera i (art. 5 CTS)')->firstOrFail()->id,
        ]);
    }

    /** Il cambio dell'area della Festa nel 2026: i parametri dell'anteprima e dell'applicazione. */
    private function cambioFesta(string $area = 'B', ?string $attivita = 'Vendita di beni', int $anno = 2026): array
    {
        return ['tipo' => 'iniziativa', 'iniziativa_id' => $this->festa->id, 'esercizio' => $anno, 'area' => $area,
            'attivita_id' => $attivita === null ? '' : Attivita::where('nome', $attivita)->firstOrFail()->id];
    }

    private function deposita(int $anno = 2026): void
    {
        DatiEsercizio::updateOrCreate(['esercizio' => $anno], ['criterio_riparto' => 'ricavi', 'figurativi_dichiarati' => false, 'stato' => 'depositato']);
    }

    /** I campi nascosti del modulo dell'anteprima, come li rimanda il browser (letti dall'HTML, non ricostruiti da `$cambio`): `impronta` compresa, il `_token` no. */
    private function modulo(array $cambio): array
    {
        $pagina = $this->come()->get('/riclassificazione/anteprima?'.http_build_query($cambio))->assertOk();
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $pagina->getContent(), $campi, PREG_SET_ORDER);
        $campi = array_filter($campi, fn (array $c) => $c[1] !== '_token');
        parse_str(implode('&', array_map(fn (array $c) => urlencode(html_entity_decode($c[1])).'='.urlencode(html_entity_decode($c[2])), $campi)), $dati);
        $dati['impronta'] ?? $this->fail('L’anteprima non porta l’impronta da riscrivere nell’applicazione.');

        return $dati;
    }

    private function impronta(array $cambio): string
    {
        return $this->modulo($cambio)['impronta'];
    }

    /** Con `$impronta` si manda `$cambio` con quella; senza, si manda quello che il modulo dell'anteprima manda davvero. */
    private function applica(array $cambio, ?string $impronta = null, ?User $utente = null, bool $riautenticato = true)
    {
        $dati = $impronta === null ? $this->modulo($cambio) : $cambio + ['impronta' => $impronta];

        return $this->come($utente, $riautenticato)->post('/riclassificazione', $dati);
    }

    public function test_senza_il_permesso_e_un_403_e_senza_riautenticazione_si_va_a_riautenticarsi(): void
    {
        $cambio = $this->cambioFesta();

        $this->come($this->utenteCon('Consigliere'))->get('/riclassificazione')->assertForbidden();
        $this->come($this->utenteCon('Consigliere'))->get('/riclassificazione/anteprima?'.http_build_query($cambio))->assertForbidden();
        $this->come($this->utenteCon('Consigliere'), false)->post('/riclassificazione', $cambio + ['impronta' => str_repeat('a', 40)])->assertForbidden();

        $this->imputa('Utenze');
        $this->come($this->tesoriere)->get('/riclassificazione?esercizio=2026')->assertOk()->assertSee('Festa della Torre');
        $this->come($this->tesoriere, false)->from('/riclassificazione/anteprima')->post('/riclassificazione', $cambio + ['impronta' => str_repeat('a', 40)])->assertRedirect(route('riautentica'));
        $this->assertSame('A', $this->festa->refresh()->predefinitiPer(2026)->area->value);
    }

    public function test_l_anteprima_dice_cosa_cambia_nel_modello_d_e_non_scrive_nulla(): void
    {
        $imputazione = $this->imputa('Utenze');
        $registro = RegistroModifica::count();

        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($this->cambioFesta()))->assertOk()
            ->assertSee('Uscite A2) Servizi')->assertSee('Uscite B2) Servizi')->assertSee('1 imputazione');

        $this->assertSame('A', $imputazione->refresh()->area->value);
        $this->assertSame('A', $this->festa->refresh()->predefinitiPer(2026)->area->value);
        $this->assertSame($registro, RegistroModifica::count(), 'L’anteprima non lascia nulla nel registro');
    }

    public function test_si_sposta_l_iniziativa_e_i_movimenti_cambiati_a_mano_si_elencano_senza_toccarli(): void
    {
        $segue = $this->imputa('Utenze', 10000, [], '2026-05-10', 'confermato');
        $proposta = $this->imputa('Materiali e beni di consumo', 4000, [], '2026-05-11', 'proposto');
        $aMano = $this->imputa('Utenze', 7000, ['area' => 'E', 'attivita_id' => null], '2026-05-12', 'confermato', ['descrizione' => 'Bolletta della sede']);
        $altroAnno = $this->imputa('Utenze', 3000, [], '2027-02-01', 'confermato');
        $senzaIniziativa = $this->imputa('Utenze', 2000, ['iniziativa_id' => null, 'area' => 'E', 'attivita_id' => null]);
        $cambio = $this->cambioFesta();

        $risposta = $this->applica($cambio);
        $risposta->assertRedirect(route('riclassificazione', ['esercizio' => 2026]))->assertSessionHasNoErrors()->assertSessionHas('ok');
        $this->assertSame([$aMano->movimento_id], array_column(session('a_mano'), 'movimento_id'), 'I cambiati a mano si elencano nell’esito');

        $this->assertSame('B', $this->festa->refresh()->predefinitiPer(2026)->area->value);
        $this->assertSame('Vendita di beni', $this->festa->predefinitiPer(2026)->attivita->nome);
        $this->assertSame('A', $this->festa->predefinitiPer(2027)->area->value ?? 'A', 'Un altro esercizio non cambia');
        foreach ([$segue, $proposta] as $i) {
            $this->assertSame('B', $i->refresh()->area->value);
            $this->assertSame('Vendita di beni', $i->attivita->nome);
        }
        $this->assertSame('E', $aMano->refresh()->area->value, 'Un movimento cambiato a mano non si tocca');
        $this->assertSame('A', $altroAnno->refresh()->area->value);
        $this->assertSame('E', $senzaIniziativa->refresh()->area->value);
        $this->assertSame('confermato', $segue->movimento->refresh()->stato, 'Resta confermato');

        $this->get(route('riclassificazione', ['esercizio' => 2026]))->assertOk()->assertSee('Bolletta della sede')->assertSee('Cambiati a mano: non si toccano'); // l'elenco dopo l'applicazione (session flash)
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'iniziativa', 'oggetto_id' => $this->festa->id, 'azione' => 'riclassificata', 'user_id' => $this->tesoriere->id]);
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'movimento', 'oggetto_id' => $segue->movimento_id, 'azione' => 'riclassificato']);
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'movimento', 'oggetto_id' => $proposta->movimento_id, 'azione' => 'riclassificato']);
        $this->assertDatabaseMissing('registro_modifiche', ['oggetto_tipo' => 'movimento', 'oggetto_id' => $aMano->movimento_id]);
    }

    public function test_l_anteprima_non_blocca_nessuna_riga_l_applicazione_si(): void
    {
        $this->imputa('Utenze');
        $cambio = $this->cambioFesta();
        $conBlocco = fn () => collect(DB::getQueryLog())->contains(fn (array $q) => stripos($q['query'], 'for update') !== false);

        DB::enableQueryLog();
        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($cambio))->assertOk();
        $this->assertFalse($conBlocco(), 'Una GET non fa aspettare import e conferme degli altri');

        $dati = $this->modulo($cambio);
        DB::flushQueryLog();
        $this->come()->post('/riclassificazione', $dati)->assertSessionHasNoErrors();
        $this->assertTrue($conBlocco(), 'L’applicazione blocca conti, movimenti e righe che legge');
    }

    public function test_anteprima_ed_esito_elencano_regole_e_righe_di_conversione_che_portano_ancora_l_area_vecchia(): void
    {
        $this->imputa('Utenze');
        $tipologia = Tipologia::where('nome', 'Utenze')->firstOrFail();
        $altra = Iniziativa::where('nome', 'Carnevale')->firstOrFail();
        $regola = fn (string $nome, Iniziativa $i, string $area) => Regola::create(['nome' => $nome, 'posizione' => 1, 'tipologia_id' => $tipologia->id, 'iniziativa_id' => $i->id, 'area' => $area]);
        $vecchia = $regola('Bolletta della festa', $this->festa, 'A');
        $regola('Già in area B', $this->festa, 'B');
        $regola('Di un altro evento', $altra, 'A');
        ConversioneCausale::query()->delete();
        $riga = fn (string $causale, Iniziativa $i, string $area) => ConversioneCausale::create(['causale' => $causale, 'chiave' => strtolower($causale), 'verso' => 'uscita', 'tipologia_id' => $tipologia->id, 'iniziativa_id' => $i->id, 'area' => $area]);
        $riga('Causale inventata della festa', $this->festa, 'A');
        $riga('Causale già in B', $this->festa, 'B');
        $riga('Causale di un altro evento', $altra, 'A');
        $cambio = $this->cambioFesta();

        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($cambio))->assertOk()
            ->assertSee('Bolletta della festa')->assertSee(route('regole.modifica', $vecchia), false)
            ->assertSee('Causale inventata della festa')->assertSee(route('conversione'), false)
            ->assertDontSee('Già in area B')->assertDontSee('Di un altro evento')->assertDontSee('Causale già in B')->assertDontSee('Causale di un altro evento');
        $this->assertSame('A', $vecchia->refresh()->area, 'Non si cambiano in automatico');

        $this->applica($cambio)->assertSessionHasNoErrors();
        $this->get(route('riclassificazione', ['esercizio' => 2026]))->assertOk()->assertSee('Bolletta della festa')->assertSee('Causale inventata della festa')->assertDontSee('Di un altro evento');
    }

    public function test_togliendo_una_riga_della_tabella_si_elencano_regole_e_righe_di_conversione_che_la_usano(): void
    {
        $utenze = Tipologia::where('nome', 'Utenze')->firstOrFail();
        Regola::create(['nome' => 'Bolletta in area E', 'posizione' => 1, 'tipologia_id' => $utenze->id, 'area' => 'E']);
        Regola::create(['nome' => 'Bolletta in area C', 'posizione' => 2, 'tipologia_id' => $utenze->id, 'area' => 'C']);

        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($this->cambioVoce('Utenze', 'E', null)))->assertOk()->assertSee('Bolletta in area E')->assertDontSee('Bolletta in area C');
    }

    public function test_l_anteprima_elenca_i_cambiati_a_mano_per_import_con_il_collegamento_alla_revisione(): void
    {
        $import = ImportEstratto::create(['conto_id' => $this->cassa->id, 'user_id' => $this->tesoriere->id, 'stato' => 'confermato', 'periodo_dal' => '2026-05-01', 'periodo_al' => '2026-05-31', 'saldo_iniziale' => 0, 'saldo_finale' => 0, 'righe' => 1, 'confermato_il' => now()]);
        $this->imputa('Utenze', 7000, ['area' => 'E', 'attivita_id' => null], '2026-05-12', 'confermato', ['descrizione' => 'Bolletta della sede', 'import_estratto_id' => $import->id]);
        $this->imputa('Utenze', 5000, ['area' => 'E', 'attivita_id' => null], '2026-05-13', 'confermato', ['descrizione' => 'Rimborso inventato']);

        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($this->cambioFesta()))->assertOk()
            ->assertSee('Bolletta della sede')->assertSee('Rimborso inventato')
            ->assertSee(route('import.revisione', [$import, 'passo' => 'tutti']), false);
    }

    public function test_dal_collegamento_la_barra_dei_selezionati_riallinea_il_movimento_al_nuovo_predefinito(): void
    {
        $import = ImportEstratto::create(['conto_id' => $this->cassa->id, 'user_id' => $this->tesoriere->id, 'stato' => 'confermato', 'periodo_dal' => '2026-05-01', 'periodo_al' => '2026-05-31', 'saldo_iniziale' => 0, 'saldo_finale' => 0, 'righe' => 1, 'confermato_il' => now()]);
        $aMano = $this->imputa('Utenze', 7000, ['area' => 'E', 'attivita_id' => null], '2026-05-12', 'confermato', ['import_estratto_id' => $import->id]);
        $this->applica($this->cambioFesta())->assertSessionHasNoErrors();
        $movimento = $aMano->movimento->refresh();

        $this->come()->post(route('import.revisione.iniziativa', $import), ['movimenti' => [$movimento->id => Classificazione::versione($movimento)], 'iniziativa_id' => $this->festa->id])->assertSessionHasNoErrors();

        $this->assertSame(['B'], $movimento->imputazioni()->get()->map(fn ($i) => $i->area->value)->all(), 'La revisione riscrive le imputazioni');
        $this->assertSame('confermato', $movimento->refresh()->stato);
    }

    public function test_le_commissioni_di_un_incasso_restano_in_area_d(): void
    {
        $incasso = $this->imputa('Corrispettivi da terzi', 10000, ['importo' => 9800]);
        $commissione = Imputazione::create(['movimento_id' => $incasso->movimento_id, 'importo' => -200, 'area' => 'D', 'tipologia_id' => Tipologia::where('nome', Tipologia::COMMISSIONI)->firstOrFail()->id]);

        $this->applica($this->cambioFesta())->assertSessionHasNoErrors();

        $this->assertSame('B', $incasso->refresh()->area->value);
        $this->assertSame('D', $commissione->refresh()->area->value);
    }

    public function test_senza_la_riga_della_tabella_per_la_nuova_area_si_rifiuta_e_si_dice_quali(): void
    {
        $this->imputa('Quote associative'); // una quota non è una raccolta fondi: in area C non ha riga (#61)
        $cambio = $this->cambioFesta('C', 'Raccolta fondi occasionale');

        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($cambio))->assertSessionHasErrors('area');
        $this->assertStringContainsString('Quote associative', session('errors')->first('area'));
        $this->assertSame('A', $this->festa->refresh()->predefinitiPer(2026)->area->value);

        // Con la riga per l'area C si può; per una raccolta occasionale serve anche la riga dello schema delle raccolte (#89).
        $quote = Tipologia::where('nome', 'Quote associative')->firstOrFail()->id;
        DB::table('voci_modello_d')->insert(['esercizio' => 2026, 'area' => 'C', 'tipologia_id' => $quote, 'voce' => 'C1']);
        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($cambio))->assertSessionHasErrors('area');
        DB::table('righe_raccolta')->insert(['esercizio' => 2026, 'tipologia_id' => $quote, 'riga' => 'altri_proventi']);
        $this->applica($cambio)->assertSessionHasNoErrors();
        $this->assertSame('C', $this->festa->refresh()->predefinitiPer(2026)->area->value);
    }

    public function test_in_area_c_la_voce_viene_dall_attivita_di_raccolta_e_senza_si_rifiuta(): void
    {
        $utenze = $this->imputa('Utenze'); // la tabella ha la riga C1 per le Utenze (#61)

        foreach ([null, 'Vendita di beni'] as $attivita) { // nessuna attività, o una che non è di raccolta (e non è dell'area C)
            $this->come()->get('/riclassificazione/anteprima?'.http_build_query($this->cambioFesta('C', $attivita)))->assertSessionHasErrors('attivita_id');
        }
        $this->assertSame('A', $utenze->refresh()->area->value);

        $this->applica($this->cambioFesta('C', 'Raccolta fondi occasionale'))->assertSessionHasNoErrors();
        $this->assertSame('C2', $utenze->refresh()->voce());
    }

    public function test_il_cambio_d_area_verso_c_controlla_anche_la_tabella_delle_righe_delle_raccolte(): void
    {
        $utenze = $this->imputa('Utenze');
        $riga = DB::table('righe_raccolta')->where(['esercizio' => 2026, 'tipologia_id' => $utenze->tipologia_id])->first();
        DB::table('righe_raccolta')->where('id', $riga->id)->delete(); // la voce C1 c'è, la riga dello schema delle raccolte no

        // Occasionale: l'imputazione andrebbe in C2 senza riga dello schema, e la quadratura non tornerebbe mai.
        $cambio = $this->cambioFesta('C', 'Raccolta fondi occasionale');
        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($cambio))->assertSessionHasErrors('area');
        $this->assertStringContainsString('Utenze', session('errors')->first('area'));
        $this->assertStringContainsString('raccolte', session('errors')->first('area'));
        $this->assertSame('A', $utenze->refresh()->area->value);

        // Abituale: è C1, la riga dello schema delle raccolte non serve.
        $this->applica($this->cambioFesta('C', 'Raccolta fondi abituale'))->assertSessionHasNoErrors();
        $this->assertSame('C1', $utenze->refresh()->voce());
    }

    public function test_il_cambio_d_area_verso_c_occasionale_passa_con_la_riga_delle_raccolte(): void
    {
        $utenze = $this->imputa('Utenze');

        $this->applica($this->cambioFesta('C', 'Raccolta fondi occasionale'))->assertSessionHasNoErrors();
        $this->assertSame('C2', $utenze->refresh()->voce());
    }

    public function test_in_area_c_la_tabella_delle_voci_accetta_solo_c1(): void
    {
        $quote = Tipologia::where('nome', 'Quote associative')->firstOrFail();
        $chiave = ['esercizio' => 2026, 'area' => 'C', 'tipologia_id' => $quote->id];

        foreach (['C2', 'C3', 'I1', 'Imposte', 'A1'] as $voce) { // C1 o C2 lo decide l'attività di raccolta, non la tabella
            $this->come()->get('/riclassificazione/anteprima?'.http_build_query($this->cambioVoce('Quote associative', 'C', $voce)))->assertSessionHasErrors('voce');
        }
        $this->assertDatabaseMissing('voci_modello_d', $chiave);

        $this->applica($this->cambioVoce('Quote associative', 'C', 'C1'))->assertSessionHasNoErrors();
        $this->assertSame('C1', DB::table('voci_modello_d')->where($chiave)->value('voce'));
    }

    public function test_un_esercizio_depositato_non_si_tocca(): void
    {
        $imputazione = $this->imputa('Utenze');
        $cambio = $this->cambioFesta();
        $impronta = $this->impronta($cambio);
        $this->deposita();

        $this->applica($cambio, $impronta)->assertSessionHasErrors('esercizio');

        $this->assertSame('A', $imputazione->refresh()->area->value);
        $this->assertSame('A', $this->festa->refresh()->predefinitiPer(2026)->area->value);
    }

    public function test_un_esercizio_approvato_si_puo_cambiare_con_l_avviso(): void
    {
        $imputazione = $this->imputa('Utenze');
        DatiEsercizio::updateOrCreate(['esercizio' => 2026], ['criterio_riparto' => 'ricavi', 'figurativi_dichiarati' => false, 'stato' => 'approvato']);
        $cambio = $this->cambioFesta();

        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($cambio))->assertOk()->assertSee('già approvato');
        $this->applica($cambio)->assertSessionHasNoErrors();

        $this->assertSame('B', $imputazione->refresh()->area->value);
    }

    public function test_se_l_effetto_e_cambiato_dopo_l_anteprima_non_si_applica(): void
    {
        $this->imputa('Utenze');
        $cambio = $this->cambioFesta();
        $impronta = $this->impronta($cambio);
        $this->imputa('Servizi vari', 5000); // un altro movimento dopo l'anteprima: l'effetto non è più quello visto

        $this->applica($cambio, $impronta)->assertSessionHasErrors('impronta');

        $this->assertSame('A', $this->festa->refresh()->predefinitiPer(2026)->area->value);
    }

    public function test_un_cambio_che_non_cambia_nulla_o_un_attivita_di_un_altra_area_si_rifiutano(): void
    {
        $this->imputa('Utenze');

        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($this->cambioFesta('A', 'Lettera i (art. 5 CTS)')))->assertSessionHasErrors('area');
        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($this->cambioFesta('B', 'Lettera f (art. 5 CTS)')))->assertSessionHasErrors('attivita_id');
        $this->come()->get('/riclassificazione/anteprima?'.http_build_query(['esercizio' => 2025] + $this->cambioFesta()))->assertSessionHasErrors('area'); // nel 2025 la Festa non ha un predefinito
    }

    private function cambioProprieta(string $tipologia, array $proprieta): array
    {
        return ['tipo' => 'tipologia', 'tipologia_id' => Tipologia::where('nome', $tipologia)->firstOrFail()->id, 'proprieta' => $proprieta];
    }

    public function test_le_proprieta_di_una_tipologia_usata_cambiano_dopo_l_anteprima_e_un_esercizio_depositato_resta_com_e(): void
    {
        $this->imputa('Corrispettivi da terzi', 10000, [], '2026-05-10');
        $this->imputa('Corrispettivi da terzi', 8000, [], '2025-05-10');
        $this->deposita(2025);
        $cambio = $this->cambioProprieta('Corrispettivi da terzi', ['commerciale_per_natura']);

        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($cambio))->assertOk()
            ->assertSee('Esercizio 2026')->assertSee('L’esercizio 2025 è depositato', false)->assertDontSee('Esercizio 2025</h2>', false);
        $this->assertSame([], Tipologia::where('nome', 'Corrispettivi da terzi')->firstOrFail()->proprieta, 'L’anteprima non cambia nulla');

        $this->applica($cambio)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(['commerciale_per_natura'], Tipologia::where('nome', 'Corrispettivi da terzi')->firstOrFail()->proprieta);
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'tipologia', 'azione' => 'riclassificata', 'user_id' => $this->tesoriere->id]);
        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($cambio))->assertSessionHasErrors('proprieta'); // adesso non cambia più nulla
    }

    public function test_le_proprieta_di_una_tipologia_mai_usata_si_cambiano_senza_esercizi_da_confrontare(): void
    {
        $cambio = $this->cambioProprieta('5 per mille', ['neutra_nel_test_dell_ente']);

        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($cambio))->assertOk()->assertSee('Nessun esercizio da confrontare');
        $this->applica($cambio)->assertSessionHasNoErrors();

        $this->assertSame(['neutra_nel_test_dell_ente'], Tipologia::where('nome', '5 per mille')->firstOrFail()->proprieta);
    }

    private function cambioVoce(string $tipologia, string $area, ?string $voce, int $anno = 2026): array
    {
        return ['tipo' => 'voce', 'esercizio' => $anno, 'tipologia_id' => Tipologia::where('nome', $tipologia)->firstOrFail()->id, 'area' => $area, 'voce' => $voce ?? ''];
    }

    public function test_una_tipologia_nuova_non_si_usa_senza_una_riga_e_si_usa_dopo_averla_aggiunta(): void
    {
        $this->come()->post('/classificazione/tipologie', ['nome' => 'Noleggio di strumenti', 'verso' => 'uscita', 'spiegazione' => 'Strumenti presi a noleggio per una festa.'])->assertSessionHasNoErrors();
        $nuova = Tipologia::where('nome', 'Noleggio di strumenti')->firstOrFail();
        $m = Movimento::factory()->create(['conto_id' => $this->cassa->id, 'verso' => 'uscita', 'importo' => 5000, 'data' => '2026-03-01', 'inserito_da' => $this->tesoriere->id, 'stato' => 'da_classificare']);
        $classifica = fn () => $this->come()->put("/movimenti/{$m->id}/classificazione", ['imputazioni' => [['importo' => '50,00', 'tipologia_id' => $nuova->id, 'area' => 'A']]]);

        $classifica()->assertSessionHasErrors('imputazioni.0.tipologia_id');

        $cambio = $this->cambioVoce('Noleggio di strumenti', 'A', 'A3');
        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($cambio))->assertOk()->assertSee('va nella voce A3');
        $this->assertDatabaseMissing('voci_modello_d', ['tipologia_id' => $nuova->id]);
        $this->applica($cambio)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('voci_modello_d', ['esercizio' => 2026, 'area' => 'A', 'tipologia_id' => $nuova->id, 'voce' => 'A3']);
        $classifica()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'esercizio', 'oggetto_id' => 2026, 'azione' => 'voce_impostata', 'user_id' => $this->tesoriere->id]);
    }

    public function test_una_riga_usata_cambia_voce_con_l_anteprima_e_non_si_toglie(): void
    {
        $this->imputa('Utenze', 10000, ['area' => 'E', 'iniziativa_id' => null, 'attivita_id' => null]);

        $cambio = $this->cambioVoce('Utenze', 'E', 'E5');
        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($cambio))->assertOk()->assertSee('Uscite E2) Servizi')->assertSee('Uscite E5) Altre uscite');
        $this->applica($cambio)->assertSessionHasNoErrors();
        $this->assertSame('E5', DB::table('voci_modello_d')->where(['esercizio' => 2026, 'area' => 'E', 'tipologia_id' => Tipologia::where('nome', 'Utenze')->value('id')])->value('voce'));

        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($this->cambioVoce('Utenze', 'E', null)))->assertSessionHasErrors('voce'); // c'è un'imputazione
        $this->assertDatabaseHas('voci_modello_d', ['esercizio' => 2026, 'area' => 'E', 'voce' => 'E5']);
    }

    public function test_una_riga_non_usata_si_toglie_e_una_voce_fuori_schema_si_rifiuta(): void
    {
        $utenze = Tipologia::where('nome', 'Utenze')->firstOrFail();

        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($this->cambioVoce('Utenze', 'E', 'Z9')))->assertSessionHasErrors('voce');
        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($this->cambioVoce('Utenze', 'E', 'B2')))->assertSessionHasErrors('voce'); // B2 è dell'area B
        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($this->cambioVoce('Utenze', 'E', 'E2')))->assertSessionHasErrors('voce'); // è già così
        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($this->cambioVoce('Quote associative', 'C', null)))->assertSessionHasErrors('voce'); // non c'è la riga (una quota non è una raccolta fondi)

        $this->applica($this->cambioVoce('Utenze', 'E', null))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('voci_modello_d', ['esercizio' => 2026, 'area' => 'E', 'tipologia_id' => $utenze->id]);
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'esercizio', 'azione' => 'voce_tolta']);
    }

    public function test_la_tabella_di_un_esercizio_non_ancora_aperto_non_si_cambia_prima_dell_apertura(): void
    {
        $cambio = $this->cambioVoce('Utenze', 'E', 'E5', 2027); // il 2027 non ha voci, il 2026 sì: una riga sola impedirebbe la copia dell'apertura

        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($cambio))->assertSessionHasErrors('esercizio');
        $this->assertDatabaseMissing('voci_modello_d', ['esercizio' => 2027]);
    }

    public function test_la_tabella_delle_voci_di_un_esercizio_depositato_non_si_cambia(): void
    {
        $this->deposita();
        $cambio = $this->cambioVoce('Utenze', 'E', 'E5');

        $this->come()->get('/riclassificazione/anteprima?'.http_build_query($cambio))->assertSessionHasErrors('esercizio');
        $this->applica($cambio, str_repeat('a', 40))->assertSessionHasErrors('esercizio');
        $this->assertDatabaseHas('voci_modello_d', ['esercizio' => 2026, 'area' => 'E', 'voce' => 'E2']);
    }
}
