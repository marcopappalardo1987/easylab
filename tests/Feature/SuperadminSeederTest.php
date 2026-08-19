<?php

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperadminSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config()->set('easylab.piattaforma.superadmin.email', 'direzione@easylab.test');
    config()->set('easylab.piattaforma.superadmin.password', 'segreto-di-prova');
});

it('creates the Superadmin with its own platform Account and Ente', function () {
    $this->seed(SuperadminSeeder::class);

    $utente = User::where('email', 'direzione@easylab.test')->sole();
    $ente = UnitaOrganizzativa::where('nome', 'EasyLab')->sole();
    $account = Account::where('ragione_sociale', 'EasyLab')->sole();

    expect($utente->hasRole('Superadmin'))->toBeTrue()
        ->and($utente->email_verified_at)->not->toBeNull()
        ->and($ente->tipo)->toBe(TipoUnitaOrganizzativa::Ente)
        ->and($ente->tenant_id)->toBe($ente->id)
        ->and($ente->account_id)->toBe($account->id)
        ->and($account->membri()->whereKey($utente->id)->exists())->toBeTrue();
});

it('binds the Superadmin to a tenant, so the fail-closed scope does not blank it', function () {
    $this->seed(SuperadminSeeder::class);

    $utente = User::where('email', 'direzione@easylab.test')->sole();
    $ente = UnitaOrganizzativa::where('nome', 'EasyLab')->sole();

    // Senza `tenant_id` il TenantScope (ADR-018) restituirebbe `1 = 0` a ogni
    // query: un Superadmin che entra e non vede niente.
    expect($utente->tenant_id)->toBe($ente->id);
});

it('creates nothing when the environment variables are missing', function () {
    config()->set('easylab.piattaforma.superadmin.email', null);
    config()->set('easylab.piattaforma.superadmin.password', null);

    $this->seed(SuperadminSeeder::class);

    expect(User::count())->toBe(0)
        ->and(Account::count())->toBe(0)
        ->and(UnitaOrganizzativa::count())->toBe(0);
});

it('creates nothing when only the password is missing', function () {
    config()->set('easylab.piattaforma.superadmin.password', '');

    $this->seed(SuperadminSeeder::class);

    expect(User::count())->toBe(0);
});

it('is idempotent and refreshes the password instead of duplicating', function () {
    $this->seed(SuperadminSeeder::class);

    config()->set('easylab.piattaforma.superadmin.password', 'password-nuova');
    $this->seed(SuperadminSeeder::class);

    expect(User::where('email', 'direzione@easylab.test')->count())->toBe(1)
        ->and(UnitaOrganizzativa::where('nome', 'EasyLab')->count())->toBe(1)
        ->and(Account::where('ragione_sociale', 'EasyLab')->count())->toBe(1);

    $utente = User::where('email', 'direzione@easylab.test')->sole();
    expect(Hash::check('password-nuova', $utente->password))->toBeTrue();
});

it('does not tear an existing user away from their own Ente', function () {
    $altroEnte = UnitaOrganizzativa::create([
        'tipo' => TipoUnitaOrganizzativa::Ente,
        'nome' => 'Laboratorio Terzo',
        'parent_id' => null,
    ]);
    $altroEnte->forceFill(['tenant_id' => $altroEnte->id])->saveQuietly();

    $esistente = User::create([
        'name' => 'Utente Esistente',
        'email' => 'direzione@easylab.test',
        'password' => Hash::make('vecchia'),
    ]);
    $esistente->forceFill(['tenant_id' => $altroEnte->id])->save();

    $this->seed(SuperadminSeeder::class);

    expect($esistente->fresh()->tenant_id)->toBe($altroEnte->id);
});
