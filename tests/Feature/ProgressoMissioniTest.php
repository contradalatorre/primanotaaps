<?php

namespace Tests\Feature;

use App\Models\Avanzamento;
use App\Models\ImportEstratto;
use App\Models\User;
use App\Onboarding\Catalogo;
use App\Onboarding\Missione;
use App\Onboarding\Svolgimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ConSpazioDiProva;
use Tests\TestCase;

/** Avanzamento nelle missioni (#71): ripresa, «Torniamo lì?», salto, timbri e sigilli, invito, permessi, vista dell'Amministratore. */
class ProgressoMissioniTest extends TestCase
{
    use RefreshDatabase, ConSpazioDiProva;

    private function stato(User $utente, string $missione): ?Avanzamento
    {
        return Avanzamento::where(['user_id' => $utente->id, 'missione' => $missione])->first();
    }

    private function completaBenvenuto(User $utente): void
    {
        $this->actingAs($utente)->post(route('impara.avvia', 'benvenuto'));
        foreach (range(1, 6) as $_) {
            $this->post(route('prova.avanti'));
        }
    }

    public function test_si_interrompe_e_si_riprende_dal_passo_lasciato_senza_azzerare_la_prova(): void
    {
        $utente = $this->utenteCon('Tesoriere');
        $this->actingAs($utente)->post(route('impara.avvia', 'import-e-revisione'));
        $this->post(route('prova.avanti')); // passo 1 → 2
        $this->post(route('prova.estratto', $this->prova()->per($utente, null, fn () => \App\Models\Conto::value('id'))));
        $this->get(route('home')); // il passo pratico si verifica da solo: passo 3 (indice 2)
        $this->assertSame(2, $this->stato($utente, 'import-e-revisione')->passo);

        $this->post(route('prova.esci'))->assertRedirect(route('impara'));
        $this->get(route('impara'))->assertOk()->assertSee('in corso, passo 3 di 10')->assertSee('Riprendi');
        $this->get(route('conti'))->assertOk()->assertDontSee('Stai facendo pratica'); // fuori dalla prova, con i permessi veri

        $this->post(route('impara.riprendi', 'import-e-revisione'))->assertRedirect(route('import.mostra', 1));
        $this->assertSame(2, $this->stato($utente, 'import-e-revisione')->passo);
        $this->assertSame(1, $this->prova()->per($utente, null, fn () => ImportEstratto::count()), 'la ripresa non azzera lo spazio');

        // Ricominciare da capo, invece, lo azzera.
        $this->post(route('impara.avvia', 'import-e-revisione'));
        $this->assertSame(0, $this->stato($utente, 'import-e-revisione')->passo);
        $this->assertSame(0, $this->prova()->per($utente, null, fn () => ImportEstratto::count()));
    }

    public function test_se_ci_si_allontana_dalla_schermata_del_passo_merlo_propone_di_tornarci(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'))->post(route('impara.avvia', 'import-e-revisione'));

        // Il primo passo è sui conti: sui movimenti Merlo non indica nulla e chiede.
        $this->get(route('movimenti'))->assertOk()
            ->assertSee('Torniamo lì?')
            ->assertSee('Sì, andiamo')
            ->assertSee('data-elemento=""', false)
            ->assertSee('href="'.route('conti').'"', false);
        $this->get(route('conti'))->assertOk()->assertDontSee('Torniamo lì?')->assertSee('data-avanti', false);
    }

    public function test_se_l_estratto_e_stato_scartato_torniamo_li_porta_a_caricarlo_di_nuovo(): void
    {
        $utente = $this->utenteCon('Tesoriere');
        $this->actingAs($utente)->post(route('impara.avvia', 'import-e-revisione'));
        $this->post(route('prova.avanti'));
        $contoId = $this->prova()->per($utente, null, fn () => \App\Models\Conto::where('tipo', 'banca')->value('id'));
        $this->post(route('prova.estratto', $contoId));
        $this->get(route('home')); // passo 3 (anteprima)
        $this->prova()->per($utente, null, fn () => ImportEstratto::query()->delete()); // «Scarta»

        $this->get(route('movimenti'))->assertOk()->assertSee('Torniamo lì?')->assertSee('href="'.route('import.indice', $contoId).'"', false);
    }

