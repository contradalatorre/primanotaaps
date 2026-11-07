<?php

namespace Tests\Unit;

use App\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** "12345678901234567890" in base32: il segreto dell'Appendice B di RFC 6238 (SHA-1). */
    private const SEGRETO = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return iterable<string, array{int, string}> Codici dell'Appendice B (8 cifre) ridotti alle ultime 6. */
    public static function vettoriRfc6238(): iterable
    {
        yield 'T=59' => [59, '287082'];
        yield 'T=1111111109' => [1111111109, '081804'];
        yield 'T=1111111111' => [1111111111, '050471'];
        yield 'T=1234567890' => [1234567890, '005924'];
        yield 'T=2000000000' => [2000000000, '279037'];
    }

    #[DataProvider('vettoriRfc6238')]
    public function test_il_codice_segue_i_vettori_di_prova_della_rfc(int $istante, string $atteso): void
    {
        $this->assertSame($atteso, Totp::codice(self::SEGRETO, intdiv($istante, 30)));
    }

    public function test_verifica_accetta_il_passo_corrente_e_i_vicini_e_restituisce_quale(): void
    {
        $passo = intdiv(1234567890, 30);

        $this->assertSame($passo, Totp::verifica(self::SEGRETO, '005924', 1234567890));
        $this->assertSame($passo, Totp::verifica(self::SEGRETO, '005924', 1234567890 + 30));
        $this->assertSame($passo, Totp::verifica(self::SEGRETO, '005924', 1234567890 - 30));
        $this->assertNull(Totp::verifica(self::SEGRETO, '005924', 1234567890 + 60));
        $this->assertNull(Totp::verifica(self::SEGRETO, '000000', 1234567890));
    }

    public function test_senza_segreto_o_con_un_codice_malformato_non_si_verifica_nulla(): void
    {
        $this->assertNull(Totp::verifica('', '287082', 59));
        $this->assertNull(Totp::verifica(self::SEGRETO, '28708', 59));
        $this->assertNull(Totp::verifica(self::SEGRETO, '2870820', 59));
        $this->assertNull(Totp::verifica(self::SEGRETO, 'abcdef', 59));
    }
}
