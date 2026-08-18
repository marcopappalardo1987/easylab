<?php

use App\Models\Account;
use App\Support\AuditLog;
use Spatie\Activitylog\Models\Activity;

/**
 * La leva console del lockout (🔗 ADR-013). Il gesto in sé è coperto da
 * AccountLockoutTest: qui si verifica la superficie del comando — validazioni
 * PRIMA di scrivere, idempotenza operativa, e che il tutto passi dai metodi
 * dominio (l'audit ne è la prova).
 */
function auditLockout(Account $account): int
{
    return Activity::inLog(AuditLog::NAME)
        ->where('subject_type', $account->getMorphClass())
        ->where('subject_id', $account->id)
        ->where('description', 'Modifica account')
        ->count();
}

it('locks an account with a motivo', function () {
    $account = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);

    $this->artisan('easylab:lockout', ['account' => $account->id, '--motivo' => 'Insoluto fattura 42'])
        ->assertSuccessful();

    expect($account->fresh()->is_locked)->toBeTrue()
        ->and($account->fresh()->locked_reason)->toBe('Insoluto fattura 42')
        ->and(auditLockout($account))->toBe(1);
});

it('refuses to lock without a motivo, leaving the account untouched', function () {
    $account = Account::factory()->create();

    $this->artisan('easylab:lockout', ['account' => $account->id])->assertFailed();

    // Il negativo dell'area rossa: FAILURE = nessuna scrittura, nessun audit.
    expect($account->fresh()->is_locked)->toBeFalse()
        ->and(auditLockout($account))->toBe(0);
});

it('fails on a missing account', function () {
    $this->artisan('easylab:lockout', ['account' => '999', '--motivo' => 'x'])->assertFailed();
});

it('is operationally idempotent: locking twice keeps one audit row', function () {
    $account = Account::factory()->create();

    $this->artisan('easylab:lockout', ['account' => $account->id, '--motivo' => 'Primo'])->assertSuccessful();
    $this->artisan('easylab:lockout', ['account' => $account->id, '--motivo' => 'Secondo'])->assertSuccessful();

    expect($account->fresh()->locked_reason)->toBe('Primo')
        ->and(auditLockout($account))->toBe(1);
});

it('unlocks with --sblocca', function () {
    $account = Account::factory()->bloccato()->create();

    $this->artisan('easylab:lockout', ['account' => $account->id, '--sblocca' => true])->assertSuccessful();

    expect($account->fresh()->is_locked)->toBeFalse()
        ->and($account->fresh()->locked_reason)->toBeNull();
});

it('warns but succeeds unlocking an account that is not locked', function () {
    $account = Account::factory()->create();

    $this->artisan('easylab:lockout', ['account' => $account->id, '--sblocca' => true])->assertSuccessful();

    expect(auditLockout($account))->toBe(0);
});
