<?php

namespace Tests\Unit;

use App\Esercizio\Importo;
use App\Esercizio\Xlsx;
use App\Import\Foglio;
use PHPUnit\Framework\TestCase;

/** Il writer di fogli .xlsx (#58): ciò che scrive si rilegge con `Foglio`, che è il lettore dell'app. Dati inventati. */
class XlsxTest extends TestCase
{
    public function test_testi_numeri_e_importi_si_rileggono_uguali_e_gli_importi_vengono_dai_centesimi(): void
    {
        $contenuto = Xlsx::crea(['Dettaglio' => [
            ['celle' => ['Voce', 'Importo (€)', 'Numero']],
            ['celle' => ['Quote associative', new Importo(123456), 3], 'grassetto' => true],
            ['celle' => ['Commissione', new Importo(-5), null]],
            ['celle' => ['Zero', new Importo(0)]],
        ]]);

        $righe = Foglio::leggi($contenuto)['Dettaglio'];

        $this->assertSame(['Voce', 'Importo (€)', 'Numero'], $righe[0]);
        $this->assertSame(['Quote associative', 1234.56, 3], $righe[1]);
        $this->assertSame(-0.05, $righe[2][1]); // il segno non si perde sotto l'euro
        $this->assertSame(0.0, $righe[3][1]);
    }

    public function test_piu_fogli_nome_lungo_accorciato_e_caratteri_proibiti_tolti(): void
    {
        $contenuto = Xlsx::crea([
            'Modello D' => [['celle' => ["a\x01b & <c>"]]],
            str_repeat('x', 40) => [],
        ]);

        $fogli = Foglio::leggi($contenuto);

        $this->assertSame(['Modello D', str_repeat('x', 31)], array_keys($fogli));
        $this->assertSame('ab & <c>', $fogli['Modello D'][0][0]);
    }
}
