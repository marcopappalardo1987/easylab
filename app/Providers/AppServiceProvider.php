<?php

namespace App\Providers;

use App\Http\Middleware\EnforceAccountLockout;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Http\Middleware\RimuoviByteNul;
use App\Listeners\AuditLogSubscriber;
use App\Models\Account;
use App\Models\Garanzia;
use App\Policies\AccountPolicy;
use App\Policies\GaranziaRicambioPolicy;
use App\Support\Listino\CatalogoPiani;
use App\Support\Listino\Stripe\PortaListinoStripe;
use App\Support\Listino\Stripe\PortaListinoStripeReale;
use App\Support\Registrazione\PortaleCheckout;
use App\Support\Registrazione\PortaleCheckoutStripe;
use App\Support\Tenancy\AccessibleNodesMemo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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
        // 3-D Secure di un flusso on-session che qui non esiste. Una superficie
        // che non serve non si tiene aperta.
        //
        // ⚠️ Dal 3 Ott 2026 il cliente attiva e cambia piano da `/abbonamento`
        // (ADR-045), e questa riga è la ragione di due scelte fatte là:
        // l'attivazione passa dal Checkout **ospitato**, dove il 3-D Secure lo
        // gestisce Stripe; il cambio usa `swap()` senza fattura immediata, che
        // non tenta nessun addebito dentro la richiesta. Chi introducesse un
        // addebito on-session (`swapAndInvoice()`, `create()` con una carta)
        // deve prima riaprire questa rotta — o lascia un pagamento senza un
        // posto in cui confermarlo.
        Cashier::ignoreRoutes();

        // ⛔ **`singleton` e non `Cache::`** — ADR-035. Il listino si legge una
        // volta per richiesta, e il memo vive nel container: Redis è
        // **condiviso** fra `easylab` e `easylab_test` (CLAUDE.md), e una
        // chiave di cache condivisa su una somma di denaro rifarebbe
        // l'incidente già pagato con `spatie.permission.cache`. Il container si
        // ricostruisce a ogni richiesta e a ogni test, quindi non serve nessun
        // reset globale in `tests/Pest.php`.
        $this->app->singleton(CatalogoPiani::class);

        // La porta verso Stripe per il listino (Product e Price). È
        // un'interfaccia perché la logica **nostra** — idempotenza, storico dei
        // prezzi, cosa succede se Stripe rifiuta — è sostanziale e va provata:
        // i test legano una finta e verificano le nostre transizioni di stato,
        // non le risposte di Stripe. Il percorso felice verso la rete resta
        // senza test di suite, come `easylab:abbona`.
        $this->app->bind(PortaListinoStripe::class, PortaListinoStripeReale::class);

        // La porta verso Stripe Checkout per il self-signup pubblico (ADR-012).
        // Interfaccia per la stessa ragione della riga qui sopra, con
        // un'aggravante: la logica **nostra** a valle — quando un account nasce,
        // l'idempotenza, il piano che non si legge dal payload — va provata
        // contro esiti che Stripe non ci darebbe mai su richiesta (sessione
        // aperta, pagamento non incassato, customer mancante). Un'interfaccia li
        // rende costruibili in una riga; una classe da estendere no.
        $this->app->bind(PortaleCheckout::class, PortaleCheckoutStripe::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::subscribe(AuditLogSubscriber::class);

        // La memo di AccessibleNodes (S7/T4) si svuota su login, logout,
        // impersonazione e scritture di assegnazioni e albero. Senza questa
        // riga resta inerte: si torna ai conteggi di prima, mai a nodi vecchi.
        Event::subscribe(AccessibleNodesMemo::class);

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

        // Stessa ragione per il 2FA obbligatorio (S7, security pass): senza, un
        // utente promosso a un ruolo col 2FA a pagina aperta continuava a
        // scrivere dalle azioni Livewire. La pagina di attivazione resta esente.
        Livewire::addPersistentMiddleware(EnsureTwoFactorIsEnabled::class);

        // Gli update Livewire saltano i middleware HTTP: il NUL si toglie anche lì.
        RimuoviByteNul::ancheSuLivewire();

        $this->timbraLImpersonazioneSullAudit();

        $this->limitaLeRegistrazioniPubbliche();

        $this->ereditaIlDiscoDocumentiDallAmbiente();
    }

    /**
     * 🔴 Il disco `documenti` prende le credenziali dal disco dell'ambiente, e
     * si tiene il proprio `throw` (🔗 ADR-042).
     *
     * ## Perché non bastano le `AWS_*`
     *
     * Su Laravel Cloud le credenziali di un bucket **non arrivano come
     * variabili `AWS_*`**: la piattaforma inietta `LARAVEL_CLOUD_DISK_CONFIG` e
     * `Illuminate\Foundation\Cloud::configureDisks()` registra al boot un disco
     * col nome del bucket, impostandolo come default. Un disco scritto a mano
     * con `env('AWS_ACCESS_KEY_ID')` nascerebbe quindi senza credenziali, e
     * funzionerebbe **solo in locale** — è la trappola già documentata in
     * `config/guide.php`, che lì si evita nominando il disco invece di
     * configurarlo.
     *
     * ## Perché qui nominarlo non basta, e serve una copia
     *
     * Perché `documenti` esiste separato **per una ragione sola**: `throw =>
     * true` (il perché sta in `config/filesystems.php`, e vale un 200 troncato
     * in download). La configurazione che inietta la piattaforma porta `'throw'
     * => false` **fisso**, non esposto dal pannello: chiamare il bucket
     * `documenti` la farebbe sovrascrivere silenziosamente, e con essa la
     * guardia. Si copia quindi la sua configurazione — chiavi, endpoint, bucket
     * — e ci si rimette sopra il solo flag che ci appartiene.
     *
     * ⚠️ **`config()` e non `env()`**: con la configurazione in cache `env()`
     * fuori da `config/` torna `null`, cioè in produzione questo metodo non
     * farebbe nulla. Il nome della sorgente si legge da
     * `filesystems.documenti_sorgente`.
     *
     * ⚠️ Gira in `boot()` e non in `register()`: `configureDisks()` è un
     * bootstrapper, e il disco che stiamo leggendo non esisterebbe ancora.
     *
     * Il caso «bucket puntato a mano con le `AWS_*`» resta intatto: se la
     * sorgente non è un disco `s3` non si tocca nulla, e `documenti` vale quel
     * che dice `config/filesystems.php`.
     */
    private function ereditaIlDiscoDocumentiDallAmbiente(): void
    {
        $sorgente = config('filesystems.documenti_sorgente');

        if ($sorgente === 'documenti' || config("filesystems.disks.{$sorgente}.driver") !== 's3') {
            return;
        }

        config(['filesystems.disks.documenti' => array_merge(
            config("filesystems.disks.{$sorgente}"),
            ['visibility' => 'private', 'throw' => true, 'report' => false],
        )]);
    }

    /**
     * 🔴 Il limite di tentativi sul modulo pubblico di `/registrati`
     * (🔗 ADR-012).
     *
     * ## Due chiavi e non una, perché una sola si aggira
     *
     * · **IP** — ferma il martellamento da una postazione, ma un proxy a
     *   rotazione lo annulla;
     * · **email** — ferma chi cambia indirizzo di rete per provare mille volte
     *   la stessa casella (che è il gesto con cui si scopre se un indirizzo è
     *   già cliente, se la risposta differisse).
     *
     * Laravel applica **tutti** i limiti dell'array: basta che uno sia esaurito
     * per rispondere 429. Con la sola chiave IP il secondo scenario passerebbe
     * indisturbato, e con la sola chiave email il primo.
     *
     * ⚠️ **La chiave dell'email va normalizzata come la normalizza il
     * controller** (`lower` + `trim`), o «Mario@Studio.it » e
     * «mario@studio.it» sarebbero due secchielli diversi per lo stesso
     * indirizzo — cioè il doppio dei tentativi per chi sa scrivere uno spazio.
     * Le due normalizzazioni devono restare d'accordo: un test le esercita
     * insieme.
     *
     * ⚠️ **`Str::transliterate` come nel limiter `login`**: senza, due grafie
     * unicode dello stesso indirizzo darebbero due secchielli.
     *
     * ## Perché qui e non in `FortifyServiceProvider`, dove stanno gli altri
     *
     * Perché questo modulo **non è di Fortify**, ed è una scelta dichiarata:
     * `Features::registration()` resta spenta in `config/fortify.php` (il
     * perché è scritto lì, e non è un dettaglio: quella feature autentica
     * l'utente appena creato e lo manda in dashboard senza che abbia pagato).
     * Mettere il limiter accanto a quelli di Fortify farebbe credere a chi
     * legge che la registrazione passi da lì.
     *
     * ## Tre al minuto
     *
     * Non è un numero difensivo scelto a caso: registrarsi è un gesto che una
     * persona compie **una volta**, e i due o tre tentativi in più esistono solo
     * per l'errore di battitura sulla password. Il limite del login è cinque
     * perché lì sbagliare è normale; qui no.
     */
    private function limitaLeRegistrazioniPubbliche(): void
    {
        RateLimiter::for('registrazione', function (Request $request) {
            $email = Str::transliterate(Str::lower(trim((string) $request->input('email'))));

            return [
                Limit::perMinute(3)->by('registrazione-ip|'.$request->ip()),
                Limit::perMinute(3)->by('registrazione-email|'.$email),
            ];
        });
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
