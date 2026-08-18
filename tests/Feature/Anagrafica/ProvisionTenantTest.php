<?php

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
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

it('creates a 1:1 account whose admin is a membro', function () {
    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Ente Demo',
        '--admin-email' => 'admin@demo.test',
        '--admin-password' => 'secret-password',
    ])->assertSuccessful();

    $ente = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Ente Demo')->first();
    $account = $ente->account;

    expect($account)->not->toBeNull()
        ->and($account->ragione_sociale)->toBe('Ente Demo')
        ->and($account->membri->pluck('email')->all())->toBe(['admin@demo.test']);
});

it('attaches the new ente to an existing account with --account', function () {
    $account = Account::factory()->create(['ragione_sociale' => 'Gruppo Esistente']);

    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Seconda Sede',
        '--account' => (string) $account->id,
        '--admin-email' => 'sede2@demo.test',
        '--admin-password' => 'secret-password',
    ])->assertSuccessful();

    $ente = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Seconda Sede')->first();

    expect($ente->account_id)->toBe($account->id)
        ->and(Account::count())->toBe(1);
});

it('fails on a missing --account without writing anything', function () {
    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Ente Fantasma',
        '--account' => '999',
    ])->assertFailed();

    // La prova della validazione-prima-di-scrivere: zero righe ovunque.
    expect(UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Ente Fantasma')->exists())->toBeFalse()
        ->and(Account::count())->toBe(0)
        ->and(User::count())->toBe(0);
});

it('attaches to the account of an existing admin, without touching their tenant', function () {
    // Il caso che il vecchio firstOrCreate rendeva un bug muto (ADR-032):
    // stesso admin, secondo Ente.
    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Prima Sede',
        '--admin-email' => 'multi@demo.test',
        '--admin-password' => 'secret-password',
    ])->assertSuccessful();

    $primaSede = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Prima Sede')->first();
    $admin = User::where('email', 'multi@demo.test')->first();
    expect($admin->tenant_id)->toBe($primaSede->id);

    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Seconda Sede',
        '--admin-email' => 'multi@demo.test',
        '--admin-password' => 'secret-password',
    ])->assertSuccessful();

    $secondaSede = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Seconda Sede')->first();

    // Un solo account con due Enti; il tenant dell'admin NON è cambiato —
    // la seconda sede la raggiunge con lo switcher.
    expect(Account::count())->toBe(1)
        ->and($secondaSede->account_id)->toBe($primaSede->account_id)
        ->and($admin->fresh()->tenant_id)->toBe($primaSede->id)
        ->and($admin->passaAllEnte($secondaSede))->toBeTrue();
});

it('demands --account when the existing admin has several accounts', function () {
    $admin = User::factory()->create(['email' => 'conteso@demo.test']);
    $primo = Account::factory()->create();
    $secondo = Account::factory()->create();
    $primo->aggiungiMembro($admin);
    $secondo->aggiungiMembro($admin);

    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Ente Conteso',
        '--admin-email' => 'conteso@demo.test',
    ])->assertFailed();

    expect(UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Ente Conteso')->exists())->toBeFalse()
        ->and(Account::count())->toBe(2);
});
