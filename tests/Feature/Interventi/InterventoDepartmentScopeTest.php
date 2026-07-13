<?php

use App\Models\Intervento;
use App\Models\Scopes\DepartmentThroughStrumentoScope;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Livello 2 del Global Scope applicato INDIRETTAMENTE via strumento (ADR-006)
 * — test NEGATIVI, area rossa della Policy di Code Review.
 *
 * Albero: Ente A → deptA1 → subA1a ; deptA2 (fratello). Ente B → deptB1.
 * Uno strumento con un intervento per nodo. Fixture create in console, prima
 * di actingAs.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->deptA1 = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create();
    $this->subA1a = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->deptA1)->create();
    $this->deptA2 = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create();

    $this->enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $this->deptB1 = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create();

    $this->strumentoA1 = Strumento::factory()->forNode($this->deptA1)->create();
    $this->strumentoA1a = Strumento::factory()->forNode($this->subA1a)->create();
    $this->strumentoA2 = Strumento::factory()->forNode($this->deptA2)->create();
    $this->strumentoB1 = Strumento::factory()->forNode($this->deptB1)->create();

    $this->intA1 = Intervento::factory()->forStrumento($this->strumentoA1)->create();
    $this->intA1a = Intervento::factory()->forStrumento($this->strumentoA1a)->create();
    $this->intA2 = Intervento::factory()->forStrumento($this->strumentoA2)->create();
    $this->intB1 = Intervento::factory()->forStrumento($this->strumentoB1)->create();
});

function interventiUserWith(string $role, ?int $tenantId): User
{
    $user = User::factory()->create(['tenant_id' => $tenantId]);
    $user->assignRole($role);

    return $user;
}

function responsabileDi(UnitaOrganizzativa $ente, array $nodi): User
{
    $resp = interventiUserWith('Responsabile Reparto', $ente->id);
    $resp->unitaResponsabili()->attach(collect($nodi)->pluck('id')->all());

    return $resp;
}

it('restricts a Responsabile to interventi of strumenti in the assigned subtree', function () {
    $this->actingAs(responsabileDi($this->enteA, [$this->deptA1]));

    expect(Intervento::pluck('id')->all())
        ->toEqualCanonicalizing([$this->intA1->id, $this->intA1a->id]);
});

it('sees the union of interventi across multiple assigned subtrees', function () {
    $this->actingAs(responsabileDi($this->enteA, [$this->deptA1, $this->deptA2]));

    expect(Intervento::pluck('id')->all())
        ->toEqualCanonicalizing([$this->intA1->id, $this->intA1a->id, $this->intA2->id]);
});

it('never sees interventi of another tenant (level 1 AND level 2)', function () {
    $this->actingAs(responsabileDi($this->enteA, [$this->deptA1]));

    expect(Intervento::pluck('id')->all())->not->toContain($this->intB1->id);
});

it('shows no interventi to a Responsabile without assignments (fail-safe)', function () {
    $this->actingAs(interventiUserWith('Responsabile Reparto', $this->enteA->id));

    expect(Intervento::count())->toBe(0);
});

it('does not let a Responsabile read an intervento outside its subtree', function () {
    $this->actingAs(responsabileDi($this->enteA, [$this->deptA1]));

    expect(Intervento::find($this->intA2->id))->toBeNull();
    expect(Intervento::where('id', $this->intA2->id)->exists())->toBeFalse();
});

it('does not let a Responsabile update or delete an intervento outside its subtree', function () {
    $this->actingAs(responsabileDi($this->enteA, [$this->deptA1]));

    $affected = Intervento::where('id', $this->intA2->id)->update(['descrizione' => 'manomesso']);
    Intervento::where('id', $this->intA2->id)->delete();

    $survivor = Intervento::withoutGlobalScopes()->withTrashed()->find($this->intA2->id);

    expect($affected)->toBe(0)
        ->and($survivor)->not->toBeNull()
        ->and($survivor->trashed())->toBeFalse()
        ->and($survivor->descrizione)->not->toBe('manomesso');
});

it('does not restrict an Admin within its own tenant', function () {
    $this->actingAs(interventiUserWith('Admin', $this->enteA->id));

    expect(Intervento::count())->toBe(3); // tutto l'Ente A, niente Ente B
});

it('does not restrict the Tenant role (interventi are visible to the Tenant, ERD §5.2)', function () {
    $this->actingAs(interventiUserWith('Tenant', $this->enteA->id));

    expect(Intervento::count())->toBe(3);
});

it('does not restrict when there is no authenticated context', function () {
    expect(Intervento::count())->toBe(4); // console/seeder: nessuno scope
});

it('still shows interventi of a soft-deleted strumento to the Responsabile', function () {
    // La subquery dello scope gira senza global scope, quindi senza
    // SoftDeletingScope: lo stesso dato che l'Admin continua a vedere.
    $this->strumentoA1->delete();

    $this->actingAs(responsabileDi($this->enteA, [$this->deptA1]));

    expect(Intervento::pluck('id')->all())->toContain($this->intA1->id);
});

it('exposes withoutGlobalScope as an escape hatch from the department filter', function () {
    $this->actingAs(responsabileDi($this->enteA, [$this->deptA1]));

    // Tolto solo il filtro reparto resta il confine tenant: tutto l'Ente A.
    expect(Intervento::withoutGlobalScope(DepartmentThroughStrumentoScope::class)->count())->toBe(3);
});

it('does not yet grant a Tecnico access through an assigned intervento (ADR-007 lands in S4)', function () {
    // Il Tecnico è senza tenant: fail-closed (ADR-018). Congela il debito noto.
    $tecnico = interventiUserWith('Tecnico', null);
    Intervento::withoutGlobalScopes()
        ->whereKey($this->intA1->id)
        ->update(['tecnico_id' => $tecnico->id]);

    $this->actingAs($tecnico);

    expect(Intervento::count())->toBe(0);
});
