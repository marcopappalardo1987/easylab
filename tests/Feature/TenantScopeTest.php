<?php

use App\Models\Scopes\TenantScope;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantThing;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    Schema::create('tenant_things', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->nullable()->index();
        $table->string('name');
        $table->timestamps();
    });

    // Due tenant distinti (gli id 1 e 2 rappresentano due Enti).
    TenantThing::withoutGlobalScope(TenantScope::class)->create(['tenant_id' => 1, 'name' => 'A-uno']);
    TenantThing::withoutGlobalScope(TenantScope::class)->create(['tenant_id' => 1, 'name' => 'A-due']);
    TenantThing::withoutGlobalScope(TenantScope::class)->create(['tenant_id' => 2, 'name' => 'B-uno']);
});

afterEach(function () {
    Schema::dropIfExists('tenant_things');
});

function tenantUser(string $role, ?int $tenantId): User
{
    $user = User::factory()->create(['tenant_id' => $tenantId]);
    $user->assignRole($role);

    return $user;
}

it('isolates one tenant from another (the most important test)', function () {
    $this->actingAs(tenantUser('Tenant', 1));

    $things = TenantThing::all();

    expect($things)->toHaveCount(2);
    expect($things->pluck('name')->all())->toEqualCanonicalizing(['A-uno', 'A-due']);
    expect($things->pluck('tenant_id')->unique()->all())->toBe([1]);
});

it('lets a tenant-bound user see only its own row by id', function () {
    $this->actingAs(tenantUser('Tenant', 1));

    expect(TenantThing::where('name', 'B-uno')->first())->toBeNull();
});

it('scopes Developer to its own tenant (no bypass, ADR-018)', function () {
    $this->actingAs(tenantUser('Developer', 1));

    expect(TenantThing::count())->toBe(2); // solo tenant 1
});

it('scopes Superadmin to its own tenant (no bypass, ADR-018)', function () {
    $this->actingAs(tenantUser('Superadmin', 2));

    expect(TenantThing::pluck('name')->all())->toBe(['B-uno']); // solo tenant 2
});

it('shows nothing to an authenticated user without a tenant (fail-closed, ADR-018)', function () {
    $this->actingAs(tenantUser('Developer', null));

    expect(TenantThing::count())->toBe(0);
});

it('does not scope when there is no authenticated context (console/seeder/job)', function () {
    expect(TenantThing::count())->toBe(3);
});

it('auto-stamps tenant_id on create from the current tenant', function () {
    $this->actingAs(tenantUser('Tenant', 1));

    $thing = TenantThing::create(['name' => 'A-tre']);

    expect($thing->tenant_id)->toBe(1);
    expect(TenantThing::count())->toBe(3); // 2 di tenant 1 + la nuova
});

it('exposes withoutGlobalScope as an escape hatch for global dashboards', function () {
    $this->actingAs(tenantUser('Tenant', 1));

    expect(TenantThing::withoutGlobalScope(TenantScope::class)->count())->toBe(3);
});

it('prevents a tenant from updating another tenant row', function () {
    $bRow = TenantThing::withoutGlobalScope(TenantScope::class)->where('name', 'B-uno')->first();

    $this->actingAs(tenantUser('Tenant', 1));

    $affected = TenantThing::where('id', $bRow->id)->update(['name' => 'hijacked']);

    expect($affected)->toBe(0);
    expect($bRow->fresh()->name)->toBe('B-uno');
});

it('prevents a tenant from deleting another tenant row', function () {
    $bRow = TenantThing::withoutGlobalScope(TenantScope::class)->where('name', 'B-uno')->first();

    $this->actingAs(tenantUser('Tenant', 1));

    TenantThing::where('id', $bRow->id)->delete();

    expect($bRow->fresh())->not->toBeNull();
});
