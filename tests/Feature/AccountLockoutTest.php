<?php

use App\Models\Account;
use App\Support\AuditLog;
use Spatie\Activitylog\Models\Activity;

/**
 * Il gesto di lockout (🔗 ADR-013) — area rossa billing: qui si verifica che il
 * gesto scriva le tre colonne, che l'audit lo registri DAL TRAIT (la promessa
 * del docblock di Account), e che l'idempotenza sia un no-op silenzioso — non
 * una riscrittura che sposta `locked_at` e sporca il registro.
 */
function righeAuditAccount(Account $account): int
{
    return Activity::inLog(AuditLog::NAME)
        ->where('subject_type', $account->getMorphClass())
        ->where('subject_id', $account->id)
        ->where('description', '!=', 'Creazione account')
        ->count();
}

it('locks the account, and the trait audits the three columns', function () {
    $account = Account::factory()->create();

    $account->blocca('Insoluto fattura 42');

    $account->refresh();
    expect($account->is_locked)->toBeTrue()
        ->and($account->locked_at)->not->toBeNull()
        ->and($account->locked_reason)->toBe('Insoluto fattura 42');

    $riga = Activity::inLog(AuditLog::NAME)
        ->where('subject_id', $account->id)
        ->latest('id')->first();

    expect($riga->attribute_changes['old']['is_locked'])->toBeFalse()
        ->and($riga->attribute_changes['attributes']['is_locked'])->toBeTrue()
        ->and($riga->attribute_changes['attributes']['locked_reason'])->toBe('Insoluto fattura 42');
});

it('is a silent no-op on an already locked account', function () {
    $account = Account::factory()->create();
    $account->blocca('Primo insoluto');
    $primoLockedAt = $account->fresh()->locked_at;
    $righePrima = righeAuditAccount($account);

    $this->travel(3)->days();
    $account->fresh()->blocca('Secondo tentativo');

    // locked_at documenta QUANDO è iniziato l'insoluto: non si riscrive.
    expect($account->fresh()->locked_at->toDateTimeString())->toBe($primoLockedAt->toDateTimeString())
        ->and($account->fresh()->locked_reason)->toBe('Primo insoluto')
        ->and(righeAuditAccount($account))->toBe($righePrima);
});

it('unlocks and clears the three columns, with its own audit row', function () {
    $account = Account::factory()->bloccato()->create();

    $account->sblocca();

    $account->refresh();
    expect($account->is_locked)->toBeFalse()
        ->and($account->locked_at)->toBeNull()
        ->and($account->locked_reason)->toBeNull();

    $riga = Activity::inLog(AuditLog::NAME)
        ->where('subject_id', $account->id)
        ->latest('id')->first();

    expect($riga->attribute_changes['old']['is_locked'])->toBeTrue()
        ->and($riga->attribute_changes['attributes']['is_locked'])->toBeFalse();
});

it('is a silent no-op unlocking an account that is not locked', function () {
    $account = Account::factory()->create();
    $righePrima = righeAuditAccount($account);

    $account->sblocca();

    expect($account->fresh()->is_locked)->toBeFalse()
        ->and(righeAuditAccount($account))->toBe($righePrima);
});
