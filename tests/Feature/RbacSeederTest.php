<?php

use App\Support\Rbac;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('creates the full catalog and the six roles', function () {
    expect(Permission::count())->toBe(54);
    expect(Role::count())->toBe(6);

    foreach (['Developer', 'Superadmin', 'Admin', 'Responsabile Reparto', 'Tenant', 'Tecnico'] as $role) {
        expect(Role::where('name', $role)->exists())->toBeTrue();
    }
});

it('gives the Developer every permission', function () {
    expect(Role::findByName('Developer')->permissions)->toHaveCount(54);
});

it('gives the Superadmin everything except system.logs.view', function () {
    $superadmin = Role::findByName('Superadmin');

    expect($superadmin->permissions)->toHaveCount(53);
    expect($superadmin->hasPermissionTo('system.logs.view'))->toBeFalse();
    expect($superadmin->hasPermissionTo('roles.manage'))->toBeTrue();
});

it('withholds platform-level permissions from the Admin', function () {
    $admin = Role::findByName('Admin');

    foreach ([
        'billing.manage_global', 'billing.lockout', 'tenants.view_all',
        'tenants.provision', 'utenti.impersonate', 'system.logs.view', 'roles.manage',
    ] as $denied) {
        expect($admin->hasPermissionTo($denied))->toBeFalse();
    }

    // l'Admin mantiene le garanzie ricambio e il billing del proprio Ente
    expect($admin->hasPermissionTo('garanzie.ricambio.manage'))->toBeTrue();
    expect($admin->hasPermissionTo('billing.manage_own'))->toBeTrue();
});

it('never grants spare-part warranties to Tenant or Tecnico', function () {
    foreach (['Tenant', 'Tecnico'] as $role) {
        $r = Role::findByName($role);
        expect($r->hasPermissionTo('garanzie.ricambio.view'))->toBeFalse();
        expect($r->hasPermissionTo('garanzie.ricambio.manage'))->toBeFalse();
    }
});

it('lets the Tecnico create catalog entries but not update or delete them', function () {
    $tecnico = Role::findByName('Tecnico');

    expect($tecnico->hasPermissionTo('ricambi.create'))->toBeTrue();
    expect($tecnico->hasPermissionTo('ricambi.update'))->toBeFalse();
    expect($tecnico->hasPermissionTo('ricambi.delete'))->toBeFalse();
    expect($tecnico->hasPermissionTo('interventi.complete'))->toBeTrue();
    expect($tecnico->hasPermissionTo('semaforo.force'))->toBeFalse();
});

it('defines the locked permission set', function () {
    expect(Rbac::locked())->toHaveCount(9);
    expect(Rbac::isLocked('garanzie.ricambio.view'))->toBeTrue();
    expect(Rbac::isLocked('strumenti.view'))->toBeFalse();
});
