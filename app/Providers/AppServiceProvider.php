<?php

namespace App\Providers;

use App\Models\User;
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
        // Provvisorio: finché i ruoli non esistono (#35), gli Amministratori sono un elenco di email nei
        // segreti. #35 sostituisce questo controllo con il permesso del ruolo.
        Gate::define('avviare-migrazioni', fn (User $utente) => in_array(
            strtolower($utente->email),
            array_map('strtolower', config('app.amministratori')),
            true,
        ));
    }
}
