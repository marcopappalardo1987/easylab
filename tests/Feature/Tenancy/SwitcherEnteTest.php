<?php

use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/**
 * Lo switcher fra i propri Enti (🔗 ADR-032 punto 4) — area rossa: è l'unico
 * punto nuovo che tocca la tenancy, non lo scope ma il dato su cui lo scope si
 * appoggia. Ogni guardia ha il suo test negativo, e ogni negativo asserisce
 * tre cose: `false`, `tenant_id` invariato, NESSUNA riga di audit.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->enteA = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Nord']);
    $this->enteB = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Sud']);

    $this->membro = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $this->membro->assignRole('Admin');
    $this->account->aggiungiMembro($this->membro);
});

function switchAudit(): ?Activity
{
    return Activity::inLog(AuditLog::NAME)
        ->where('description', 'Ente attivo cambiato')
        ->latest('id')->first();
}

it('lets a membro switch between the enti of their account, audited', function () {
    $this->actingAs($this->membro);

    expect($this->membro->passaAllEnte($this->enteB))->toBeTrue()
        ->and($this->membro->fresh()->tenant_id)->toBe($this->enteB->id);

    $riga = switchAudit();
    expect($riga)->not->toBeNull()
        ->and($riga->causer_id)->toBe($this->membro->id)
        ->and($riga->properties['da_tenant_id'])->toBe($this->enteA->id)
        ->and($riga->properties['a_tenant_id'])->toBe($this->enteB->id)
        ->and($riga->properties['account_id'])->toBe($this->account->id);
});

it('shows only the new tenant data after the switch (ADR-018 intact)', function () {
    $strumentoA = Strumento::factory()->forNode($this->enteA)->create(['nome' => 'Macchina Nord']);
    $strumentoB = Strumento::factory()->forNode($this->enteB)->create(['nome' => 'Macchina Sud']);

    $this->actingAs($this->membro);
    expect(Strumento::pluck('id')->all())->toBe([$strumentoA->id]);

    $this->membro->passaAllEnte($this->enteB);

    // Nuova "richiesta": ci si riautentica con l'utente aggiornato.
    auth()->logout();
    $this->actingAs($this->membro->fresh());
    expect(Strumento::pluck('id')->all())->toBe([$strumentoB->id]);
});

it('refuses a user who is not a membro', function () {
    $estraneo = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $estraneo->assignRole('Tenant');
    $this->actingAs($estraneo);

    expect($estraneo->passaAllEnte($this->enteB))->toBeFalse()
        ->and($estraneo->fresh()->tenant_id)->toBe($this->enteA->id)
        ->and(switchAudit())->toBeNull();
});

it('refuses an ente that belongs to somebody else account', function () {
    $altrui = UnitaOrganizzativa::factory()->ente()
        ->perAccount(Account::factory()->create())
        ->create();
    $this->actingAs($this->membro);

    expect($this->membro->passaAllEnte($altrui))->toBeFalse()
        ->and($this->membro->fresh()->tenant_id)->toBe($this->enteA->id)
        ->and(switchAudit())->toBeNull();
});

it('refuses an ente without an account', function () {
    $orfano = UnitaOrganizzativa::factory()->ente()->create();
    $this->actingAs($this->membro);

    expect($this->membro->passaAllEnte($orfano))->toBeFalse()
        ->and(switchAudit())->toBeNull();
});

it('refuses a non-ente node', function () {
    $dipartimento = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create();
    $this->actingAs($this->membro);

    expect($this->membro->passaAllEnte($dipartimento))->toBeFalse()
        ->and($this->membro->fresh()->tenant_id)->toBe($this->enteA->id)
        ->and(switchAudit())->toBeNull();
});

it('refuses the enti of an account in lockout', function () {
    $bloccato = Account::factory()->bloccato()->create();
    $sede = UnitaOrganizzativa::factory()->ente()->perAccount($bloccato)->create();
    $bloccato->aggiungiMembro($this->membro);
    $this->actingAs($this->membro);

    expect($this->membro->passaAllEnte($sede))->toBeFalse()
        ->and($this->membro->fresh()->tenant_id)->toBe($this->enteA->id)
        ->and(switchAudit())->toBeNull();
});

it('refuses to switch while impersonating', function () {
    $superadmin = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $superadmin->assignRole('Superadmin');

    $this->actingAs($superadmin)->get(route('impersonate', $this->membro));

    $impersonato = auth()->user();
    expect($impersonato->id)->toBe($this->membro->id)
        ->and($impersonato->passaAllEnte($this->enteB))->toBeFalse()
        ->and($this->membro->fresh()->tenant_id)->toBe($this->enteA->id)
        ->and(switchAudit())->toBeNull();
});

it('never lets tenant_id be mass-assigned', function () {
    // Fuori dalle factory (che girano unguarded): il percorso di un form.
    $creato = User::create([
        'name' => 'Forgiato',
        'email' => 'forgiato@test.test',
        'password' => bcrypt('password'),
        'tenant_id' => $this->enteA->id,
    ]);
    expect($creato->tenant_id)->toBeNull();

    $this->membro->update(['tenant_id' => $this->enteB->id, 'name' => 'Rinominato']);
    expect($this->membro->fresh()->tenant_id)->toBe($this->enteA->id)
        ->and($this->membro->fresh()->name)->toBe('Rinominato');
});

it('leaves a Responsabile fail-safe in the ente they switched into', function () {
    // Nota affermativa, non un bug: le assegnazioni `responsabile_unita` sono
    // per-nodo di UN ente. Nell'ente di arrivo il Responsabile non ha nodi →
    // il fail-safe esistente ([] = non vede nulla) fa esattamente il suo
    // lavoro, finché qualcuno non gli assegna un reparto anche lì.
    $resp = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $resp->assignRole('Responsabile Reparto');
    $dipartimento = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create();
    $resp->unitaResponsabili()->attach($dipartimento->id);
    $this->account->aggiungiMembro($resp);

    Strumento::factory()->forNode($dipartimento)->create();
    Strumento::factory()->forNode($this->enteB)->create();

    $this->actingAs($resp);
    expect(Strumento::count())->toBe(1);

    $resp->passaAllEnte($this->enteB);
    auth()->logout();
    $this->actingAs($resp->fresh());

    expect(Strumento::count())->toBe(0);
});
