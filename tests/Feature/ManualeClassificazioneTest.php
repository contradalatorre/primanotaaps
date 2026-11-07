<?php

namespace Tests\Feature;

use App\Models\ManualeSezione;
use App\Models\RegistroAccesso;
use App\Models\RegistroModifica;
use App\Models\Tipologia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Il manuale di classificazione (#44): lo legge chiunque veda, lo modifica chi ha `gestire_regole`, le versioni sono nel registro. */
class ManualeClassificazioneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-20 10:00');
    }

    public function test_chi_puo_solo_vedere_legge_il_manuale_ma_non_lo_modifica(): void
    {
        $lettore = $this->utenteCon('Lettore esterno');
        $sezione = ManualeSezione::orderBy('id')->firstOrFail();

        $this->actingAs($lettore)->get('/manuale')->assertOk()
            ->assertSee($sezione->titolo)->assertSee('id="sezione-'.$sezione->codice.'"', false)->assertDontSee('Nuova sezione');
        $this->actingAs($lettore)->put("/manuale/{$sezione->id}", ['titolo' => 'x', 'corpo' => 'y'])->assertForbidden();
        $this->actingAs($lettore)->post('/manuale', ['titolo' => 'x', 'corpo' => 'y'])->assertForbidden();
        $this->actingAs($this->utenteCon('Consigliere'))->put("/manuale/{$sezione->id}", ['titolo' => 'x', 'corpo' => 'y'])->assertForbidden();
    }

    public function test_il_tesoriere_modifica_una_sezione_e_il_registro_tiene_il_testo_di_prima_e_di_dopo(): void
    {
        $tesoriere = $this->utenteCon('Tesoriere');
        $sezione = ManualeSezione::orderBy('id')->firstOrFail();
        $codice = $sezione->codice;
        $prima = $sezione->corpo;

        $this->actingAs($tesoriere)->put("/manuale/{$sezione->id}", [
            'titolo' => 'Nuovo titolo', 'corpo' => 'Un testo **nuovo**.', 'codice' => 'zzz', 'versione' => $sezione->versione(),
        ])->assertRedirect('/manuale');

        $sezione->refresh();
        $this->assertSame(['Nuovo titolo', 'Un testo **nuovo**.'], [$sezione->titolo, $sezione->corpo]);
        $this->assertSame($codice, $sezione->codice); // l'identificativo è stabile: citarlo come «manuale § X» deve valere sempre
        $riga = RegistroModifica::where('oggetto_tipo', 'manuale')->firstOrFail();
        $this->assertSame([$tesoriere->id, 'modificato', $sezione->id], [$riga->user_id, $riga->azione, $riga->oggetto_id]);
        $this->assertSame($prima, $riga->prima['corpo']);
        $this->assertSame('Un testo **nuovo**.', $riga->dopo['corpo']);

        $this->actingAs($tesoriere)->get('/registro')->assertOk()->assertSee('ha modificato la sezione del manuale')->assertSee('Nuovo titolo')
            ->assertSee('la sezione del manuale § '.$codice)->assertDontSee('la sezione del manuale n. '.$sezione->id);
    }

    public function test_salvare_senza_cambiare_nulla_non_scrive_nel_registro(): void
    {
        $sezione = ManualeSezione::orderBy('id')->firstOrFail();

        $this->actingAs($this->utenteCon('Tesoriere'))->put("/manuale/{$sezione->id}", ['titolo' => $sezione->titolo, 'corpo' => $sezione->corpo, 'versione' => $sezione->versione()])->assertRedirect('/manuale');

        $this->assertSame(0, RegistroModifica::where('oggetto_tipo', 'manuale')->count());
    }

    public function test_il_browser_manda_i_fine_riga_crlf_e_salvare_senza_cambiare_nulla_non_scrive_nel_registro(): void
    {
        $sezione = ManualeSezione::orderBy('id')->firstOrFail();
        $sezione->update(['corpo' => "Prima riga.\n\n- primo\n- secondo"]);

        $this->actingAs($this->utenteCon('Tesoriere'))->put("/manuale/{$sezione->id}", [
            'titolo' => $sezione->titolo, 'corpo' => "Prima riga.\r\n\r\n- primo\r\n- secondo", 'versione' => $sezione->versione(),
        ])->assertRedirect('/manuale');

        $this->assertSame(0, RegistroModifica::where('oggetto_tipo', 'manuale')->count());
        $this->assertSame("Prima riga.\n\n- primo\n- secondo", $sezione->fresh()->corpo);
    }

    public function test_due_modifiche_concorrenti_non_si_sovrascrivono_senza_avviso(): void
    {
        $sezione = ManualeSezione::orderBy('id')->firstOrFail();
        $vista = $sezione->versione();
        $tesoriere = $this->utenteCon('Tesoriere');

        $this->actingAs($tesoriere)->put("/manuale/{$sezione->id}", ['titolo' => $sezione->titolo, 'corpo' => 'Il primo.', 'versione' => $vista])->assertRedirect('/manuale');
        $this->actingAs($tesoriere)->from('/manuale')->put("/manuale/{$sezione->id}", ['titolo' => $sezione->titolo, 'corpo' => 'Il secondo.', 'versione' => $vista])
            ->assertRedirect('/manuale')->assertSessionHasErrors('versione');

        $this->assertSame('Il primo.', $sezione->fresh()->corpo);
        $this->assertSame(1, RegistroModifica::where('oggetto_tipo', 'manuale')->count());
    }

    public function test_si_aggiunge_una_sezione_con_il_codice_successivo_e_non_si_cancella(): void
    {
        $ultimo = (int) ManualeSezione::orderByDesc('id')->value('codice');

        $this->actingAs($this->utenteCon('Amministratore'))->post('/manuale', ['titolo' => 'Una regola in più', 'corpo' => 'Testo.', 'codice' => '1'])->assertRedirect('/manuale');

        $nuova = ManualeSezione::firstWhere('titolo', 'Una regola in più');
        $this->assertSame((string) ($ultimo + 1), $nuova->codice);
        $this->assertSame('creato', RegistroModifica::where('oggetto_tipo', 'manuale')->firstOrFail()->azione);
        $this->actingAs($this->utenteCon('Tesoriere'))->delete("/manuale/{$nuova->id}")->assertStatus(405);
    }

    public function test_titolo_e_testo_sono_obbligatori(): void
    {
        $sezione = ManualeSezione::orderBy('id')->firstOrFail();

        $this->actingAs($this->utenteCon('Tesoriere'))->put("/manuale/{$sezione->id}", ['titolo' => '', 'corpo' => '', 'versione' => $sezione->versione()])->assertSessionHasErrors(['titolo', 'corpo']);
    }

    public function test_il_testo_e_formattato_ma_il_codice_html_non_passa(): void
    {
        $sezione = ManualeSezione::orderBy('id')->firstOrFail();
        $sezione->update(['corpo' => "Un **grassetto** e <script>alert('x')</script> e [un link](javascript:alert(1)).\n\n- primo\n- secondo"]);

        // Un lettore: chi può modificare ha il testo grezzo nel campo di modifica.
        $this->actingAs($this->utenteCon('Lettore esterno'))->get('/manuale')->assertOk()
            ->assertSee('<strong>grassetto</strong>', false)->assertSee('<li>primo</li>', false)
            ->assertDontSee('<script>', false)->assertDontSee('javascript:', false);
    }

    public function test_ogni_tipologia_attiva_ha_la_sua_spiegazione_con_un_punto_di_arrivo_stabile(): void
    {
        $archiviata = Tipologia::firstWhere('nome', 'Libri e pubblicazioni');
        $archiviata->forceFill(['archiviata_il' => now()])->save();
        $risposta = $this->actingAs($this->utenteCon('Lettore esterno'))->get('/manuale')->assertOk()->assertSee('id="tipologie"', false);

        foreach (Tipologia::attive()->get() as $tipologia) {
            $risposta->assertSee('id="tipologia-'.$tipologia->id.'"', false)->assertSee(e($tipologia->spiegazione), false);
        }
        $risposta->assertDontSee('id="tipologia-'.$archiviata->id.'"', false);
    }

    public function test_la_pagina_classificazione_rimanda_alla_spiegazione_nel_manuale(): void
    {
        $tipologia = Tipologia::firstWhere('nome', 'Quote associative');

        $archiviata = Tipologia::firstWhere('nome', 'Libri e pubblicazioni');
        $archiviata->forceFill(['archiviata_il' => now()])->save();

        $this->actingAs($this->utenteCon('Lettore esterno'))->get('/classificazione')->assertOk()
            ->assertSee('/manuale#tipologia-'.$tipologia->id, false)
            ->assertDontSee('/manuale#tipologia-'.$archiviata->id, false); // il manuale elenca solo le attive: niente rimando a un'ancora che non c'è
    }

    public function test_l_esportazione_e_un_file_di_testo_con_le_sezioni_e_le_tipologie_e_finisce_nel_registro_degli_accessi(): void
    {
        $lettore = $this->utenteCon('Lettore esterno');
        $sezione = ManualeSezione::orderBy('id')->firstOrFail();

        $risposta = $this->actingAs($lettore)->get('/manuale/esporta')->assertOk();

        $this->assertStringContainsString('attachment; filename=manuale-di-classificazione-2026-05-20.md', $risposta->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('text/markdown', $risposta->headers->get('Content-Type'));
        $testo = $risposta->getContent();
        $this->assertStringContainsString("§ {$sezione->codice} {$sezione->titolo}", $testo);
        $this->assertStringContainsString($sezione->corpo, $testo);
        $this->assertStringContainsString('§ tipologie', $testo);
        $this->assertStringContainsString(Tipologia::firstWhere('nome', 'Quote associative')->spiegazione, $testo);
        $this->assertSame($lettore->id, RegistroAccesso::where('evento', 'esportazione_manuale')->firstOrFail()->user_id);
    }

    public function test_la_prima_stesura_copre_le_decisioni_prese_e_non_contiene_dati_reali(): void
    {
        $testo = ManualeSezione::testo();

        foreach (['A7', 'da confermare con il commercialista', 'Imposte', 'sponsorizzazioni', 'secondarietà', '516,46', 'D1', 'giroconti', 'Personale', 'dubbio', 'La Casa', 'oneri finanziari', 'proventi figurativi', 'B2 e B4'] as $argomento) {
            $this->assertStringContainsStringIgnoringCase($argomento, $testo, "manca: $argomento");
        }
        $this->assertDoesNotMatchRegularExpression('/IT\d{2}[A-Z]\d{10,}|@[a-z0-9-]+\.(it|com)/i', $testo); // niente IBAN né indirizzi
    }

    public function test_la_tolleranza_del_6_per_cento_vale_al_massimo_per_tre_esercizi_poi_soglia_piena(): void
    {
        $testo = ManualeSezione::testo();

        $this->assertSame(2, substr_count($testo, 'è tollerato uno scarto fino al 6%, ma al massimo per tre esercizi consecutivi, poi si applica la soglia piena'));
        $this->assertStringNotContainsString('più il 6%', $testo);
    }
}
