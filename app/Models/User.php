<?php

namespace App\Models;

use App\Permesso;
use Closure;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    /**
     * Gli Amministratori attivi: utenti interni con un'assegnazione attiva il cui ruolo dà la gestione utenti.
     * Si riconoscono dal permesso e non dal nome del ruolo, che l'Amministratore può cambiare. Stesse regole di `puo()`.
     */
    public static function amministratoriAttivi(): Builder
    {
        return static::where('esterno', false)->whereHas('assegnazioniAttive', fn (Builder $a) => $a->whereHas(
            'ruolo', fn (Builder $r) => $r->whereJsonContains('permessi', Permesso::GestireUtenti->value),
        ));
    }

    /**
     * Esegue una modifica a ruoli o assegnazioni e la annulla se toglie la gestione utenti all'ultimo Amministratore.
     * Con zero Amministratori (avvio a freddo) non blocca niente.
     */
    public static function proteggendoUltimoAmministratore(Closure $modifica): mixed
    {
        return DB::transaction(function () use ($modifica) {
            $prima = static::amministratoriAttivi()->count();
            $risultato = $modifica();

            if ($prima > 0 && static::amministratoriAttivi()->count() === 0) {
                throw ValidationException::withMessages(['amministratori' => 'Deve restare almeno un Amministratore con la gestione utenti: la modifica non è stata fatta.']);
            }

            return $risultato;
        });
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
                && (! $permesso->haAmbito() || $a->valeSuConto($contoId)),
        );
    }
}
