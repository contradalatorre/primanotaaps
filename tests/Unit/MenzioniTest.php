<?php

namespace Tests\Unit;

use App\Menzioni;
use PHPUnit\Framework\TestCase;

class MenzioniTest extends TestCase
{
    private const NOMI = ['u1' => 'Tina Verdi', 'u2' => 'Maria', 'u3' => 'Maria Rossi', 'r1' => 'Tesoriere', 'r2' => 'Lettore esterno'];

    public function test_trova_nomi_con_spazi_senza_badare_alle_maiuscole(): void
    {
        $this->assertEqualsCanonicalizing(['u1', 'r2'], Menzioni::trova('Ciao @tina verdi, e @LETTORE ESTERNO!', self::NOMI));
    }

    public function test_il_nome_piu_lungo_vince_su_quello_che_ne_e_l_inizio(): void
    {
        $this->assertSame(['u3'], Menzioni::trova('Chiedi a @Maria Rossi.', self::NOMI));
        $this->assertSame(['u2'], Menzioni::trova('Chiedi a @Maria.', self::NOMI));
        $this->assertEqualsCanonicalizing(['u2', 'u3'], Menzioni::trova('@Maria Rossi e @Maria', self::NOMI));
    }

    public function test_la_chiocciola_dentro_una_parola_o_un_nome_incompleto_non_chiama_nessuno(): void
    {
        $this->assertSame([], Menzioni::trova('scrivi a tina@Tina Verdi.it, oppure @Tesorieri, @Tesoriere2 o Tesoriere', self::NOMI));
    }

    public function test_una_menzione_ripetuta_conta_una_volta(): void
    {
        $this->assertSame(['r1'], Menzioni::trova('@Tesoriere @tesoriere', self::NOMI));
    }
}
