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

// Riscritto l'8 Ago 2026 (ADR-027). Diceva «to Tenant or Tecnico» e congelava
// una svista: ADR-004 — la fonte che sia Schema Ruoli §4.3 sia ADR-016 citavano —
// nomina il SOLO Tenant, e il Tecnico è personale EasyLab. Il divieto al Tecnico
// non era mai stato deciso da nessuno, ma difeso da questo test era diventato
// indistinguibile da una decisione. Modifica consapevole, non adeguamento.
// Invertito il 15 Ago 2026 (ADR-029), ed è la seconda volta che questo test
// cambia verso: l'8 Ago aveva perso il Tecnico (ADR-027), ora perde il Tenant.
// Il divieto che difendeva non era mai stato deciso — Fase 2 lo dava per
// scontato, ADR-004 lo ratificò come «già previsto» — e poggiava su una
// premessa mai scritta: che i ricambi li fornisca EasyLab. Qui resta il
// DEFAULT della piattaforma; l'eccezione del singolo Ente non è esprimibile in
// RBAC (`teams = false`) e vive nella colonna dell'Ente + Policy.
it('grants spare-part warranties to the Tenant, who owns the machine', function () {
    $tenant = Role::findByName('Tenant');

    expect($tenant->hasPermissionTo('garanzie.ricambio.view'))->toBeTrue();
    expect($tenant->hasPermissionTo('garanzie.ricambio.manage'))->toBeTrue();

    // Contrappeso: resta un ruolo in sola lettura sul resto dell'operatività —
    // il permesso nuovo non ne ha allargato il profilo.
    expect($tenant->hasPermissionTo('interventi.create'))->toBeFalse();
    expect($tenant->hasPermissionTo('strumenti.create'))->toBeFalse();
});

// ADR-027: è il Tecnico a montare il pezzo, quindi è la fonte del dato sulla sua
// garanzia. `.manage` e non solo `.view`, perché il form intervento (ADR-022) la
// CREA contestualmente alla riga di ricambio: con la sola lettura resterebbe il
// gesto spezzato in due che ADR-022 esisteva per evitare.
it('grants spare-part warranties to the Tecnico, who mounts the part', function () {
    $tecnico = Role::findByName('Tecnico');

    expect($tecnico->hasPermissionTo('garanzie.ricambio.view'))->toBeTrue();
    expect($tecnico->hasPermissionTo('garanzie.ricambio.manage'))->toBeTrue();
});

it('lets the Tecnico create catalog entries but not update or delete them', function () {
    $tecnico = Role::findByName('Tecnico');

    expect($tecnico->hasPermissionTo('ricambi.create'))->toBeTrue();
    expect($tecnico->hasPermissionTo('ricambi.update'))->toBeFalse();
    expect($tecnico->hasPermissionTo('ricambi.delete'))->toBeFalse();
    expect($tecnico->hasPermissionTo('interventi.complete'))->toBeTrue();
    expect($tecnico->hasPermissionTo('semaforo.force'))->toBeFalse();
});

it('never lets a role create interventi without being able to assign them', function () {
    // Dal 9 Ago 2026 un intervento è SEMPRE assegnato a un tecnico, e il campo
    // è obbligatorio nel form per chi ha `interventi.assign`. Un ruolo che
    // potesse creare SENZA assegnare produrrebbe interventi non assegnati —
    // cioè un buco nella regola, aperto da una modifica alla matrice e non da
    // un bug nel form. Il test lo congela: se domani qualcuno concede
    // `interventi.create` senza `interventi.assign`, se ne accorge qui.
    foreach (Rbac::roleNames() as $ruolo) {
        $permessi = Rbac::permissionsForRole($ruolo);

        if (in_array('interventi.create', $permessi, true)) {
            // NB: `toContain` in Pest prende N valori da cercare, non un
            // messaggio — il nome del ruolo va nella descrizione, non lì.
            expect(in_array('interventi.assign', $permessi, true))
                ->toBeTrue("il ruolo {$ruolo} può creare interventi ma non assegnarli");
        }
    }
});

it('defines the locked permission set', function () {
    // 9 → 7 il 15 Ago 2026: `garanzie.ricambio.*` è uscito dal set con ADR-029.
    // Non essendoci più un divieto assoluto da difendere, tenerle bloccate
    // avrebbe impedito alla UI di S6 di cambiare un default che ora È una
    // decisione. Il set torna a contenere solo ciò che è bloccato per legge,
    // sicurezza o struttura.
    expect(Rbac::locked())->toHaveCount(7);
    expect(Rbac::isLocked('garanzie.ricambio.view'))->toBeFalse();
    expect(Rbac::isLocked('utenti.impersonate'))->toBeTrue();
    expect(Rbac::isLocked('strumenti.view'))->toBeFalse();
});
