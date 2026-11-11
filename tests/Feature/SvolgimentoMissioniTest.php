<?php

namespace Tests\Feature;

use App\Models\Avanzamento;
use App\Models\Conto;
use App\Models\ImportEstratto;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\Tipologia;
use App\Models\User;
use App\Onboarding\Catalogo;
use App\Onboarding\Passo;
use App\Onboarding\Schermate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ConSpazioDiProva;
use Tests\TestCase;

/**
 * Ogni missione del repo si svolge davvero, passo per passo, come la farebbe un utente (#71): l'elemento citato esiste nella
 * schermata del passo, il passo pratico si può fare e lo conferma lo stato dello spazio di prova, la missione dà il timbro.
 * Così una modifica all'app che rompe una missione si vede qui e non in silenzio.
 */
class SvolgimentoMissioniTest extends TestCase
{
    use ConSpazioDiProva, RefreshDatabase;

    /** @return iterable<string, array{string}> una riga per ogni file in resources/onboarding/missioni */
    public static function missioni(): iterable
    {
        foreach (glob(__DIR__.'/../../resources/onboarding/missioni/*.yml') as $file) {
            yield basename($file, '.yml') => [basename($file, '.yml')];
        }
    }

    #[DataProvider('missioni')]
    public function test_la_missione_si_svolge_dal_primo_all_ultimo_passo(string $id): void
    {
        $missione = Catalogo::predefinito()->missione($id);
        $utente = User::factory()->create(); // senza ruolo: in prova valgono i permessi della missione
        $this->actingAs($utente)->post(route('impara.avvia', $id));
        $stato = fn () => Avanzamento::where(['user_id' => $utente->id, 'missione' => $id])->firstOrFail();

        foreach ($missione->passi as $n => $passo) {
            $dove = "$id, passo ".($n + 1);
            $url = $this->prova()->per($utente, $missione, fn () => Schermate::url($passo->schermata));
            $this->assertNotNull($url, "$dove: la schermata {$passo->schermata} non c'è nello spazio di prova");

            $pagina = $this->get($url);
            $this->assertSame(200, $pagina->status(), "$dove: $url risponde {$pagina->status()} {$pagina->headers->get('Location')}");
            $html = $pagina->getContent();
            $this->assertStringContainsString('data-merlo="'.$passo->elemento.'"', $html, "$dove: l'elemento «{$passo->elemento}» non esiste nella schermata {$passo->schermata}");
            $this->assertStringContainsString('data-elemento="'.$passo->elemento.'"', $html, "$dove: Merlo non indica l'elemento");
            $this->assertSame($n, $stato()->passo, "$dove: la missione non è arrivata qui (un passo pratico non si è verificato?)");

            if ($passo->pratico()) {
                $this->assertStringNotContainsString('data-avanti', $html, "$dove: un passo pratico non ha «Avanti»");
                $this->faiIlPasso($utente, $passo, $html);
            } else {
                $this->post(route('prova.avanti'));
            }
        }
        $this->get(route('home')); // fa verificare l'ultimo passo, se pratico

        $this->assertTrue($stato()->completata(), "$id: la missione non risulta completata");
        $this->assertSame('completata', $stato()->stato);
        $this->assertFalse(session()->has('prova.missione'), 'a missione finita si esce dalla prova');
        $this->get(route('impara.timbri', ['nuovo' => $id]))->assertOk()->assertSee($missione->titolo);
    }

    /** Fa quello che l'utente farebbe nella schermata, con le stesse richieste che farebbe il browser. */
    private function faiIlPasso(User $utente, Passo $passo, string $html): void
    {
        $nelloSpazio = fn (callable $f) => $this->prova()->per($utente, null, $f);

        match ($passo->verifica) {
            'estratto_caricato' => $this->post(route('prova.estratto', $nelloSpazio(fn () => Conto::where('tipo', 'banca')->value('id')))),
            'import_confermato' => $this->post(route('import.conferma', $nelloSpazio(fn () => ImportEstratto::latest('id')->value('id')))),
            'movimenti_confermati', 'tutto_confermato' => $this->post(route('import.revisione.conferma-pronte', $nelloSpazio(fn () => ImportEstratto::latest('id')->value('id'))), ['impronta' => $this->impronta($html)]),
            'nessun_da_classificare' => $this->put(route('movimenti.classificazione.salva', $nelloSpazio(fn () => Movimento::where('stato', 'da_classificare')->value('id'))), [
                'imputazioni' => [[
                    'tipologia_id' => $nelloSpazio(fn () => Tipologia::where('nome', 'Acquisti per la somministrazione')->value('id')),
                    'iniziativa_id' => $nelloSpazio(fn () => Iniziativa::where('nome', 'Aggregazione e convivialità')->value('id')),
                ]],
            ]),
        };
    }

    private function impronta(string $html): string
    {
        $this->assertSame(1, preg_match('/name="impronta" value="([0-9a-f]{64})"/', $html, $m), 'la schermata non ha il pulsante delle proposte pronte');

        return $m[1];
    }
}
