<?php

namespace App\Providers;

use App\Http\Middleware\EnforceAccountLockout;
use App\Listeners\AuditLogSubscriber;
use App\Models\Account;
use App\Models\Garanzia;
use App\Policies\AccountPolicy;
use App\Policies\GaranziaRicambioPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ADR-032 — il customer Stripe è l'Account, non lo User: qui si dice a
        // Cashier chi è il Billable, ed è da qui che discende `account_id` come
        // chiave esterna di `subscriptions`.
        Cashier::useCustomerModel(Account::class);

        // 🔴 Le rotte del pacchetto NON si registrano, e sono due.
        //
        // `POST {path}/webhook`: il `WebhookController` di Cashier aggancia
        // `VerifyWebhookSignature` solo `if (config('cashier.webhook.secret'))`
        // — cioè senza segreto l'endpoint accetta payload arbitrari e chiunque
        // ne conosca l'URL può bloccare account altrui. Fail-OPEN, su un
        // percorso che scrive `is_locked`. La riscriviamo in routes/web.php col
        // middleware dichiarato sulla rotta: incondizionato, visibile in
        // `route:list`, e senza dipendere da come il controller è costruito.
        //
        // `GET {path}/payment/{id}`: pubblica e non autenticata (il suo
        // controller monta il solo `VerifyRedirectUrl`), serve alla conferma
        // 3-D Secure di un flusso on-session che in V1 non esiste — l'unica
        // sottoscrizione passa da `easylab:abbona`, in console. Una superficie
        // che non serve non si tiene aperta.
        Cashier::ignoreRoutes();
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

        // ADR-032 — `billing.manage_own` è la condizione necessaria, la Policy
        // restringe con l'appartenenza all'account. Registrata a mano come
        // sopra, e con ability dal nome diverso dal permesso: vedi AccountPolicy.
        Gate::policy(Account::class, AccountPolicy::class);

        // ADR-013 — prima registrazione persistente del progetto, e non è
        // un'ottimizzazione: le richieste /livewire/update NON passano dai
        // middleware di pagina, quindi senza questa riga ogni azione Livewire
        // (cioè quasi tutte le scritture dell'app) aggirerebbe il lockout. Un
        // test la esercita con un POST reale all'endpoint update.
        Livewire::addPersistentMiddleware(EnforceAccountLockout::class);
    }
}
