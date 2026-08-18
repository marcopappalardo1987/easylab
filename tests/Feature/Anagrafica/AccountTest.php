<?php

use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/**
 * L'Account (🔗 ADR-032): relazioni, invarianti e le colonne che nessun form
 * può forgiare. Il *come si usa* (switcher, provisioning) è testato altrove.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('owns its enti across tenants, and lists its membri', function () {
    $account = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $enteA = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create();
    $enteB = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create();

    $admin = User::factory()->create(['tenant_id' => $enteA->id]);
    $admin->assignRole('Admin');
    $account->aggiungiMembro($admin);

    // Da utente scopato sull'ente A: la relazione enti() deve comunque vedere
    // entrambi — è l'eccezione nominata, gli Enti di un account sono per
    // definizione fuori dal tenant corrente.
    $this->actingAs($admin);

    expect($account->enti()->pluck('id')->all())->toEqualCanonicalizing([$enteA->id, $enteB->id])
        ->and($account->membri->pluck('id')->all())->toBe([$admin->id]);
});

it('refuses to remove the last membro', function () {
    $account = Account::factory()->create();
    $ente = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create();
    $admin = User::factory()->create(['tenant_id' => $ente->id]);
    $account->aggiungiMembro($admin);

    expect(fn () => $account->rimuoviMembro($admin))->toThrow(RuntimeException::class);
    expect($account->membri()->count())->toBe(1);
});

it('removes a membro when another one remains', function () {
    $account = Account::factory()->create();
    $ente = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create();
    $primo = User::factory()->create(['tenant_id' => $ente->id]);
    $secondo = User::factory()->create(['tenant_id' => $ente->id]);
    $account->aggiungiMembro($primo);
    $account->aggiungiMembro($secondo);

    $account->rimuoviMembro($primo);

    expect($account->membri()->pluck('users.id')->all())->toBe([$secondo->id]);
});

it('never lets account_id live on a non-ente node', function () {
    $account = Account::factory()->create();
    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $dipartimento = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create();

    expect(fn () => $dipartimento->forceFill(['account_id' => $account->id])->save())
        ->toThrow(RuntimeException::class);
});

it('never lets account_id be mass-assigned on a node', function () {
    $account = Account::factory()->create();
    $ente = UnitaOrganizzativa::factory()->ente()->create();

    $ente->update(['account_id' => $account->id, 'nome' => 'Rinominato']);

    expect($ente->fresh()->account_id)->toBeNull()
        ->and($ente->fresh()->nome)->toBe('Rinominato');
});

it('never lets the lockout be mass-assigned', function () {
    $account = Account::factory()->create();

    $account->update(['is_locked' => true, 'ragione_sociale' => 'Rinominata']);

    expect($account->fresh()->is_locked)->toBeFalse()
        ->and($account->fresh()->ragione_sociale)->toBe('Rinominata');
});

it('audits the creation through the domain trait', function () {
    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $admin = User::factory()->create(['tenant_id' => $ente->id]);
    $admin->assignRole('Admin');
    $this->actingAs($admin);

    $account = Account::create(['ragione_sociale' => 'Gruppo Bianchi']);

    $riga = Activity::inLog(AuditLog::NAME)->latest('id')->first();
    expect($riga->description)->toBe('Creazione account')
        ->and($riga->subject_id)->toBe($account->id);
});
