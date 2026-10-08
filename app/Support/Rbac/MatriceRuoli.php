<?php

namespace App\Support\Rbac;

use App\Support\AuditLog;
use App\Support\Rbac;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * 🔴 La matrice ruolo→permesso, letta e **scritta** in un posto solo (S6 — ADR-016).
 *
 * È l'editor dei permessi di ruolo ridotto alla sua regola, senza pagina. Le
 * guardie **sono** la feature: se arrivassero dopo la griglia, la griglia
 * sarebbe «la cosa che funziona» e le guardie un innesto — la forma di difetto
 * che ADR-018 nomina («*l'azione è sicura perché il gate di rotta ha tenuto* è
 * il ragionamento che ha già prodotto un difetto in `_panoramica.blade.php`»).
 *
 * E c'è una ragione più stringente di quella stilistica: **il gate della pagina
 * è protetto dalla regola che la pagina implementa.** `roles.manage` e
 * `tenants.view_all` sono nel set bloccato, quindi rompere la guardia del set
 * bloccato significa rompere anche il gate di `/piattaforma`, di
 * `/piattaforma/audit` e di questo editor, tutti insieme. La regola deve reggere
 * prima che esista qualcosa che la usa.
 *
 * ## Le due guardie, che sono ortogonali e non si sostituiscono
 *
 * ⚠️ **Il set bloccato è una COLONNA, non una cella.** `Rbac::isLocked()` prende
 * un permesso, non una coppia — e `config/rbac.php` lo dice a parole:
 * «"Bloccato" dice che la UI non può ridistribuirlo, NON a chi è negato». Un
 * permesso bloccato non è quindi né revocabile né **concedibile**, a nessun
 * ruolo. La lettura per-coppia sembra più ricca ed è invece la falla: renderebbe
 * `roles.manage` concedibile all'Admin (l'auto-delega che ADR-016 vieta), e
 * soprattutto concedibile al `Tenant` — mentre `EnsureTwoFactorIsEnabled` gata
 * il secondo fattore **per nome di ruolo** (`Rbac::twoFactorRequiredRoles()`) e
 * non per permesso. Si otterrebbe un editor della matrice dei permessi
 * raggiungibile senza 2FA obbligatorio.
 *
 * *Il caso contrario, disinnescato perché è ciò che la prossima persona
 * troverà per primo*: ADR-016 enumera il set bloccato in forma di **coppia**
 * («`system.logs.view` → solo `Developer`»), e ADR-027 ha spostato
 * `garanzie.ricambio.*` a un ruolo che non le aveva *mentre* erano ancora
 * bloccate. Quel gesto fu fatto **in config e seeder**, cioè in codice — che
 * questa lettura consente esplicitamente. È la UI a non poterlo fare, non il
 * progetto.
 *
 * ⚠️ **La riga del Developer è inerte in ENTRAMBE le direzioni** — il set
 * bloccato non la copre, perché protegge sette permessi e non gli altri
 * quarantasette. `Developer => ['all' => true]` è la chiave di riserva della
 * piattaforma e non esiste alcun `Gate::before` da super-admin
 * (`AppServiceProvider` ha solo `Gate::policy()`), quindi il Developer dipende
 * davvero dalla matrice a DB. La definizione sta in `Rbac::isRuoloProtetto()` e
 * **solo** lì: la vista legge quella, non un secondo elenco.
 *
 * ## Perché `ValidationException` e non un 403
 *
 * Chi manda la richiesta **ha** `roles.manage`: non è un problema di
 * autorizzazione, è un gesto vietato per chiunque. Stessa forma di
 * `FissaVisibilitaSede::fissaVisibilita()` sul valore fuori enum — fail-closed e
 * rumoroso, mai un `return` silenzioso, perché una UI che rende quelle celle
 * senza controllo non può produrre questa chiamata: chi la produce non sta
 * usando la pagina.
 *
 * ## La cache dei permessi
 *
 * ⚠️ **Si scrive SOLO con l'API Eloquent di spatie** (`givePermissionTo()` /
 * `revokePermissionTo()`), mai con `permissions()->attach|detach|sync()` e mai
 * con `DB::table('role_has_permissions')`. Il motivo è meccanico, letto nel
 * vendor: quei due metodi chiamano `forgetCachedPermissions()` quando
 * `$this instanceof Role` (`HasPermissions.php:416-418, 465-467`), il pivot nudo
 * no. `forgetCachedPermissions()` dimentica la chiave `spatie.permission.cache`
 * su uno store **condiviso fra i processi web** (Redis in esercizio), quindi
 * l'invalidazione è globale e immediata: non «vale dal prossimo login», vale
 * dalla richiesta successiva, per tutti — ed è anche ciò che rende immediato il
 * recupero via `db:seed --class=RolesAndPermissionsSeeder`.
 *
 * ⚠️ **Buco dichiarato e non chiuso: i worker di coda in daemon.**
 * `PermissionServiceProvider` registra `PermissionRegistrar` come **singleton**
 * (non `scoped`) e `clearPermissionsCollection()` gira solo alla prima
 * risoluzione del `Gate` in boot: un `queue:work` che resta su tiene la propria
 * copia in memoria per tutta la vita del processo, e una modifica fatta dal web
 * gli è invisibile finché non riparte. Oggi l'esposizione è **nulla** — niente,
 * in coda, interroga permessi — quindi non si chiude in codice: chiuderla
 * costerebbe una lettura di cache per job (un listener su `JobProcessing`) per
 * un rischio che non ha superficie.
 *
 * 🛡️ **E «l'esposizione è nulla» ha smesso di essere un'affermazione**
 * (25 Ago 2026): `PermessiInCodaGuardrailTest` deriva ciò che gira in coda —
 * classi `ShouldQueue` **e** `Notification`, perché una notifica gira dove gira
 * chi la manda — e diventa rosso appena una di esse legge un permesso o un
 * ruolo. Il giorno in cui la superficie nasce, chi la fa nascere passa di lì e
 * legge cosa fare, invece di doversene ricordare.
 *
 * 🔴 **E il rimedio scritto qui fino a quel giorno era sbagliato.** Diceva
 * «`php artisan queue:restart` dopo ogni modifica alla matrice», che non regge
 * più per due fatti: su Laravel Cloud i worker si riavviano **da soli a ogni
 * deploy** (e `Setup Repository e Ambienti.md` §3.2 vieta `queue:restart` fra i
 * deploy command); e da S6 la matrice **non cambia al rilascio**, cambia a
 * runtime col click di un Superadmin su `/piattaforma/ruoli` — una checklist di
 * rilascio non scatta su un gesto che non è un rilascio. Il rimedio vero, quando
 * servirà, è `Queue::looping()` →
 * `app(PermissionRegistrar::class)->clearPermissionsCollection()`, **mai**
 * `forgetCachedPermissions()`, che cancella la chiave di cache condivisa e
 * farebbe rileggere la matrice a ogni processo web a ogni job.
 *
 * ## Perché NON passa da `VistaPiattaforma`
 *
 * `roles` e `permissions` sono tabelle **globali**, senza tenancy: non c'è
 * nessuno scope da togliere. Il riflesso sarebbe aggiungere alla porta un
 * `ruoli()`, e il docblock della porta lo rifiuta per nome — «un metodo in più
 * sarebbe un bypass finto, che legittimerebbe l'idea che serva sempre». Il
 * prezzo da accettare in cambio: questa classe **non eredita** il guardrail
 * «nessuna scrittura concatenata alla porta», quindi il guardrail sui sorgenti
 * contro le scritture dirette al pivot non è ridondanza — è la sua unica rete.
 *
 * 🔗 ERD §9 (`activity_log`), ADR-016 (RBAC e UI di gestione), ADR-018
 * (tenancy senza bypass), ADR-027 (canale `audit`), ADR-029 (set bloccato a 7).
 */
