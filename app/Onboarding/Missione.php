<?php

namespace App\Onboarding;

use App\Models\User;
use App\Permesso;

/** Una missione letta da un file di `resources/onboarding/missioni` (glossario: Missione). */
final readonly class Missione
{
    /**
     * @param  list<Permesso>  $permessi  quelli che servono a farla; in prova valgono questi, non quelli dell'assegnazione
     * @param  list<Passo>  $passi
     */
    public function __construct(
        public string $id,
        public string $titolo,
        public int $versione,
        public ?string $cosaECambiato,
        public string $percorso,
        public int $ordine,
        public string $durata,
        public array $permessi,
        public string $scenario,
        public bool $telefono,
        public array $passi,
    ) {}

    /** La missione è «dei miei permessi» se l'utente li ha tutti, per davvero (fuori dalla prova). */
    public function suggeritaA(User $utente): bool
    {
        return collect($this->permessi)->every(fn (Permesso $p) => $utente->puo($p));
    }
}
