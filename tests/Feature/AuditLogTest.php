<?php

use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function lastAudit(): ?Activity
{
    return Activity::where('log_name', AuditLog::NAME)->latest('id')->first();
}

it('uses a dedicated audit log channel', function () {
    expect(AuditLog::NAME)->toBe('audit');
});

it('logs a successful login', function () {
    $user = User::factory()->create(['password' => bcrypt('secret-pw-123')]);

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-pw-123']);

    $entry = lastAudit();
    expect($entry)->not->toBeNull();
    expect($entry->description)->toBe('Login');
    expect($entry->causer_id)->toBe($user->id);
});

it('logs a failed login with the attempted email', function () {
    $user = User::factory()->create(['password' => bcrypt('secret-pw-123')]);

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);

    $entry = Activity::where('log_name', AuditLog::NAME)->where('description', 'Login fallito')->first();
    expect($entry)->not->toBeNull();
    expect($entry->properties['email'])->toBe($user->email);
    expect($entry->causer_id)->toBeNull();
});

it('logs a logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout');

    expect(Activity::where('log_name', AuditLog::NAME)->where('description', 'Logout')->exists())->toBeTrue();
});

it('logs an impersonation take with causer and subject', function () {
    $superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');
    $tenant = User::factory()->create();
    $tenant->assignRole('Tenant');

    $this->actingAs($superadmin)->get(route('impersonate', $tenant));

    $entry = Activity::where('log_name', AuditLog::NAME)->where('description', 'Impersonation avviata')->first();
    expect($entry)->not->toBeNull();
    expect($entry->causer_id)->toBe($superadmin->id);
    expect($entry->subject_id)->toBe($tenant->id);
});

it('logs a 2FA confirmation', function () {
    $user = User::factory()->create();

    event(new TwoFactorAuthenticationConfirmed($user));

    $entry = lastAudit();
    expect($entry->description)->toBe('2FA confermato');
    expect($entry->causer_id)->toBe($user->id);
});