final class MatriceRuoli
{
    /**
     * Il guard di tutta l'applicazione. Hardcoded come nel seeder: non esiste un
     * secondo guard, e leggerlo dalla config aggiungerebbe un modo di divergere
     * fra chi semina e chi assegna.
     */
    private const GUARD = 'web';

    /**
     * La matrice intera: nome di ruolo → insieme dei suoi permessi.
     *
     * **Due query fisse**, qualunque sia il numero di ruoli: una per `roles`,
     * una per il pivot in eager load. ⚠️ Mai `$role->hasPermissionTo($p)` dentro
     * il doppio ciclo del chiamante — passa dal registrar e, senza eager load,
     * sono 324 risoluzioni per rendere una pagina.
     *
     * L'insieme è un array `nome => true` e non una lista, perché il consumo è
     * `isset($matrice[$ruolo][$permesso])`: su 324 celle un `in_array()` è una
     * scansione lineare per cella.
     *
     * L'ordine dei ruoli è quello che il DB restituisce (cioè quello di
     * creazione, cioè quello della config): **ordinare è una decisione di
     * presentazione** e sta nella vista, insieme alla ragione per cui i ruoli
     * vanno in colonna e i permessi in riga.
     *
     * @return array<string, array<string, true>>
     */
    public static function stato(): array
    {
        return Role::with('permissions')
            ->get()
            ->mapWithKeys(fn (Role $ruolo) => [
                $ruolo->name => $ruolo->permissions
                    ->mapWithKeys(fn (Permission $permesso) => [$permesso->name => true])
                    ->all(),
            ])
            ->all();
    }

