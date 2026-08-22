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
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Models\Activity;

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

        $this->timbraLImpersonazioneSullAudit();
    }

    /**
     * 🔴 Le righe di audit scritte **dentro una richiesta** durante
     * un'impersonazione portano chi c'era davvero dietro.
     *
     * Senza questa registrazione il registro contiene un **dato falso**, non un
     * dettaglio di presentazione. `CauserResolver::getDefaultCauser()` legge
     * `auth()->guard()->user()`, e lab404 **sostituisce** quell'utente
     * (`ImpersonateManager::quietLogin`): un Superadmin che impersona un cliente
     * e ne cancella una garanzia produce una riga attribuita **al cliente**. Vale
     * per le diciotto scritture esplicite e — soprattutto — per tutte quelle del
     * trait `AuditsDomainWrites`, che sono la maggioranza del volume.
     *
     * **Il danno è cumulativo e irreversibile**: «l'audit non si riscrive» è già
     * una regola di questo progetto, quindi ogni giorno senza questa riga sono
     * righe che nessuno potrà più correggere. È per questo che è arrivata prima
     * della vista Audit, che è ciò che l'ha fatta scoprire.
     *
     * ⚠️ **Perché un hook e non un ritocco ai call site.**
     * `LogActivityAction::beforeLogging()` gira su **ogni** attività subito prima
     * del `save()`, qualunque sia la strada che l'ha prodotta: una registrazione
     * sola invece di diciotto call site più il trait — e soprattutto invece di
     * una regola da ricordare per ogni scrittura futura.
     *
     * ⚠️ **Perché non si ricostruisce in lettura.** Le coppie «Impersonation
     * avviata»/«terminata» portano già causer e subject giusti, e sarebbero
     * bastate a delimitare la finestra anche sullo storico. Ma una sessione
     * chiusa per **timeout** non scrive mai la riga di chiusura: la finestra
     * resterebbe aperta per sempre, e il registro comincerebbe ad attribuire a
     * EasyLab gesti che non sono suoi. In un registro di sicurezza
     * un'attribuzione euristica è peggio di una mancante.
     *
     * ⚠️ **Non copre ciò che viene scritto fuori dalla richiesta**, e la prima
     * stesura di questo docblock diceva «ogni riga» — una promessa di
     * completezza su un dato che qualcuno leggerà per trarne conclusioni.
     * `isImpersonating()` legge la **sessione**: in un worker di coda, in
     * console, in un webhook o in un seeder la sessione non c'è, quindi il
     * timbro non c'è. Il caso concreto esiste già: `InvitoUtente` è
     * `ShouldQueue` e scrive audit da `failed()`, quindi un invito fallito
     * originato durante un'impersonazione non ha né causer né timbro.
     * Il comportamento è giusto — nessuna sessione, nessuna affermazione — ma va
     * **dichiarato**, qui e in pagina. Chiuderlo davvero vorrebbe dire catturare
     * l'id **al dispatch** e trasportarlo nel payload del job: è un lavoro a sé,
     * non una riga.
     *
     * ⚠️ **`rescue()` perché questo è l'unico codice del progetto che sta sul
     * percorso di scrittura di tutto.** Il callback gira dentro l'evento del
     * model, cioè dentro la transazione del chiamante, a ogni scrittura del
     * prodotto: se un giorno `session()` lanciasse, non si perderebbe una riga di
     * audit — **si annullerebbe la scrittura di business**. Oggi non lancia
     * (verificato: in console `isImpersonating()` torna `false` senza toccare il
     * driver), ma il rapporto fra il danno e il beneficio non ammette il
     * dubbio. L'eccezione viene comunque **riportata**, non ingoiata.
     *
     * Lo **storico già scritto non è recuperabile**, e la vista Audit dichiara da
     * quale data l'attribuzione è affidabile — `AuditLog::ATTRIBUZIONE_AFFIDABILE_DA`,
     * in un posto solo.
     */
    private function timbraLImpersonazioneSullAudit(): void
    {
        // ⚠️ Ripulito **prima** di registrare: `$beforeLoggingCallbacks` è una
        // property **statica** del pacchetto, e `boot()` rigira a ogni riboot del
        // framework — cioè a ogni test. Senza questa riga a fine suite sono 1029
        // closure identiche eseguite su **ogni** riga di audit (misurato: ~6% di
        // suite). Il tempo è il meno: ciascuna closure lega `$this`, quindi
        // trattiene il provider e il container di *quel* boot. Oggi è innocuo solo
        // perché il corpo usa l'helper `app()`, che risolve dal container
        // corrente; il giorno in cui qualcuno scrivesse `$this->app->make(...)`
        // qui dentro, una riga di audit verrebbe decisa da un container morto.
        LogActivityAction::clearBeforeLoggingCallbacks();

        LogActivityAction::beforeLogging(function (Activity $attivita): void {
            rescue(function () use ($attivita) {
                if (! app('impersonate')->isImpersonating()) {
                    return;
                }

                $impersonatore = app('impersonate')->getImpersonatorId();

                // ⚠️ **Un'auto-attribuzione non è mai informativa, ed è falsa.**
                // `ImpersonateManager::take()` scrive la chiave di sessione
                // **prima** di emettere l'evento, quindi la riga «Impersonation
                // avviata» — che ha già l'impersonatore come causer — si
                // timbrerebbe da sé con `causer_id === impersonato_da`. Peggio:
                // aprire un'impersonazione non è un gesto compiuto *come*
                // l'impersonato, e quella riga finirebbe nel filtro «azioni
                // compiute in impersonazione» insieme a ogni apertura mai
                // avvenuta. (`leave()` invece ripulisce la sessione prima
                // dell'evento, quindi la riga di chiusura non si timbra: senza
                // questa guardia le due righe gemelle si comporterebbero in modo
                // diverso.)
                if ($attivita->causer_id === $impersonatore) {
                    return;
                }

                // `properties` non è mai `null` a questo punto — `ActivityLogger`
                // inizializza con `withProperties([])` su ogni percorso, trait
                // compreso — quindi il `??` è una cintura. Sulle righe del trait
                // questo è l'unico contenuto di `properties`, ed è voluto: il
                // timbro non si mescola ai valori cambiati, che sono un'altra cosa.
                $attivita->properties = ($attivita->properties ?? collect())
                    ->put('impersonato_da', $impersonatore);
            });
        });
    }
}
