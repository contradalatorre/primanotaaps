<?php

namespace Tests\Feature;

use App\Giroconti;
use App\Models\Assegnazione;
use App\Models\Conto;
use App\Models\Giroconto;
use App\Models\ImportEstratto;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\Regola;
use App\Models\Ruolo;
use App\Models\Tipologia;
use App\Models\User;
use App\Permesso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** I giroconti: proposta, conferma, metà proposta e metà orfana (#47). Dati inventati. */
class GirocontiTest extends TestCase
{
    use RefreshDatabase;

    private Conto $banca;

    private Conto $cassa;

    private ImportEstratto $import;

    private User $tesoriere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-01 10:00');
        $this->tesoriere = $this->utenteCon('Tesoriere', [], ['name' => 'Tina Verdi']);
        $this->banca = Conto::factory()->create([
            'tipo' => 'banca', 'nome' => 'Conto corrente', 'formato_import' => 'credit_agricole_csv',
            'riga_modello_d' => 'depositi', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 100000,
        ]);
        $this->cassa = Conto::factory()->create(['tipo' => 'cassa', 'nome' => 'Cassa contanti', 'aperto_il' => '2026-01-01', 'saldo_iniziale' => 5000]);
        // saldo finale del file: 1.000,00 − 100,00 (il prelievo di molti test) = 900,00
        $this->import = ImportEstratto::create([
            'conto_id' => $this->banca->id, 'user_id' => $this->tesoriere->id, 'stato' => 'confermato', 'periodo_dal' => '2026-05-01',
            'periodo_al' => '2026-05-31', 'saldo_iniziale' => 100000, 'saldo_finale' => 90000, 'righe' => 1, 'confermato_il' => now(),
        ]);
    }

    /** Un movimento importato dalla banca, da classificare. */
    private function dellaBanca(string $causale, string $descrizione, int $importo, string $verso, string $data = '2026-05-10'): Movimento
    {
        return Movimento::factory()->create([
            'conto_id' => $this->banca->id, 'import_estratto_id' => $this->import->id, 'manuale' => false, 'inserito_da' => $this->tesoriere->id,
            'causale' => $causale, 'descrizione' => $descrizione, 'importo' => $importo, 'verso' => $verso, 'data' => $data,
        ]);
    }

    private function prelievo(int $importo = 10000, string $data = '2026-05-10'): Movimento
    {
        return $this->dellaBanca('PRELIEVI', 'PRELIEVO BANCOMAT SPORTELLO ESEMPIO', $importo, 'uscita', $data);
    }

    private function inCassa(string $descrizione, int $importo, string $verso, string $data = '2026-05-10'): Movimento
    {
        return Movimento::factory()->create([
            'conto_id' => $this->cassa->id, 'inserito_da' => $this->tesoriere->id, 'descrizione' => $descrizione, 'importo' => $importo, 'verso' => $verso, 'data' => $data,
        ]);
    }

    private function revisione(?string $passo = null)
    {
        return $this->actingAs($this->tesoriere)->get("/import/{$this->import->id}/revisione".($passo ? "?passo=$passo" : ''));
    }

    private function conferma(Movimento $a, Movimento $b, ?User $utente = null)
    {
        return $this->actingAs($utente ?? $this->tesoriere)->post("/import/{$this->import->id}/giroconti", ['movimento_a' => $a->id, 'movimento_b' => $b->id]);
    }

    private function registraMeta(Movimento $movimento, ?Conto $conto = null, ?User $utente = null)
    {
        return $this->actingAs($utente ?? $this->tesoriere)->post("/import/{$this->import->id}/giroconti/meta", ['movimento' => $movimento->id, 'conto_id' => ($conto ?? $this->cassa)->id]);
    }

    public function test_un_prelievo_con_la_sua_entrata_in_cassa_si_propone_come_giroconto(): void
    {
        $prelievo = $this->prelievo();
        $this->inCassa('Contanti ritirati', 10000, 'entrata', '2026-05-11');

        $this->revisione()
            ->assertOk()
            ->assertSee('data-passo="giroconti" data-conteggio="1"', false)
            ->assertSee('data-passo="decidere" data-conteggio="0"', false) // il giroconto precede: non è da classificare
            ->assertSeeText('Restano 1 giroconto da controllare.');
        $this->revisione('giroconti')
            ->assertSeeText('PRELIEVO BANCOMAT SPORTELLO ESEMPIO')
            ->assertSeeText('Contanti ritirati')
            ->assertSeeText('Conferma il giroconto');
        $this->assertSame('da_classificare', $prelievo->refresh()->stato); // proporre non cambia nulla
        $this->assertSame(0, Giroconto::count());
    }

    public function test_dall_estratto_caricato_il_prelievo_arriva_proposto_come_giroconto(): void
    {
        Storage::fake('local');
        $csv = implode("\r\n", [
            'Data Op.;Data Val.;Causale;Descrizione;Importo;Saldo Parziale', ';;Saldo Iniziale;;;1.000,00',
            '10/05/2026;10/05/2026;PRELIEVI;PRELIEVO BANCOMAT SPORTELLO ESEMPIO;\'-100,00;900,00',
            '12/05/2026;12/05/2026;PAGAMENTO TRAMITE POS;CARTA CA DEBIT VISA *1234 FERRAMENTA ESEMPIO;\'-10,00;890,00',
            ';;Saldo Contabile;;;890,00',
        ])."\r\n";
        $this->import->delete();
        $this->actingAs($this->tesoriere)->post("/conti/{$this->banca->id}/import", ['estratto' => UploadedFile::fake()->createWithContent('estratto.csv', $csv)])->assertSessionHasNoErrors();
        $import = ImportEstratto::sole();

        $this->get("/import/{$import->id}")->assertOk()->assertSeeInOrder(['Giroconti proposti', '1']);
        $this->post("/import/{$import->id}/conferma")->assertSessionHasNoErrors();

        $this->get("/import/{$import->id}/revisione")
            ->assertSee('data-passo="giroconti" data-conteggio="1"', false)
            ->assertSee('data-passo="decidere" data-conteggio="1"', false) // il pagamento con la carta resta da classificare
            ->assertSeeText('Restano 1 giroconto da controllare e 1 movimento da decidere.');
    }

    public function test_l_operatore_conferma_l_abbinamento_e_le_due_meta_escono_dai_totali(): void
    {
        $prelievo = $this->prelievo();
        $entrata = $this->inCassa('Contanti ritirati', 10000, 'entrata', '2026-05-11');

        $this->conferma($prelievo, $entrata)->assertRedirect(route('import.revisione', [$this->import, 'passo' => 'giroconti']))->assertSessionHasNoErrors();

        $giroconto = Giroconto::sole();
        $this->assertSame([$prelievo->id, $entrata->id], [$giroconto->uscita_id, $giroconto->entrata_id]);
        $this->assertSame('prelievo', $giroconto->regola);
        foreach ([$prelievo, $entrata] as $meta) {
            $this->assertSame('confermato', $meta->refresh()->stato);
            $this->assertSame($this->tesoriere->id, $meta->confermato_da);
            $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'movimento', 'oggetto_id' => $meta->id, 'azione' => 'giroconto_confermato']);
        }
        // Fuori dai totali di entrate e uscite, ma il saldo del conto non cambia: il denaro si è mosso davvero.
        $this->assertSame(0, Movimento::senzaGiroconto()->whereIn('id', [$prelievo->id, $entrata->id])->count());
        $this->assertSame(90000, $this->banca->saldoAl('2026-05-31'));
        $this->assertSame(15000, $this->cassa->saldoAl('2026-05-31'));

        $this->revisione('tutti')
            ->assertSee('data-passo="giroconti" data-conteggio="0"', false)
            ->assertSeeText('Giroconto con Cassa contanti')
            ->assertSeeText('± Giroconti (1)')
            ->assertSeeText('Tutti i movimenti sono confermati.')
            ->assertSeeText('Uguale all’estratto'); // saldo finale = iniziale + entrate − uscite ± giroconti
    }

    public function test_un_movimento_di_giroconto_non_si_classifica_ne_si_elimina_finche_non_si_scioglie(): void
    {
        $prelievo = $this->prelievo();
        $entrata = $this->inCassa('Contanti ritirati', 10000, 'entrata', '2026-05-11');
        $this->conferma($prelievo, $entrata);

        $this->actingAs($this->tesoriere)->get("/movimenti/{$prelievo->id}/classificazione")->assertForbidden();
        $this->actingAs($this->tesoriere)->post("/movimenti/{$entrata->id}/eliminazione", ['motivazione' => 'prova'])->assertSessionHasErrors('movimento');
        $this->assertNull($entrata->refresh()->eliminato_il);

        $this->actingAs($this->tesoriere)->post("/import/{$this->import->id}/giroconti/".Giroconto::sole()->id.'/scioglimento')
            ->assertRedirect(route('import.revisione', [$this->import, 'passo' => 'tutti']));
        $this->assertSame(0, Giroconto::count());
        $this->assertSame('da_classificare', $prelievo->refresh()->stato);
        $this->assertNull($prelievo->confermato_da);
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_id' => $prelievo->id, 'azione' => 'giroconto_sciolto']);
        $this->revisione()->assertSee('data-passo="giroconti" data-conteggio="1"', false); // la regola lo propone di nuovo
        $this->actingAs($this->tesoriere)->get("/movimenti/{$prelievo->id}/classificazione")->assertOk();
    }

    public function test_la_conferma_toglie_la_classificazione_che_il_movimento_aveva_come_proposta(): void
    {
        $prelievo = $this->prelievo();
        $entrata = $this->inCassa('Contanti ritirati', 10000, 'entrata');
        $this->actingAs($this->tesoriere)->put("/movimenti/{$entrata->id}/classificazione", [
            'imputazioni' => [['tipologia_id' => Tipologia::where('nome', 'Quote associative')->value('id'), 'area' => 'A']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $entrata->imputazioni()->count());

        $this->conferma($prelievo, $entrata)->assertSessionHasNoErrors();

        $this->assertSame(0, $entrata->imputazioni()->count()); // un giroconto non finisce nel Modello D
    }

    public function test_per_un_prelievo_senza_la_meta_in_cassa_l_app_propone_di_scriverla_con_registra_e_conferma(): void
    {
        $prelievo = $this->prelievo();

        $this->revisione('giroconti')
            ->assertSeeText('Manca la metà sull’altro conto')
            ->assertSeeText('Prelievo da Conto corrente')
            ->assertSeeText('Registra e conferma');

        $this->registraMeta($prelievo)->assertRedirect(route('import.revisione', [$this->import, 'passo' => 'giroconti']))->assertSessionHasNoErrors();

        $meta = Movimento::where('conto_id', $this->cassa->id)->sole();
        $this->assertSame(['entrata', 10000, '2026-05-10', 'Prelievo da Conto corrente', true, 'confermato'], [$meta->verso, $meta->importo, $meta->data->toDateString(), $meta->descrizione, $meta->manuale, $meta->stato]);
        $this->assertSame($this->tesoriere->id, $meta->inserito_da);
        $giroconto = Giroconto::sole();
        $this->assertSame([$prelievo->id, $meta->id], [$giroconto->uscita_id, $giroconto->entrata_id]);
        $this->assertSame('confermato', $prelievo->refresh()->stato);
        $this->assertSame(15000, $this->cassa->saldoAl('2026-05-31'));
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'movimento', 'oggetto_id' => $meta->id, 'azione' => 'inserito']);
        $this->assertDatabaseHas('registro_modifiche', ['oggetto_tipo' => 'movimento', 'oggetto_id' => $meta->id, 'azione' => 'giroconto_confermato']);
    }

    public function test_un_versamento_in_banca_propone_la_meta_in_uscita_dalla_cassa(): void
    {
        $versamento = $this->dellaBanca('VERSAMENTI', 'VERSAMENTO CONTANTI ESEMPIO', 20000, 'entrata');

        $this->registraMeta($versamento)->assertSessionHasNoErrors();

        $meta = Movimento::where('conto_id', $this->cassa->id)->sole();
        $this->assertSame(['uscita', 20000, 'Versamento su Conto corrente'], [$meta->verso, $meta->importo, $meta->descrizione]);
    }

    public function test_la_meta_non_si_scrive_se_esiste_gia_o_su_un_conto_che_non_va(): void
    {
        $prelievo = $this->prelievo();
        $esistente = $this->inCassa('Contanti ritirati', 10000, 'entrata', '2026-05-12');

        $this->registraMeta($prelievo)->assertSessionHasErrors('conferma'); // si abbina quella che c'è, la cassa non si raddoppia
        $this->assertSame(1, Movimento::where('conto_id', $this->cassa->id)->count());

        $esistente->delete();
        $chiusa = Conto::factory()->create(['tipo' => 'cassa', 'nome' => 'Cassa Festa', 'aperto_il' => '2026-01-01', 'chiuso_il' => '2026-05-01']);
        $this->registraMeta($prelievo, $chiusa)->assertSessionHasErrors('conferma'); // chiusa prima della data del movimento
        $this->registraMeta($prelievo, $this->banca)->assertSessionHasErrors('conferma'); // non è una cassa
        $this->assertSame(0, Giroconto::count());
        $this->registraMeta($prelievo)->assertSessionHasNoErrors();
    }

    public function test_il_secondo_prelievo_con_una_sola_entrata_in_cassa_registra_la_sua_meta(): void
    {
        // L'entrata in cassa è la metà del primo prelievo: per il secondo la proposta è scrivere la metà, e deve riuscire.
        $primo = $this->prelievo(10000, '2026-05-10');
        $secondo = $this->prelievo(10000, '2026-05-11');
        $entrata = $this->inCassa('Contanti ritirati', 10000, 'entrata', '2026-05-10');

        $this->registraMeta($secondo)->assertSessionHasNoErrors();

        $this->assertSame([$secondo->id], Giroconto::pluck('uscita_id')->all());
        $this->assertSame('da_classificare', $primo->refresh()->stato);
        $this->assertSame('da_classificare', $entrata->refresh()->stato);
        $this->registraMeta($secondo)->assertSessionHasErrors('conferma'); // ora è abbinato
    }

    public function test_la_meta_non_si_scrive_se_in_cassa_c_e_gia_un_movimento_confermato_che_le_somiglia(): void
    {
        $prelievo = $this->prelievo();
        $this->inCassa('Contanti ritirati', 10000, 'entrata', '2026-05-12')->forceFill(['stato' => 'confermato'])->save();

        $this->registraMeta($prelievo)->assertSessionHasErrors('conferma');
        $this->assertSame(1, Movimento::where('conto_id', $this->cassa->id)->count());
        $this->assertSame(0, Giroconto::count());
    }

    public function test_una_meta_senza_corrispondenza_dopo_alcuni_giorni_viene_segnalata(): void
    {
        $recente = $this->prelievo(5000, '2026-05-30'); // due giorni
        $vecchio = $this->prelievo(10000, '2026-05-10'); // ventidue giorni

        $this->actingAs($this->tesoriere)->get('/')
            ->assertSeeText('Giroconti senza corrispondenza')
            ->assertSeeText('da 22 giorni senza l’altra metà')
            ->assertDontSeeText('da 2 giorni senza l’altra metà');
        $this->revisione('giroconti')->assertSeeText('Senza corrispondenza da 22 giorni');
        $this->assertSame([$vecchio->id], Giroconti::orfane()->pluck('movimento.id')->all());

        $this->registraMeta($vecchio)->assertSessionHasNoErrors();
        $this->actingAs($this->tesoriere)->get('/')->assertDontSeeText('Giroconti senza corrispondenza'); // abbinato: non è più orfano
        $this->assertNotNull($recente);
    }

    public function test_una_metà_senza_regola_di_meta_aspetta_l_altro_conto_e_viene_segnalata(): void
    {
        config(['giroconti.regole.credit_agricole_csv.prelievo' => ['nome' => 'Prelievo', 'verso' => 'uscita', 'contiene' => ['PRELIEV'], 'con' => 'cassa', 'giorni' => 3]]);
        $this->prelievo();

        $this->revisione('giroconti')->assertSeeText('Aspetta la metà dall’altro conto')->assertDontSeeText('Registra e conferma')->assertSeeText('Senza corrispondenza da 22 giorni');
    }

    public function test_le_regole_si_aggiungono_e_si_ritirano_per_formato(): void
    {
        $this->dellaBanca('BONIFICO ESTERO', 'BONIFICO ESTERO VERSO CASSA FESTA', 7000, 'uscita');
        $this->inCassa('Dalla banca', 7000, 'entrata', '2026-05-10');
        $regole = config('giroconti.regole.credit_agricole_csv');
        $this->revisione()->assertSee('data-passo="giroconti" data-conteggio="0"', false); // nessuna regola lo riconosce

        config(['giroconti.regole.credit_agricole_csv' => $regole + ['estero' => ['nome' => 'Bonifico alla cassa', 'verso' => 'uscita', 'contiene' => ['verso cassa'], 'con' => 'cassa', 'giorni' => 3]]]); // senza maiuscole
        $this->revisione()->assertSee('data-passo="giroconti" data-conteggio="1"', false)->assertSeeText('Bonifico alla cassa');

        config(['giroconti.regole.credit_agricole_csv' => $regole]); // ritirata
        $this->revisione()->assertSee('data-passo="giroconti" data-conteggio="0"', false);
    }

    public function test_chiudere_il_conto_spegne_le_sue_regole(): void
    {
        $this->prelievo();
        $this->revisione()->assertSee('data-passo="giroconti" data-conteggio="1"', false);

        $this->actingAs($this->tesoriere)->post("/conti/{$this->banca->id}/chiusura", ['chiuso_il' => '2026-05-31'])->assertSessionHasNoErrors();

        $this->revisione()->assertSee('data-passo="giroconti" data-conteggio="0"', false)->assertSee('data-passo="decidere" data-conteggio="1"', false);
        $this->assertSame(0, Giroconti::orfane()->count());
    }

    public function test_la_meta_non_si_scrive_su_una_cassa_chiusa_e_senza_cassa_aperta_non_c_e_il_modulo(): void
    {
        $this->prelievo();
        $this->cassa->update(['chiuso_il' => '2026-05-05']);

        $this->revisione('giroconti')->assertSeeText('Nessun conto di questo tipo era aperto il 10/05/2026')->assertDontSeeText('Registra e conferma');
    }

    public function test_un_abbinamento_che_nessuna_regola_propone_o_non_piu_libero_si_rifiuta(): void
    {
        $prelievo = $this->prelievo();
        $altroImporto = $this->inCassa('Altra cosa', 9999, 'entrata');
        $lontano = $this->inCassa('Troppo tardi', 10000, 'entrata', '2026-05-20');
        $buono = $this->inCassa('Contanti ritirati', 10000, 'entrata', '2026-05-11');

        $this->conferma($prelievo, $altroImporto)->assertSessionHasErrors('conferma');
        $this->conferma($prelievo, $lontano)->assertSessionHasErrors('conferma');
        $this->conferma($prelievo, $prelievo)->assertSessionHasErrors('movimento_b');
        $this->assertSame(0, Giroconto::count());

        $this->conferma($prelievo, $buono)->assertSessionHasNoErrors();
        // Una pagina rimasta aperta: la metà è già presa, non si confermano due volte né si riusa
        $altroPrelievo = $this->prelievo(10000, '2026-05-12');
        $this->conferma($prelievo, $buono)->assertSessionHasErrors('conferma');
        $this->conferma($altroPrelievo, $buono)->assertSessionHasErrors('conferma');
        $this->assertSame(1, Giroconto::count());
    }

    public function test_due_prelievi_uguali_non_si_abbinano_alla_stessa_entrata(): void
    {
        $primo = $this->prelievo(10000, '2026-05-10');
        $secondo = $this->prelievo(10000, '2026-05-11');
        $entrata = $this->inCassa('Contanti ritirati', 10000, 'entrata', '2026-05-11');

        $proposte = Giroconti::proposte();
        $this->assertSame(['coppia', 'meta'], $proposte->pluck('tipo')->all()); // la prima ha l'entrata; l'altra ne vuole una nuova
        $this->assertSame([$primo->id, $secondo->id], $proposte->pluck('movimento.id')->all());
        $this->assertSame($entrata->id, $proposte[0]['controparte']->id);
    }

    public function test_senza_il_permesso_non_si_confermano_i_giroconti(): void
    {
        $prelievo = $this->prelievo();
        $entrata = $this->inCassa('Contanti ritirati', 10000, 'entrata');
        $consigliere = $this->utenteCon('Consigliere');

        $this->conferma($prelievo, $entrata, $consigliere)->assertForbidden();
        $this->registraMeta($prelievo, null, $consigliere)->assertForbidden();
        $this->actingAs($consigliere)->get('/')->assertDontSeeText('Giroconti senza corrispondenza');
        $this->assertSame(0, Giroconto::count());
    }

    public function test_la_meta_in_cassa_segue_l_ambito_di_chi_scrive(): void
    {
        $prelievo = $this->prelievo();
        $altraCassa = Conto::factory()->create(['tipo' => 'cassa', 'nome' => 'Cassa Festa', 'aperto_il' => '2026-01-01']);
        $ruolo = Ruolo::create(['nome' => 'Abbinatore', 'permessi' => ['vedere', 'importare_e_confermare', 'inserire_movimenti_cassa']]);
        $utente = $this->utenteCon($ruolo, ['conti' => [$altraCassa->id]]); // scrive solo sulla Cassa Festa

        $this->registraMeta($prelievo, $this->cassa, $utente)->assertForbidden();
        $this->assertSame(0, Giroconto::count());
        $this->registraMeta($prelievo, $altraCassa, $utente)->assertSessionHasNoErrors();
        $this->assertSame($altraCassa->id, Giroconto::sole()->entrata->conto_id);
    }

    public function test_il_permesso_si_rilegge_a_riga_bloccata(): void
    {
        $prelievo = $this->prelievo();
        $entrata = $this->inCassa('Contanti ritirati', 10000, 'entrata');
        $utente = $this->utenteCon('Tesoriere');
        $this->assertTrue($utente->puo(Permesso::ImportareEConfermare)); // le assegnazioni restano caricate sull'utente
        Assegnazione::where('user_id', $utente->id)->delete(); // revocato tra il controllo della rotta e il blocco

        try {
            Giroconti::conferma($utente, $prelievo->id, $entrata->id);
            $this->fail('Doveva rifiutare.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, Giroconto::count());
        $this->assertSame('da_classificare', $prelievo->refresh()->stato);
    }

    public function test_la_transazione_blocca_i_conti_e_poi_i_movimenti_prima_di_leggere(): void
    {
        // MySQL (REPEATABLE READ) fotografa alla prima lettura semplice: dopo i lock dei conti, un solo lock su tutti i movimenti
        // coinvolti e nessuna lettura semplice prima. Il test gira già in una transazione: si guarda la sequenza delle query.
        $prelievo = $this->prelievo();
        $entrata = $this->inCassa('Contanti ritirati', 10000, 'entrata');
        DB::enableQueryLog();
        $this->conferma($prelievo, $entrata)->assertSessionHasNoErrors();

        $query = DB::getQueryLog();
        $inizio = array_key_first(array_filter($query, fn ($q) => str_contains($q['query'], 'from `conti`') && str_contains($q['query'], 'for update')));
        $this->assertNotNull($inizio);
        $lock = null;
        foreach (array_slice($query, $inizio) as $q) {
            if (! str_contains($q['query'], 'for update')) {
                $this->fail('Lettura semplice prima del lock dei movimenti: '.$q['query']);
            }
            if (str_contains($q['query'], 'from `movimenti`') && count(array_intersect([$prelievo->id, $entrata->id], $q['bindings'])) === 2) {
                $lock = $q;
                break;
            }
        }
        $this->assertNotNull($lock, 'nessun lock unico sui due movimenti');
        $this->assertStringContainsString('order by `id`', $lock['query']);
    }

    public function test_una_regola_del_tesoriere_non_propone_sul_movimento_che_un_giroconto_riconosce(): void
    {
        $prelievo = $this->prelievo();
        $paypal = Conto::factory()->create(['tipo' => 'paypal', 'nome' => 'PayPal', 'formato_import' => 'paypal_csv', 'riga_modello_d' => 'depositi']);
        $altro = Movimento::factory()->create([
            'conto_id' => $paypal->id, 'manuale' => false, 'inserito_da' => $this->tesoriere->id, 'descrizione' => 'PRELIEVO SOMMA PICCOLA', 'importo' => 500, 'verso' => 'uscita',
        ]);

        $this->actingAs($this->tesoriere)->post('/regole', [
            'nome' => 'Prelievi', 'testo' => 'PRELIEV', 'tipologia_id' => Tipologia::where('nome', 'Utenze')->value('id'), 'iniziativa_id' => Iniziativa::where('nome', 'Carnevale')->value('id'),
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(Regola::where('nome', 'Prelievi')->first());
        $this->assertSame('proposto', $altro->refresh()->stato); // la regola lavora dove nessun giroconto la precede
        $this->assertSame('da_classificare', $prelievo->refresh()->stato);
        $this->assertSame(0, $prelievo->imputazioni()->count());
    }
}
