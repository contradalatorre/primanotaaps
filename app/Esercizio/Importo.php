<?php

namespace App\Esercizio;

/** Una cella che è un importo, in centesimi interi: i fogli di calcolo la scrivono come numero in euro, i documenti come testo. */
final readonly class Importo
{
    public function __construct(public int $centesimi) {}

    /** «1234.56»: dai centesimi con la sola aritmetica intera, mai un float. */
    public function decimale(): string
    {
        return ($this->centesimi < 0 ? '-' : '').intdiv(abs($this->centesimi), 100).'.'.str_pad((string) (abs($this->centesimi) % 100), 2, '0', STR_PAD_LEFT);
    }
}
