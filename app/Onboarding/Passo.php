<?php

namespace App\Onboarding;

/** Un passo di una missione: dove (schermata e elemento), come si mostra Merlo e cosa dice; se è pratico, cosa si verifica. */
final readonly class Passo
{
    /** @param string|null $verifica nome di un controllo di `Verifiche` (solo i passi pratici) */
    public function __construct(
        public string $schermata,
        public string $elemento,
        public string $posa,
        public string $frase,
        public ?string $verifica = null,
        public int $almeno = 1,
    ) {}

    /** Un passo pratico si completa da solo quando lo stato dello spazio di prova lo conferma; gli altri vanno avanti con «Avanti». */
    public function pratico(): bool
    {
        return $this->verifica !== null;
    }
}
