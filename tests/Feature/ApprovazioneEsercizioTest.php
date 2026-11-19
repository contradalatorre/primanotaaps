<?php

namespace Tests\Feature;

use App\Esercizio\Verifiche;
use App\Models\Attivita;
use App\Models\Conto;
use App\Models\DatiEsercizio;
use App\Models\EsercizioStorico;
use App\Models\Giroconto;
use App\Models\ImpostazioniAssociazione;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Tipologia;
use App\Models\User;
use App\Models\VersioneEsercizio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Approvazione e riapertura dell'esercizio (#59): stati, permessi, riautenticazione, versioni. Dati inventati. */
class ApprovazioneEsercizioTest extends TestCase
{
    use RefreshDatabase;

    private User $tesoriere;

    private User $amministratore;

    private Conto $cassa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2027-04-20 10:00');
        foreach ([2026, 2027] as $dal) {
            ImpostazioniAssociazione::updateOrCreate(['dal_esercizio' => $dal], ['denominazione' => 'Contrada La Torre', 'codice_fiscale' => '00000000000', 'numero_runts' => '12345']);
        }
        $this->tesoriere = $this->utenteCon('Tesoriere');
        $this->amministratore = $this->utenteCon('Amministratore');
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa contanti', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
    }

    private function imputa(string $verso, int $importo, string $data, string $tipologia, string $area = 'A', string $stato = 'confermato'): Imputazione
    {
        $m = Movimento::factory()->create(['conto_id' => $this->cassa->id, 'verso' => $verso, 'importo' => $importo, 'data' => $data, 'inserito_da' => $this->tesoriere->id, 'stato' => $stato]);

        return Imputazione::create([
            'movimento_id' => $m->id, 'importo' => $importo, 'area' => $area, 'tipologia_id' => Tipologia::where('nome', $tipologia)->firstOrFail()->id,
            'iniziativa_id' => Iniziativa::where('nome', 'Festa della Torre')->firstOrFail()->id,
            'attivita_id' => Attivita::where('nome', 'Lettera i (art. 5 CTS)')->firstOrFail()->id,
        ]);
    }

    private function esercizio2026(): void
    {
        $this->imputa('entrata', 80000, '2026-06-01', 'Somministrazione');
        $this->imputa('uscita', 35000, '2026-06-02', 'Acquisti per la somministrazione');
    }

    private function come(User $utente, bool $riautenticato = true): static
    {
        $this->flushSession(); // la sessione dei test sopravvive tra una richiesta e l'altra: l'accesso recente di prima non deve valere

        return $this->actingAs($utente)->withSession($riautenticato ? ['auth.password_confirmed_at' => now()->unix()] : []);
    }

    private function approva(int $anno = 2026, string $data = '2027-04-20', ?User $utente = null)
    {
        return $this->come($utente ?? $this->tesoriere)->post("/esercizi/$anno/approvazione", ['data' => $data]);
    }

    public function test_il_tesoriere_approva_l_esercizio_e_si_salva_la_versione(): void
    {
        $this->esercizio2026();
        $stato2bis = Verifiche::pagina(2026)['catena']['areaA']['stato'];

        $this->approva()->assertRedirect(route('esercizio.approvazione', 2026));

        $dati = DatiEsercizio::per(2026);
        $this->assertSame('approvato', $dati->stato);
        $this->assertSame(1, $dati->versione);
        $this->assertNotNull($dati->criterio_secondarieta, 'Il criterio si fissa con l’approvazione');
        $versione = VersioneEsercizio::where('esercizio', 2026)->sole();
        $this->assertSame(1, $versione->numero);
        $this->assertSame('2027-04-20', $versione->approvata_il->toDateString());
        $this->assertSame($this->tesoriere->id, $versione->approvata_da);
        $this->assertSame($stato2bis, $versione->stato_2bis);
        $this->assertSame(80000, $versione->istantanea['modello_d']['aree']['A']['entrate']['totale']['importo']);
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'esercizio', 'oggetto_id' => 2026, 'azione' => 'approvato', 'user_id' => $this->tesoriere->id]);
    }

    public function test_senza_riautenticazione_non_si_approva(): void
    {
        $this->esercizio2026();

        $this->come($this->tesoriere, false)->from('/esercizi/2026/approvazione')->post('/esercizi/2026/approvazione', ['data' => '2027-04-20'])->assertRedirect(route('riautentica'));

        $this->assertSame('aperto', DatiEsercizio::per(2026)->stato);
        $this->assertSame(0, VersioneEsercizio::count());
    }

    public function test_chi_non_ha_il_permesso_riceve_403_anche_senza_riautenticazione(): void
    {
        $lettore = $this->utenteCon('Lettore esterno');

        $this->come($lettore, false)->post('/esercizi/2026/approvazione', ['data' => '2027-04-20'])->assertForbidden();
        $this->come($this->utenteCon('Consigliere'), false)->post('/esercizi/2026/approvazione', ['data' => '2027-04-20'])->assertForbidden();
        $this->assertSame(0, VersioneEsercizio::count());
    }

    public function test_un_secondo_clic_non_scrive_una_seconda_versione(): void
    {
        $this->esercizio2026();
        $this->approva()->assertRedirect();

        $this->approva()->assertSessionHasErrors('stato');

        $this->assertSame(1, VersioneEsercizio::where('esercizio', 2026)->count());
    }

    public function test_la_data_va_dalla_fine_dell_esercizio_a_oggi(): void
    {
        $this->esercizio2026();

        $this->approva(2026, '2026-12-31')->assertSessionHasErrors('data');
        $this->approva(2026, '2027-04-21')->assertSessionHasErrors('data'); // domani
        $this->approva(2026, '20/04/2027')->assertSessionHasErrors('data');
        $this->assertSame(0, VersioneEsercizio::count());

        $this->approva(2026, '2027-01-01')->assertSessionHasNoErrors();
    }

    public function test_un_esercizio_storico_o_senza_voci_non_si_approva(): void
    {
        EsercizioStorico::create(['esercizio' => 2025, 'importi' => [], 'saldo_cassa' => 0, 'saldo_depositi' => 0, 'figurativi' => [], 'differenze' => []]);
        $this->approva(2025, '2026-04-20')->assertSessionHasErrors('stato');
        $this->travelTo('2028-02-01');
        $this->approva(2027, '2028-01-10')->assertSessionHasErrors('stato'); // le voci del 2027 non sono state aperte

        $this->assertSame(0, VersioneEsercizio::count());
    }

    public function test_il_criterio_confermato_resta_e_quello_non_confermato_si_fissa_al_proposto(): void
    {
        $this->esercizio2026();
        $proposto = Verifiche::secondarieta(2026)['proposto'];
        $this->approva()->assertRedirect();
        $this->assertSame($proposto, DatiEsercizio::per(2026)->criterio_secondarieta);
        $this->assertSame($proposto, VersioneEsercizio::sole()->istantanea['secondarieta']['confermato']);
    }

    public function test_il_criterio_confermato_dal_tesoriere_non_cambia_all_approvazione(): void
    {
        $this->esercizio2026();
        $altro = Verifiche::secondarieta(2026)['proposto'] === 'a' ? 'b' : 'a';
        $this->come($this->tesoriere)->post('/esercizi/2026/test/criterio', ['criterio' => $altro])->assertRedirect();

        $this->approva()->assertRedirect();

        $this->assertSame($altro, DatiEsercizio::per(2026)->criterio_secondarieta);
        $this->assertSame($altro, VersioneEsercizio::sole()->criterio_secondarieta);
    }

    public function test_dopo_l_approvazione_il_criterio_non_si_cambia_piu(): void
    {
        $this->esercizio2026();
        $this->approva()->assertRedirect();
        $fissato = DatiEsercizio::per(2026)->criterio_secondarieta;

        $this->come($this->tesoriere)->post('/esercizi/2026/test/criterio', ['criterio' => $fissato === 'a' ? 'b' : 'a'])->assertSessionHasErrors('criterio');

        $this->assertSame($fissato, DatiEsercizio::per(2026)->criterio_secondarieta);
    }

    public function test_l_elenco_di_controllo_informa_senza_bloccare(): void
    {
        $this->imputa('uscita', 5000, '2026-03-01', 'Acquisti per la somministrazione', 'A', 'proposto'); // proposto, e senza giustificativo
        Movimento::factory()->create(['conto_id' => $this->cassa->id, 'verso' => 'uscita', 'importo' => 700, 'data' => '2026-03-02', 'inserito_da' => $this->tesoriere->id, 'stato' => 'da_classificare']);

        $this->come($this->tesoriere)->get('/esercizi/2026/approvazione')->assertOk()
            ->assertSee('1 movimento è da classificare')
            ->assertSee('2 spese sono senza giustificativo')
            ->assertSee('Informa, non blocca');

        $this->approva()->assertRedirect();
        $this->assertSame('approvato', DatiEsercizio::per(2026)->stato);
    }

    public function test_un_giroconto_non_e_una_spesa_senza_giustificativo(): void
    {
        $banca = Conto::factory()->create(['nome' => 'Banca', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 100000]);
        $uscita = Movimento::factory()->create(['conto_id' => $banca->id, 'verso' => 'uscita', 'importo' => 20000, 'data' => '2026-03-01', 'inserito_da' => $this->tesoriere->id, 'stato' => 'confermato']);
        $entrata = Movimento::factory()->create(['conto_id' => $this->cassa->id, 'verso' => 'entrata', 'importo' => 20000, 'data' => '2026-03-01', 'inserito_da' => $this->tesoriere->id, 'stato' => 'confermato']);
        Giroconto::create(['uscita_id' => $uscita->id, 'entrata_id' => $entrata->id, 'regola' => 'prova', 'confermato_da' => $this->tesoriere->id, 'confermato_il' => now()]);

        $this->come($this->tesoriere)->get('/esercizi/2026/approvazione')->assertOk()->assertDontSee('senza giustificativo');
    }

    public function test_riaprire_e_solo_dell_amministratore_con_motivazione_e_riautenticazione(): void
    {
        $this->esercizio2026();
        $this->approva()->assertRedirect();

        $this->come($this->tesoriere)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore'])->assertForbidden();
        $this->come($this->amministratore, false)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore'])->assertRedirect(route('riautentica'));
        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => '  '])->assertSessionHasErrors('motivazione');
        $this->assertSame('approvato', DatiEsercizio::per(2026)->stato);

        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => 'Una fattura registrata due volte'])->assertRedirect(route('esercizio.approvazione', 2026));

        $dati = DatiEsercizio::per(2026);
        $this->assertSame('aperto', $dati->stato);
        $this->assertSame(2, $dati->versione);
        $v1 = VersioneEsercizio::where(['esercizio' => 2026, 'numero' => 1])->sole();
        $this->assertNotNull($v1->riaperta_il);
        $this->assertSame($this->amministratore->id, $v1->riaperta_da);
        $this->assertSame('Una fattura registrata due volte', $v1->motivazione_riapertura);
        $registro = RegistroModifica::where(['oggetto_tipo' => 'esercizio', 'oggetto_id' => 2026, 'azione' => 'riaperto'])->sole();
        $this->assertSame('Una fattura registrata due volte', $registro->motivazione);
    }

    public function test_un_esercizio_aperto_non_si_riapre(): void
    {
        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore'])->assertSessionHasErrors('stato');
    }

    public function test_dopo_la_riapertura_la_nuova_approvazione_e_la_versione_due_e_la_prima_resta_nello_storico(): void
    {
        $this->esercizio2026();
        $this->approva()->assertRedirect();
        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore'])->assertRedirect();

        $this->approva(2026, '2027-04-22', $this->tesoriere)->assertSessionHasErrors('data'); // domani: non c'è ancora
        $this->travelTo('2027-04-25');
        $this->approva(2026, '2027-04-22')->assertRedirect();

        $this->assertSame([1, 2], VersioneEsercizio::where('esercizio', 2026)->orderBy('numero')->pluck('numero')->all());
        $this->assertSame('approvato', DatiEsercizio::per(2026)->stato);
        $this->assertSame(2, DatiEsercizio::per(2026)->versione);
        $this->assertSame(2, VersioneEsercizio::vigente(2026)->numero);
        $this->come($this->tesoriere)->get('/esercizi/2026/approvazione')->assertOk()->assertSee('Versione 1')->assertSee('Versione 2')->assertSee('Errore');
    }
}
