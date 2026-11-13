<?php

namespace Tests\Unit;

use App\Esercizio\Annotazione;
use App\Esercizio\Calcolo;
use App\Esercizio\Imputata;
use App\Esercizio\Secondarieta;
use PHPUnit\Framework\TestCase;

/** L'annotazione in calce precompilata dagli esiti (#54): criterio, strumentalità, riparto. Importi inventati. */
class AnnotazioneTest extends TestCase
{
    /** 90,00 di entrate B su 250,00 di entrate e 200,00 di uscite: il 30% non regge (36%), il 66% sì (45%). */
    private static function secondarieta(?string $confermato = null): array
    {
        $imputazioni = [new Imputata('entrate', 'B3', 9000), new Imputata('entrate', 'A1', 16000), new Imputata('uscite', 'E2', 20000)];

        return Secondarieta::calcola(Calcolo::modelloD($imputazioni, [], []), true, 80, $confermato);
    }

    public function test_dice_il_criterio_proposto_da_confermare_i_numeri_e_l_esito(): void
    {
        $testo = Annotazione::testo(2026, self::secondarieta(), 'ricavi');

        $this->assertStringContainsString('criterio b (66%)', $testo);
        $this->assertStringContainsString('proposto dall’app, da confermare', $testo);
        $this->assertStringContainsString('90,00 €', $testo);
        $this->assertStringContainsString('45,00%', $testo);
        $this->assertStringContainsString('dei costi complessivi', $testo);
        $this->assertStringContainsString('Rispettato', $testo);
    }

    public function test_con_un_criterio_confermato_usa_quello_anche_se_peggiore_e_non_dice_da_confermare(): void
    {
        $testo = Annotazione::testo(2026, self::secondarieta('a'), 'ricavi');

        $this->assertStringContainsString('criterio a (30%)', $testo);
        $this->assertStringContainsString('delle entrate della gestione', $testo);
        $this->assertStringContainsString('Non rispettato', $testo);
        $this->assertStringContainsString('confermato dal Tesoriere', $testo);
        $this->assertStringNotContainsString('proposto dall’app', $testo);
    }

    public function test_la_strumentalita_e_una_dichiarazione_da_confermare_e_il_riparto_dice_il_criterio_scelto(): void
    {
        $ricavi = Annotazione::testo(2026, self::secondarieta(), 'ricavi');
        $diretti = Annotazione::testo(2026, self::secondarieta(), 'costi_diretti');

        $this->assertStringContainsString('Strumentalità:', $ricavi);
        $this->assertStringContainsString('[da confermare', $ricavi);
        $this->assertStringContainsString('quota dei ricavi', $ricavi);
        $this->assertStringContainsString('quota dei costi diretti', $diretti);
        $this->assertStringNotContainsString('quota dei ricavi', $diretti);
    }

    public function test_il_testo_non_ha_ritorni_a_capo_del_browser(): void
    {
        $this->assertStringNotContainsString("\r", Annotazione::testo(2026, self::secondarieta(), 'ricavi'));
    }
}
