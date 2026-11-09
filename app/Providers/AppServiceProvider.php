<?php

namespace App\Providers;

use App\Models\User;
use App\Permesso;
use App\Models\Movimento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Le note (e le prossime tabelle polimorfe) salvano l'alias, non il nome della classe: come `registro_modifiche.oggetto_tipo`.
        Relation::enforceMorphMap(['movimento' => Movimento::class]);

        // Un'abilità per permesso: `can:gestire_utenti` nelle rotte, `@can('note')` nelle viste. Per i permessi
        // con ambito si passa il conto (id o modello) come secondo argomento: `can:inserire_movimenti_cassa,conto`.
        foreach (Permesso::cases() as $permesso) {
            Gate::define($permesso->value, fn (User $utente, int|string|Model|null $conto = null) => $utente->puo(
                $permesso,
                $conto instanceof Model ? $conto->getKey() : ($conto === null ? null : (int) $conto),
            ));
        }
    }
}
