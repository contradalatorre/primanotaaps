<?php

namespace Tests\Feature;

use App\Esercizio\Prospetto;
use App\Esercizio\Verifiche;
use App\Import\Foglio;
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
        ] + ($area === 'A' ? [
            'iniziativa_id' => Iniziativa::where('nome', 'Festa della Torre')->firstOrFail()->id,
            'attivita_id' => Attivita::where('nome', 'Lettera i (art. 5 CTS)')->firstOrFail()->id,
        ] : []));
    }

    /** 90,00 di sponsorizzazioni (B) su 250,00 di entrate e 200,00 di uscite: il 30% non regge (36%), il 66% sì (45%). */
    private function esercizioConB(string $data = '2026-03-01'): void
    {
        $this->imputa('entrata', 9000, $data, 'Sponsorizzazioni e pubblicità', 'B');
        $this->imputa('entrata', 16000, $data, 'Quote associative');
        $this->imputa('uscita', 20000, $data, 'Utenze', 'E');
    }

    private function confermaCriterio(string $criterio, int $anno = 2026)
    {
        return $this->come($this->tesoriere)->post("/esercizi/$anno/test/criterio", ['criterio' => $criterio]);
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
        $this->riverificaAccountGoogle();
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

    public function test_l_approvazione_scrive_lo_stato_del_2bis_e_dal_2027_la_tolleranza_del_6_per_cento_si_conta(): void
    {
        // Corrispettivi 103,00 contro costi 100,00: oltre il pareggio ma entro il 6%.
        $this->imputa('entrata', 10300, '2026-06-01', 'Somministrazione');
        $this->imputa('uscita', 10000, '2026-06-02', 'Acquisti per la somministrazione');
        $this->assertFalse(Verifiche::pagina(2027)['catena']['areaA']['tolleranza']['disponibile'], 'Senza un esito approvato non si conta');

        $this->approva()->assertRedirect();

        $this->assertSame('tolleranza', VersioneEsercizio::sole()->stato_2bis);
        $tolleranza = Verifiche::pagina(2027)['catena']['areaA']['tolleranza'];
        $this->assertTrue($tolleranza['disponibile']);
        $this->assertSame(1, $tolleranza['usati']);

        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore'])->assertRedirect();
        $this->assertFalse(Verifiche::pagina(2027)['catena']['areaA']['tolleranza']['disponibile'], 'Riaperto, l’esito approvato non vale più');
    }

    public function test_dopo_una_secondarieta_non_rispettata_l_anno_dopo_usa_lo_stesso_criterio_con_la_soglia_ridotta(): void
    {
        $this->esercizioConB();
        $this->confermaCriterio('a')->assertSessionHasNoErrors();
        $this->approva()->assertRedirect();

        // 90 su 250 è il 36%: sei punti oltre il 30%, il 24% l'anno dopo.
        $v = VersioneEsercizio::sole();
        $this->assertSame('non_rispettato', $v->esito_secondarieta);
        $this->assertSame(2400, $v->soglia_rientro_centesimi_di_punto);

        $this->come($this->tesoriere)->post('/esercizi/2027/apertura')->assertRedirect();
        $this->imputa('entrata', 2500, '2027-03-01', 'Sponsorizzazioni e pubblicità', 'B');
        $this->imputa('entrata', 7500, '2027-03-01', 'Quote associative');
        $s = Verifiche::secondarieta(2027);
        $this->assertSame('a', $s['proposto']);
        $this->assertSame(24, $s['criteri']['a']['soglia']);
        $this->assertSame('non_rispettato', $s['esito'], '25% è sotto il 30% di legge ma sopra il 24% del rientro');
        $this->assertSame(66, $s['criteri']['b']['soglia']);

        $this->come($this->tesoriere)->get('/esercizi/2027/test')->assertOk()->assertSeeText('24%');
        $this->confermaCriterio('b', 2027)->assertSessionHasErrors('criterio');
        $this->confermaCriterio('a', 2027)->assertSessionHasNoErrors();

        // Riaprire il 2026 non cancella l'esito approvato: il rientro resta finché non c'è una nuova approvazione.
        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore'])->assertRedirect();
        $this->assertSame(24, Verifiche::secondarieta(2027)['criteri']['a']['soglia']);
    }

    /** Il rientro non si aggira con un criterio confermato prima dell'approvazione dell'anno prima: la soglia ridotta vale, mai a favore dell'ente. */
    public function test_un_criterio_confermato_prima_dell_approvazione_dell_anno_prima_non_evita_il_rientro(): void
    {
        $this->esercizioConB();
        $this->confermaCriterio('a')->assertSessionHasNoErrors();
        $this->come($this->tesoriere)->post('/esercizi/2027/apertura')->assertRedirect();
        $this->imputa('entrata', 2500, '2027-03-01', 'Sponsorizzazioni e pubblicità', 'B');
        $this->imputa('entrata', 7500, '2027-03-01', 'Quote associative');
        $this->confermaCriterio('b', 2027)->assertSessionHasNoErrors(); // il 2026 non è ancora approvato: il 66% si può scegliere

        $this->approva()->assertRedirect(); // il 2026 non rispetta il 30%: il 2027 rientra con lo stesso criterio, il 30%

        $s = Verifiche::secondarieta(2027);
        $this->assertSame('a', $s['confermato']);
        $this->assertSame(24, $s['criteri']['a']['soglia']);
        $this->assertSame('non_rispettato', $s['esito']);

        $this->travelTo('2028-02-01 10:00');
        $this->riverificaAccountGoogle();
        $this->approva(2027, '2028-01-20')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('a', DatiEsercizio::per(2027)->criterio_secondarieta, 'Il criterio fissato è quello del rientro');
        $this->assertSame('non_rispettato', VersioneEsercizio::where('esercizio', 2027)->sole()->esito_secondarieta);
    }

    /** Il rientro e la tolleranza del 2027 si leggono dall'esito approvato del 2026: senza il 2026 approvato il 2027 non si approva (altrimenti il rientro salterebbe). */
    public function test_un_esercizio_si_approva_solo_dopo_quello_prima(): void
    {
        $this->esercizioConB();
        $this->come($this->tesoriere)->post('/esercizi/2027/apertura')->assertRedirect();
        $this->imputa('entrata', 1000, '2027-03-01', 'Quote associative');
        $this->travelTo('2028-02-01 10:00');
        $this->riverificaAccountGoogle();

        $this->approva(2027, '2028-01-20')->assertSessionHasErrors('stato');
        $this->assertSame(0, VersioneEsercizio::count());
        $this->assertSame('aperto', DatiEsercizio::per(2027)->stato);

        $this->confermaCriterio('a')->assertSessionHasNoErrors();
        $this->approva(2026, '2027-04-20')->assertRedirect();
        $this->approva(2027, '2028-01-20')->assertRedirect()->assertSessionHasNoErrors();

        // L'esercizio prima riaperto torna a bloccare quello dopo, finché non si approva di nuovo.
        $this->come($this->amministratore)->post('/esercizi/2027/riapertura', ['motivazione' => 'Errore'])->assertRedirect();
        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore'])->assertRedirect();
        $this->approva(2027, '2028-01-21')->assertSessionHasErrors('stato');
    }

    /** Si riapre in ordine inverso: con l'anno dopo ancora approvato, correggere questo farebbe saltare il rientro e la segnalazione che quello ha misurato. */
    public function test_un_esercizio_non_si_riapre_finche_quello_dopo_e_approvato(): void
    {
        $this->esercizioConB();
        $this->come($this->tesoriere)->post('/esercizi/2027/apertura')->assertRedirect();
        $this->imputa('entrata', 1000, '2027-03-01', 'Quote associative');
        $this->travelTo('2028-02-01 10:00');
        $this->riverificaAccountGoogle();
        $this->confermaCriterio('b')->assertSessionHasNoErrors(); // il 66% regge: il 2026 è approvato come rispettato
        $this->approva(2026, '2027-04-20')->assertRedirect();
        $this->approva(2027, '2028-01-20')->assertRedirect();

        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore'])->assertSessionHasErrors('stato');
        $this->assertSame('approvato', DatiEsercizio::per(2026)->stato);
        $this->assertSame(1, DatiEsercizio::per(2026)->versione);
        $this->assertNull(VersioneEsercizio::where(['esercizio' => 2026, 'numero' => 1])->sole()->riaperta_il);
        $this->assertSame(0, RegistroModifica::where(['oggetto_id' => 2026, 'azione' => 'riaperto'])->count());

        $this->come($this->amministratore)->post('/esercizi/2027/riapertura', ['motivazione' => 'Errore'])->assertRedirect()->assertSessionHasNoErrors();
        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('aperto', DatiEsercizio::per(2026)->stato);
    }

    public function test_dopo_uno_storico_l_esercizio_si_approva_senza_aspettare(): void
    {
        EsercizioStorico::create(['esercizio' => 2025, 'importi' => [], 'saldo_cassa' => 0, 'saldo_depositi' => 0, 'figurativi' => [], 'differenze' => []]);
        $this->esercizio2026();

        $this->approva()->assertRedirect()->assertSessionHasNoErrors();
    }

    /** Un esercizio storico (#63) non ha un criterio confermato: se un criterio non è rispettato il rientro dell'anno dopo vale (la lettura più severa, mai a favore dell'ente). */
    public function test_la_secondarieta_non_rispettata_di_uno_storico_riduce_la_soglia_dell_anno_dopo(): void
    {
        $storico = fn (array $importi) => EsercizioStorico::updateOrCreate(['esercizio' => 2025], ['importi' => $importi, 'saldo_cassa' => 0, 'saldo_depositi' => 0, 'figurativi' => ['costi' => [], 'proventi' => []], 'differenze' => []]);

        // 90 su 250: il 36% non regge il 30%, il 66% delle uscite (200) sì: il rientro è sul criterio che non regge.
        $storico(['entrate:B3' => 9000, 'entrate:A1' => 16000, 'uscite:E2' => 20000]);
        $s = Verifiche::secondarieta(2026);
        $this->assertSame(['criterio' => 'a', 'soglia' => 2400], $s['rientro']);
        $this->assertSame(24, $s['criteri']['a']['soglia']);
        $this->confermaCriterio('b')->assertSessionHasErrors('criterio');
        $this->confermaCriterio('a')->assertSessionHasNoErrors();

        $storico(['entrate:B3' => 1000, 'entrate:A1' => 24000, 'uscite:E2' => 20000]);
        $this->assertNull(Verifiche::secondarieta(2026)['rientro']);
    }

    /** Il criterio fissato alla prima approvazione resta fisso dopo la riapertura, e un esito non rispettato approvato non si dimentica: rientro e segnalazione restano. */
    public function test_dopo_la_riapertura_il_criterio_resta_e_un_nuovo_esito_non_cancella_rientro_e_segnalazione(): void
    {
        $this->esercizioConB();
        $this->confermaCriterio('a')->assertSessionHasNoErrors();
        $this->approva(2026, '2027-04-10')->assertRedirect();
        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore'])->assertRedirect();

        $this->confermaCriterio('b')->assertSessionHasErrors('criterio');
        $this->assertSame('a', DatiEsercizio::per(2026)->criterio_secondarieta);

        $this->imputa('entrata', 100000, '2026-04-01', 'Quote associative'); // la correzione: ora il 30% regge
        $this->approva(2026, '2027-04-12')->assertRedirect();
        $v2 = VersioneEsercizio::where(['esercizio' => 2026, 'numero' => 2])->sole();
        $this->assertSame('a', $v2->criterio_secondarieta);
        $this->assertSame('rispettato', $v2->esito_secondarieta);

        $this->assertSame(['criterio' => 'a', 'soglia' => 2400], Verifiche::rientro(2027));
        $this->come($this->tesoriere)->get('/')->assertOk()->assertSeeText('entro il 10/05/2027');
        $this->come($this->tesoriere)->post('/esercizi/2026/segnalazione-runts', ['data' => '2027-04-15', 'protocollo' => 'RUNTS-7'])->assertSessionHasNoErrors();
        $this->assertSame('RUNTS-7', VersioneEsercizio::where(['esercizio' => 2026, 'numero' => 1])->sole()->segnalazione_protocollo);
        $this->come($this->tesoriere)->get('/')->assertOk()->assertDontSeeText('entro il 10/05/2027');
    }

    public function test_dopo_una_secondarieta_rispettata_o_senza_approvazione_la_soglia_e_quella_di_legge(): void
    {
        $this->esercizioConB();
        $this->assertSame(30, Verifiche::secondarieta(2027)['criteri']['a']['soglia'], 'Il 2026 non è approvato: non c’è un esito su cui ridurre');

        $this->confermaCriterio('b')->assertSessionHasNoErrors(); // il 66% regge: rispettato
        $this->approva()->assertRedirect();

        $this->assertNull(VersioneEsercizio::sole()->soglia_rientro_centesimi_di_punto);
        $this->assertSame(30, Verifiche::secondarieta(2027)['criteri']['a']['soglia']);
        $this->assertNull(Verifiche::secondarieta(2027)['rientro']);
    }

    public function test_il_promemoria_della_segnalazione_al_runts_resta_in_home_finche_non_si_registra_l_invio(): void
    {
        $this->esercizioConB();
        $this->confermaCriterio('a');
        $this->approva(2026, '2027-04-10')->assertRedirect();

        $this->come($this->tesoriere)->get('/')->assertOk()
            ->assertSeeText('Segnalazione al RUNTS')->assertSeeText('entro il 10/05/2027')
            ->assertSeeText('Bozza del testo')->assertSeeText('Contrada La Torre')->assertSeeText('36,00%')->assertSeeText('superamento di 6,00 punti percentuali')->assertSeeText('soglia ridotta al 24%');
        $this->come($this->lettoreEsterno())->get('/')->assertOk()->assertDontSeeText('Segnalazione al RUNTS');

        $this->come($this->tesoriere)->post('/esercizi/2026/segnalazione-runts', ['data' => '2027-04-09'])->assertSessionHasErrors('data'); // prima dell'approvazione
        $this->come($this->tesoriere)->post('/esercizi/2026/segnalazione-runts', ['data' => '2027-04-15', 'protocollo' => 'RUNTS-123'])->assertSessionHasNoErrors();

        $this->assertSame('RUNTS-123', VersioneEsercizio::sole()->segnalazione_protocollo);
        $this->come($this->tesoriere)->get('/')->assertOk()->assertDontSeeText('entro il 10/05/2027');
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'esercizio', 'oggetto_id' => 2026, 'azione' => 'segnalazione_runts']);
    }

    public function test_la_riapertura_non_cancella_l_obbligo_della_segnalazione(): void
    {
        $this->esercizioConB();
        $this->confermaCriterio('a');
        $this->approva(2026, '2027-04-10')->assertRedirect();
        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore su un giustificativo'])->assertRedirect();

        $this->come($this->tesoriere)->get('/')->assertOk()->assertSeeText('entro il 10/05/2027');
        $this->come($this->tesoriere)->post('/esercizi/2026/segnalazione-runts', ['data' => '2027-04-15', 'protocollo' => 'RUNTS-9'])->assertSessionHasNoErrors();
        $this->assertSame('RUNTS-9', VersioneEsercizio::sole()->segnalazione_protocollo);
        $this->come($this->tesoriere)->get('/')->assertOk()->assertDontSeeText('entro il 10/05/2027');
    }

    public function test_con_la_secondarieta_rispettata_non_c_e_nessun_promemoria(): void
    {
        $this->esercizioConB();
        $this->confermaCriterio('b');
        $this->approva()->assertRedirect();

        $this->come($this->tesoriere)->get('/')->assertOk()->assertDontSeeText('Segnalazione al RUNTS');
        $this->come($this->tesoriere)->post('/esercizi/2026/segnalazione-runts', ['data' => '2027-04-20'])->assertSessionHasErrors('stato');
    }

    private function lettoreEsterno(): User
    {
        return $this->utenteCon('Lettore esterno', ['scade_il' => now()->addMonth()], ['email' => 'revisore@esterno.example']);
    }

    public function test_un_esercizio_aperto_e_provvisorio_e_senza_differenze(): void
    {
        $this->esercizio2026();

        foreach (['/esercizi/2026/modello-d', '/esercizi/2026/rendiconto', '/esercizi/2026/test'] as $pagina) {
            $this->come($this->tesoriere)->get($pagina)->assertOk()->assertSeeText('Provvisorio – aggiornato al 20/04/2027 10:00')->assertDontSeeText('Differenze dalla versione approvata');
        }
    }

    public function test_dopo_l_approvazione_i_prospetti_mostrano_le_differenze_dalla_versione_approvata(): void
    {
        $this->esercizio2026();
        $this->approva()->assertRedirect();

        foreach (['/esercizi/2026/modello-d', '/esercizi/2026/rendiconto', '/esercizi/2026/test'] as $pagina) {
            $this->come($this->tesoriere)->get($pagina)->assertOk()
                ->assertSeeText('Approvato il 20/04/2027 · versione 1')->assertDontSeeText('Provvisorio')
                ->assertSeeText('Nessuna differenza');
        }

        // Una correzione tardiva: 20,00 € di entrate in più nell'area A.
        $this->imputa('entrata', 2000, '2026-07-01', 'Somministrazione');

        // Il Modello D e i test mostrano le voci del Modello D, il rendiconto le sue cifre (per attività, iniziativa, tipologia e conto).
        foreach (['/esercizi/2026/modello-d' => 'Totale entrate della gestione', '/esercizi/2026/rendiconto' => 'Somministrazione · Cassa contanti', '/esercizi/2026/test' => 'Totale entrate della gestione'] as $pagina => $riga) {
            $this->come($this->tesoriere)->get($pagina)->assertOk()
                ->assertSeeText('Differenze dalla versione approvata')
                ->assertSeeText($riga)->assertSeeText('800,00')->assertSeeText('820,00')->assertSeeText('20,00')
                ->assertDontSeeText('Nessuna differenza');
        }
    }

    public function test_le_esportazioni_portano_la_versione_dell_esercizio_e_le_differenze(): void
    {
        $this->esercizio2026();
        $xlsx = fn () => Foglio::leggi($this->come($this->tesoriere)->get('/esercizi/2026/modello-d/xlsx')->assertOk()->getContent())['Modello D'][3];

        $this->assertSame(['Provvisorio – aggiornato al 20/04/2027 10:00. Solo le imputazioni confermate.'], $xlsx());

        $this->approva()->assertRedirect();
        $this->assertSame(['Approvato il 20/04/2027 · versione 1 · aggiornato al 20/04/2027 10:00. Solo le imputazioni confermate.'], $xlsx());

        $this->imputa('entrata', 2000, '2026-07-01', 'Somministrazione');
        $this->assertSame(['Approvato il 20/04/2027 · versione 1 · aggiornato al 20/04/2027 10:00. Solo le imputazioni confermate. Ci sono differenze dalla versione approvata.'], $xlsx());

        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore'])->assertRedirect();
        $this->assertSame(['Provvisorio – aggiornato al 20/04/2027 10:00. Solo le imputazioni confermate.'], $xlsx());
    }

    /** Il rendiconto per l'assemblea è nella versione approvata: un'imputazione che cambia solo iniziativa muove il rendiconto e non il Modello D. */
    public function test_il_rendiconto_si_confronta_con_la_versione_approvata(): void
    {
        $imputazione = $this->imputa('entrata', 80000, '2026-06-01', 'Somministrazione');
        $this->approva()->assertRedirect();
        $this->assertArrayHasKey('rendiconto', VersioneEsercizio::sole()->istantanea);

        $this->come($this->tesoriere)->get('/esercizi/2026/rendiconto')->assertOk()->assertSeeText('Nessuna differenza');
        $imputazione->update(['iniziativa_id' => Iniziativa::where('nome', 'Si può fare')->firstOrFail()->id]); // stessa tipologia e stessa voce

        $this->come($this->tesoriere)->get('/esercizi/2026/modello-d')->assertOk()->assertSeeText('Nessuna differenza');
        $this->come($this->tesoriere)->get('/esercizi/2026/rendiconto')->assertOk()->assertDontSeeText('Nessuna differenza')->assertSeeText('Si può fare')->assertSeeText('Festa della Torre');
        $intestazione = fn (string $prospetto, string $foglio) => Foglio::leggi($this->come($this->tesoriere)->get("/esercizi/2026/$prospetto/xlsx")->assertOk()->getContent())[$foglio][3][0];
        $this->assertStringContainsString('Ci sono differenze dalla versione approvata', $intestazione('rendiconto', 'Rendiconto'));
        $this->assertStringNotContainsString('Ci sono differenze', $intestazione('modello-d', 'Modello D'));
    }

    /** Con `?proposte=1` il prospetto non è quello approvato (ci sono anche le proposte): esce provvisorio. */
    public function test_l_esportazione_con_le_proposte_di_un_esercizio_approvato_esce_provvisoria(): void
    {
        $this->esercizio2026();
        $this->approva()->assertRedirect();

        $riga = Foglio::leggi($this->come($this->tesoriere)->get('/esercizi/2026/modello-d/xlsx?proposte=1')->assertOk()->getContent())['Modello D'][3];

        $this->assertSame(['Provvisorio – aggiornato al 20/04/2027 10:00. Con anche le imputazioni proposte, ancora da confermare.'], $riga);
    }

    public function test_chi_modifica_un_movimento_di_un_esercizio_approvato_e_avvisato(): void
    {
        $imputazione = $this->imputa('entrata', 80000, '2026-06-01', 'Somministrazione');
        $movimento = $imputazione->movimento;
        $movimento->forceFill(['manuale' => true, 'stato' => 'proposto', 'inserito_da' => $this->tesoriere->id])->save(); // la modifica dei dati è dell'autore di un movimento a mano non ancora confermato
        $pagine = ["/movimenti/{$movimento->id}/classificazione", "/movimenti/{$movimento->id}/modifica", '/esercizi/2026/dati'];

        foreach ($pagine as $pagina) {
            $this->come($this->tesoriere)->get($pagina)->assertOk()->assertDontSeeText('è già approvato');
        }
        $this->approva()->assertRedirect();
        foreach ($pagine as $pagina) {
            $this->come($this->tesoriere)->get($pagina)->assertOk()->assertSeeText('L’esercizio 2026 è già approvato: la modifica è ammessa');
        }
    }

    public function test_un_esercizio_depositato_non_ammette_l_eliminazione_di_un_movimento(): void
    {
        $manuale = Movimento::factory()->create(['conto_id' => $this->cassa->id, 'verso' => 'uscita', 'importo' => 500, 'data' => '2026-03-01', 'manuale' => true, 'inserito_da' => $this->tesoriere->id, 'stato' => 'confermato']);
        $this->approva()->assertSessionHasNoErrors();

        $this->come($this->tesoriere)->post("/movimenti/{$manuale->id}/eliminazione", ['motivazione' => 'Doppione'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSoftDeleted($manuale);

        $altro = Movimento::factory()->create(['conto_id' => $this->cassa->id, 'verso' => 'uscita', 'importo' => 700, 'data' => '2026-03-02', 'manuale' => true, 'inserito_da' => $this->tesoriere->id, 'stato' => 'confermato']);
        DatiEsercizio::where('esercizio', 2026)->update(['stato' => 'depositato']); // il deposito arriva con #60

        $this->come($this->tesoriere)->post("/movimenti/{$altro->id}/eliminazione", ['motivazione' => 'Errore'])->assertSessionHasErrors('movimento');
        $this->assertNotSoftDeleted($altro);
    }

    public function test_dopo_la_riapertura_il_prospetto_torna_provvisorio_con_la_nuova_versione(): void
    {
        $this->esercizio2026();
        $this->approva()->assertRedirect();
        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore'])->assertRedirect();

        $this->come($this->tesoriere)->get('/esercizi/2026/modello-d')->assertOk()->assertSeeText('Provvisorio')->assertSeeText('Esercizio riaperto: versione 2')->assertDontSeeText('Differenze dalla versione approvata');
    }

    public function test_il_confronto_dell_anno_dopo_legge_la_versione_approvata_e_non_i_dati_di_oggi(): void
    {
        $this->esercizio2026();
        $this->approva()->assertRedirect();
        $this->imputa('entrata', 2000, '2026-07-01', 'Somministrazione'); // dopo l'approvazione

        $colonna = collect(Verifiche::pagina(2027)['confronto'])->firstWhere('etichetta', '2026');

        $this->assertSame(80000, $colonna['entrate'], 'Il 2026 vale per come è stato approvato');
        $this->assertSame(82000, Verifiche::pagina(2026)['confronto'][2]['entrate'], 'L’esercizio guardato è sempre dal vivo');
    }

    public function test_un_esercizio_riaperto_nel_confronto_torna_ai_dati_di_oggi(): void
    {
        $this->esercizio2026();
        $this->approva()->assertRedirect();
        $this->imputa('entrata', 2000, '2026-07-01', 'Somministrazione');
        $this->come($this->amministratore)->post('/esercizi/2026/riapertura', ['motivazione' => 'Errore'])->assertRedirect();

        $this->assertSame(82000, collect(Verifiche::pagina(2027)['confronto'])->firstWhere('etichetta', '2026')['entrate']);
    }

    /** I saldi iniziali dell'esercizio dopo sono la chiusura di questo, per riga del Modello D: nessun inserimento a mano, e la pagina li mostra. */
    public function test_i_saldi_iniziali_dell_esercizio_successivo_sono_la_chiusura(): void
    {
        $banca = Conto::factory()->create(['nome' => 'Banca', 'tipo' => 'banca', 'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 100000]);
        $this->esercizio2026(); // in cassa: 800,00 − 350,00
        Movimento::factory()->create(['conto_id' => $banca->id, 'verso' => 'uscita', 'importo' => 25000, 'data' => '2026-09-01', 'inserito_da' => $this->tesoriere->id, 'stato' => 'confermato']);
        $this->approva()->assertRedirect();

        $perRiga = fn (int $anno, string $campo) => collect(Prospetto::saldi($anno))->groupBy('riga')->map(fn ($saldi) => $saldi->sum($campo))->sortKeys()->all();
        $this->assertSame(['cassa' => 45000, 'depositi' => 75000], $perRiga(2026, 'finale'));
        $this->assertSame($perRiga(2026, 'finale'), $perRiga(2027, 'iniziale'));
        $this->assertSame(Prospetto::modelloD(2026)['liquidita']['totale'], Prospetto::modelloD(2027)['liquidita']['iniziale']);

        $this->come($this->tesoriere)->get('/esercizi/2026/approvazione')->assertOk()
            ->assertSeeText('Saldi iniziali del 2027')->assertSeeText('450,00')->assertSeeText('750,00');
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
        $this->riverificaAccountGoogle();
        $this->approva(2026, '2027-04-22')->assertRedirect();

        $this->assertSame([1, 2], VersioneEsercizio::where('esercizio', 2026)->orderBy('numero')->pluck('numero')->all());
        $this->assertSame('approvato', DatiEsercizio::per(2026)->stato);
        $this->assertSame(2, DatiEsercizio::per(2026)->versione);
        $this->assertSame(2, VersioneEsercizio::vigente(2026)->numero);
        $this->come($this->tesoriere)->get('/esercizi/2026/approvazione')->assertOk()->assertSee('Versione 1')->assertSee('Versione 2')->assertSee('Errore');
    }
}
