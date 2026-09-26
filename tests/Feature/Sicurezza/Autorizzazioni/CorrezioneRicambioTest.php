<?php

use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * T1a (S7) — la correzione di una riga ricambio che crea una voce di catalogo.
 *
 * `salvaRicambio()` passa il nome a `Ricambio::collegaOCrea()`: un nome nuovo
 * è una voce nuova del catalogo. Il form intervento chiede `ricambi.create`
 * per lo stesso gesto (ADR-022); la correzione no, quindi chi aveva solo
 * `ricambio_utilizzo.update` allargava il catalogo. Nessun ruolo di bootstrap
 * ha l'uno senza l'altro, ma la matrice si modifica a runtime (ADR-016 §7).
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Laboratorio Rossi']);
    $dep = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create();
    $this->strumento = Strumento::factory()->forNode($dep)->create(['nome' => 'Autoclave']);
    $this->ricambio = Ricambio::factory()->forTenant($ente)->create(['nome' => 'Guarnizione O-Ring']);
    $this->altro = Ricambio::factory()->forTenant($ente)->create(['nome' => 'Filtro HEPA']);

    $intervento = Intervento::factory()->forStrumento($this->strumento)->pianificato()->create();
    $this->utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->strumento)
        ->forRicambio($this->ricambio)
        ->forIntervento($intervento)
        ->create();
    Garanzia::factory()->forRicambio($this->utilizzo)
        ->scadenzaDichiarata(today()->addYear()->toDateString())->create();

    $this->admin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    Role::findByName('Admin')->revokePermissionTo('ricambi.create');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('forbids renaming a mounted part into a new catalogue entry without ricambi.create', function () {
    $prima = Ricambio::withoutGlobalScopes()->count();

    Livewire::actingAs($this->admin)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openCorreggiRicambio', $this->utilizzo->id)
        ->set('ricambioForm.nome', 'Pezzo inventato')
        ->call('salvaRicambio')
        ->assertForbidden();

    expect(Ricambio::withoutGlobalScopes()->count())->toBe($prima);
    expect($this->utilizzo->fresh()->ricambio_id)->toBe($this->ricambio->id);
});

it('still lets the part be pointed to an existing catalogue entry', function () {
    Livewire::actingAs($this->admin)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openCorreggiRicambio', $this->utilizzo->id)
        ->set('ricambioForm.nome', 'filtro hepa')
        ->call('salvaRicambio')
        ->assertHasNoErrors()
        ->assertOk();

    expect($this->utilizzo->fresh()->ricambio_id)->toBe($this->altro->id);
});
