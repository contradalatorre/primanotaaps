<?php

namespace App\Providers;

use App\Models\User;
use App\Permesso;
use Illuminate\Database\Eloquent\Model;
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
