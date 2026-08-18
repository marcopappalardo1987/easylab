<?php

namespace App\Providers;

use App\Listeners\AuditLogSubscriber;
use App\Models\Garanzia;
use App\Policies\GaranziaRicambioPolicy;
use Illuminate\Support\Facades\Event;
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
        Event::subscribe(AuditLogSubscriber::class);

        // ADR-029. Registrata a mano e non per convenzione (`GaranziaPolicy`)
        // perché non governa il model `Garanzia` per intero: risponde alle due
        // sole domande sulle righe `soggetto = ricambio`, dove il permesso da
        // solo non basta. Le garanzie MACCHINA restano sui permessi nudi.
        Gate::policy(Garanzia::class, GaranziaRicambioPolicy::class);
    }
}
