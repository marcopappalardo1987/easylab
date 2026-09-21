<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Feature\Isolamento\Support\Matrice;
use Tests\Feature\Isolamento\Support\MondoDueEnti;
use Tests\Support\IsolationHarness;

/**
 * La batteria di IsolationHarness su OGNI modello con BelongsToTenant, preso
 * dall'inventario generato e non da un elenco scritto a mano: un modello nuovo
 * entra qui da solo, e se il mondo di prova non ne crea righe il test lo dice.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mondo = MondoDueEnti::crea();
});

it('blocks every query vector across tenants on every tenant model', function () {
    $enteA = $this->mondo->riga('A', 'ente')->id;
    $enteB = $this->mondo->riga('B', 'ente')->id;

    $this->actingAs($this->mondo->riga('A', 'admin'));

    foreach (Matrice::modelliTenant() as $modello) {
        $propri = $modello::withoutGlobalScopes()->where('tenant_id', $enteA)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $estranea = $modello::withoutGlobalScopes()->where('tenant_id', $enteB)->value('id');

        expect($propri)->not->toBeEmpty()
            ->and($estranea)->not->toBeNull();

        if ((new $modello)->getUpdatedAtColumn() !== null) {
            IsolationHarness::assertReadIsolation($modello, $propri, (int) $estranea);

            continue;
        }

        // IsolationHarness scrive `updated_at`, che AvvisoScadenza non ha
        // (UPDATED_AT = null): stessa batteria con una colonna che esiste.
        // Richiesta di generalizzare l'harness in board (T2).
        expect($modello::pluck('id')->all())->toEqualCanonicalizing($propri)
            ->and($modello::find($estranea))->toBeNull()
            ->and($modello::where('id', $estranea)->exists())->toBeFalse()
            ->and(fn () => $modello::findOrFail($estranea))->toThrow(ModelNotFoundException::class)
            ->and($modello::where('id', $estranea)->update(['tenant_id' => $enteA]))->toBe(0);
        $modello::where('id', $estranea)->delete();
        expect($modello::withoutGlobalScopes()->find($estranea)?->tenant_id)->toBe($enteB);
    }
});