    /** Concede un permesso a un ruolo. Idempotente: se ce l'ha già, resta. */
    public static function concedi(string $ruolo, string $permesso): void
    {
        self::applica($ruolo, $permesso, concedi: true);
    }

    /** Revoca un permesso a un ruolo. Idempotente: se non ce l'ha, resta senza. */
    public static function revoca(string $ruolo, string $permesso): void
    {
        self::applica($ruolo, $permesso, concedi: false);
    }

    /**
     * Inverte una cella, **leggendo lo stato attuale dal database**.
     *
     * ⚠️ Non accetta il valore desiderato dal chiamante, ed è la differenza che
     * conta: un booleano che arriva dal browser afferma uno stato che il
     * mittente aveva letto prima: due click in gara si sovrascriverebbero a
     * vicenda affermando ciascuno una verità vecchia. Calcolandolo qui, due
     * click producono due righe di audit e uno stato finale deterministico.
     *
     * `roles.updated_at` non serve da token di versione, e va detto perché è il
     * primo posto dove si guarderebbe: un `attach`/`detach` su una
     * `belongsToMany` **non tocca** i timestamp del padre. Un design ottimistico
     * dovrebbe inventarsi un'impronta della riga, cioè costruire il meccanismo
     * *e* la cosa che protegge.
     */
    public static function commuta(string $ruolo, string $permesso): void
    {
        self::applica($ruolo, $permesso, concedi: null);
    }

