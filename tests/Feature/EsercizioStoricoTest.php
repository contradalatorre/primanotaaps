<?php

namespace Tests\Feature;

use App\Models\Conto;
use App\Models\EsercizioStorico;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\FogliInventati;
use Tests\TestCase;
use Tests\Unit\RendicontoFoglioTest;

/** Il caricamento dell'esercizio storico dal foglio approvato (#63, #18): anteprima, abbinamento manuale, conferma, blocco. Fogli inventati. */
class EsercizioStoricoTest extends TestCase
{
    use FogliInventati, RefreshDatabase;

    private User $tesoriere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-20 09:30');
        $this->tesoriere = $this->utenteCon('Tesoriere');
    }

    /** Un foglio 2025 inventato: quote 1.000,00, utenze 300,00, una riga da abbinare a mano e l'avanzo da investimenti sbagliato come nel foglio vero. */
    private function foglio(string $formato = 'xlsx', array $schede = []): UploadedFile
    {
        $righe = RendicontoFoglioTest::foglio(
            ['entrate:A1' => 1000, 'uscite:E2' => 300, 'uscite:I1' => 50],
            ['avanzo_investimenti' => 50.0], // dovrebbe essere −50,00
            ['cassa' => 120, 'depositi' => 530],
        );
        array_splice($righe, 5, 0, [['Spese postali varie', 12.5]]); // nella sezione delle uscite A, senza numero: non si riconosce

        return UploadedFile::fake()->createWithContent("foglio-2025.$formato", self::$formato(['RUNTS_Rendiconto' => $righe] + $schede));
    }

    private function carica(?UploadedFile $file = null, ?User $utente = null)
    {
        return $this->actingAs($utente ?? $this->tesoriere)->post('/esercizi/2025/storico', ['foglio' => $file ?? $this->foglio()]);
    }

    private function anteprimaUrl($risposta): string
    {
        $risposta->assertRedirectContains('/esercizi/2025/storico/anteprima/');

        return $risposta->headers->get('Location');
    }

    public function test_il_caricamento_mostra_l_anteprima_con_i_totali_ricalcolati_e_le_differenze_col_foglio(): void
    {
        $pagina = $this->actingAs($this->tesoriere)->get($this->anteprimaUrl($this->carica()))->assertOk();

        $pagina->assertSeeText('1.000,00')->assertSeeText('Spese postali varie')->assertSeeText('12,50')
            ->assertSee('value="120,00"', false)->assertSee('value="530,00"', false)
            // L'app ricalcola l'avanzo da investimenti (−50,00) e segnala che il foglio ne scrive +50,00.
            ->assertSeeText('Avanzo/disavanzo da investimenti')->assertSeeText('nel foglio 50,00')->assertSeeText('ricalcolato -50,00');
        $this->assertSame(0, EsercizioStorico::count()); // finché non si conferma non entra nulla
    }

    public function test_funziona_anche_con_un_ods(): void
    {
        $this->actingAs($this->tesoriere)->get($this->anteprimaUrl($this->carica($this->foglio('ods'))))->assertOk()->assertSeeText('1.000,00');
    }

    public function test_un_file_che_non_e_un_foglio_o_senza_il_foglio_del_rendiconto_si_rifiuta(): void
    {
        $this->carica(UploadedFile::fake()->createWithContent('x.xlsx', 'non è un foglio'))->assertSessionHasErrors('foglio');
        $this->carica(UploadedFile::fake()->createWithContent('x.ods', self::ods(['Cassa' => [['a']]])))->assertSessionHasErrors('foglio');
    }

    public function test_la_conferma_salva_con_l_abbinamento_manuale_i_figurativi_e_i_saldi_e_poi_blocca(): void
    {
        $url = $this->anteprimaUrl($this->carica());
        $storage = Storage::disk('local');

        $this->actingAs($this->tesoriere)->post($url, [
            'abbinamenti' => ['6' => 'uscite:E2'], 'saldo_cassa' => '120,00', 'saldo_depositi' => '530,00',
            'figurativi' => ['costi' => ['A' => '40,00', 'B' => '0'], 'proventi' => ['A' => '', 'B' => '5']],
        ])->assertRedirect('/esercizi/2025/storico')->assertSessionHas('ok');

        $storico = EsercizioStorico::where('esercizio', 2025)->firstOrFail();
        $this->assertEqualsCanonicalizing(['entrate:A1' => 100000, 'uscite:E2' => 31250, 'uscite:I1' => 5000], $storico->importi); // il JSON di MySQL riordina le chiavi
        $this->assertSame([12000, 53000], [$storico->saldo_cassa, $storico->saldo_depositi]);
        $this->assertEqualsCanonicalizing(['costi' => ['A' => 4000, 'B' => 0], 'proventi' => ['A' => 0, 'B' => 500]], $storico->figurativi);
        $m = $storico->prospetto();
        $this->assertSame(100000 - 31250, $m['gestione']['avanzo']['importo']);
        $this->assertSame(-5000, $m['investimenti']['avanzo']['importo']);
        $this->assertContains('avanzo_investimenti', array_column($storico->differenze, 'chiave'));
        $r = RegistroModifica::where('oggetto_tipo', 'esercizio_storico')->firstOrFail();
        $this->assertSame([$this->tesoriere->id, 'caricato'], [$r->user_id, $r->azione]);
        $this->assertSame([], $storage->files('estratti')); // l'anteprima è sparita

        // Un secondo caricamento dello stesso esercizio non c'è: è bloccato.
        $this->actingAs($this->tesoriere)->post('/esercizi/2025/storico', ['foglio' => $this->foglio()])->assertSessionHasErrors('esercizio');
    }
}
