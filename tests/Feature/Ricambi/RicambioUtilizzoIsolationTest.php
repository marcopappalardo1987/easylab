<?php

use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\IsolationHarness;

/**
 * Isolamento multi-tenant delle righe di montaggio (ADR-001/018) — test
 * NEGATIVI. Sono il ponte del doppio salto di ADR-020: se perdessero
 * l'isolamento, la garanzia di un pezzo altrui accenderebbe il semaforo qui.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $deptA = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create();
    $this->strumentoA = Strumento::factory()->forNode($deptA)->create();
    $ricambioA = Ricambio::factory()->forTenant($this->enteA)->create();

    $this->enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create();
    $this->strumentoB = Strumento::factory()->forNode($deptB)->create();
    $ricambioB = Ricambio::factory()->forTenant($this->enteB)->create();

    $this->a1 = RicambioUtilizzo::factory()->forStrumento($this->strumentoA)->forRicambio($ricambioA)->create();
    $this->a2 = RicambioUtilizzo::factory()->forStrumento($this->strumentoA)->forRicambio($ricambioA)->create();
    $this->b1 = RicambioUtilizzo::factory()->forStrumento($this->strumentoB)->forRicambio($ricambioB)->create();

    $this->adminA = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $this->adminA->assignRole('Admin');
});

it('isolates ricambio_utilizzo across tenants (reusing the IsolationHarness)', function () {
    $this->actingAs($this->adminA);

    IsolationHarness::assertReadIsolation(
        RicambioUtilizzo::class,
        [$this->a1->id, $this->a2->id],
        (int) $this->b1->id
    );
});

it('stamps the current tenant on create', function () {
    $this->actingAs($this->adminA);

    $utilizzo = RicambioUtilizzo::create([
        'strumento_id' => $this->strumentoA->id,
        'ricambio_id' => Ricambio::where('tenant_id', $this->enteA->id)->value('id'),
        'data' => today()->toDateString(),
    ]);

    expect($utilizzo->tenant_id)->toBe($this->enteA->id);
});

it('does not let a tenant forge a row for another tenant', function () {
    $this->actingAs($this->adminA);

    // Il tenant_id forgiato viene riscritto da BelongsToTenant in `creating`;
    // la guardia, che gira DOPO, vede il valore vero e la riga resta di A.
    $utilizzo = RicambioUtilizzo::create([
        'tenant_id' => $this->enteB->id,
        'strumento_id' => $this->strumentoA->id,
        'ricambio_id' => Ricambio::where('tenant_id', $this->enteA->id)->value('id'),
        'data' => today()->toDateString(),
    ]);

    expect($utilizzo->tenant_id)->toBe($this->enteA->id);
});

it('does not let a tenant move a row to another tenant', function () {
    $this->actingAs($this->adminA);

    $utilizzo = RicambioUtilizzo::findOrFail($this->a1->id);
    $utilizzo->tenant_id = $this->enteB->id;
    $utilizzo->save();

    expect($utilizzo->fresh()->tenant_id)->toBe($this->enteA->id);
});

it('does not leak rows of another tenant through the strumento relation', function () {
    $this->actingAs($this->adminA);

    $strumentoB = Strumento::withoutGlobalScopes()->findOrFail($this->strumentoB->id);

    expect($strumentoB->ricambiUtilizzati)->toBeEmpty();
});
