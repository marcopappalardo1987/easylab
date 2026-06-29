<?php

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('provisions an ente node and an admin user', function () {
    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Ente Demo',
        '--admin-email' => 'admin@demo.test',
        '--admin-password' => 'secret-password',
    ])->assertSuccessful();

    $ente = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Ente Demo')->first();
    expect($ente)->not->toBeNull();
    expect($ente->tipo)->toBe(TipoUnitaOrganizzativa::Ente);
    expect($ente->tenant_id)->toBe($ente->id);
    expect($ente->parent_id)->toBeNull();

    $admin = User::where('email', 'admin@demo.test')->first();
    expect($admin)->not->toBeNull();
    expect($admin->tenant_id)->toBe($ente->id);
    expect($admin->hasRole('Admin'))->toBeTrue();
});

it('is idempotent on the admin email', function () {
    $args = [
        'nome' => 'Ente Demo',
        '--admin-email' => 'admin@demo.test',
        '--admin-password' => 'secret-password',
    ];

    $this->artisan('easylab:provision-tenant', $args)->assertSuccessful();
    $this->artisan('easylab:provision-tenant', $args)->assertSuccessful();

    expect(User::where('email', 'admin@demo.test')->count())->toBe(1);
});
