<?php

use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Rbac;
use App\Support\Tenancy\AccessoTecnico;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Il Gestore (🔗 ADR-046): personale EasyLab che gestisce la manutenzione dei
 * clienti che gli sono assegnati.
 *
 * **Area rossa.** In lettura il Gestore segue la regola del Tecnico — portafoglio
 * ∪ assegnazione, in `AccessoTecnico` — ma può **scrivere**: ogni sede che vede
 * per errore è una sede su cui registra macchine e chiude interventi.
 *
 * Albero: Ente A → Dip → due macchine; Ente B → Dip → una macchina.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->deptA = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create(['nome' => 'Reparto A']);
    $this->autoclave = Strumento::factory()->forNode($this->deptA)->create(['nome' => 'Autoclave']);
    $this->cappa = Strumento::factory()->forNode($this->deptA)->create(['nome' => 'Cappa chimica']);

    $this->enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $this->deptB = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create(['nome' => 'Reparto B']);
    $this->centrifuga = Strumento::factory()->forNode($this->deptB)->create(['nome' => 'Centrifuga']);

    $this->gestore = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $this->gestore->assignRole('Gestore');
});

// --- La lettura: la regola del portafoglio ---

it('shows a gestore the machines and the tree of the sedi in the portfolio', function () {
    $this->gestore->portafoglioClienti()->attach($this->enteA);

    $this->actingAs($this->gestore);

    expect(Strumento::pluck('nome')->all())->toEqualCanonicalizing(['Autoclave', 'Cappa chimica'])
        ->and(UnitaOrganizzativa::pluck('nome')->all())->toEqualCanonicalizing(['Ente A', 'Reparto A']);
});

it('never shows a gestore a sede outside the portfolio', function () {
    $this->gestore->portafoglioClienti()->attach($this->enteA);

    $this->actingAs($this->gestore);

    expect(Strumento::find($this->centrifuga->id))->toBeNull()
        ->and(UnitaOrganizzativa::find($this->deptB->id))->toBeNull()
        ->and(UnitaOrganizzativa::find($this->enteB->id))->toBeNull();
});

it('shows nothing at all to a gestore without a portfolio', function () {
    $this->actingAs($this->gestore);

    expect(Strumento::count())->toBe(0)
        ->and(UnitaOrganizzativa::count())->toBe(0)
        ->and(Intervento::count())->toBe(0);
});

it('takes the access away as soon as the sede leaves the portfolio', function () {
    $this->gestore->portafoglioClienti()->attach($this->enteA);

    $this->actingAs($this->gestore);
    expect(Strumento::count())->toBe(2);

    $this->gestore->portafoglioClienti()->detach($this->enteA);

    expect(Strumento::count())->toBe(0);
});

it('reaches the machine of an intervento a gestore took on, as a tecnico would', function () {
    Intervento::factory()->forStrumento($this->centrifuga)->create(['tecnico_id' => $this->gestore->id]);

    $this->actingAs($this->gestore);

    expect(Strumento::pluck('nome')->all())->toBe(['Centrifuga']);
});

it('does not log a gestore opening a machine card: that trace is the tecnico\'s', function () {
    // 🔗 ADR-007 traccia le letture del Tecnico, che non scrive quasi nulla. Il
    // Gestore lascia una riga di audit a ogni scrittura: tracciarne anche le
    // aperture riempirebbe il registro del cliente di rumore.
    $this->gestore->portafoglioClienti()->attach($this->enteA);
    $this->actingAs($this->gestore);

    AccessoTecnico::tracciaAperturaScheda($this->autoclave);

    expect(Activity::where('log_name', AuditLog::NAME)
        ->where('description', 'Scheda strumento aperta da un tecnico')->count())->toBe(0);
});

// --- La matrice: cosa può e cosa no ---

it('lets a gestore run the maintenance of a client', function (string $permesso) {
    expect($this->gestore->can($permesso))->toBeTrue();
})->with([
    'unita_organizzativa.create', 'unita_organizzativa.update',
    'strumenti.create', 'strumenti.update', 'strumenti.move',
    'interventi.create', 'interventi.update', 'interventi.assign', 'interventi.complete', 'interventi.delete',
    'garanzie.macchina.manage', 'garanzie.ricambio.manage',
    'ricambio_utilizzo.create', 'documenti.upload', 'fornitori.create',
]);

it('never lets a gestore delete, administer people, bill or enter the platform', function (string $permesso) {
    // 🔴 `unita_organizzativa.delete` è la decisione di Marco del 6 Ott 2026:
    // reparti e sotto-laboratori si creano e si rinominano, non si eliminano.
    expect($this->gestore->can($permesso))->toBeFalse();
})->with([
    'unita_organizzativa.delete', 'strumenti.delete', 'ricambi.delete', 'ricambi.merge', 'fornitori.delete',
    'utenti.view', 'utenti.create', 'utenti.update', 'utenti.delete', 'utenti.impersonate',
    'billing.manage_own', 'billing.manage_global', 'billing.lockout',
    'tenants.view_all', 'tenants.provision', 'audit.view', 'roles.manage', 'system.logs.view',
]);

it('holds no locked permission, by default', function () {
    expect(array_intersect(Rbac::permissionsForRole('Gestore'), Rbac::locked()))->toBe([]);
});

it('demands the second factor from a gestore', function () {
    $senza = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => null]);
    $senza->assignRole('Gestore');

    $this->actingAs($senza)->get('/dashboard')->assertRedirect(route('settings.security'));
});

// --- Il ruolo sui database che i ruoli li hanno già ---

it('creates the role on a database that already has roles, without touching the others', function () {
    $migrazione = include database_path('migrations/2026_10_06_100100_create_ruolo_gestore.php');

    // Lo stato di produzione prima del deploy: i sei ruoli, senza il Gestore, e
    // una personalizzazione fatta dall'editor.
    Role::findByName('Gestore')->delete();
    Role::findByName('Tenant')->revokePermissionTo('documenti.upload');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $migrazione->up();

    $gestore = Role::findByName('Gestore');

    expect($gestore->permissions->pluck('name')->all())->toEqualCanonicalizing(Rbac::permissionsForRole('Gestore'))
        // 🔴 La ragione per cui è una migration e non il seeder.
        ->and(Role::findByName('Tenant')->hasPermissionTo('documenti.upload'))->toBeFalse()
        ->and(Activity::where('description', 'Ruolo Gestore creato')->count())->toBe(1);
});

it('leaves alone a gestore role that is already there, customised or not', function () {
    $migrazione = include database_path('migrations/2026_10_06_100100_create_ruolo_gestore.php');

    Role::findByName('Gestore')->revokePermissionTo('semaforo.force');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $migrazione->up();

    expect(Role::findByName('Gestore')->hasPermissionTo('semaforo.force'))->toBeFalse()
        ->and(Activity::where('description', 'Ruolo Gestore creato')->count())->toBe(0);
});

it('does nothing on a database without roles, where the seeder will create them all', function () {
    $migrazione = include database_path('migrations/2026_10_06_100100_create_ruolo_gestore.php');

    DB::table('model_has_roles')->delete();
    DB::table('role_has_permissions')->delete();
    DB::table('roles')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $migrazione->up();

    expect(DB::table('roles')->count())->toBe(0);
});
