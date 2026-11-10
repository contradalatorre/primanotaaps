<?php

namespace Tests\Feature;

use App\Classificazione;
use App\Models\Conto;
use App\Models\Movimento;
use App\Models\Nota;
use App\Models\RegistroModifica;
use App\Models\Ruolo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Note, dubbi e menzioni sui movimenti (#45). Dati inventati. */
class NoteTest extends TestCase
{
    use RefreshDatabase;

    private Conto $festa;

    private Conto $altra;

    private Movimento $movimento;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-01 10:00');
        $this->festa = Conto::factory()->create(['nome' => 'Cassa Festa']);
        $this->altra = Conto::factory()->create(['nome' => 'Cassa Magazzino']);
        $this->movimento = Movimento::factory()->create(['conto_id' => $this->festa->id, 'descrizione' => 'Ghiaccio per la festa']);
    }

    private function scrivi(User $utente, string $testo, array $altro = [], ?Movimento $movimento = null)
    {
        return $this->actingAs($utente)->post('/movimenti/'.($movimento ?? $this->movimento)->id.'/note', ['testo' => $testo] + $altro);
    }

    public function test_un_consigliere_scrive_su_un_movimento_fuori_dal_suo_ambito(): void
    {
        $maria = $this->utenteCon('Consigliere', ['conti' => [$this->altra->id]], ['name' => 'Maria Rossi']);

        $this->scrivi($maria, 'Lo scontrino è nel cassetto.')->assertRedirect()->assertSessionHasNoErrors();

        $nota = Nota::sole();
        $this->assertSame([$this->movimento->id, $maria->id, 'Lo scontrino è nel cassetto.', false], [$nota->oggetto_id, $nota->user_id, $nota->testo, $nota->dubbio]);
        $registro = RegistroModifica::where('oggetto_tipo', 'nota')->sole();
        $this->assertSame(['scritta', $maria->id, $nota->id], [$registro->azione, $registro->user_id, $registro->oggetto_id]);
        $this->assertSame('Lo scontrino è nel cassetto.', $registro->dopo['testo']);
    }

    public function test_anche_il_lettore_esterno_scrive_ma_senza_il_permesso_note_no(): void
    {
        $revisore = $this->utenteCon('Lettore esterno', [], ['esterno' => true]);
        $this->scrivi($revisore, 'Quadra con l’estratto.')->assertRedirect();
        $this->assertSame(1, Nota::count());

        $soloVista = $this->utenteCon(Ruolo::create(['nome' => 'Solo vista', 'permessi' => ['vedere']]));
        $this->scrivi($soloVista, 'No.')->assertForbidden();
        $this->actingAs($soloVista)->get("/movimenti/{$this->movimento->id}/note")->assertForbidden();
        $this->assertSame(1, Nota::count());
    }

    public function test_la_nota_e_breve_e_non_vuota(): void
    {
        $maria = $this->utenteCon('Consigliere');
        $this->scrivi($maria, '   ')->assertSessionHasErrors('testo');
        $this->scrivi($maria, str_repeat('a', 1001))->assertSessionHasErrors('testo');
        $this->assertSame(0, Nota::count());
    }

    public function test_le_note_si_leggono_in_sequenza_e_il_testo_e_escapato(): void
    {
        $maria = $this->utenteCon('Consigliere', [], ['name' => 'Maria Rossi']);
        $this->scrivi($maria, 'Prima <script>alert(1)</script>');
        $this->scrivi($maria, 'Seconda');

        $this->actingAs($maria)->get("/movimenti/{$this->movimento->id}/note")->assertOk()
            ->assertSeeInOrder(['Prima &lt;script&gt;alert(1)&lt;/script&gt;', 'Seconda'], false)
            ->assertDontSee('<script>alert(1)', false)->assertSee('Maria Rossi');
    }

    public function test_icona_e_conteggio_sulla_riga_degli_elenchi(): void
    {
        $maria = $this->utenteCon('Consigliere');
        $altro = Movimento::factory()->create(['conto_id' => $this->festa->id, 'descrizione' => 'Senza note']);
        $this->scrivi($maria, 'Una');
        $this->scrivi($maria, 'Due');

        $this->actingAs($maria)->get('/movimenti')->assertOk()
            ->assertSee('data-note="2"', false)->assertSee('data-note="0"', false);
        $this->assertSame(0, $altro->note()->count());
    }

    public function test_una_nota_non_cambia_la_versione_su_cui_si_conferma(): void
    {
        $prima = Classificazione::versione($this->movimento);
        $this->scrivi($this->utenteCon('Consigliere'), 'Solo una nota.');
        $this->assertSame($prima, Classificazione::versione($this->movimento->refresh()));
    }

    // --- Modifica e cancellazione entro 24 ore ---

    public function test_l_autore_modifica_entro_24_ore_e_poi_non_piu(): void
    {
        $maria = $this->utenteCon('Consigliere', [], ['name' => 'Maria Rossi']);
        $this->scrivi($maria, 'Prima versione');
        $nota = Nota::sole();

        $this->travelTo($nota->created_at->copy()->addHours(23)->addMinutes(59));
        $this->actingAs($maria)->put("/note/{$nota->id}", ['testo' => 'Seconda versione'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Seconda versione', $nota->refresh()->testo);
        $registro = RegistroModifica::where('azione', 'modificata')->sole();
        $this->assertSame(['Prima versione', 'Seconda versione'], [$registro->prima['testo'], $registro->dopo['testo']]);
        $this->actingAs($maria)->get("/movimenti/{$this->movimento->id}/note")->assertSee('modificata');
        $this->actingAs($this->utenteCon('Amministratore'))->get('/registro?oggetto=nota')->assertOk()->assertSee('ha modificato');

        $this->travelTo($nota->created_at->copy()->addHours(24)->addMinute());
        $this->actingAs($maria)->put("/note/{$nota->id}", ['testo' => 'Troppo tardi'])->assertForbidden();
        $this->actingAs($maria)->delete("/note/{$nota->id}")->assertForbidden();
        $this->assertSame('Seconda versione', $nota->refresh()->testo);
        $this->assertNull($nota->eliminata_il);
    }

    public function test_cancellare_lascia_il_segno_e_il_testo_nel_registro(): void
    {
        $maria = $this->utenteCon('Consigliere', [], ['name' => 'Maria Rossi']);
        $this->scrivi($maria, 'Scritto per errore');
        $nota = Nota::sole();

        $this->actingAs($maria)->delete("/note/{$nota->id}")->assertRedirect();

        $this->actingAs($maria)->get("/movimenti/{$this->movimento->id}/note")->assertOk()
            ->assertSee('Nota eliminata da Maria Rossi.')->assertDontSee('Scritto per errore');
        $this->actingAs($maria)->get('/movimenti')->assertSee('data-note="0"', false);
        $this->assertSame('Scritto per errore', RegistroModifica::where('azione', 'eliminata')->sole()->prima['testo']);
    }

    public function test_nessun_altro_modifica_o_cancella_la_nota_di_un_autore(): void
    {
        $this->scrivi($this->utenteCon('Consigliere'), 'Mia');
        $nota = Nota::sole();

        foreach (['Tesoriere', 'Amministratore'] as $ruolo) {
            $altro = $this->utenteCon($ruolo);
            $this->actingAs($altro)->put("/note/{$nota->id}", ['testo' => 'Altrui'])->assertForbidden();
            $this->actingAs($altro)->delete("/note/{$nota->id}")->assertForbidden();
        }
        $this->assertSame('Mia', $nota->refresh()->testo);
    }

    // --- Oscuramento ---

    public function test_l_amministratore_oscura_con_motivazione_e_il_testo_resta_nel_registro(): void
    {
        $maria = $this->utenteCon('Consigliere');
        $admin = $this->utenteCon('Amministratore', [], ['name' => 'Anna Bianchi']);
        $this->scrivi($maria, 'Il suo IBAN è IT00X');
        $nota = Nota::sole();

        $this->actingAs($this->utenteCon('Tesoriere'))->post("/note/{$nota->id}/oscuramento", ['motivazione' => 'Dati personali'])->assertForbidden();
        $this->actingAs($maria)->post("/note/{$nota->id}/oscuramento", ['motivazione' => 'Dati personali'])->assertForbidden();
        $this->actingAs($admin)->post("/note/{$nota->id}/oscuramento", ['motivazione' => ''])->assertSessionHasErrors('motivazione');
        $this->assertNull($nota->refresh()->oscurata_il);

        $this->actingAs($admin)->post("/note/{$nota->id}/oscuramento", ['motivazione' => 'Dati personali'])->assertRedirect();

        $this->actingAs($maria)->get("/movimenti/{$this->movimento->id}/note")
            ->assertDontSee('IT00X')->assertSee('Nota oscurata da Anna Bianchi: Dati personali');
        $registro = RegistroModifica::where('azione', 'oscurata')->sole();
        $this->assertSame(['Il suo IBAN è IT00X', 'Dati personali'], [$registro->prima['testo'], $registro->motivazione]);
        $this->actingAs($admin)->get('/registro?oggetto=nota')->assertOk()->assertSee('IT00X');
        // L'autore non può più correggerla, e un secondo oscuramento non scrive una seconda riga.
        $this->actingAs($maria)->put("/note/{$nota->id}", ['testo' => 'Riscritta'])->assertForbidden();
        $this->actingAs($admin)->post("/note/{$nota->id}/oscuramento", ['motivazione' => 'Ancora'])->assertRedirect();
        $this->assertSame(1, RegistroModifica::where('azione', 'oscurata')->count());
    }

    // --- Dubbio ---

    public function test_il_dubbio_e_una_nota_contrassegnata_e_tinge_la_riga_in_ogni_elenco(): void
    {
        $tina = $this->utenteCon('Tesoriere');
        $altro = Movimento::factory()->create(['conto_id' => $this->festa->id, 'descrizione' => 'Senza dubbi']);
        $this->scrivi($tina, 'Una nota qualunque', [], $altro);
        $this->scrivi($tina, 'Chiedere al commercialista', ['dubbio' => '1']);

        $this->assertTrue($this->movimento->refresh()->inDubbio());
        $this->assertFalse($altro->refresh()->inDubbio());
        $this->actingAs($tina)->get('/movimenti')->assertOk()->assertSee('scheda in-dubbio', false)->assertSee('In dubbio');
        $this->assertSame(1, substr_count($this->actingAs($tina)->get('/movimenti')->getContent(), 'scheda in-dubbio'));
    }

    public function test_la_conferma_chiude_il_dubbio_e_la_nota_resta(): void
    {
        $tina = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        Classificazione::salva($tina, $this->movimento, null, [['tipologia_id' => \App\Models\Tipologia::where('nome', 'Materiali e beni di consumo')->value('id'), 'iniziativa_id' => \App\Models\Iniziativa::where('nome', 'Carnevale')->value('id')]]);
        $this->scrivi($tina, 'La tipologia è giusta?', ['dubbio' => '1']);
        $this->assertTrue($this->movimento->refresh()->inDubbio());

        $this->actingAs($tina)->post("/movimenti/{$this->movimento->id}/conferma", ['versione' => Classificazione::versione($this->movimento)])->assertSessionHasNoErrors();

        $nota = Nota::sole();
        $this->assertFalse($this->movimento->refresh()->inDubbio());
        $this->assertSame($tina->id, $nota->chiuso_da);
        $this->assertSame(1, RegistroModifica::where('azione', 'dubbio_chiuso')->count());
        $this->actingAs($tina)->get("/movimenti/{$this->movimento->id}/note")->assertSee('La tipologia è giusta?')->assertSee('Dubbio chiuso');
    }

    /** Quello che il modulo manda: gli id dei dubbi aperti che la pagina mostrava. */
    private function dubbiVisti(?Movimento $movimento = null): array
    {
        return ['dubbi_visti' => ($movimento ?? $this->movimento)->note()->dubbioAperto()->pluck('id')->all()];
    }

    public function test_togliere_il_dubbio_chiude_solo_quelli_che_l_utente_ha_visto(): void
    {
        $this->scrivi($this->utenteCon('Consigliere'), 'Il primo dubbio', ['dubbio' => '1']);
        $visti = $this->dubbiVisti();
        $this->scrivi($this->utenteCon('Consigliere'), 'Nato dopo che la pagina era aperta', ['dubbio' => '1']);

        $this->actingAs($this->utenteCon('Tesoriere'))->post("/movimenti/{$this->movimento->id}/dubbio/chiusura", $visti)->assertRedirect();

        $this->assertTrue($this->movimento->refresh()->inDubbio());
        $this->assertSame(['Nato dopo che la pagina era aperta'], $this->movimento->note()->dubbioAperto()->pluck('testo')->all());
        $this->actingAs($this->utenteCon('Tesoriere'))->post("/movimenti/{$this->movimento->id}/dubbio/chiusura")->assertSessionHasErrors('dubbi_visti');
    }

    public function test_togliere_il_dubbio_e_di_chi_conferma_e_cancellare_la_nota_lo_toglie(): void
    {
        $maria = $this->utenteCon('Consigliere');
        $this->scrivi($maria, 'Boh', ['dubbio' => '1']);

        $this->actingAs($maria)->post("/movimenti/{$this->movimento->id}/dubbio/chiusura", $this->dubbiVisti())->assertForbidden();
        $this->actingAs($this->utenteCon('Tesoriere'))->post("/movimenti/{$this->movimento->id}/dubbio/chiusura", $this->dubbiVisti())->assertRedirect();
        $this->assertFalse($this->movimento->refresh()->inDubbio());

        $this->scrivi($maria, 'Di nuovo', ['dubbio' => '1']);
        $this->assertTrue($this->movimento->refresh()->inDubbio());
        $this->actingAs($maria)->delete('/note/'.Nota::latest('id')->value('id'));
        $this->assertFalse($this->movimento->refresh()->inDubbio());
    }

    public function test_un_dubbio_oscurato_resta_aperto(): void
    {
        $this->scrivi($this->utenteCon('Consigliere'), 'Dubbio con dati personali', ['dubbio' => '1']);
        $this->actingAs($this->utenteCon('Amministratore'))->post('/note/'.Nota::sole()->id.'/oscuramento', ['motivazione' => 'Dati personali']);
        $this->assertTrue($this->movimento->refresh()->inDubbio());
    }

    public function test_un_dubbio_aperto_cambia_la_versione_e_la_conferma_sulla_versione_di_prima_non_lo_chiude(): void
    {
        $tina = $this->utenteCon('Tesoriere');
        Classificazione::salva($tina, $this->movimento, null, [['tipologia_id' => \App\Models\Tipologia::where('nome', 'Materiali e beni di consumo')->value('id'), 'iniziativa_id' => \App\Models\Iniziativa::where('nome', 'Carnevale')->value('id')]]);
        $vista = Classificazione::versione($this->movimento->refresh());
        $this->scrivi($this->utenteCon('Consigliere'), 'Aspetta, non torna.', ['dubbio' => '1']);

        $this->assertNotSame($vista, Classificazione::versione($this->movimento->refresh()));
        $this->actingAs($tina)->post("/movimenti/{$this->movimento->id}/conferma", ['versione' => $vista])->assertSessionHasErrors('conferma');
        $this->assertTrue($this->movimento->refresh()->inDubbio());
        $this->assertSame('proposto', $this->movimento->stato);
        // Rileggendo il movimento (ora con il dubbio) la conferma passa e lo chiude.
        $this->actingAs($tina)->post("/movimenti/{$this->movimento->id}/conferma", ['versione' => Classificazione::versione($this->movimento)])->assertSessionHasNoErrors();
        $this->assertFalse($this->movimento->refresh()->inDubbio());
    }

    public function test_la_pagina_delle_note_offre_la_chiusura_del_dubbio_a_chi_conferma(): void
    {
        $this->scrivi($this->utenteCon('Consigliere'), 'Boh', ['dubbio' => '1']);
        $this->movimento->forceFill(['stato' => 'confermato'])->save(); // un confermato fuori da un import: la revisione non lo mostra

        $url = "/movimenti/{$this->movimento->id}/dubbio/chiusura";
        $this->actingAs($this->utenteCon('Consigliere'))->get("/movimenti/{$this->movimento->id}/note")->assertOk()->assertDontSee($url);
        $tina = $this->utenteCon('Tesoriere');
        $this->actingAs($tina)->get("/movimenti/{$this->movimento->id}/note")->assertOk()->assertSee($url);
        $this->actingAs($tina)->post($url, $this->dubbiVisti())->assertRedirect();
        $this->assertFalse($this->movimento->refresh()->inDubbio());
        $this->actingAs($tina)->get("/movimenti/{$this->movimento->id}/note")->assertDontSee($url);
    }

    public function test_chi_ha_scritto_la_nota_a_un_ruolo_di_cui_fa_parte_non_la_segna_vista(): void
    {
        $tina = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->scrivi($tina, '@Tesoriere ricordo a tutti lo scontrino');
        $menzione = \App\Models\Menzione::sole();

        $this->actingAs($tina)->post("/menzioni/{$menzione->id}/fatto")->assertForbidden();
        $this->assertNull($menzione->refresh()->vista_il);
    }

    public function test_la_menzione_a_un_ruolo_senza_vedere_non_si_crea_e_la_home_chiede_vedere(): void
    {
        $this->utenteCon(Ruolo::create(['nome' => 'Solo note', 'permessi' => ['note']]));
        $this->scrivi($this->utenteCon('Tesoriere'), '@Solo note ci sei?');
        $this->assertSame(0, \App\Models\Menzione::count());

        $maria = $this->utenteCon('Consigliere', [], ['name' => 'Maria Rossi']);
        $this->scrivi($this->utenteCon('Tesoriere'), '@Maria Rossi guarda');
        $solo = $this->utenteCon(Ruolo::create(['nome' => 'Solo note 2', 'permessi' => ['note']]));
        \App\Models\Menzione::sole()->update(['user_id' => $solo->id]); // menzione rimasta a chi ha perso `vedere`
        $this->actingAs($solo)->get('/')->assertOk()->assertDontSee('Da vedere');
        $this->actingAs($maria)->get('/')->assertOk();
    }

    // --- Menzioni ---

    public function test_la_menzione_a_un_utente_attivo_va_in_da_vedere_e_a_uno_disattivato_resta_testo(): void
    {
        $maria = $this->utenteCon('Consigliere', [], ['name' => 'Maria Rossi']);
        $tina = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $gino = $this->utenteCon('Consigliere', ['scade_il' => '2026-05-31'], ['name' => 'Gino Neri']);
        $nessuno = User::factory()->create(['name' => 'Ugo Senzaruolo']);

        $this->scrivi($maria, '@tina verdi guarda questo. @Gino Neri e @Ugo Senzaruolo no.');

        $nota = Nota::sole();
        $this->assertSame([$tina->id], $nota->menzioni()->pluck('user_id')->all());
        $this->actingAs($tina)->get('/')->assertOk()->assertSee('Da vedere')->assertSee('Maria Rossi')->assertSee('Ghiaccio per la festa');
        $this->actingAs($gino)->get('/')->assertDontSee('Da vedere');
        // Chi si menziona da solo non si trova la propria nota nel riquadro.
        $this->scrivi($maria, 'Mi ricordo @Maria Rossi');
        $this->assertSame(1, \App\Models\Menzione::count());
        $this->actingAs($maria)->get('/')->assertDontSee('Da vedere');
    }

    public function test_una_menzione_a_un_ruolo_la_chiude_la_prima_persona_che_preme_fatto(): void
    {
        $maria = $this->utenteCon('Consigliere', [], ['name' => 'Maria Rossi']);
        $tina = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $tommaso = $this->utenteCon('Tesoriere', [], ['name' => 'Tommaso Gialli']);
        $this->scrivi($maria, '@Tesoriere puoi guardare lo scontrino?');
        $menzione = \App\Models\Menzione::sole();

        $this->actingAs($tommaso)->get('/')->assertSee('Da vedere');
        $this->actingAs($tina)->get('/')->assertSee('Da vedere');
        $this->actingAs($maria)->post("/menzioni/{$menzione->id}/fatto")->assertForbidden();

        $this->actingAs($tina)->post("/menzioni/{$menzione->id}/fatto")->assertRedirect();

        $this->actingAs($tommaso)->get('/')->assertDontSee('Da vedere');
        $this->actingAs($tommaso)->post("/menzioni/{$menzione->id}/fatto")->assertRedirect(); // già vista: non cambia chi
        $this->actingAs($maria)->get("/movimenti/{$this->movimento->id}/note")->assertSee('vista da Tina Verdi il 01/06/2026 10:00');
        $this->assertSame($tina->id, $menzione->refresh()->vista_da);
    }

    public function test_rispondere_nel_movimento_chiude_la_menzione(): void
    {
        $maria = $this->utenteCon('Consigliere');
        $tina = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->scrivi($maria, '@Tina Verdi puoi guardare?');
        $altroMovimento = Movimento::factory()->create(['conto_id' => $this->festa->id]);

        $this->scrivi($tina, 'Su un altro movimento', [], $altroMovimento);
        $this->assertNull(\App\Models\Menzione::sole()->vista_il);
        $this->scrivi($tina, 'Fatto, tutto a posto.');

        $this->assertSame($tina->id, \App\Models\Menzione::sole()->vista_da);
        $this->actingAs($tina)->get('/')->assertDontSee('Da vedere');
    }

    public function test_scrivere_ancora_non_chiude_la_propria_menzione_a_un_ruolo(): void
    {
        $tina = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $tommaso = $this->utenteCon('Tesoriere', [], ['name' => 'Tommaso Neri']);
        $this->scrivi($tina, '@Tesoriere chiedere lo scontrino');
        $this->scrivi($tina, 'Aggiungo un dettaglio.');

        $this->assertNull(\App\Models\Menzione::sole()->vista_il);
        $this->actingAs($tommaso)->get('/')->assertSee('Da vedere')->assertSee('chiedere lo scontrino');
    }

    public function test_un_ruolo_senza_il_permesso_note_non_si_menziona(): void
    {
        $this->utenteCon(Ruolo::create(['nome' => 'Solo vista', 'permessi' => ['vedere']]));
        $this->scrivi($this->utenteCon('Tesoriere'), '@Solo vista ci sei?');

        $this->assertSame(0, \App\Models\Menzione::count());
    }

    public function test_riclassificare_un_confermato_chiude_il_dubbio(): void
    {
        $tina = $this->utenteCon('Tesoriere');
        $carnevale = \App\Models\Iniziativa::where('nome', 'Carnevale')->value('id');
        $tipologia = fn (string $nome) => \App\Models\Tipologia::where('nome', $nome)->value('id');
        Classificazione::salva($tina, $this->movimento, null, [['tipologia_id' => $tipologia('Materiali e beni di consumo'), 'iniziativa_id' => $carnevale]]);
        Classificazione::conferma($tina, $this->movimento, Classificazione::versione($this->movimento->refresh()));
        $this->scrivi($tina, 'Forse era un affitto', ['dubbio' => '1']);
        $this->assertTrue($this->movimento->refresh()->inDubbio());

        Classificazione::salva($tina, $this->movimento, null, [['tipologia_id' => $tipologia('Affitti e noleggi'), 'iniziativa_id' => $carnevale]]);

        $this->assertFalse($this->movimento->refresh()->inDubbio());
    }

    public function test_una_nota_eliminata_oscurata_o_cambiata_toglie_la_menzione(): void
    {
        $maria = $this->utenteCon('Consigliere');
        $tina = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->scrivi($maria, '@Tina Verdi una');
        $this->scrivi($maria, '@Tina Verdi due');
        $this->scrivi($maria, '@Tina Verdi tre');
        [$una, $due, $tre] = Nota::orderBy('id')->get()->all();

        $this->actingAs($maria)->put("/note/{$una->id}", ['testo' => 'Non serve più chiamarti'])->assertRedirect();
        $this->actingAs($maria)->delete("/note/{$due->id}");
        $this->actingAs($this->utenteCon('Amministratore'))->post("/note/{$tre->id}/oscuramento", ['motivazione' => 'Dati personali']);

        $this->assertSame(0, $una->menzioni()->count()); // il testo nuovo non la chiama più
        $this->actingAs($tina)->get('/')->assertDontSee('Da vedere');
    }

    public function test_chi_ha_gia_visto_una_menzione_resta_visto_dopo_una_modifica(): void
    {
        $maria = $this->utenteCon('Consigliere');
        $tina = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->scrivi($maria, '@Tina Verdi guarda');
        $this->actingAs($tina)->post('/menzioni/'.\App\Models\Menzione::sole()->id.'/fatto');

        $this->actingAs($maria)->put('/note/'.Nota::sole()->id, ['testo' => '@Tina Verdi guarda bene'])->assertRedirect();

        $this->assertNotNull(\App\Models\Menzione::sole()->vista_il);
    }

    public function test_il_testo_delle_note_e_dei_riquadri_e_sempre_escapato(): void
    {
        $maria = $this->utenteCon('Consigliere', [], ['name' => 'Maria Rossi']);
        $tina = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->scrivi($maria, '@Tina Verdi <img src=x onerror=alert(1)>');

        $this->actingAs($tina)->get('/')->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)->assertDontSee('<img src=x', false);
    }

    // --- Seriamente: i permessi si ricontrollano a riga bloccata ---

    public function test_le_scritture_rileggono_i_permessi_dopo_il_lock_del_movimento(): void
    {
        $maria = $this->utenteCon('Consigliere');
        $tina = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->scrivi($maria, '@Tina Verdi ciao');
        $nota = Nota::sole();
        $menzione = \App\Models\Menzione::sole();
        $admin = $this->utenteCon('Amministratore');

        $azioni = [
            fn () => $this->actingAs($maria)->post("/movimenti/{$this->movimento->id}/note", ['testo' => 'Altra']),
            fn () => $this->actingAs($maria)->put("/note/{$nota->id}", ['testo' => '@Tina Verdi ciao ciao']),
            fn () => $this->actingAs($tina)->post("/menzioni/{$menzione->id}/fatto"),
            fn () => $this->actingAs($admin)->post("/note/{$nota->id}/oscuramento", ['motivazione' => 'x']),
            fn () => $this->actingAs($maria)->delete("/note/{$nota->id}"),
        ];
        foreach ($azioni as $azione) {
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            $azione();
            $query = array_column(\DB::getQueryLog(), 'query');
            $lock = array_key_first(array_filter($query, fn ($q) => str_contains($q, 'from `movimenti`') && str_contains($q, 'for update')));
            $permessi = array_key_last(array_filter($query, fn ($q) => str_contains($q, 'from `assegnazioni`')));
            $this->assertNotNull($lock, 'nessun lock sul movimento');
            $this->assertGreaterThan($lock, $permessi, 'i permessi non sono riletti dopo il lock');
            // Prima del lock del movimento nessuna scrittura: il lock è la prima cosa che la transazione fa.
            $this->assertEmpty(array_filter(array_slice($query, 0, $lock), fn ($q) => preg_match('/^(insert|update|delete)/', $q)));
        }
    }
}
