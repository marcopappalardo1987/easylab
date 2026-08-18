<?php

use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Tenancy\AccessibleNodes;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * `AccessibleNodes::forUser()`, estratta in S5 per lo scheduler (🔗 ADR-011).
 *
 * Il punto di questi test non è il sotto-albero — quello lo copre già
 * `DepartmentScopeTest` dal lato dello scope — ma l'**allineamento**: il digest
 * email gira in console e deve rispondere esattamente come risponderebbe l'app
 * all'utente loggato. Se le due strade divergessero, l'email diventerebbe il
 * canale che mostra al Responsabile i reparti che in app non vede.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept1 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->sub1 = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->dept1)->create();
    $this->dept2 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
});

function responsabileConNodi(int $tenantId, array $nodi): User
{
    $user = User::factory()->create(['tenant_id' => $tenantId]);
    $user->assignRole('Responsabile Reparto');
    $user->unitaResponsabili()->attach($nodi);

    return $user;
}

it('returns the assigned subtree without an authenticated user', function () {
    $resp = responsabileConNodi($this->ente->id, [$this->dept1->id]);

    // Nessun actingAs: è la condizione dello scheduler.
    expect(AccessibleNodes::forUser($resp))
        ->toEqualCanonicalizing([$this->dept1->id, $this->sub1->id]);
});

it('answers exactly like forCurrentUser does for the same user', function () {
    $resp = responsabileConNodi($this->ente->id, [$this->dept1->id, $this->dept2->id]);

    $daConsole = AccessibleNodes::forUser($resp);

    $this->actingAs($resp);
    expect($daConsole)->toEqualCanonicalizing(AccessibleNodes::forCurrentUser());
});

it('returns null for a user without department restriction', function () {
    $admin = User::factory()->create(['tenant_id' => $this->ente->id]);
    $admin->assignRole('Admin');

    // null = «nessuna restrizione di reparto», diverso da [] = «non vede nulla».
    expect(AccessibleNodes::forUser($admin))->toBeNull();
});

it('is fail-safe for a Responsabile with no assignments', function () {
    $resp = responsabileConNodi($this->ente->id, []);

    expect(AccessibleNodes::forUser($resp))->toBe([]);
});

it('is fail-safe for a Responsabile without a tenant', function () {
    $resp = User::factory()->create(['tenant_id' => null]);
    $resp->assignRole('Responsabile Reparto');

    expect(AccessibleNodes::forUser($resp))->toBe([]);
});