    public function test_la_pagina_dei_timbri_ignora_un_parametro_nuovo_che_non_e_un_testo(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'))->get(route('impara.timbri', ['nuovo' => ['x']]))
            ->assertOk()->assertDontSee('completata: ecco il tuo timbro');
    }

    public function test_saltare_non_da_il_timbro_e_la_missione_resta_suggerita(): void
    {
        $utente = $this->utenteCon('Tesoriere');
        $this->actingAs($utente)->post(route('impara.avvia', 'benvenuto'));

        $this->post(route('prova.salta'))->assertRedirect(route('impara'));

        $this->assertSame('saltata', $this->stato($utente, 'benvenuto')->stato);
        $this->assertNull($this->stato($utente, 'benvenuto')->versione_completata);
        $this->assertTrue(app(Svolgimento::class)->suggerite($utente)->has('benvenuto'));
        $this->get(route('impara.timbri'))->assertOk()->assertSee('Non ancora fatta');
    }

    public function test_completare_una_missione_da_il_timbro_e_il_percorso_completo_il_sigillo(): void
    {
        $utente = $this->utenteCon('Consigliere');
        $this->completaBenvenuto($utente);

        $avanzamento = $this->stato($utente, 'benvenuto');
        $this->assertSame('completata', $avanzamento->stato);
        $this->assertSame(1, $avanzamento->versione_completata);
        $this->assertNotNull($avanzamento->completata_il);
        $this->assertFalse(session()->has('prova.missione'));
        $this->get(route('impara.timbri', ['nuovo' => 'benvenuto']))->assertOk()
            ->assertSee('Timbro Benvenuto')           // il timbro
            ->assertSee('Sigillo Per tutti')          // «Per tutti» ha solo Benvenuto: il percorso è completo
            ->assertSee('Percorso completato')
            ->assertDontSee('punti');                 // niente punti né classifiche

        // Rifarla è la versione rapida (senza animazioni di ingresso) e il timbro resta anche se si salta.
        $this->post(route('impara.avvia', 'benvenuto'));
        $this->get(route('home'))->assertSee('data-rapida="1"', false);
        $this->post(route('prova.salta'));
        $this->assertSame(1, $this->stato($utente, 'benvenuto')->versione_completata);
        $this->get(route('impara'))->assertSee('timbro ottenuto')->assertSee('Rifai (versione rapida)');
    }

    public function test_la_prima_volta_si_vede_la_guida_con_animazione_e_la_missione_da_telefono_non_avvisa(): void
    {
        $this->actingAs($this->utenteCon('Consigliere'))->post(route('impara.avvia', 'benvenuto'));
        $this->get(route('home'))->assertSee('data-rapida="0"', false)->assertDontSee('data-computer', false);

        // «Import e revisione» ha bisogno dello schermo largo: sul telefono propone di continuare dal computer.
        $this->post(route('prova.esci'));
        $this->post(route('impara.avvia', 'import-e-revisione'));
        $this->get(route('conti'))->assertSee('data-computer', false)->assertSee('va meglio dal computer');
    }

