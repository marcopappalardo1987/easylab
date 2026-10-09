<?php

use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Audit\SoggettiAudit;
use App\Support\Rbac;
use App\Support\Rbac\MatriceRuoli;
use App\Support\Tenancy\VistaPiattaforma;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 🔴 La regola dell'editor dei permessi di ruolo, provata **senza pagina**
 * (S6 — ADR-016).
 *
 * Le guardie sono la feature, e la ragione non è stilistica: `roles.manage` e
 * `tenants.view_all` sono nel set bloccato, quindi il gate di questa pagina è
 * protetto dalla regola che questa pagina implementa. Romperla significa
 * rompere insieme il gate di `/piattaforma`, quello di `/piattaforma/audit` e
 * quello dell'editor. Per questo la regola esiste e si prova prima che esista
 * qualcosa che la usi.
 *
 * ⚠️ **Nessun test qui tocca l'HTML**, ed è dichiarato: `Livewire::test()` non
 * passa dai middleware e un `@if` in Blade si toglie in un secondo, quindi
 * un'asserzione sulla resa delle celle bloccate proverebbe la presentazione e
 * non la protezione. Tutto ciò che segue chiama il **metodo di dominio**.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'EasyLab']);

    $this->superadmin = User::factory()->create([
        'name' => 'Direzione',
        'tenant_id' => $this->ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->superadmin->assignRole('Superadmin');
});

// `permessiDiRuolo()` vive in `tests/Pest.php`: la usano due suite, e una
// funzione condivisa non può abitare in una delle due — la lezione è già
// scritta lì accanto, accanto a `snapshotDa()`.

/**
 * Gli errori di validazione di un gesto rifiutato.
 *
 * ⚠️ Si asserisce su `errors()` e non su `getMessage()`: la sintesi di
 * `ValidationException` è un dettaglio del framework, la chiave («permesso» o
 * «ruolo») è la cosa che questa classe decide — ed è quella che dice a chi legge
 * il rosso *quale* delle due guardie ha parlato.
 */
