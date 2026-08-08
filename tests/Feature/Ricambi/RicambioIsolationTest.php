<?php

use App\Models\Ricambio;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\IsolationHarness;

/**
 * Isolamento multi-tenant del catalogo ricambi (ADR-001/018) — test NEGATIVI,
 * area rossa. Il catalogo è dell'Ente: il nome di un pezzo montato da un altro
 * cliente non deve comparire da nessuna parte.
 *
 * Fixture create PRIMA di actingAs: in contesto console l'hook `creating` di
 * BelongsToTenant non ritimbra, quindi la riga "estranea" resta dell'Ente B.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);

    $this->a1 = Ricambio::factory()->forTenant($this->enteA)->create(['nome' => 'Guarnizione']);
    $this->a2 = Ricambio::factory()->forTenant($this->enteA)->create(['nome' => 'Cinghia']);
    $this->b1 = Ricambio::factory()->forTenant($this->enteB)->create(['nome' => 'Pezzo Riservato']);

    $this->adminA = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $this->adminA->assignRole('Admin');
});

it('isolates ricambi across tenants (reusing the IsolationHarness)', function () {
    $this->actingAs($this->adminA);

    IsolationHarness::assertReadIsolation(
        Ricambio::class,
        [$this->a1->id, $this->a2->id],
        (int) $this->b1->id
    );
});

it('stamps the current tenant on create', function () {
    $this->actingAs($this->adminA);

    expect(Ricambio::create(['nome' => 'Filtro HEPA'])->tenant_id)->toBe($this->enteA->id);
});

it('does not let a tenant forge a ricambio for another tenant', function () {
    $this->actingAs($this->adminA);

    $ricambio = Ricambio::create(['tenant_id' => $this->enteB->id, 'nome' => 'Forgiato']);

    expect($ricambio->tenant_id)->toBe($this->enteA->id);
});

it('does not let a tenant move a ricambio to another tenant', function () {
    $this->actingAs($this->adminA);

    $ricambio = Ricambio::findOrFail($this->a1->id);
    $ricambio->tenant_id = $this->enteB->id;
    $ricambio->save();

    expect($ricambio->fresh()->tenant_id)->toBe($this->enteA->id);
});

it('does not link a foreign tenant catalogue entry through collegaOCrea', function () {
    // Sostituisce il test "leak attraverso la relazione" degli altri model: qui
    // la via d'accesso indebita non è una relazione ma il collega-o-crea, che
    // per un utente tenant-bound crea nel PROPRIO catalogo invece di leggere
    // quello altrui — fail-safe.
    $this->actingAs($this->adminA);

    $collegato = Ricambio::collegaOCrea('Pezzo Riservato', $this->enteB->id);

    expect($collegato->id)->not->toBe($this->b1->id)
        ->and($collegato->tenant_id)->toBe($this->enteA->id);
});
