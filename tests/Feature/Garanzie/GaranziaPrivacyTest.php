<?php

use App\Models\Garanzia;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

/**
 * Privacy delle garanzie sui ricambi (ADR-004, Definition of Done S3: «il
 * tenant non vede le garanzie ricambi»). Area rossa: test NEGATIVI.
 *
 * Le fixture si creano in contesto console, dove i global scope non filtrano.
 * Dall'8 Ago 2026 (S4 blocco 1) la riga `ricambio` poggia su un
 * `ricambio_utilizzo` VERO: la FK esiste, e l'intero libero che stava qui
 * prima ora violerebbe il vincolo su entrambi i driver.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->strumento)
        ->forRicambio(Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Guarnizione O-Ring']))
        ->create();

    $this->garanziaMacchina = Garanzia::factory()->forStrumento($this->strumento)->create();
    $this->garanziaRicambio = Garanzia::factory()->forRicambio($this->utilizzo)->create();

    $this->utente = function (string $ruolo): User {
        $u = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        return $u;
    };
});

it('hides ricambio garanzie from the Tenant', function () {
    $this->actingAs(($this->utente)('Tenant'));

    expect(Garanzia::pluck('id')->all())->toBe([$this->garanziaMacchina->id])
        ->and(Garanzia::find($this->garanziaRicambio->id))->toBeNull()
        ->and(Garanzia::where('id', $this->garanziaRicambio->id)->exists())->toBeFalse();
});

// Invertito l'8 Ago 2026 (ADR-027): diceva `hides ... from the Tecnico`. Il
// divieto non veniva da ADR-004 — che nomina il solo Tenant — ma da una citazione
// allargata oltre la fonte, poi difesa da questo test. Il Tecnico monta il pezzo,
// quindi è chi conosce la garanzia; il controllo su di lui è la traccia (canale
// `audit`) e il livello 2 di ADR-007, non la cecità.
it('shows ricambio garanzie to the Tecnico, who mounts the part', function () {
    $tecnico = ($this->utente)('Tecnico');
    $this->actingAs($tecnico);

    expect($tecnico->can('garanzie.ricambio.view'))->toBeTrue()
        ->and(Garanzia::pluck('id')->all())->toContain($this->garanziaRicambio->id);
});

it('shows ricambio garanzie to an Admin, who holds the permission', function () {
    $admin = ($this->utente)('Admin');
    $this->actingAs($admin);

    expect($admin->can('garanzie.ricambio.view'))->toBeTrue()
        ->and(Garanzia::pluck('id')->all())->toContain($this->garanziaRicambio->id);
});

it('still hides ricambio garanzie from a Responsabile, by scope and not by permission (debito S4)', function () {
    // Il Responsabile HA `garanzie.ricambio.view`, ma il livello 2 del global
    // scope filtra su `strumento_id`, che sulle righe ricambio è NULL: restano
    // fuori dal suo sotto-albero. È fail-closed e per ora innocuo (in S3 non
    // esistono garanzie ricambio reali); in S4, con `ricambio_utilizzo`,
    // servirà il doppio salto garanzie → ricambio_utilizzo → strumenti.
    $resp = ($this->utente)('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dept->id);
    $this->actingAs($resp);

    expect($resp->can('garanzie.ricambio.view'))->toBeTrue()
        ->and(Garanzia::pluck('id')->all())->toBe([$this->garanziaMacchina->id]);
});

it('is fail-closed for an ad-hoc role without the permission', function () {
    // Un ruolo nuovo parte cieco: si vede solo con permesso esplicito.
    Role::create(['name' => 'Ospite'])->givePermissionTo('garanzie.macchina.view');
    $ospite = User::factory()->create(['tenant_id' => $this->ente->id]);
    $ospite->assignRole('Ospite');

    $this->actingAs($ospite);

    expect(Garanzia::pluck('id')->all())->toBe([$this->garanziaMacchina->id]);
});

it('does not filter in a console context, so the seeder sees everything', function () {
    // Nessun actingAs: stessa postura di TenantScope.
    expect(Garanzia::count())->toBe(2);
});

it('does not leak ricambio garanzie through the strumento relation', function () {
    // Difesa in profondità: la relazione riapplica i global scope del model.
    $this->actingAs(($this->utente)('Tenant'));

    expect($this->strumento->garanzie()->pluck('id')->all())->toBe([$this->garanziaMacchina->id]);
});
