<?php

namespace Tests\Unit;

use App\Euro;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EuroTest extends TestCase
{
    /** @return iterable<string, array{string, ?int}> */
    public static function importi(): iterable
    {
        yield 'virgola' => ['12,50', 1250];
        yield 'punto' => ['12.50', 1250];
        yield 'intero' => ['12', 1200];
        yield 'una cifra decimale' => ['0,5', 50];
        yield 'migliaia e virgola' => ['1.234,56', 123456];
        yield 'con simbolo e spazi' => [' € 12,50 ', 1250];
        yield 'negativo' => ['-3,20', -320];
        yield 'tre decimali' => ['12,345', null];
        yield 'migliaia senza decimali' => ['1.234', null];
        yield 'lettere' => ['abc', null];
        yield 'vuoto' => ['', null];
        yield 'troppo grande' => ['99999999999999999999', null];
    }

    #[DataProvider('importi')]
    public function test_legge_gli_importi_scritti_all_italiana(string $testo, ?int $centesimi): void
    {
        $this->assertSame($centesimi, Euro::daTesto($testo));
    }

    public function test_formatta_all_italiana(): void
    {
        $this->assertSame('1.234,56', Euro::formatta(123456));
        $this->assertSame('-3,20', Euro::formatta(-320));
        $this->assertSame('0,05', Euro::formatta(5));
    }
}