    /**
     * L'unica scrittura sulla matrice.
     *
     * L'ordine dei passi non è casuale: **le due guardie rifiutano prima di
     * toccare il database**, così una richiesta forgiata a mano non lascia né
     * righe nel pivot né righe nel registro. Poi si risolvono ruolo e permesso
     * (che possono non esistere), e solo alla fine la transazione, che tiene
     * insieme la scrittura e la sua traccia.
     *
     * @param  bool|null  $concedi  `null` = calcola l'inverso dello stato attuale
     */
    private static function applica(string $ruolo, string $permesso, ?bool $concedi): void
    {
        if (Rbac::isLocked($permesso)) {
            // Fail-closed e rumoroso: la UI rende le colonne bloccate come badge
            // statici senza `wire:click`, quindi chi produce questa chiamata non
            // sta usando la pagina.
            throw ValidationException::withMessages([
                'permesso' => "«{$permesso}» è nel set bloccato: non è ridistribuibile dalla UI, a nessun ruolo e in nessuna direzione. Allargare o restringere il set è un'operazione di codice (config/rbac.php).",
            ]);
        }

        // ⚠️ **Il catalogo è codice, e questa è la guardia che lo fa valere.**
        // `activity_log` non è l'unica tabella che sopravvive alle proprie
        // definizioni: `permissions` conserva le righe di permessi tolti dalla
        // config, perché il seeder usa `firstOrCreate` e non cancella ciò che
        // non conosce più. È già successo — i `letture_contaore.*`, tolti in
        // S3-bis, sono rimasti attaccati a quattro ruoli del DB di sviluppo fino
        // all'8 Ago 2026 (CLAUDE.md).
        //
        // Senza questa guardia una richiesta forgiata potrebbe **riassegnare**
        // una di quelle righe orfane, e l'editor legittimerebbe ciò che il
        // progetto ha già pagato una volta. La griglia del Blocco 3 le terrà
        // fuori dalle celle cliccabili, ma quella è presentazione: una difesa
        // che vive solo nella pagina è la falsa sicurezza che questo piano
        // combatte ovunque.
        if (! in_array($permesso, Rbac::permissions(), true)) {
            throw ValidationException::withMessages([
                'permesso' => "«{$permesso}» non è nel catalogo di config/rbac.php: è una riga orfana, rimasta a database dopo essere stata tolta dalla configurazione. Non si riassegna dalla UI — va rimossa a mano.",
            ]);
        }

        // La gemella sul ruolo. Non è simmetria per gusto: `Role::create()` è a
        // portata di chiunque abbia una console, e un ruolo fuori catalogo non
        // ha né scope di riga né 2FA obbligatorio (`two_factor_required_roles`
        // è config, e si legge **per nome**). Riempirlo di permessi dalla UI
        // creerebbe un ruolo potente e senza secondo fattore.
        if (! in_array($ruolo, Rbac::roleNames(), true)) {
            throw ValidationException::withMessages([
                'ruolo' => "«{$ruolo}» non è fra i ruoli di config/rbac.php: la matrice si modifica solo sui ruoli del catalogo. Aggiungerne uno è un'operazione di codice.",
            ]);
        }

        if (Rbac::isRuoloProtetto($ruolo)) {
            throw ValidationException::withMessages([
                'ruolo' => "La riga del ruolo «{$ruolo}» è in sola lettura: è la chiave di riserva della piattaforma, e svuotarla toglierebbe l'ultima via di rientro.",
            ]);
        }

        $riga = self::ruolo($ruolo);
        $colonna = self::permesso($permesso);

        // ⚠️ Lo stato attuale si legge dal **pivot**, non da `hasPermissionTo()`:
        // quello passa dal registrar, cioè da una cache che in un worker in
        // daemon può essere vecchia di ore. Qui la cosa che si inverte deve
        // essere ciò che c'è scritto adesso.
        $concedi ??= ! $riga->permissions()->whereKey($colonna->getKey())->exists();

        DB::transaction(function () use ($riga, $colonna, $ruolo, $permesso, $concedi) {
            // ⚠️ API Eloquent di spatie e nient'altro: è ciò che invalida la
            // cache dei permessi. Vedi il docblock di classe.
            if ($concedi) {
                $riga->givePermissionTo($colonna);
            } else {
                $riga->revokePermissionTo($colonna);
            }

            // Canale `audit` e non `default`, per una ragione meccanica:
            // `VistaPiattaforma::audit()` ha il pavimento
            // `where('log_name', AuditLog::NAME)` **dentro la porta**, quindi una
            // riga scritta altrove non comparirebbe mai nel registro — e ADR-016
            // chiede che ogni modifica alla matrice sia loggata *e leggibile*.
            //
            // `properties.impersonato_da` arriva gratis dall'hook
            // `LogActivityAction::beforeLogging()` registrato in
            // `AppServiceProvider`: qui non va aggiunto a mano.
            //
            // ⚠️ **Niente `->event()`**: `event` resta NULL e la riga è un
            // **atto**, raggiungibile dal filtro con la sentinella `__atto`. È
            // un gesto, non il diff delle colonne di un model. E ⚠️ **mai
            // `withChanges()`**: `attribute_changes` è la colonna del trait
            // `AuditsDomainWrites`, e scriverla da fuori romperebbe
            // l'invariante che `DettaglioAttivita` rende per l'intero registro.
            //
            // Soggetto il `Role` e **non** il `Permission`: il gesto è «al ruolo
            // X è stato tolto Y», e il ruolo è ciò su cui si vorrà filtrare. Un
            // solo tipo soggetto, non due.
            activity(AuditLog::NAME)
                ->causedBy(auth()->user())
                ->performedOn($riga)
                ->withProperties([
                    'ruolo' => $ruolo,
                    'permesso' => $permesso,
                    // `da`/`a` sono già nel vocabolario del registro: le rende
                    // `DettaglioAttivita` come elenco chiave/valore.
                    'da' => $concedi ? 'no' : 'sì',
                    'a' => $concedi ? 'sì' : 'no',
                ])
                ->log($concedi ? 'Permesso concesso al ruolo' : 'Permesso revocato al ruolo');
        });
    }

