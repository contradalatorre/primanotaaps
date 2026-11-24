<?php

namespace Tests\Unit;

use App\Esercizio\Natura;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le regole della natura commerciale calcolata (#52), a tabella: niente database né HTTP. Il test 2-bis dell'area A
 * (#54) non è qui: le entrate che ne dipendono si riconoscono da `dipendeDal2bis`.
 */
class NaturaCommercialeTest extends TestCase
{
    private const COMMERCIALE = true;

    private const NON_COMMERCIALE = false;

    private function ingressi(array $diversi): array
    {
        return $diversi + ['lato' => 'entrate', 'area' => 'A', 'voce' => 'A7', 'tipologia_id' => 1, 'proprieta' => [], 'tipo_controparte' => null, 'affiliazione_rete' => false];
    }

    /** @return array<string, array{array, ?bool, string}> */
    public static function casi(): array
    {
        return [
            'quota associativa: non è un corrispettivo' => [['voce' => 'A1'], self::NON_COMMERCIALE, 'quota-non-corrispettiva'],
            'liberalità' => [['voce' => 'A4'], self::NON_COMMERCIALE, 'non-corrispettivo'],
            '5 per mille' => [['voce' => 'A5'], self::NON_COMMERCIALE, 'non-corrispettivo'],
            'contributo di un privato in A' => [['voce' => 'A6'], self::NON_COMMERCIALE, 'non-corrispettivo'],
            'contributo pubblico in A' => [['voce' => 'A8'], self::NON_COMMERCIALE, 'non-corrispettivo'],
            'corrispettivo di un terzo in A: non commerciale se il test 2-bis è rispettato' => [['voce' => 'A7', 'tipo_controparte' => 'terzo'], self::NON_COMMERCIALE, 'corrispettivo-area-a'],
            'corrispettivo senza controparte in A7' => [['voce' => 'A7'], self::NON_COMMERCIALE, 'corrispettivo-area-a'],
            'contratto con un ente pubblico in A9' => [['voce' => 'A9'], self::NON_COMMERCIALE, 'corrispettivo-area-a'],
            'corrispettivo di un socio in A7: decommercializzato (art. 85 c. 1)' => [['voce' => 'A7', 'tipo_controparte' => 'socio'], self::NON_COMMERCIALE, 'corrispettivo-socio'],
            'voce degli associati senza controparte indicata (A2)' => [['voce' => 'A2'], self::NON_COMMERCIALE, 'corrispettivo-socio'],
            'voce degli associati ma la controparte è un terzo: vince la scelta' => [['voce' => 'A3', 'tipo_controparte' => 'terzo'], self::NON_COMMERCIALE, 'corrispettivo-area-a'],
            'commerciale per natura, anche in area A (art. 85 c. 3)' => [['voce' => 'A7', 'proprieta' => ['commerciale_per_natura']], self::COMMERCIALE, 'commerciale-per-natura'],
            'commerciale per natura prevale sul socio (somministrazione ai soci, art. 85 c. 3)' => [['voce' => 'A2', 'tipo_controparte' => 'socio', 'proprieta' => ['commerciale_per_natura', 'somministrazione']], self::COMMERCIALE, 'commerciale-per-natura'],
            'sponsorizzazioni in B3' => [['area' => 'B', 'voce' => 'B3', 'proprieta' => ['commerciale_per_natura', 'neutra_nel_test_dell_ente']], self::COMMERCIALE, 'commerciale-per-natura'],
            'prestazione a terzi in B3' => [['area' => 'B', 'voce' => 'B3'], self::COMMERCIALE, 'area-b'],
            'contributo in B2' => [['area' => 'B', 'voce' => 'B2'], self::COMMERCIALE, 'area-b'],
            'cessione ai soci in B1' => [['area' => 'B', 'voce' => 'B1', 'tipo_controparte' => 'socio'], self::COMMERCIALE, 'area-b'],
            'somministrazione ai soci con l\'affiliazione (art. 85 c. 4)' => [['voce' => 'A2', 'tipo_controparte' => 'socio', 'affiliazione_rete' => true, 'proprieta' => ['commerciale_per_natura', 'somministrazione']], self::NON_COMMERCIALE, 'somministrazione-ai-soci-affiliata'],
            'somministrazione ai soci con l\'affiliazione, in area B: l\'attività diversa resta commerciale' => [['area' => 'B', 'voce' => 'B1', 'tipo_controparte' => 'socio', 'affiliazione_rete' => true, 'proprieta' => ['commerciale_per_natura', 'somministrazione']], self::COMMERCIALE, 'commerciale-per-natura'],
            'somministrazione ai soci senza l\'affiliazione' => [['voce' => 'A2', 'tipo_controparte' => 'socio', 'proprieta' => ['commerciale_per_natura', 'somministrazione']], self::COMMERCIALE, 'commerciale-per-natura'],
            'somministrazione con l\'affiliazione ma la controparte non è indicata: prudente' => [['voce' => 'A2', 'affiliazione_rete' => true, 'proprieta' => ['commerciale_per_natura', 'somministrazione']], self::COMMERCIALE, 'commerciale-per-natura'],
            'somministrazione ai terzi con l\'affiliazione' => [['voce' => 'A7', 'tipo_controparte' => 'terzo', 'affiliazione_rete' => true, 'proprieta' => ['commerciale_per_natura', 'somministrazione']], self::COMMERCIALE, 'commerciale-per-natura'],
            'l\'affiliazione non decommercializza altro che la somministrazione' => [['voce' => 'A3', 'tipo_controparte' => 'socio', 'affiliazione_rete' => true, 'proprieta' => ['commerciale_per_natura']], self::COMMERCIALE, 'commerciale-per-natura'],
            'raccolta fondi occasionale' => [['area' => 'C', 'voce' => 'C2'], self::NON_COMMERCIALE, 'area-c'],
            'raccolta fondi abituale' => [['area' => 'C', 'voce' => 'C1'], self::NON_COMMERCIALE, 'area-c1'],
            'interessi in D' => [['area' => 'D', 'voce' => 'D1'], self::NON_COMMERCIALE, 'area-d-e'],
            'altre entrate in E' => [['area' => 'E', 'voce' => 'E2'], self::NON_COMMERCIALE, 'area-d-e'],
            'entrata in A senza voce (combinazione non in tabella): non si può dire' => [['voce' => null], null, 'senza-voce'],
            'commerciale per natura non ha bisogno della voce' => [['voce' => null, 'proprieta' => ['commerciale_per_natura']], self::COMMERCIALE, 'commerciale-per-natura'],
            'uscita in B' => [['lato' => 'uscite', 'area' => 'B', 'voce' => 'B2'], null, 'uscita'],
            'uscita in A' => [['lato' => 'uscite', 'area' => 'A', 'voce' => 'A2'], null, 'uscita'],
            'senza tipologia' => [['tipologia_id' => null, 'voce' => null], null, 'senza-tipologia'],
        ];
    }

    public function test_la_spiegazione_di_c1_dice_che_la_parte_corrispettiva_conta_nel_forfettario(): void
    {
        $perche = Natura::calcola($this->ingressi(['area' => 'C', 'voce' => 'C1']))->perche;

        $this->assertStringContainsString('forfettario', $perche);
        $this->assertStringContainsString('art. 86', $perche);
    }

    #[DataProvider('casi')]
    public function test_la_natura_calcolata(array $diversi, ?bool $commerciale, string $regola): void
    {
        $natura = Natura::calcola($this->ingressi($diversi));

        $this->assertSame($commerciale, $natura->commerciale);
        $this->assertSame($regola, $natura->regola);
        $this->assertNotSame('', $natura->perche);
    }

    public function test_solo_i_corrispettivi_dei_terzi_in_area_a_dipendono_dal_test_2_bis(): void
    {
        $dipende = fn (array $diversi) => Natura::calcola($this->ingressi($diversi))->dipendeDal2bis;

        $this->assertTrue($dipende(['voce' => 'A7']));
        $this->assertTrue($dipende(['voce' => 'A9', 'tipo_controparte' => 'terzo']));
        $this->assertFalse($dipende(['voce' => 'A7', 'tipo_controparte' => 'socio']));
        $this->assertFalse($dipende(['voce' => 'A4']));
        $this->assertFalse($dipende(['area' => 'B', 'voce' => 'B3']));
        $this->assertFalse($dipende(['voce' => 'A7', 'proprieta' => ['commerciale_per_natura']]));
    }

    public function test_il_perche_cita_la_base_normativa(): void
    {
        $this->assertStringContainsString('art. 85 c. 3', Natura::calcola($this->ingressi(['proprieta' => ['commerciale_per_natura']]))->perche);
        $this->assertStringContainsString('art. 85 c. 4', Natura::calcola($this->ingressi(['voce' => 'A2', 'tipo_controparte' => 'socio', 'affiliazione_rete' => true, 'proprieta' => ['commerciale_per_natura', 'somministrazione']]))->perche);
        $this->assertStringContainsString('art. 85 c. 1', Natura::calcola($this->ingressi(['voce' => 'A2']))->perche);
    }

    public function test_cosa_e_cambiato_tra_due_serie_di_ingressi(): void
    {
        $prima = $this->ingressi([]);

        $this->assertSame([], Natura::cambiamenti($prima, $prima));
        $this->assertSame(['area', 'voce'], Natura::cambiamenti($prima, $this->ingressi(['area' => 'B', 'voce' => 'B3'])));
        $this->assertSame(['proprietà fiscali della tipologia'], Natura::cambiamenti($prima, $this->ingressi(['proprieta' => ['commerciale_per_natura']])));
        $this->assertSame(['affiliazione a una rete nazionale'], Natura::cambiamenti($prima, $this->ingressi(['affiliazione_rete' => true])));
    }
}
