<?php

namespace Tests\Feature;

use App\Models\Avanzamento;
use App\Models\SpazioProva;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ConSpazioDiProva;
use Tests\TestCase;

/** Pulizia periodica degli spazi di prova (#71, ADR 0003): senza assegnazioni o inattivi da 6 mesi; i timbri restano. */
class CancellazioneSpaziTest extends TestCase
{
    use RefreshDatabase, ConSpazioDiProva;

    public function test_si_cancellano_gli_spazi_di_chi_non_ha_piu_assegnazioni_e_di_chi_e_inattivo_da_sei_mesi(): void
    {
        $senzaRuolo = $this->utenteCon('Consigliere');
        $inattivo = $this->utenteCon('Consigliere');
        $attivo = $this->utenteCon('Consigliere');
        $candidato = User::factory()->create(); // mai avuto assegnazioni: si allena prima del ruolo
        foreach ([$senzaRuolo, $inattivo, $attivo, $candidato] as $utente) {
            $this->actingAs($utente)->post(route('impara.avvia', 'benvenuto'));
        }
        $senzaRuolo->assegnazioni()->delete(); // la revoca cancella la riga: dopo, non si distingue da chi non ha mai avuto un ruolo
        $scaduta = $this->utenteCon('Consigliere', ['scade_il' => now()->addDay()]);
        $this->actingAs($scaduta)->post(route('impara.avvia', 'benvenuto'));
        $scaduta->assegnazioni()->update(['scade_il' => now()->subDay()]);
        SpazioProva::whereKey($inattivo->id)->update(['ultimo_uso' => now()->subMonths(6)->subDay()]);
        Avanzamento::where('user_id', $senzaRuolo->id)->update(['stato' => 'completata', 'versione_completata' => 1]);

        $this->artisan('prova:cancella')->assertSuccessful();

        $this->assertFalse($this->prova()->esiste($senzaRuolo->id));
        $this->assertFalse($this->prova()->esiste($scaduta->id));
        $this->assertFalse($this->prova()->esiste($inattivo->id));
        $this->assertTrue($this->prova()->esiste($attivo->id));
        $this->assertTrue($this->prova()->esiste($candidato->id));
        $this->assertEqualsCanonicalizing([$attivo->id, $candidato->id], SpazioProva::pluck('user_id')->all());
        $this->assertSame(1, Avanzamento::where('user_id', $senzaRuolo->id)->whereNotNull('versione_completata')->count(), 'i timbri restano');
        $this->assertSame(5, Avanzamento::count(), 'l’avanzamento resta');
    }

    public function test_il_comando_gira_ogni_giorno(): void
    {
        $eventi = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'prova:cancella'));

        $this->assertCount(1, $eventi);
        $this->assertSame('0 0 * * *', $eventi->first()->expression);
    }
}
