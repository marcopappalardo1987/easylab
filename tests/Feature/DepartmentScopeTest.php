<?php

use App\Models\Scopes\DepartmentScope;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Ente A: ente → dept1 → sub1a ; dept2 (fratello)
    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->deptA1 = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create(['nome' => 'Dept A1']);
    $this->subA1a = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->deptA1)->create(['nome' => 'Sub A1a']);
    $this->deptA2 = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create(['nome' => 'Dept A2']);

    // Ente B: ente → dept1
    $this->enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $this->deptB1 = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create(['nome' => 'Dept B1']);
});

function userWith(string $role, ?int $tenantId): User
{
    $user = User::factory()->create(['tenant_id' => $tenantId]);
    $user->assignRole($role);

    return $user;
}

it('restricts a Responsabile to the assigned node and its descendants', function () {
    $resp = userWith('Responsabile Reparto', $this->enteA->id);
    $resp->unitaResponsabili()->attach($this->deptA1->id);

    $this->actingAs($resp);

    expect(UnitaOrganizzativa::pluck('id')->all())
        ->toEqualCanonicalizing([$this->deptA1->id, $this->subA1a->id]);
});

it('sees the union of multiple assigned subtrees', function () {
    $resp = userWith('Responsabile Reparto', $this->enteA->id);
    $resp->unitaResponsabili()->attach([$this->deptA1->id, $this->deptA2->id]);

    $this->actingAs($resp);

    expect(UnitaOrganizzativa::pluck('id')->all())
        ->toEqualCanonicalizing([$this->deptA1->id, $this->subA1a->id, $this->deptA2->id]);
});

it('never sees nodes of another tenant (level 1 AND level 2)', function () {
    $resp = userWith('Responsabile Reparto', $this->enteA->id);
    $resp->unitaResponsabili()->attach($this->deptA1->id);

    $this->actingAs($resp);

    $ids = UnitaOrganizzativa::pluck('id')->all();
    expect($ids)->not->toContain($this->enteB->id);
    expect($ids)->not->toContain($this->deptB1->id);
});

it('shows nothing to a Responsabile without assignments (fail-safe)', function () {
    $resp = userWith('Responsabile Reparto', $this->enteA->id);

    $this->actingAs($resp);

    expect(UnitaOrganizzativa::count())->toBe(0);
});

it('does not restrict an Admin within its own tenant', function () {
    $this->actingAs(userWith('Admin', $this->enteA->id));

    // Tutto l'albero dell'Ente A (4 nodi), niente Ente B.
    expect(UnitaOrganizzativa::count())->toBe(4);
});

it('does not restrict a Tenant within its own tenant', function () {
    $this->actingAs(userWith('Tenant', $this->enteA->id));

    expect(UnitaOrganizzativa::count())->toBe(4);
});

it('scopes a Superadmin to its own tenant, with no department restriction (ADR-018)', function () {
    // Superadmin con proprio Ente A: vede tutto l'albero di A (4), non l'Ente B.
    $this->actingAs(userWith('Superadmin', $this->enteA->id));

    expect(UnitaOrganizzativa::count())->toBe(4);
});

it('shows nothing to a Superadmin/Developer without a tenant (fail-closed, ADR-018)', function () {
    $this->actingAs(userWith('Superadmin', null));

    expect(UnitaOrganizzativa::count())->toBe(0);
});

it('does not restrict when there is no authenticated context', function () {
    expect(UnitaOrganizzativa::count())->toBe(6);
});

it('exposes withoutGlobalScope as an escape hatch from the department filter', function () {
    $resp = userWith('Responsabile Reparto', $this->enteA->id);
    $resp->unitaResponsabili()->attach($this->deptA1->id);

    $this->actingAs($resp);

    // Tolto solo il filtro reparto, resta il confine tenant: tutto l'Ente A.
    expect(UnitaOrganizzativa::withoutGlobalScope(DepartmentScope::class)->count())->toBe(4);
});
