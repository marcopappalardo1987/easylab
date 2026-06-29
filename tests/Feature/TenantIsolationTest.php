<?php

use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantThing;
use Tests\Support\IsolationHarness;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    Schema::create('tenant_things', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->nullable()->index();
        $table->string('name');
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('tenant_things');
});

function isolationUser(string $role, ?int $tenantId): User
{
    $user = User::factory()->create(['tenant_id' => $tenantId]);
    $user->assignRole($role);

    return $user;
}

// --- Vettori di isolamento sul modello reale (UnitaOrganizzativa) ---

it('blocks every read/query vector across tenants on the real model', function () {
    $enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'A']);
    $deptA = UnitaOrganizzativa::factory()->dipartimento()->under($enteA)->create(['nome' => 'A-dept']);
    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'B']);

    $this->actingAs(isolationUser('Admin', $enteA->id));

    IsolationHarness::assertReadIsolation(
        UnitaOrganizzativa::class,
        ownIds: [$enteA->id, $deptA->id],
        foreignId: $enteB->id,
    );
});

// --- Stessa batteria su un secondo modello (fixture) → genericità harness ---

it('blocks every read/query vector across tenants on a second model', function () {
    TenantThing::withoutGlobalScopes()->create(['tenant_id' => 1, 'name' => 'A1']);
    TenantThing::withoutGlobalScopes()->create(['tenant_id' => 1, 'name' => 'A2']);
    $foreign = TenantThing::withoutGlobalScopes()->create(['tenant_id' => 2, 'name' => 'B1']);

    $this->actingAs(isolationUser('Tenant', 1));

    $ownIds = TenantThing::pluck('id')->all();
    IsolationHarness::assertReadIsolation(TenantThing::class, $ownIds, (int) $foreign->id);
});

// --- Hardening lato scrittura (comportamento del trait) ---

it('forces tenant_id to the own tenant on create even if a foreign one is provided', function () {
    $this->actingAs(isolationUser('Tenant', 1));

    $thing = TenantThing::create(['tenant_id' => 2, 'name' => 'forgiato']);

    expect($thing->tenant_id)->toBe(1);
});

it('reverts a tenant_id change on update for a tenant-bound user', function () {
    $own = TenantThing::withoutGlobalScopes()->create(['tenant_id' => 1, 'name' => 'mio']);

    $this->actingAs(isolationUser('Tenant', 1));

    $own->tenant_id = 2;
    $own->save();

    expect($own->fresh()->tenant_id)->toBe(1);
});

// --- Nessun ruolo è esente: anche il Superadmin è scopato (ADR-018) ---

it('forces tenant_id even for a Superadmin on create (no bypass, ADR-018)', function () {
    $this->actingAs(isolationUser('Superadmin', 1));

    $thing = TenantThing::create(['tenant_id' => 2, 'name' => 'forgiato']);

    expect($thing->tenant_id)->toBe(1);
});

it('reverts a tenant_id change even for a Superadmin on update (ADR-018)', function () {
    $own = TenantThing::withoutGlobalScopes()->create(['tenant_id' => 1, 'name' => 'mio']);

    $this->actingAs(isolationUser('Superadmin', 1));

    $own->tenant_id = 2;
    $own->save();

    expect($own->fresh()->tenant_id)->toBe(1);
});

// --- Solo il contesto SENZA utente (console/provisioning) scrive liberamente ---

it('does not force tenant_id in console/guest context (provisioning, spostamenti)', function () {
    $thing = TenantThing::withoutGlobalScopes()->create(['tenant_id' => 7, 'name' => 'console']);

    expect($thing->tenant_id)->toBe(7);
});
