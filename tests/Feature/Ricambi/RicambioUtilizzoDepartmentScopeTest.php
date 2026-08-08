<?php

use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Livello 2 applicato INDIRETTAMENTE via strumento (ADR-006) sulle righe di
 * montaggio — test NEGATIVI. Verifica il cablaggio di
 * `BelongsToOrgNodeThroughStrumento`, che qui non richiede override di
 * `strumentoColumn()` perché la colonna si chiama già `strumento_id`.
 *
 * Albero: Ente A → deptA1 → subA1a ; deptA2 (fratello).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->deptA1 = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create();
    $this->subA1a = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->deptA1)->create();
    $this->deptA2 = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create();

    $ricambio = Ricambio::factory()->forTenant($this->enteA)->create();

    $utilizzoSu = fn (UnitaOrganizzativa $nodo) => RicambioUtilizzo::factory()
        ->forStrumento(Strumento::factory()->forNode($nodo)->create())
        ->forRicambio($ricambio)
        ->create();

    $this->suA1 = $utilizzoSu($this->deptA1);
    $this->suA1a = $utilizzoSu($this->subA1a);
    $this->suA2 = $utilizzoSu($this->deptA2);

    $this->responsabile = function (array $nodi): User {
        $resp = User::factory()->create(['tenant_id' => $this->enteA->id]);
        $resp->assignRole('Responsabile Reparto');
        $resp->unitaResponsabili()->attach(collect($nodi)->pluck('id')->all());

        return $resp;
    };
});

it('restricts the Responsabile to the rows of their own sub-tree', function () {
    $this->actingAs(($this->responsabile)([$this->deptA1]));

    // Il sotto-albero include il sotto-laboratorio, non il dipartimento fratello.
    expect(RicambioUtilizzo::pluck('id')->all())
        ->toEqualCanonicalizing([$this->suA1->id, $this->suA1a->id]);
});

it('hides a sibling department row from the Responsabile, on read and on write', function () {
    $this->actingAs(($this->responsabile)([$this->deptA1]));

    expect(RicambioUtilizzo::find($this->suA2->id))->toBeNull()
        ->and(fn () => RicambioUtilizzo::findOrFail($this->suA2->id))
        ->toThrow(ModelNotFoundException::class)
        ->and(RicambioUtilizzo::where('id', $this->suA2->id)->update(['quantita' => 99]))->toBe(0);
});

it('is fail-safe for a Responsabile with no assignment', function () {
    $this->actingAs(($this->responsabile)([]));

    expect(RicambioUtilizzo::pluck('id')->all())->toBeEmpty();
});

it('does not restrict an Admin, who has no sub-tree', function () {
    $admin = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $admin->assignRole('Admin');
    $this->actingAs($admin);

    expect(RicambioUtilizzo::pluck('id')->all())
        ->toEqualCanonicalizing([$this->suA1->id, $this->suA1a->id, $this->suA2->id]);
});
