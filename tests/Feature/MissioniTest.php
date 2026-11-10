<?php

namespace Tests\Feature;

use App\Onboarding\Catalogo;
use App\Onboarding\Verifiche;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** I file delle missioni (#71): lo schema, e che tutto ciò che citano esista nell'app. */
class MissioniTest extends TestCase
{
    public function test_le_missioni_del_repo_si_leggono_e_citano_cose_che_esistono(): void
    {
        $catalogo = Catalogo::predefinito();

        $this->assertEqualsCanonicalizing(['benvenuto', 'import-e-revisione'], $catalogo->missioni()->keys()->all());
        foreach ($catalogo->missioni() as $missione) {
            $this->assertGreaterThanOrEqual(1, $missione->versione);
            $this->assertNotNull($catalogo->percorso($missione->percorso), "{$missione->id}: percorso sconosciuto");
            $this->assertNotEmpty($missione->passi);
            foreach ($missione->passi as $n => $passo) {
                $dove = "{$missione->id}, passo ".($n + 1);
                $this->assertTrue(Route::has($passo->schermata), "$dove: rotta {$passo->schermata} inesistente");
                $this->assertContains($passo->posa, Catalogo::POSE, "$dove: posa sconosciuta");
                $this->assertNotSame('', trim($passo->frase), "$dove: frase vuota");
                $this->assertNotSame('', $passo->elemento, "$dove: elemento mancante");
                if ($passo->pratico()) {
                    $this->assertContains($passo->verifica, Verifiche::NOMI, "$dove: verifica sconosciuta");
                }
            }
        }
    }

    public function test_un_file_con_un_campo_mancante_si_rifiuta_dicendo_quale(): void
    {
        $cartella = sys_get_temp_dir().'/missioni-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($cartella.'/missioni');
        File::put($cartella.'/percorsi.yml', "- {id: tutti, titolo: Per tutti}\n");
        File::put($cartella.'/missioni/rotta.yml', "titolo: Rotta\nversione: 1\npercorso: tutti\npermessi: [vedere]\nscenario: base\ntelefono: true\npassi:\n  - {schermata: home, posa: saluta, frase: Ciao}\n");

        try {
            $this->expectExceptionMessage('rotta: il passo 1 non ha «elemento»');
            (new Catalogo($cartella))->missioni();
        } finally {
            File::deleteDirectory($cartella);
        }
    }
}
