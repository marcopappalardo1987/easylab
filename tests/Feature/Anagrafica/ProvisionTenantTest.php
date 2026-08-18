<?php

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\InvitoUtente;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

it('invites a brand new admin instead of handing out a password', function () {
    // Il default del provisioning da ADR-012: nessuna password da comunicare.
    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Ente Invitato',
        '--admin-email' => 'invitato@demo.test',
    ])->assertSuccessful()
        ->doesntExpectOutputToContain('Password generata');

    $admin = User::where('email', 'invitato@demo.test')->first();

    expect($admin->email_verified_at)->toBeNull();
    Notification::assertSentTo($admin, InvitoUtente::class,
        fn (InvitoUtente $n, array $canali) => $canali === ['mail']);
});

it('keeps the historical behaviour when a password is given', function () {
    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Ente Manuale',
        '--admin-email' => 'manuale@demo.test',
        '--admin-password' => 'secret-password',
    ])->assertSuccessful();

    expect(User::where('email', 'manuale@demo.test')->first()->email_verified_at)->not->toBeNull();
    Notification::assertNothingSent();
});

it('never invites an already active admin attached to a new sede', function () {
    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Prima',
        '--admin-email' => 'attivo@demo.test',
        '--admin-password' => 'secret-password',
    ])->assertSuccessful();

    Notification::fake(); // si guarda solo il secondo giro
    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Seconda',
        '--admin-email' => 'attivo@demo.test',
    ])->assertSuccessful();

    Notification::assertNothingSent();
});

it('resends the invite to somebody who never activated', function () {
    $args = ['nome' => 'Ente Lento', '--admin-email' => 'lento@demo.test'];

    $this->artisan('easylab:provision-tenant', $args)->assertSuccessful();
    $this->artisan('easylab:provision-tenant', $args)->assertSuccessful();

    $admin = User::where('email', 'lento@demo.test')->first();
    Notification::assertSentToTimes($admin, InvitoUtente::class, 2);
    expect(User::where('email', 'lento@demo.test')->count())->toBe(1);
});

it('keeps the provisioning even if the invite cannot be sent', function () {
    // SMTP giù: le scritture sono committate, il comando non deve mentire
    // dicendo che è fallito — dice che l'invito non è partito.
    Notification::shouldReceive('send')->andThrow(new RuntimeException('SMTP irraggiungibile'));

    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Ente Sfortunato',
        '--admin-email' => 'sfortunato@demo.test',
    ])->assertSuccessful()
        ->expectsOutputToContain('rilanciare lo stesso comando');

    expect(UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Ente Sfortunato')->exists())->toBeTrue()
        ->and(User::where('email', 'sfortunato@demo.test')->exists())->toBeTrue();
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
