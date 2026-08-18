<?php

namespace App\Providers;

use App\Http\Middleware\EnforceAccountLockout;
use App\Listeners\AuditLogSubscriber;
use App\Models\Garanzia;
use App\Policies\GaranziaRicambioPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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

        // ADR-013 — prima registrazione persistente del progetto, e non è
        // un'ottimizzazione: le richieste /livewire/update NON passano dai
        // middleware di pagina, quindi senza questa riga ogni azione Livewire
        // (cioè quasi tutte le scritture dell'app) aggirerebbe il lockout. Un
        // test la esercita con un POST reale all'endpoint update.
        Livewire::addPersistentMiddleware(EnforceAccountLockout::class);
    }
}
