<?php

namespace Tests\Unit;

use App\Import\Iban;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** La maschera tocca solo gli IBAN veri: il resto del testo dell'estratto resta com'è (#27). Esempi inventati o d'uso pubblico. */
class IbanTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function casi(): iterable
    {
        yield 'compatto' => ['A IT60X0542811101000000123456 B', 'A '.str_repeat('*', 23).'3456 B'];
        yield 'a gruppi di 4' => ['A IT60 X054 2811 1010 0000 0123 456 B', 'A '.str_repeat('*', 23).'3456 B'];
        yield 'minuscolo' => ['a it60x0542811101000000123456 b', 'a '.str_repeat('*', 23).'3456 b'];
        yield 'minuscolo a gruppi' => ['a it60 x054 2811 1010 0000 0123 456 b', 'a '.str_repeat('*', 23).'3456 b'];
        yield 'estero' => ['DE89370400440532013000', str_repeat('*', 18).'3000'];
        yield 'la parola dopo un IBAN a gruppi resta' => ['IT60 X054 2811 1010 0000 0123 456 ROSA', str_repeat('*', 23).'3456 ROSA'];
        yield 'un numero dopo un IBAN a gruppi resta' => ['IT60 X054 2811 1010 0000 0123 456 12', str_repeat('*', 23).'3456 12'];
        yield 'la parola dopo un IBAN compatto resta' => ['IT60X0542811101000000123456 ROSA', str_repeat('*', 23).'3456 ROSA'];
        yield 'identificativo creditore SEPA' => ['ID CREDITORE IT98ZZZ0000012345678901', 'ID CREDITORE IT98ZZZ0000012345678901'];
        yield 'riferimento creditore RF' => ['RIF RF18 5390 0754 7034', 'RIF RF18 5390 0754 7034'];
        yield 'RF lungo' => ['RF18 5390 0754 7034 1234 5678', 'RF18 5390 0754 7034 1234 5678'];
        yield 'coda 6BIC' => ['IT 12 6BIC 1234', 'IT 12 6BIC 1234'];
        yield 'IBAN a gruppi e poi 6BIC' => ['IT60 X054 2811 1010 0000 0123 456 6BIC', str_repeat('*', 23).'3456 6BIC'];
        yield 'parola in maiuscolo con cifre' => ['PAGAMENTO FATTURA AB12CD34EF56GH78', 'PAGAMENTO FATTURA AB12CD34EF56GH78'];
        yield 'IBAN con un errore di cifra non è un IBAN' => ['IT60X0542811101000000123457', 'IT60X0542811101000000123457'];
    }

    #[DataProvider('casi')]
    public function test_maschera(string $testo, string $atteso): void
    {
        $this->assertSame($atteso, Iban::maschera($testo));
    }
}