    /**
     * Il ruolo, o un errore che dice cosa fare.
     *
     * `RoleDoesNotExist` di spatie è corretto ma muto per chi legge: dice che il
     * ruolo non c'è, non che il bootstrap non è mai stato eseguito su questo
     * database.
     */
    private static function ruolo(string $nome): Role
    {
        try {
            return Role::findByName($nome, self::GUARD);
        } catch (RoleDoesNotExist) {
            throw ValidationException::withMessages([
                'ruolo' => "Il ruolo «{$nome}» non esiste nel database. I ruoli nascono dal bootstrap: `php artisan db:seed --class=RolesAndPermissionsSeeder`.",
            ]);
        }
    }

    /**
     * Il permesso, o un errore che nomina il comando che lo crea.
     *
     * ⚠️ Il caso non è teorico ed è già costato: il catalogo di `config/rbac.php`
     * può dichiarare un permesso che a database **non esiste** — è la forma
     * dell'incidente `fornitori.view`, dove «il documento affermava un default
     * che la config non creava». Un nome in catalogo ma non seminato fa lanciare
     * `Permission::findByName()`, e senza questa traduzione l'operatore
     * leggerebbe un'eccezione di vendor invece del comando che risolve.
     */
    private static function permesso(string $nome): Permission
    {
        try {
            return Permission::findByName($nome, self::GUARD);
        } catch (PermissionDoesNotExist) {
            // ⚠️ **«non risulta» e non «non c'è»**, ed è una differenza che va
            // rispettata nel testo: `findByName()` interroga il **registrar**,
            // cioè la cache dei permessi, non il database. Con una cache
            // scaldata prima che la riga esistesse, questa eccezione scatta su
            // un permesso che a database c'è; nel caso opposto — riga cancellata
            // e cache ancora calda — `findByName()` restituisce un model
            // fantasma e a cadere è la chiave esterna, con l'eccezione di vendor
            // che questo messaggio esiste per evitare. La transazione fa
            // rollback, quindi il pivot resta intatto e non nasce nessuna riga
            // di audit — ma l'operatore vede l'errore grezzo. Chiuderlo
            // vorrebbe dire leggere dal DB scavalcando la cache a ogni
            // scrittura: si dichiara invece di far finta.
            throw ValidationException::withMessages([
                'permesso' => "Il permesso «{$nome}» è nel catalogo ma non risulta a database: va seminato con `php artisan db:seed --class=RolesAndPermissionsSeeder` prima di poterlo assegnare a un ruolo.",
            ]);
        }
    }
}
