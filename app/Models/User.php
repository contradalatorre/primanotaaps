<?php

namespace App\Models;

use App\Permesso;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['name', 'email', 'google_id'])]
#[Hidden(['google_refresh_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'google_refresh_token' => 'encrypted',
            'google_verificato_il' => 'datetime',
            'esterno' => 'boolean',
        ];
    }

    public function assegnazioni(): HasMany
    {
        return $this->hasMany(Assegnazione::class);
    }

    public function assegnazioniAttive(): HasMany
    {
        return $this->assegnazioni()->attive();
    }

    /**
     * I permessi di tutte le assegnazioni attive si sommano. L'ambito limita solo i permessi che ne hanno uno
     * (`Permesso::haAmbito`) e solo se si chiede per un conto preciso; senza conto basta averlo su qualche conto.
     * Un esterno non va mai oltre `Permesso::valePerEsterni`, qualunque ruolo gli si dia.
     */
    public function puo(Permesso $permesso, ?int $contoId = null): bool
    {
        if ($this->esterno && ! $permesso->valePerEsterni()) {
            return false;
        }

        return $this->loadMissing('assegnazioniAttive.ruolo')->assegnazioniAttive->contains(
            fn (Assegnazione $a) => $a->ruolo->haPermesso($permesso)
                && (! $permesso->haAmbito() || $contoId === null || $a->valeSuConto($contoId)),
        );
    }
}