function erroriDi(Closure $gesto): array
{
    try {
        $gesto();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Il gesto non ha lanciato ValidationException: la guardia non ha parlato.');
}

it('never grants a locked permission to a role that lacks it', function () {
    // 🔴 **Il test dell'intera decisione sul set bloccato**, e l'unico che
    // distingue la lettura per COLONNA da quella per coppia: un'implementazione
    // che blocca solo le revoche resta verde su ogni altro test di questo file.
    //
    // Perché è la lettura giusta: `EnsureTwoFactorIsEnabled` gata il secondo
    // fattore **per nome di ruolo**, non per permesso. Sotto la lettura per
    // coppia, `roles.manage` sarebbe concedibile al `Tenant` — cioè un editor
    // della matrice dei permessi raggiungibile senza 2FA obbligatorio.
    expect(Rbac::isLocked('roles.manage'))->toBeTrue()
        ->and(Rbac::permissionsForRole('Admin'))->not->toContain('roles.manage');

    Activity::query()->delete();

    expect(erroriDi(fn () => MatriceRuoli::concedi('Admin', 'roles.manage')))
        ->toHaveKey('permesso');

    // E non ha lasciato niente dietro di sé: la guardia rifiuta **prima** di
    // toccare il database, quindi né riga nel pivot né riga nel registro.
    expect(permessiDiRuolo('Admin'))->not->toContain('roles.manage')
        ->and(Activity::query()->count())->toBe(0);

    // 🔴 **E dalla stessa porta da cui entrerà la pagina.** `commuta()` è
    // l'unico metodo che la UI chiamerà (Blocco 4), e provare la guardia solo
    // attraverso `concedi()`/`revoca()` lascia scoperto proprio l'ingresso
    // principale: verificato, una guardia che si spegne quando `$concedi` è
    // `null` lascia questo file **tutto verde** mentre concede `roles.manage`
    // all'Admin. Il Blocco 1 si dichiara «la regola, provabile senza pagina»:
    // deve valere anche dal verso in cui la pagina la userà.
    expect(erroriDi(fn () => MatriceRuoli::commuta('Admin', 'roles.manage')))
        ->toHaveKey('permesso');

    expect(permessiDiRuolo('Admin'))->not->toContain('roles.manage');
});

it('never revokes a locked permission from the role that holds it', function () {
    // 🔴 Se cadesse, cadrebbero insieme il gate di `/piattaforma` e quello di
    // `/piattaforma/audit`: entrambi sono `can:tenants.view_all`, scelto
    // **proprio perché** è nel set bloccato. La riga qui sotto sta nello stesso
    // corpo perché chi legge il rosso deve trovarci accanto la ragione.
    expect(Rbac::isLocked('tenants.view_all'))->toBeTrue();

    Activity::query()->delete();

    expect(erroriDi(fn () => MatriceRuoli::revoca('Superadmin', 'tenants.view_all')))
        ->toHaveKey('permesso');

    expect(permessiDiRuolo('Superadmin'))->toContain('tenants.view_all')
        ->and(Activity::query()->count())->toBe(0);

    // Anche qui dal verso della pagina, e per la stessa ragione: `commuta()` su
    // una cella accesa è una **revoca**, ed è il gesto con cui il Superadmin si
    // chiuderebbe fuori da entrambe le schermate di piattaforma.
    expect(erroriDi(fn () => MatriceRuoli::commuta('Superadmin', 'tenants.view_all')))
        ->toHaveKey('permesso');

    expect(permessiDiRuolo('Superadmin'))->toContain('tenants.view_all');
});

it("never touches the Developer's row, in either direction", function () {
    // 🔴 Il buco che il set bloccato **non** copre: quello protegge sette
    // permessi, la riga del Developer ne ha cinquantaquattro.
    // `Developer => ['all' => true]` è la chiave di riserva della piattaforma e
    // non esiste alcun `Gate::before` da super-admin, quindi il Developer
    // dipende davvero dalla matrice a DB.
    //
    // ⚠️ Il permesso di prova **non è nel set bloccato** di proposito: con uno
    // bloccato parlerebbe la prima guardia e questo test resterebbe verde anche
    // togliendo la seconda, cioè proverebbe l'altra cosa.
    expect(Rbac::isLocked('strumenti.view'))->toBeFalse();

    // La concessione ha bisogno di una cella spenta, e il Developer le ha tutte
    // accese: si spegne **fuori** da `MatriceRuoli`, che è un'operazione di
    // codice e non un gesto della UI — esattamente la distinzione che ADR-027 ha
    // usato per spostare `garanzie.ricambio.*` a un ruolo che non le aveva.
    Role::findByName('Developer', 'web')->revokePermissionTo('spostamenti.view');

    Activity::query()->delete();

    expect(erroriDi(fn () => MatriceRuoli::revoca('Developer', 'strumenti.view')))->toHaveKey('ruolo')
        ->and(erroriDi(fn () => MatriceRuoli::concedi('Developer', 'spostamenti.view')))->toHaveKey('ruolo')
        ->and(erroriDi(fn () => MatriceRuoli::commuta('Developer', 'strumenti.view')))->toHaveKey('ruolo');

    expect(permessiDiRuolo('Developer'))->toContain('strumenti.view')
        ->and(permessiDiRuolo('Developer'))->not->toContain('spostamenti.view')
        ->and(Activity::query()->count())->toBe(0);
});

it('forgets the permission cache key, not just the in-process copy', function () {
    // 🔴 **Si asserisce sullo store, e non su `can()`.** La proprietà che conta
    // è cross-processo: dopo una scrittura, nessun php-fpm deve poter servire la
    // matrice vecchia dalla chiave condivisa — che altrimenti resterebbe buona
    // per le 24 ore di `cache.expiration_time`. Un `can()` fatto **in questo
    // stesso processo** non parla di quella proprietà: parla della copia in
    // memoria del registrar, che è un'altra cosa.
    //
    // ⚠️ *Scarto verificato rispetto al piano, che qui sbagliava.* Il piano
    // dava il test ingenuo per **non falsificabile** («passa comunque, perché la
    // copia in memoria viene azzerata lo stesso»). Misurato il 22 Ago 2026
    // mutando `forgetCachedPermissions()` in `clearPermissionsCollection()`:
    // diventa rosso **anche** il `can()`. Il motivo è un dettaglio d'ordine e
    // non una virtù di quel test — `applica()` risolve il permesso con
    // `Permission::findByName()`, che passa dal registrar e quindi **riscalda la
    // chiave** un istante prima della scrittura, così la lettura successiva
    // serve la copia vecchia. È un rosso di rimbalzo, che dipende da come sono
    // ordinate le chiamate qui dentro; l'asserzione sullo store no.
    $this->actingAs($this->superadmin->fresh());

    // Scaldare la chiave fa parte del test: senza, `Cache::has()` sarebbe falso
    // per non essere mai stato vero, e l'asserzione finale non proverebbe nulla.
    expect($this->superadmin->can('strumenti.view'))->toBeTrue()
        ->and(Cache::has(config('permission.cache.key')))->toBeTrue();

    MatriceRuoli::commuta('Tenant', 'spostamenti.view');

    expect(Cache::has(config('permission.cache.key')))->toBeFalse();
});

it('writes one audit row per cell, on the audit channel, readable through the door', function () {
    // 🔴 Si asserisce **attraverso `VistaPiattaforma::audit()`** e non su
    // `Activity::query()`: il pavimento `where('log_name', AuditLog::NAME)` sta
    // *dentro* la porta, quindi una riga scritta sul canale `default`
    // esisterebbe nel database e non comparirebbe **mai** nel registro. Solo
    // così la mutazione «tolto il canale audit» diventa rossa, e ADR-016 chiede
    // che ogni modifica alla matrice sia loggata *e leggibile*.
    //
    // ⚠️ `Activity` va svuotata prima del gesto: le factory scrivono audit da sé
    // (`Account` usa il trait), e un conteggio fatto senza questa riga
    // misurerebbe anche il rumore della fixture.
    Activity::query()->delete();

    $this->actingAs($this->superadmin->fresh());

    MatriceRuoli::commuta('Tenant', 'spostamenti.view');

    $righe = VistaPiattaforma::audit()->get();

    expect($righe)->toHaveCount(1);

    $riga = $righe->first();

    expect($riga->description)->toBe('Permesso concesso al ruolo')
        ->and($riga->subject_type)->toBe(Role::class)
        ->and($riga->subject_id)->toBe(Role::findByName('Tenant', 'web')->getKey())
        ->and($riga->causer_id)->toBe($this->superadmin->id)
        // 🔴 `event` resta NULL: la riga è un **atto**, non il diff delle colonne
        // di un model, ed è così che resta raggiungibile dal filtro con la
        // sentinella `__atto`. Un `->event('updated')` la farebbe scivolare fra
        // le modifiche di dominio e sparire da lì.
        ->and($riga->event)->toBeNull()
        // `attribute_changes` è la colonna del trait `AuditsDomainWrites`:
        // scriverla da fuori romperebbe l'invariante che `DettaglioAttivita`
        // rende per l'intero registro.
        ->and($riga->attribute_changes)->toBeEmpty()
        ->and($riga->properties->get('ruolo'))->toBe('Tenant')
        ->and($riga->properties->get('permesso'))->toBe('spostamenti.view')
        ->and($riga->properties->get('da'))->toBe('no')
        ->and($riga->properties->get('a'))->toBe('sì');

    // E il soggetto si legge col suo nome. Senza `Role::class` nella mappa di
    // `SoggettiAudit` l'etichetta sarebbe «Role · #id» — in inglese, e col tipo
    // assente dal filtro del registro. Verificato: è davvero ciò che succede.
    $riga->load(['subject' => SoggettiAudit::vincolo()]);

    expect(SoggettiAudit::etichetta($riga)->testo())->toBe('Ruolo · Tenant');
});

it('computes the new state from the database, not from the caller', function () {
    // Due `commuta` consecutive sulla stessa cella tornano allo stato iniziale e
    // lasciano **due** righe di audit. Congela per intento ciò che rende
    // innocua la concorrenza: lo stato desiderato non arriva dal browser, che lo
    // aveva letto due minuti prima, ma dal pivot com'è adesso.
    Activity::query()->delete();

    $this->actingAs($this->superadmin->fresh());

    $prima = permessiDiRuolo('Tenant');

    expect($prima)->not->toContain('spostamenti.view');

    MatriceRuoli::commuta('Tenant', 'spostamenti.view');

    expect(permessiDiRuolo('Tenant'))->toContain('spostamenti.view');

    MatriceRuoli::commuta('Tenant', 'spostamenti.view');

    // ⚠️ `orderBy('id')`: l'asserzione è su una **sequenza**, e senza ordine la
    // sequenza la decide il motore. Su Postgres le due righe sono tornate
    // invertite in CI il 9 Ott 2026, con lo stesso commit verde un giro prima.
    expect(permessiDiRuolo('Tenant'))->toBe($prima)
        ->and(VistaPiattaforma::audit()->orderBy('id')->pluck('description')->all())->toBe([
            'Permesso concesso al ruolo',
            'Permesso revocato al ruolo',
        ]);
});

it('refuses a permission that is in the catalogue but not seeded', function () {
    // ⚠️ **Il caso va costruito, o il test è verde per assenza di caso.**
    // `fornitori.view` — la forma originale del difetto, «il documento affermava
    // un default che la config non creava» — è seminato dal 15 Ago 2026, quindi
    // oggi in catalogo non c'è più niente che manchi a database. Si cancella
    // quindi la riga a mano, che è esattamente ciò che resterebbe dopo
    // l'aggiunta di un permesso nuovo al catalogo senza riseeding.
    expect(Rbac::permissions())->toContain('spostamenti.view');

    DB::table('permissions')->where('name', 'spostamenti.view')->delete();

    // `Permission::findByName()` legge dal registrar, non dal database: senza
    // questo, la riga cancellata continuerebbe a esistere in cache e il test
    // proverebbe il contrario di ciò che dice.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $errori = erroriDi(fn () => MatriceRuoli::concedi('Tenant', 'spostamenti.view'));

    expect($errori)->toHaveKey('permesso')
        // L'errore deve nominare **il comando che risolve**, non limitarsi a
        // dire che il permesso non c'è: chi lo legge sta guardando un catalogo
        // che dichiara quel nome, e la domanda successiva è «e adesso?».
        ->and($errori['permesso'][0])->toContain('db:seed --class=RolesAndPermissionsSeeder');
});

it('builds the whole matrix in a fixed number of queries', function () {
    // Anti-N+1: `$role->hasPermissionTo($p)` dentro il doppio ciclo passerebbe
    // dal registrar e, senza eager load, farebbe 324 risoluzioni per rendere una
    // pagina.
    //
    // ⚠️ **Si conta su `stato()` in isolamento, non sul montaggio di un
    // componente**: `render()` porterebbe con sé il gate, che a cache fredda fa
    // caricare al registrar l'intera matrice, più sessione e utente — un numero
    // esatto contato lì è fragile rispetto all'ordine dei test.
    //
    // ⚠️ **E si conta sulla matrice PIENA**: un anti-N+1 misurato su ruoli senza
    // permessi non può fallire, perché il ciclo che si vuole evitare non gira.
    DB::enableQueryLog();
    DB::flushQueryLog();

    $matrice = MatriceRuoli::stato();

    $query = DB::getQueryLog();
    DB::disableQueryLog();

    expect($query)->toHaveCount(2, 'La matrice deve costare due query: i ruoli e il pivot in eager load.')
        ->and(array_keys($matrice))->toEqualCanonicalizing(Rbac::roleNames())
        ->and(array_keys($matrice['Developer']))->toEqualCanonicalizing(Rbac::permissionsForRole('Developer'))
        ->and(array_keys($matrice['Tenant']))->toEqualCanonicalizing(Rbac::permissionsForRole('Tenant'))
        ->and($matrice['Developer'])->toHaveCount(54);
});

it('never re-assigns a permission the catalogue no longer knows', function () {
    // 🔴 `permissions` sopravvive alle proprie definizioni, esattamente come
    // `activity_log`: il seeder usa `firstOrCreate` e non cancella ciò che non
    // conosce più. È già successo — i `letture_contaore.*`, tolti dalla config
    // in S3-bis, sono rimasti attaccati a quattro ruoli del DB di sviluppo fino
    // all'8 Ago 2026.
    //
    // ⚠️ La riga si crea **a mano**, perché il caso non esiste nel seed: senza
    // questo setup il test sarebbe verde per assenza di caso, non per la
    // guardia — la forma di errore che questo progetto ha già pagato.
    Permission::create(['name' => 'letture_contaore.view', 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Rbac::permissions())->not->toContain('letture_contaore.view');

    Activity::query()->delete();

    // Da tutte e tre le porte: la griglia del Blocco 3 terrà gli orfani fuori
    // dalle celle cliccabili, ma quella è **presentazione** — una difesa che
    // vive solo nella pagina non regge a una richiesta forgiata a mano.
    foreach (['concedi', 'commuta'] as $gesto) {
        expect(erroriDi(fn () => MatriceRuoli::$gesto('Tenant', 'letture_contaore.view')))
            ->toHaveKey('permesso');
    }

    expect(permessiDiRuolo('Tenant'))->not->toContain('letture_contaore.view')
        ->and(Activity::query()->count())->toBe(0);
});

it('never fills a role the catalogue no longer knows', function () {
    // La gemella sul ruolo, e non è simmetria per gusto: un ruolo fuori catalogo
    // non ha né scope di riga né 2FA obbligatorio — `two_factor_required_roles`
    // è config e si legge **per nome**. Riempirlo dalla UI produrrebbe un ruolo
    // potente e senza secondo fattore.
    Role::create(['name' => 'Intruso', 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Rbac::roleNames())->not->toContain('Intruso');

    Activity::query()->delete();

    expect(erroriDi(fn () => MatriceRuoli::commuta('Intruso', 'strumenti.view')))
        ->toHaveKey('ruolo');

    expect(permessiDiRuolo('Intruso'))->toBe([])
        ->and(Activity::query()->count())->toBe(0);
});
