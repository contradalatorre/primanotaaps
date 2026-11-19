<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\ConSpazioDiProva;
use Tests\TestCase;

/** Il trait dei test sullo spazio di prova (#87): due processi di test sullo stesso database non si cancellano a vicenda gli spazi. */
class ConSpazioDiProvaTest extends TestCase
{
    use ConSpazioDiProva, RefreshDatabase;

    /** Quello che vede un altro processo di test: una sua sessione sul server, che non è quella di questo test. */
    private function bloccoLibero(): bool
    {
        $altro = DB::connectUsing('altro-processo', config('database.connections.'.config('database.default')), true);
        try {
            return (bool) $altro->selectOne('select is_free_lock(?) as libero', [$this->nomeBloccoProva()])->libero;
        } finally {
            DB::purge('altro-processo');
        }
    }

    public function test_per_tutta_la_durata_del_test_lo_spazio_di_prova_e_riservato_a_questo_processo(): void
    {
        $this->assertFalse($this->bloccoLibero());

        $this->tearDownConSpazioDiProva(); // a fine test si libera: un altro processo può entrare
        $this->assertTrue($this->bloccoLibero());

        $this->setUpConSpazioDiProva();
        $this->assertFalse($this->bloccoLibero());
    }
}
