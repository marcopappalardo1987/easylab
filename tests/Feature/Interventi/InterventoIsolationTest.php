<?php

use App\Enums\TipoIntervento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\IsolationHarness;

/**
 * Isolamento multi-tenant degli interventi (ADR-001/018) — test NEGATIVI,
 * area rossa della Policy di Code Review.
 *
 * Le fixture dei due Enti si creano PRIMA di actingAs: in contesto console
 * l'hook `creating` di BelongsToTenant non ritimbra il tenant, quindi la riga
 * "estranea" resta davvero dell'Ente B.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $deptA = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create();
    $this->strumentoA = Strumento::factory()->forNode($deptA)->create();

    $this->enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create();
    $this->strumentoB = Strumento::factory()->forNode($deptB)->create();

    $this->a1 = Intervento::factory()->forStrumento($this->strumentoA)->create();
    $this->a2 = Intervento::factory()->forStrumento($this->strumentoA)->scaduto()->create();
    $this->b1 = Intervento::factory()->forStrumento($this->strumentoB)->create();

    $this->adminA = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $this->adminA->assignRole('Admin');
});

it('isolates interventi across tenants (reusing the IsolationHarness)', function () {
    $this->actingAs($this->adminA);

    IsolationHarness::assertReadIsolation(
        Intervento::class,
        [$this->a1->id, $this->a2->id],
        (int) $this->b1->id
    );
});

it('stamps the current tenant on create', function () {
    $this->actingAs($this->adminA);

    $intervento = Intervento::create([
        'strumento_id' => $this->strumentoA->id,
        'descrizione' => 'Taratura annuale',
        'tipo' => TipoIntervento::Taratura,
        'data_scadenza' => '2026-12-01',
    ]);

    expect($intervento->tenant_id)->toBe($this->enteA->id);
});

it('does not let a tenant forge an intervento for another tenant', function () {
    $this->actingAs($this->adminA);

    $intervento = Intervento::create([
        'tenant_id' => $this->enteB->id, // tentativo di regalare il dato all'Ente B
        'strumento_id' => $this->strumentoA->id,
        'descrizione' => 'Forgiato',
        'tipo' => TipoIntervento::Altro,
        'data_scadenza' => '2026-12-01',
    ]);

    expect($intervento->tenant_id)->toBe($this->enteA->id);
});

it('does not let a tenant move an intervento to another tenant', function () {
    $this->actingAs($this->adminA);

    $intervento = Intervento::findOrFail($this->a1->id);
    $intervento->tenant_id = $this->enteB->id;
    $intervento->save();

    expect($intervento->fresh()->tenant_id)->toBe($this->enteA->id);
});

it('does not leak interventi of another tenant through the strumento relation', function () {
    $this->actingAs($this->adminA);

    // Lo strumento di B è raggiungibile solo forzando lo scope: i suoi interventi
    // restano comunque invisibili, perché la relazione riapplica il TenantScope.
    $strumentoB = Strumento::withoutGlobalScopes()->findOrFail($this->strumentoB->id);

    expect($strumentoB->interventi)->toBeEmpty();
});
