<?php

namespace Tests\Feature;

use App\Models\Attivita;
use App\Models\Conto;
use App\Models\DatiEsercizio;
use App\Models\ImpostazioniAssociazione;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\Tipologia;
use App\Models\User;
use App\Models\VersioneEsercizio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Il deposito al RUNTS (#60, #12, #18): PDF/A di deposito, data dell'invio, istantanea immutabile, movimenti bloccati. Dati inventati. */
class DepositoRuntsTest extends TestCase
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
        ] + ($area === 'A' ? [
            'iniziativa_id' => Iniziativa::where('nome', 'Festa della Torre')->firstOrFail()->id,
            'attivita_id' => Attivita::where('nome', 'Lettera i (art. 5 CTS)')->firstOrFail()->id,
        ] : []));
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

    private function approva(int $anno = 2026): void
    {
        $this->come($this->tesoriere)->post("/esercizi/$anno/approvazione", ['data' => '2027-04-20'])->assertSessionHasNoErrors();
    }

    private function deposita(string $data = '2027-04-25', ?User $utente = null, int $anno = 2026)
    {
        return $this->come($utente ?? $this->tesoriere)->post("/esercizi/$anno/deposito", ['data' => $data]);
    }

    public function test_la_data_dell_invio_porta_l_esercizio_a_depositato_e_salva_l_istantanea(): void
    {
        $this->esercizio2026();
        $this->approva();
        $this->travelTo('2027-04-26 10:00');

        $this->deposita('2027-04-25')->assertRedirect(route('esercizio.approvazione', 2026))->assertSessionHasNoErrors();

        $this->assertSame('depositato', DatiEsercizio::per(2026)->stato);
        $versione = VersioneEsercizio::vigente(2026);
        $this->assertSame('2027-04-25', $versione->depositata_il->toDateString());
        $this->assertSame($this->tesoriere->id, $versione->depositata_da);
        $this->assertSame(80000, $versione->istantanea_deposito['prospetti']['modello_d']['aree']['A']['entrate']['totale']['importo']);
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'esercizio', 'oggetto_id' => 2026, 'azione' => 'depositato', 'user_id' => $this->tesoriere->id]);
    }

    public function test_senza_riautenticazione_non_si_deposita(): void
    {
        $this->esercizio2026();
        $this->approva();

        $this->come($this->tesoriere, false)->from('/esercizi/2026/approvazione')->post('/esercizi/2026/deposito', ['data' => '2027-04-20'])->assertRedirect(route('riautentica'));

        $this->assertSame('approvato', DatiEsercizio::per(2026)->stato);
    }

    public function test_chi_non_ha_il_permesso_riceve_403_anche_senza_riautenticazione(): void
    {
        $this->esercizio2026();
        $this->approva();

        $this->come($this->utenteCon('Lettore esterno'), false)->post('/esercizi/2026/deposito', ['data' => '2027-04-20'])->assertForbidden();
        $this->come($this->utenteCon('Consigliere'), false)->post('/esercizi/2026/deposito', ['data' => '2027-04-20'])->assertForbidden();
        $this->assertSame('approvato', DatiEsercizio::per(2026)->stato);
    }

    public function test_un_esercizio_aperto_non_si_deposita(): void
    {
        $this->esercizio2026();

        $this->deposita('2027-04-20')->assertSessionHasErrors('stato');

        $this->assertSame('aperto', DatiEsercizio::per(2026)->stato);
    }

    public function test_un_secondo_clic_non_deposita_due_volte(): void
    {
        $this->esercizio2026();
        $this->approva();
        $this->deposita('2027-04-20')->assertSessionHasNoErrors();

        $this->deposita('2027-04-20')->assertSessionHasErrors('stato');

        $this->assertSame(1, VersioneEsercizio::where('esercizio', 2026)->whereNotNull('depositata_il')->count());
        $this->assertSame(1, \App\Models\RegistroModifica::where('azione', 'depositato')->count());
    }

    public function test_la_data_dell_invio_va_dall_approvazione_a_oggi(): void
    {
        $this->esercizio2026();
        $this->approva();

        $this->deposita('2027-04-19')->assertSessionHasErrors('data');
        $this->deposita('2027-04-21')->assertSessionHasErrors('data');
        $this->deposita('')->assertSessionHasErrors('data');

        $this->assertSame('approvato', DatiEsercizio::per(2026)->stato);
    }
}