    public function test_le_missioni_dei_propri_permessi_sono_suggerite_e_le_altre_si_fanno_in_prova(): void
    {
        $consigliere = $this->utenteCon('Consigliere'); // ha «vedere» ma non «importare e confermare»
        $suggerite = app(Svolgimento::class)->suggerite($consigliere);
        $this->assertSame(['benvenuto'], $suggerite->keys()->all());

        $pagina = $this->actingAs($consigliere)->get(route('impara'))->assertOk();
        $pagina->assertSeeInOrder(['Per te', 'Benvenuto', 'Altri percorsi', 'Import e revisione'])->assertSee('Si fanno solo in prova');

        // Il Consigliere può farla lo stesso, in prova: lì ha i permessi della missione.
        $this->post(route('impara.avvia', 'import-e-revisione'))->assertRedirect(route('conti'));
        $this->get(route('import.indice', $this->prova()->per($consigliere, null, fn () => \App\Models\Conto::value('id'))))->assertOk();
        $this->post(route('prova.esci'));
        $this->get(route('conti'))->assertOk(); // fuori dalla prova: i permessi veri
        $contoVero = \App\Models\Conto::create(['tipo' => 'cassa', 'nome' => 'Cassa vera', 'formato_import' => 'manuale', 'riga_modello_d' => 'cassa', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
        $this->get(route('import.indice', $contoVero))->assertForbidden();
    }

    public function test_al_primo_accesso_merlo_invita_senza_bloccare_e_non_insiste(): void
    {
        $utente = $this->utenteCon('Consigliere');

        $this->actingAs($utente)->get(route('home'))->assertOk()
            ->assertSee('Ti faccio vedere come si usa l’app?')->assertSee('Non ora')->assertDontSee('aria-modal', false);

        $this->post(route('impara.invito'))->assertRedirect();
        $this->get(route('home'))->assertOk()->assertDontSee('Ti faccio vedere come si usa l’app?');
        $this->assertTrue(app(Svolgimento::class)->suggerite($utente)->has('benvenuto'), '«Non ora» non toglie la missione da Impara');
        $this->assertNull($this->stato($utente, 'benvenuto')->versione_completata);

        // Chi non ha missioni suggerite (nessun permesso) non riceve inviti.
        $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()->assertDontSee('Ti faccio vedere');
    }

    public function test_l_amministratore_vede_solo_le_missioni_completate_non_errori_o_tentativi(): void
    {
        $this->completaBenvenuto($this->utenteCon('Consigliere', [], ['name' => 'Carla Bianchi']));
        $inCorso = $this->utenteCon('Consigliere', [], ['name' => 'Dario Neri']);
        $this->actingAs($inCorso)->post(route('impara.avvia', 'benvenuto'));

        $this->actingAs($inCorso)->get(route('impara.avanzamento'))->assertForbidden();

        $amministratore = $this->utenteCon('Amministratore');
        $pagina = $this->actingAs($amministratore)->get(route('impara.avanzamento'))->assertOk();
        $pagina->assertSeeInOrder(['Carla Bianchi', 'Benvenuto', 'Dario Neri', 'Nessuna missione completata']);
        $pagina->assertDontSee('passo')->assertDontSee('tentativ', false)->assertDontSee('errori', false);
    }

    public function test_chi_aveva_completato_una_versione_precedente_vede_cosa_e_cambiato_una_volta_sola(): void
    {
        $utente = User::factory()->create();
        $avanzamento = Avanzamento::create(['user_id' => $utente->id, 'missione' => 'x', 'stato' => 'completata', 'versione' => 1, 'versione_completata' => 1]);
        $v2 = $this->missione(versione: 2, cosaECambiato: 'Ora l’estratto si carica da Conti.');
        $senzaNota = $this->missione(versione: 2, cosaECambiato: null); // un cambio piccolo non ha effetti

        $svolgimento = app(Svolgimento::class);
        $this->assertNull($svolgimento->novita($senzaNota, $avanzamento));
        $this->assertSame('Ora l’estratto si carica da Conti.', $svolgimento->novita($v2, $avanzamento));
        $this->assertNull($svolgimento->novita($v2, $avanzamento->refresh()), 'si vede una volta sola');
        $this->assertSame(1, $avanzamento->refresh()->versione_completata, 'il timbro resta');
    }

    private function missione(int $versione, ?string $cosaECambiato): Missione
    {
        $m = Catalogo::predefinito()->missione('benvenuto');

        return new Missione('x', 'X', $versione, $cosaECambiato, $m->percorso, 1, '', $m->permessi, $m->scenario, true, $m->passi);
    }
}
