<?php

namespace Tests\Feature;

use App\Esercizio\Apertura;
use App\Esercizio\Prospetto;
use App\Models\Assegnazione;
use App\Models\Conto;
use App\Models\Giroconto;
use App\Models\ImportEstratto;
use App\Models\ImpostazioniAssociazione;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\Nota;
use App\Models\RegistroModifica;
use App\Models\RigaFigurativa;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Il Modello D a schermo, le sue imputazioni, gli impedimenti e le impostazioni dell'associazione (#51). Dati inventati. */
class ModelloDTest extends TestCase
{
    use RefreshDatabase;

    private User $tesoriere;

    private Conto $cassa;

    private Conto $banca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-20 09:30');
        $this->tesoriere = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->cassa = Conto::factory()->create(['nome' => 'Cassa contanti', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 0]);
        $this->banca = Conto::factory()->create(['tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv', 'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 100000]);
    }

    private function movimento(Conto $conto, string $verso, int $importo, string $data = '2026-03-01', array $extra = []): Movimento
    {
        return Movimento::factory()->confermato()->create($extra + ['conto_id' => $conto->id, 'verso' => $verso, 'importo' => $importo, 'data' => $data, 'inserito_da' => $this->tesoriere->id]);
    }

    private function imputa(Movimento $movimento, string $tipologia, string $area, ?int $importo = null, array $extra = []): Imputazione
    {
        return Imputazione::create($extra + ['movimento_id' => $movimento->id, 'importo' => $importo ?? $movimento->importo, 'area' => $area, 'tipologia_id' => Tipologia::where('nome', $tipologia)->firstOrFail()->id]);
    }

    /**
     * Un esercizio in cui tutto quadra. Entrate: 200,00 di quote (A1) e 100,00 di liberalità incassate con PayPal, lorde (A4);
     * uscite: 50,00 di utenze (E2) e 3,50 di commissione (D1). Avanzo 246,50. Più cose che non devono contare: un giroconto, un movimento eliminato.
     */
    private function esercizioCheQuadra(): void
    {
        $this->imputa($this->movimento($this->cassa, 'entrata', 20000), 'Quote associative', 'A');
        $this->imputa($this->movimento($this->cassa, 'uscita', 5000), 'Utenze', 'E');
        $liberalita = $this->movimento($this->banca, 'entrata', 9650, '2026-04-01', ['commissione' => 350]);
        $this->imputa($liberalita, 'Erogazioni liberali', 'A', 10000);
        $this->imputa($liberalita, 'Commissioni e oneri bancari', 'D', -350);

        // Un giroconto dalla cassa alla banca: due metà confermate, né entrata né uscita.
        $uscita = $this->movimento($this->cassa, 'uscita', 3000, '2026-05-01');
        $entrata = $this->movimento($this->banca, 'entrata', 3000, '2026-05-01');
        Giroconto::create(['uscita_id' => $uscita->id, 'entrata_id' => $entrata->id, 'regola' => 'prova', 'confermato_da' => $this->tesoriere->id, 'confermato_il' => now()]);
        // Eliminato: sparisce dal saldo e dal prospetto.
        $this->imputa($this->movimento($this->cassa, 'uscita', 999, '2026-06-01', ['eliminato_il' => now()]), 'Utenze', 'E');
        // Del 2025: non è del 2026 (ma il suo denaro è nel saldo iniziale).
        $this->imputa($this->movimento($this->banca, 'entrata', 777, '2025-12-30'), 'Quote associative', 'A');
    }

    public function test_il_modello_d_provvisorio_calcola_dal_vivo_cifre_totali_e_quadratura(): void
    {
        $this->esercizioCheQuadra();

        $pagina = $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d')->assertOk();

        $pagina->assertSeeText('Provvisorio – aggiornato al 20/12/2026 09:30');
        $pagina->assertSeeText('Contrada La Torre APS');
        $pagina->assertSeeText('Totale entrate della gestione')->assertSeeText('300,00'); // 200 + 100 al lordo
        $pagina->assertSeeText('Totale uscite della gestione')->assertSeeText('53,50');   // 50 + commissione 3,50
        $pagina->assertSeeText('246,50');                                                   // avanzo complessivo
        $pagina->assertSeeText('Quadra: la liquidità è cambiata esattamente dell’avanzo complessivo.');

        $m = Prospetto::modelloD(2026);
        $this->assertSame(30000, $m['gestione']['entrate']['importo']);
        $this->assertSame(5350, $m['gestione']['uscite']['importo']);
        $this->assertSame(24650, $m['avanzoComplessivo']['importo']);
        $this->assertTrue($m['quadratura']['quadra']);
        $this->assertSame([], $m['anomalie']);
    }

    public function test_cassa_e_depositi_sono_il_saldo_dei_conti_al_31_12_secondo_la_riga_del_conto(): void
    {
        $this->esercizioCheQuadra();

        $liquidita = Prospetto::modelloD(2026)['liquidita'];

        // cassa: +200,00 −50,00 −30,00 (giroconto) = 120,00; banca: 1.000,00 +7,77 (2025) +96,50 +30,00 (giroconto) = 1.134,27
        $this->assertSame(12000, $liquidita['cassa']);
        $this->assertSame(113427, $liquidita['depositi']);
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d')->assertSeeText('120,00')->assertSeeText('1.134,27')->assertSeeText('1.254,27');
    }

    public function test_senza_imputazioni_lo_schema_e_completo(): void
    {
        $pagina = $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d')->assertOk();

        $pagina->assertSeeText('A1) Entrate da quote associative e apporti dei fondatori')
            ->assertSeeText('A9) Entrate da contratti con enti pubblici')->assertSeeText('C2) Uscite per raccolte fondi occasionali')
            ->assertSeeText('E2) Altre entrate di supporto generale')->assertSeeText('I4) Rimborso di finanziamenti per quota capitale e di prestiti')
            ->assertSeeText('Avanzo/disavanzo d’esercizio prima delle imposte')->assertSeeText('Avanzo/disavanzo complessivo')
            ->assertSeeText('Cassa e banca')->assertSeeText('Costi figurativi')->assertSeeText('Proventi figurativi');
    }

    public function test_per_impostazione_solo_le_confermate_e_con_l_interruttore_anche_le_proposte(): void
    {
        $proposto = $this->movimento($this->cassa, 'uscita', 4000, '2026-02-01', ['stato' => 'proposto', 'confermato_il' => null, 'confermato_da' => null]);
        $this->imputa($proposto, 'Servizi vari', 'A');

        $this->assertSame(0, Prospetto::modelloD(2026)['gestione']['uscite']['importo']);
        $this->assertSame(4000, Prospetto::modelloD(2026, true)['gestione']['uscite']['importo']);
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d')->assertSeeText('Solo le imputazioni confermate');
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d?proposte=1')->assertSeeText('Con anche le imputazioni proposte');
    }

    public function test_il_lato_e_della_tipologia_non_del_movimento_la_commissione_e_un_uscita_in_d1(): void
    {
        $incasso = $this->movimento($this->banca, 'entrata', 9650, '2026-04-01', ['commissione' => 350]);
        $this->imputa($incasso, 'Erogazioni liberali', 'A', 10000);
        $this->imputa($incasso, 'Commissioni e oneri bancari', 'D', -350);

        $m = Prospetto::modelloD(2026);

        $this->assertSame(10000, $m['aree']['A']['entrate']['righe'][3]['importo']); // A4
        $this->assertSame(350, $m['aree']['D']['uscite']['righe'][0]['importo']);    // D1 uscite
        $this->assertSame(0, $m['aree']['D']['entrate']['righe'][0]['importo']);
    }

    public function test_un_imputazione_senza_riga_non_sparisce_in_silenzio(): void
    {
        // Area C senza un'attività di raccolta (#61): non si sa se è C1 o C2, quindi la combinazione non ha voce.
        $this->imputa($this->movimento($this->cassa, 'entrata', 2500), 'Erogazioni liberali', 'C');

        $m = Prospetto::modelloD(2026);

        $this->assertSame(2500, $m['senzaRiga']['entrate']);
        $pagina = $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d')->assertSeeText('senza voce Modello D');
        $pagina->assertSeeText('Vedi le imputazioni senza riga nel Modello D');
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/imputazioni?chiavi='.Prospetto::SENZA_RIGA)->assertSeeText('25,00');
    }

    public function test_ogni_cifra_porta_all_elenco_filtrato_e_la_somma_dell_elenco_e_la_cifra(): void
    {
        $this->esercizioCheQuadra();
        $m = Prospetto::modelloD(2026);

        foreach (['gestione' => $m['gestione']['avanzo'], 'complessivo' => $m['avanzoComplessivo'], 'area A' => $m['aree']['A']['avanzo'], 'riga A4' => $m['aree']['A']['entrate']['righe'][3]] as $nome => $cifra) {
            $elenco = Prospetto::elenco(2026, false, $cifra['chiavi']);
            $this->assertSame($cifra['importo'], $elenco['entrate'] - $elenco['uscite'], $nome);
        }

        $pagina = $this->actingAs($this->tesoriere)->get('/esercizi/2026/imputazioni?'.http_build_query(['chiavi' => implode(',', $m['avanzoComplessivo']['chiavi']), 'titolo' => 'Avanzo/disavanzo complessivo']));
        $pagina->assertOk()->assertSeeText('Avanzo/disavanzo complessivo')->assertSeeText('Entrate 300,00 €')->assertSeeText('uscite 53,50 €')->assertSeeText('differenza 246,50 €')
            ->assertSeeText('commissione')->assertDontSeeText('9,99');
        // La pagina del Modello D porta davvero ai suoi elenchi.
        $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d')->assertSee('/esercizi/2026/imputazioni?chiavi=entrate%3AA4', false);
    }

    public function test_un_avanzo_a_zero_con_entrate_e_uscite_uguali_porta_comunque_alle_sue_imputazioni(): void
    {
        $this->imputa($this->movimento($this->cassa, 'entrata', 1000), 'Quote associative', 'A');
        $this->imputa($this->movimento($this->cassa, 'uscita', 1000), 'Servizi vari', 'A');

        $pagina = $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d')->assertOk();

        $this->assertSame(0, Prospetto::modelloD(2026)['aree']['A']['avanzo']['importo']);
        $link = route('esercizio.imputazioni', ['anno' => 2026, 'chiavi' => implode(',', Prospetto::modelloD(2026)['aree']['A']['avanzo']['chiavi']), 'titolo' => 'Avanzo/disavanzo A']);
        $pagina->assertSee('href="'.e($link).'"', false);
    }

    public function test_i_costi_e_proventi_figurativi_dai_dati_dell_esercizio_restano_fuori_dai_totali(): void
    {
        $this->imputa($this->movimento($this->cassa, 'entrata', 20000), 'Quote associative', 'A');
        RigaFigurativa::create(['esercizio' => 2026, 'tipo' => 'costo', 'area' => 'A', 'descrizione' => 'Volontari del Carnevale, 40 ore', 'importo' => 60000]);
        RigaFigurativa::create(['esercizio' => 2026, 'tipo' => 'provento', 'area' => 'B', 'descrizione' => 'Prestazione gratuita di un tecnico', 'importo' => 15000]);
        RigaFigurativa::create(['esercizio' => 2025, 'tipo' => 'costo', 'area' => 'A', 'descrizione' => 'Di un altro anno', 'importo' => 1]);

        $pagina = $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d')->assertOk();

        // Solo righe e importi: le descrizioni libere possono nominare persone (storia 143) e non stanno nel Modello D.
        $pagina->assertSeeText('Da attività di interesse generale')->assertSeeText('600,00')->assertSeeText('150,00')
            ->assertDontSeeText('Volontari del Carnevale')->assertDontSeeText('Prestazione gratuita')->assertDontSeeText('Di un altro anno');
        $this->assertSame(20000, Prospetto::modelloD(2026)['avanzoComplessivo']['importo']);
    }

    public function test_un_figurativo_in_un_area_senza_riga_e_segnalato_senza_la_sua_descrizione(): void
    {
        RigaFigurativa::create(['esercizio' => 2026, 'tipo' => 'costo', 'area' => 'E', 'descrizione' => 'Ore di Mario Rossi', 'importo' => 8000]);

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d')->assertOk()
            ->assertSeeText('Un costo figurativo di 80,00 €')->assertDontSeeText('Mario Rossi');
    }

    public function test_gli_impedimenti_si_elencano_accanto_al_prospetto(): void
    {
        $this->movimento($this->cassa, 'uscita', 100, '2026-02-01', ['stato' => 'da_classificare']);
        $proposto = $this->movimento($this->cassa, 'uscita', 200, '2026-02-02', ['stato' => 'proposto']);
        $dubbio = $this->movimento($this->cassa, 'uscita', 300, '2026-02-03');
        Nota::create(['oggetto_tipo' => 'movimento', 'oggetto_id' => $dubbio->id, 'user_id' => $this->tesoriere->id, 'testo' => 'Da verificare', 'dubbio' => true]);
        // Un estratto confermato fino al 31/05; la banca ha poi un movimento di giugno: riconciliata solo fino a lì.
        $this->movimento($this->banca, 'entrata', 500, '2026-06-10');
        ImportEstratto::create(['conto_id' => $this->banca->id, 'user_id' => $this->tesoriere->id, 'stato' => 'confermato', 'periodo_dal' => '2026-05-01', 'periodo_al' => '2026-05-31', 'saldo_iniziale' => 100000, 'saldo_finale' => 100000, 'righe' => 0, 'confermato_il' => now()]);
        // Un prelievo non ancora abbinato: la metà di un giroconto senza la seconda.
        Movimento::factory()->create(['conto_id' => $this->banca->id, 'manuale' => false, 'inserito_da' => $this->tesoriere->id, 'causale' => 'PRELIEVI', 'descrizione' => 'PRELIEVO BANCOMAT SPORTELLO ESEMPIO', 'importo' => 7000, 'verso' => 'uscita', 'data' => '2026-06-12']);

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/modello-d')->assertOk()
            ->assertSeeText('2 movimenti sono da classificare') // il da_classificare di questo test e il prelievo
            ->assertSeeText('1 movimento ha una proposta da confermare')
            ->assertSeeText('1 movimento è in dubbio')
            ->assertSeeText('«Conto corrente» è riconciliato solo fino al 31/05/2026')
            ->assertSeeText('«Cassa contanti» non è mai stato riconciliato')
            ->assertSeeText('Giroconto senza la seconda metà: uscita di 70,00 €');
        $this->assertNotNull($proposto);
    }

    public function test_un_esercizio_senza_movimenti_non_ha_impedimenti(): void
    {
        $this->actingAs($this->tesoriere)->get('/esercizi/2027/modello-d')->assertOk()->assertSeeText('Nessun impedimento');
    }

    public function test_le_impostazioni_valgono_dall_esercizio_indicato_in_poi(): void
    {
        $admin = $this->utenteCon('Amministratore');
        ImpostazioniAssociazione::create(['dal_esercizio' => 2028, 'denominazione' => 'Contrada La Torre ETS', 'codice_fiscale' => '00000000000', 'numero_runts' => '12345', 'affiliazione_rete' => true]);

        $this->actingAs($admin)->get('/esercizi/2027/modello-d')->assertSeeText('Contrada La Torre APS')->assertSeeText('numero RUNTS da inserire');
        $this->actingAs($admin)->get('/esercizi/2028/modello-d')->assertSeeText('Contrada La Torre ETS')->assertSeeText('codice fiscale 00000000000')->assertSeeText('numero RUNTS 12345');
        $this->assertTrue(ImpostazioniAssociazione::per(2028)->affiliazione_rete);
        $this->assertFalse(ImpostazioniAssociazione::per(2027)->affiliazione_rete);

        $this->actingAs($admin)->put('/impostazioni', ['dal_esercizio' => 2027, 'denominazione' => 'Contrada La Torre APS', 'codice_fiscale' => '11111111111', 'numero_runts' => '999', 'affiliazione_rete' => '1'])
            ->assertRedirect('/impostazioni')->assertSessionHasNoErrors();

        $this->assertSame('11111111111', ImpostazioniAssociazione::per(2027)->codice_fiscale);
        $this->assertNull(ImpostazioniAssociazione::per(2026)->codice_fiscale, 'gli esercizi precedenti non cambiano');
        $riga = RegistroModifica::where('oggetto_tipo', 'impostazioni')->sole();
        $this->assertSame([$admin->id, 'creato'], [$riga->user_id, $riga->azione]);
        $this->actingAs($admin)->get('/registro')->assertOk()->assertSeeText('le impostazioni dell’associazione dall’esercizio');
    }

    public function test_salvare_di_nuovo_le_impostazioni_di_un_esercizio_le_modifica_e_registra_prima_e_dopo(): void
    {
        $admin = $this->utenteCon('Amministratore');

        $this->actingAs($admin)->put('/impostazioni', ['dal_esercizio' => 2026, 'denominazione' => 'Contrada La Torre APS', 'numero_runts' => '777'])->assertSessionHasNoErrors();

        $this->assertSame(1, ImpostazioniAssociazione::where('dal_esercizio', 2026)->count());
        $riga = RegistroModifica::where('oggetto_tipo', 'impostazioni')->sole();
        $this->assertSame([null, '777'], [$riga->prima['numero_runts'], $riga->dopo['numero_runts']]);
        $this->actingAs($admin)->put('/impostazioni', ['dal_esercizio' => 2026, 'denominazione' => ''])->assertSessionHasErrors('denominazione');
    }

    public function test_il_permesso_revocato_dopo_il_controllo_della_rotta_non_basta_a_scrivere_le_impostazioni(): void
    {
        $utente = $this->utenteCon('Amministratore');
        $this->assertTrue($utente->can('gestire_impostazioni')); // il controllo della rotta carica le assegnazioni
        Assegnazione::where('user_id', $utente->id)->delete(); // revoca nel frattempo

        foreach ([2026, 2027] as $anno) { // una riga che c'è e una che non c'è
            try {
                ImpostazioniAssociazione::scrivi($utente, ['dal_esercizio' => $anno, 'denominazione' => 'Nome rifiutato', 'affiliazione_rete' => false]);
                $this->fail('La scrittura doveva essere rifiutata.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame('Contrada La Torre APS', ImpostazioniAssociazione::where('dal_esercizio', 2026)->value('denominazione'));
        $this->assertFalse(ImpostazioniAssociazione::where('dal_esercizio', 2027)->exists());
        $this->assertSame(0, RegistroModifica::where('oggetto_tipo', 'impostazioni')->count());
    }

    public function test_la_riga_nuova_delle_impostazioni_nasce_senza_i_dati_dell_utente_che_si_scrivono_a_riga_bloccata(): void
    {
        $utente = $this->utenteCon('Amministratore');
        $this->assertTrue($utente->can('gestire_impostazioni'));
        $revoca = true;
        DB::listen(function ($query) use ($utente, &$revoca) { // la riga è appena stata inserita, fuori dalla transazione: revoca prima del controllo a riga bloccata
            if ($revoca && str_contains($query->sql, 'insert') && str_contains($query->sql, 'impostazioni_associazione')) {
                $revoca = false;
                Assegnazione::where('user_id', $utente->id)->delete();
            }
        });

        try {
            ImpostazioniAssociazione::scrivi($utente, ['dal_esercizio' => 2027, 'denominazione' => 'Nome rifiutato', 'codice_fiscale' => '11111111111', 'affiliazione_rete' => true]);
            $this->fail('La scrittura doveva essere rifiutata.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame([], array_intersect(['Nome rifiutato', '11111111111'], ImpostazioniAssociazione::where('dal_esercizio', 2027)->get(['denominazione', 'codice_fiscale'])->flatMap->getAttributes()->all()));
    }

    public function test_il_registro_si_filtra_per_dati_dell_esercizio_e_per_riga_figurativa(): void
    {
        $admin = $this->utenteCon('Amministratore');
        foreach (['dati_esercizio' => 'Dati di prova', 'riga_figurativa' => 'Riga di prova'] as $tipo => $segno) {
            RegistroModifica::create(['user_id' => $admin->id, 'oggetto_tipo' => $tipo, 'oggetto_id' => 1, 'azione' => 'modificato', 'prima' => null, 'dopo' => ['esercizio' => 2026, 'descrizione' => $segno]]);
        }

        $this->actingAs($admin)->get('/registro?oggetto=dati_esercizio')->assertOk()->assertSee('Dati di prova')->assertDontSee('Riga di prova');
        $this->actingAs($admin)->get('/registro?oggetto=riga_figurativa')->assertOk()->assertSee('Riga di prova')->assertDontSee('Dati di prova');
        $this->actingAs($admin)->get('/registro')->assertOk()->assertSee('value="dati_esercizio"', false)->assertSee('value="riga_figurativa"', false);
    }

    public function test_chi_puo_vedere_legge_il_modello_d_ma_solo_chi_gestisce_le_impostazioni_le_cambia(): void
    {
        $lettore = $this->utenteCon('Lettore esterno', ['scade_il' => now()->addMonth()], ['email' => 'revisore@esterno.example']);

        $this->actingAs($lettore)->get('/esercizi/2026/modello-d')->assertOk();
        $this->actingAs($lettore)->get('/esercizi/2026/imputazioni?chiavi=entrate:A1')->assertOk();
        $this->actingAs($this->tesoriere)->get('/impostazioni')->assertForbidden();
        $this->actingAs($this->tesoriere)->put('/impostazioni', ['dal_esercizio' => 2026, 'denominazione' => 'X'])->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/esercizi/2026/modello-d')->assertForbidden();
        $this->actingAs($this->tesoriere)->get('/modello-d')->assertRedirect('/esercizi/2026/modello-d');
    }

    public function test_l_elenco_offre_di_aprire_il_movimento_solo_a_chi_puo_classificarlo(): void
    {
        $this->imputa($this->movimento($this->cassa, 'entrata', 1000), 'Quote associative', 'A');
        $lettore = $this->utenteCon('Lettore esterno', ['scade_il' => now()->addMonth()], ['email' => 'revisore@esterno.example']);

        $this->actingAs($this->tesoriere)->get('/esercizi/2026/imputazioni?chiavi=entrate:A1')->assertSeeText('Apri il movimento');
        $this->actingAs($lettore)->get('/esercizi/2026/imputazioni?chiavi=entrate:A1')->assertOk()->assertSeeText('10,00')->assertDontSeeText('Apri il movimento');
    }

    public function test_il_registro_dice_da_quale_esercizio_valgono_le_impostazioni_modificate(): void
    {
        $admin = $this->utenteCon('Amministratore');
        $this->actingAs($admin)->put('/impostazioni', ['dal_esercizio' => 2031, 'denominazione' => 'Contrada La Torre APS'])->assertSessionHasNoErrors();

        $this->actingAs($admin)->get('/registro')->assertOk()->assertSeeText('le impostazioni dell’associazione dall’esercizio 2031');
    }

    public function test_un_anno_impossibile_e_un_404_e_non_un_errore(): void
    {
        $this->actingAs($this->tesoriere)->get('/esercizi/9999/modello-d')->assertOk();
        $this->actingAs($this->tesoriere)->get('/esercizi/0000/modello-d')->assertNotFound();
        $this->actingAs($this->tesoriere)->get('/esercizi/0000/imputazioni')->assertNotFound();
        $this->actingAs($this->tesoriere)->post('/esercizi/0000/apertura')->assertNotFound();
    }

    public function test_un_esercizio_nuovo_senza_voci_si_apre_copiando_voci_e_predefiniti_dal_precedente(): void
    {
        $pagina = $this->actingAs($this->tesoriere)->get('/esercizi/2027/modello-d')->assertOk();
        $pagina->assertSeeText('la tabella delle voci del Modello D non è caricata')->assertSeeText('copia voci e predefiniti dal 2026');
        $this->assertSame(0, DB::table('voci_modello_d')->where('esercizio', 2027)->count());

        $this->actingAs($this->tesoriere)->post('/esercizi/2027/apertura')->assertRedirect('/esercizi/2027/modello-d');

        $this->assertSame(DB::table('voci_modello_d')->where('esercizio', 2026)->count(), DB::table('voci_modello_d')->where('esercizio', 2027)->count());
        $this->assertSame(DB::table('iniziativa_esercizio')->where('esercizio', 2026)->count(), DB::table('iniziativa_esercizio')->where('esercizio', 2027)->count());
        $festa = Iniziativa::where('nome', 'Festa della Torre')->firstOrFail();
        $this->assertSame('A', DB::table('iniziativa_esercizio')->where(['iniziativa_id' => $festa->id, 'esercizio' => 2027])->value('area'));
        $this->assertSame('esercizio', RegistroModifica::where('azione', 'aperto')->sole()->oggetto_tipo);
        $this->actingAs($this->tesoriere)->get('/esercizi/2027/modello-d')->assertDontSeeText('non è caricata');
        // Una seconda apertura non raddoppia nulla.
        $this->actingAs($this->tesoriere)->post('/esercizi/2027/apertura')->assertSessionHasErrors('esercizio');
        // E una classificazione del 2027 ora ha la sua voce.
        $movimento = $this->movimento($this->cassa, 'entrata', 1000, '2027-02-01');
        $this->assertSame('A1', $this->imputa($movimento, 'Quote associative', 'A')->voce());
    }

    public function test_aprire_un_esercizio_e_di_chi_modifica_la_tabella_delle_voci(): void
    {
        $consigliere = $this->utenteCon('Consigliere');

        $this->actingAs($consigliere)->post('/esercizi/2027/apertura')->assertForbidden();
        $this->actingAs($consigliere)->get('/esercizi/2027/modello-d')->assertOk()->assertSeeText('Chi può modificare la tabella delle voci può copiarla dal 2026');
        $this->assertSame(0, DB::table('voci_modello_d')->where('esercizio', 2027)->count());
    }

    public function test_il_permesso_revocato_dopo_il_controllo_della_rotta_non_basta_ad_aprire_l_esercizio(): void
    {
        $utente = $this->utenteCon('Tesoriere');
        $this->assertTrue($utente->can('riclassificare_in_blocco')); // il controllo della rotta carica le assegnazioni
        Assegnazione::where('user_id', $utente->id)->delete(); // revoca nel frattempo

        try {
            Apertura::apri($utente, 2027);
            $this->fail('L’apertura doveva essere rifiutata.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, DB::table('voci_modello_d')->where('esercizio', 2027)->count());
    }

    public function test_un_movimento_di_un_anno_senza_voci_non_e_perso_ma_segnalato(): void
    {
        $this->imputa($this->movimento($this->cassa, 'entrata', 1000, '2027-02-01'), 'Quote associative', 'A');

        $m = Prospetto::modelloD(2027);

        $this->assertSame(1000, $m['senzaRiga']['entrate']);
        $this->assertSame(0, $m['gestione']['entrate']['importo']);
    }
}
