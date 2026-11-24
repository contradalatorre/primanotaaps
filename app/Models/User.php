<?php

namespace App\Models;

use App\Onboarding\Prova;
use App\Permesso;
use Closure;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable(['name', 'email', 'google_id', 'email_menzioni'])]
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
            'emergenza' => 'boolean',
        ];
    }

    /**
     * Gli Amministratori attivi: utenti interni con un'assegnazione attiva il cui ruolo dà la gestione utenti.
     * Si riconoscono dal permesso e non dal nome del ruolo, che l'Amministratore può cambiare. Stesse regole di `puo()`.
     * Con `$senzaScadenza` contano solo le assegnazioni senza scadenza: sono le uniche che domani ci saranno ancora,
     * e la guardia sull'ultimo Amministratore non deve potersi aggirare con una scadenza a oggi.
     */
    public static function amministratoriAttivi(bool $senzaScadenza = false): Builder
    {
        return static::where('esterno', false)->whereHas('assegnazioniAttive', fn (Builder $a) => $a
            ->conConti()
            ->when($senzaScadenza, fn (Builder $a) => $a->whereNull('scade_il'))
            ->whereHas('ruolo', fn (Builder $r) => $r->whereJsonContains('permessi', Permesso::GestireUtenti->value)));
    }

    /**
     * Esegue una modifica a ruoli o assegnazioni e la annulla se toglie la gestione utenti all'ultimo Amministratore
     * (senza scadenza: vedi `amministratoriAttivi`). Con zero Amministratori (avvio a freddo) non blocca niente.
     */
    public static function proteggendoUltimoAmministratore(Closure $modifica): mixed
    {
        return DB::transaction(function () use ($modifica) {
            // Il lock serializza due modifiche contemporanee: senza, due Amministratori che si revocano a vicenda
            // contano ciascuno 2 nella propria istantanea e restano a 0.
            $primaAttivi = static::amministratoriAttivi()->lockForUpdate()->pluck('id')->count();
            $primaStabili = static::amministratoriAttivi(true)->count();
            $risultato = $modifica();

            // Nessuno dei due gruppi può svuotarsi: né chi è Amministratore oggi, né chi lo è senza scadenza.
            if (($primaAttivi > 0 && static::amministratoriAttivi()->count() === 0)
                || ($primaStabili > 0 && static::amministratoriAttivi(true)->count() === 0)) {
                throw ValidationException::withMessages(['amministratori' => 'Deve restare almeno un Amministratore con la gestione utenti, e se ce n’è uno senza scadenza deve restarne uno: la modifica non è stata fatta.']);
            }

            return $risultato;
        });
    }

    /**
     * Chi è rimasto senza conti: ha un'assegnazione attiva senza conti (i suoi conti si sono chiusi) e nessun'altra
     * attiva che valga su qualche conto. Chi ne ha un'altra su tutti i conti scrive ancora, quindi non va avvisato.
     */
    public static function senzaConti(): Builder
    {
        return static::whereHas('assegnazioniAttive', fn (Builder $a) => $a->senzaConti())
            ->whereDoesntHave('assegnazioniAttive', fn (Builder $a) => $a->conConti());
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
     * Un'assegnazione decaduta (limitata a conti ora chiusi, senza conti) dà solo i permessi di lettura
     * (`Permesso::valePerEsterni`): se la persona non ha più conti resta in sola lettura (#16).
     * Un esterno non va mai oltre `Permesso::valePerEsterni`, qualunque ruolo gli si dia.
     */
    public function puo(Permesso $permesso, ?int $contoId = null): bool
    {
        // In prova (#71, ADR 0003) valgono i permessi della missione in corso, non quelli dell'assegnazione: un candidato
        // tesoriere si allena prima di avere il ruolo. Un esterno resta comunque entro `valePerEsterni`.
        if (($inProva = app(Prova::class)->permessi()) !== null) {
            return in_array($permesso, $inProva, true) && ! ($this->esterno && ! $permesso->valePerEsterni());
        }

        // L'account di emergenza (#36) vale come un Amministratore su tutto, senza avere assegnazioni: con una
        // conterebbe tra gli Amministratori e nasconderebbe l'avviso di quando non ne resta nessuno.
        if ($this->emergenza) {
            return true;
        }

        if ($this->esterno && ! $permesso->valePerEsterni()) {
            return false;
        }

        return $this->loadMissing('assegnazioniAttive.ruolo')->assegnazioniAttive->contains(
            fn (Assegnazione $a) => $a->ruolo->haPermesso($permesso)
                && ($permesso->valePerEsterni() || $a->valeSuConto($permesso->haAmbito() ? $contoId : null)),
        );
    }
}
