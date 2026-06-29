<?php

use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\IsolationHarness;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('isolates strumenti across tenants (reusing the IsolationHarness)', function () {
    $enteA = UnitaOrganizzativa::factory()->ente()->create();
    $deptA = UnitaOrganizzativa::factory()->dipartimento()->under($enteA)->create();
    $a1 = Strumento::factory()->forNode($deptA)->create();
    $a2 = Strumento::factory()->forNode($deptA)->create();

    $enteB = UnitaOrganizzativa::factory()->ente()->create();
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();
    $b1 = Strumento::factory()->forNode($deptB)->create();

    $admin = User::factory()->create(['tenant_id' => $enteA->id]);
    $admin->assignRole('Admin');
    $this->actingAs($admin);

    IsolationHarness::assertReadIsolation(Strumento::class, [$a1->id, $a2->id], (int) $b1->id);
});
