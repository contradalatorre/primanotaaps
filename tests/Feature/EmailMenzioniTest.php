<?php

namespace Tests\Feature;

use App\EmailMenzioni;
use App\Mail\MenzioniDaVedere;
use App\Models\Menzione;
use App\Models\Movimento;
use App\Models\Nota;
use App\Models\User;
use App\Onboarding\Catalogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\ConSpazioDiProva;
use Tests\TestCase;

/** Riepilogo delle 8 ed email immediata delle menzioni (#46). Posta simulata, dati inventati. */
class EmailMenzioniTest extends TestCase
{
    use ConSpazioDiProva, RefreshDatabase;

    private const SEGRETO = 'testo-segreto-della-nota-3141';

    private User $tina;

    private User $maria;

    private Movimento $movimento;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-01 10:00');
        Mail::fake();
        $this->tina = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->maria = $this->utenteCon('Consigliere', [], ['name' => 'Maria Rossi']);
        $this->movimento = Movimento::factory()->create(['importo' => 1250, 'verso' => 'uscita', 'descrizione' => 'Ghiaccio per la festa']);
    }

    private function scrivi(User $autore, string $testo, ?Movimento $movimento = null)
    {
        return $this->actingAs($autore)->post('/movimenti/'.($movimento ?? $this->movimento)->id.'/note', ['testo' => $testo]);
    }

    private function inviate(): Collection
    {
        return Mail::sent(MenzioniDaVedere::class);
    }

    public function test_il_riepilogo_arriva_a_chi_ha_menzioni_da_vedere_e_il_testo_della_nota_no(): void
    {
        $this->scrivi($this->tina, 'Per favore @Maria Rossi '.self::SEGRETO);

        $this->artisan('menzioni:riepilogo')->assertSuccessful();

        $this->assertCount(1, $this->inviate());
        $mail = $this->inviate()->first();
        $this->assertTrue($mail->hasTo($this->maria->email));
        $mail->assertSeeInText('Tina Verdi');
        $mail->assertSeeInText('01/06/2026');
        $mail->assertSeeInText('12,50');
        $mail->assertSeeInText('Ghiaccio per la festa');
        $mail->assertSeeInText(url('/movimenti/'.$this->movimento->id.'/note'));
        $mail->assertDontSeeInText(self::SEGRETO);
        $this->assertStringNotContainsString(self::SEGRETO, $mail->envelope()->subject);
    }

    public function test_il_riepilogo_non_parte_senza_menzioni_da_vedere(): void
    {
        $this->scrivi($this->tina, 'Nessun richiamo qui.');
        $this->scrivi($this->tina, 'Per favore @Maria Rossi');
        $this->actingAs($this->maria)->post('/menzioni/'.Menzione::sole()->id.'/fatto')->assertRedirect();

        $this->artisan('menzioni:riepilogo')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_il_riepilogo_rispetta_la_preferenza_e_non_scrive_all_autore(): void
    {
        $this->scrivi($this->tina, 'Per favore @Maria Rossi');
        $this->maria->update(['email_menzioni' => 'nessuna']);
        $this->artisan('menzioni:riepilogo')->assertSuccessful();
        Mail::assertNothingSent();

        $this->maria->update(['email_menzioni' => 'immediata']); // l'email è già partita, o non parte: il riepilogo no
        $this->artisan('menzioni:riepilogo')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_una_menzione_di_ruolo_arriva_a_chi_ha_il_ruolo(): void
    {
        $altro = $this->utenteCon('Consigliere', [], ['name' => 'Gino Neri']);
        $this->scrivi($this->tina, 'Serve un parere @Consigliere');

        $this->artisan('menzioni:riepilogo')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$this->maria->email, $altro->email], $this->inviate()->map(fn ($m) => $m->to[0]['address'])->all());
    }

    public function test_chi_non_legge_piu_le_note_non_riceve_il_riepilogo(): void
    {
        $this->scrivi($this->tina, 'Per favore @Maria Rossi');
        $this->maria->assegnazioni()->delete();

        $this->artisan('menzioni:riepilogo')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_un_destinatario_con_la_posta_giu_non_ferma_gli_altri(): void
    {
        $altro = $this->utenteCon('Consigliere', [], ['name' => 'Gino Neri']);
        $this->scrivi($this->tina, 'Serve un parere @Consigliere');
        Mail::shouldReceive('to')->twice()->andThrow(new \RuntimeException('Posta non raggiungibile'));

        $this->artisan('menzioni:riepilogo')->assertSuccessful();
    }

    public function test_con_l_email_immediata_parte_subito_dopo_la_nota_e_una_volta_sola(): void
    {
        $this->maria->update(['email_menzioni' => 'immediata']);

        $this->scrivi($this->tina, 'Guarda @Maria Rossi '.self::SEGRETO)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertCount(1, $this->inviate());
        $mail = $this->inviate()->first();
        $this->assertTrue($mail->hasTo($this->maria->email));
        $mail->assertSeeInText('Tina Verdi');
        $mail->assertDontSeeInText(self::SEGRETO);

        $nota = Nota::sole();
        $this->actingAs($this->tina)->put('/note/'.$nota->id, ['testo' => 'Guarda ancora @Maria Rossi'])->assertRedirect();
        $this->assertCount(1, $this->inviate(), 'una modifica non rimanda la stessa menzione');
    }

    public function test_l_email_immediata_non_parte_per_chi_ha_scelto_altro_ne_per_l_autore(): void
    {
        $this->scrivi($this->tina, 'Guarda @Maria Rossi'); // Maria ha il riepilogo, di serie
        $this->tina->update(['email_menzioni' => 'immediata']);
        $this->scrivi($this->maria, 'Rispondo, @Tina Verdi');
        $this->maria->update(['email_menzioni' => 'immediata']);
        $this->scrivi($this->tina, 'Cito me stessa: @Tina Verdi');

        $this->assertCount(1, $this->inviate()); // solo la risposta a Tina
        $this->assertTrue($this->inviate()->first()->hasTo($this->tina->email));
    }

    public function test_con_la_posta_giu_la_nota_si_scrive_lo_stesso(): void
    {
        $this->maria->update(['email_menzioni' => 'immediata']);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Posta non raggiungibile'));

        $this->scrivi($this->tina, 'Guarda @Maria Rossi')->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Nota::count());
        $this->assertSame(1, Menzione::count());
    }

    public function test_in_prova_l_email_immediata_non_parte_nemmeno_in_uno_spazio_nato_prima_della_preferenza(): void
    {
        $this->prova()->prepara($this->tina, Catalogo::predefinito()->missione('benvenuto'));

        $this->prova()->per($this->tina, null, function () {
            Schema::dropColumns('users', 'email_menzioni'); // uno spazio creato prima di #46 non ha la colonna: la guardia non deve nemmeno leggerla
            EmailMenzioni::immediate(collect([Menzione::make()->forceFill(['id' => 1])]));
        });

        Mail::assertNothingSent();
    }

    public function test_ognuno_sceglie_la_propria_preferenza(): void
    {
        $this->assertSame('riepilogo', $this->maria->fresh()->email_menzioni);

        $this->actingAs($this->maria)->get('/preferenze')->assertOk()->assertSee('Riepilogo alle 8');
        $this->actingAs($this->maria)->put('/preferenze', ['email_menzioni' => 'immediata'])->assertRedirect('/preferenze');
        $this->assertSame('immediata', $this->maria->fresh()->email_menzioni);

        $this->put('/preferenze', ['email_menzioni' => 'ogni-minuto'])->assertSessionHasErrors('email_menzioni');
        $this->assertSame('immediata', $this->maria->fresh()->email_menzioni);
    }
}
