<?php

use App\Enums\TipoUnitaOrganizzativa;
use App\Livewire\Anagrafica\Albero;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function enteWithAdmin(string $nome = 'Ente A'): array
{
    $ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => $nome]);
    $admin = User::factory()->create([
        'tenant_id' => $ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('Admin');

    return [$ente, $admin];
}

// --- Accesso alla rotta ---

it('redirects guests to login', function () {
    $this->get(route('anagrafica.index'))->assertRedirect(route('login'));
});

it('forbids users without the view permission', function () {
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    // nessun ruolo → nessun permesso

    $this->actingAs($user)->get(route('anagrafica.index'))->assertForbidden();
});

it('renders the tree for an admin', function () {
    [$ente, $admin] = enteWithAdmin();

    $this->actingAs($admin)->get(route('anagrafica.index'))
        ->assertOk()
        ->assertSee('Ente A');
});

// --- CRUD ---

it('lets an admin create a dipartimento under the ente', function () {
    [$ente, $admin] = enteWithAdmin();

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('addChild', $ente->id)
        ->assertSet('showForm', true)
        ->assertSet('tipo', TipoUnitaOrganizzativa::Dipartimento->value)
        ->set('nome', 'Diagnostica')
        ->call('save')
        ->assertSet('showForm', false);

    $dip = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Diagnostica')->first();
    expect($dip->parent_id)->toBe($ente->id);
    expect($dip->tipo)->toBe(TipoUnitaOrganizzativa::Dipartimento);
    expect($dip->tenant_id)->toBe($ente->id);
});

it('derives sottolaboratorio when creating under a dipartimento', function () {
    [$ente, $admin] = enteWithAdmin();
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => 'Dip']);

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('addChild', $dip->id)
        ->assertSet('tipo', TipoUnitaOrganizzativa::Sottolaboratorio->value)
        ->set('nome', 'Lab 1')
        ->call('save');

    $lab = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Lab 1')->first();
    expect($lab->tipo)->toBe(TipoUnitaOrganizzativa::Sottolaboratorio);
    expect($lab->parent_id)->toBe($dip->id);
});

it('validates the node name', function () {
    [$ente, $admin] = enteWithAdmin();

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('addChild', $ente->id)
        ->set('nome', '')
        ->call('save')
        ->assertHasErrors(['nome' => 'required']);
});

it('lets an admin rename a node', function () {
    [$ente, $admin] = enteWithAdmin();
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => 'Vecchio']);

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('edit', $dip->id)
        ->set('nome', 'Nuovo')
        ->call('save');

    expect($dip->fresh()->nome)->toBe('Nuovo');
});

it('lets an admin rename the ente node', function () {
    [$ente, $admin] = enteWithAdmin('Ente Vecchio');

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('edit', $ente->id)
        ->set('nome', 'Ente Nuovo')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    expect($ente->fresh()->nome)->toBe('Ente Nuovo');
});

it('soft-deletes a leaf node', function () {
    [$ente, $admin] = enteWithAdmin();
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => 'Foglia']);

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('confirmDelete', $dip->id)
        ->assertSet('deletingId', $dip->id)
        ->call('delete')
        ->assertSet('deletingId', null);

    $row = UnitaOrganizzativa::withoutGlobalScopes()->find($dip->id);
    expect($row)->not->toBeNull();
    expect($row->trashed())->toBeTrue();
});

it('blocks deleting a node that has children', function () {
    [$ente, $admin] = enteWithAdmin();
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => 'Padre']);
    UnitaOrganizzativa::factory()->sottolaboratorio()->under($dip)->create(['nome' => 'Figlio']);

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('confirmDelete', $dip->id)
        ->call('delete')
        ->assertSet('notice', 'Elimina prima le unità interne.');

    expect(UnitaOrganizzativa::withoutGlobalScopes()->find($dip->id))->not->toBeNull();
});

it('blocks deleting the ente node', function () {
    [$ente, $admin] = enteWithAdmin();

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('confirmDelete', $ente->id)
        ->call('delete')
        ->assertSet('notice', 'L\'Ente non può essere eliminato.');

    expect(UnitaOrganizzativa::withoutGlobalScopes()->find($ente->id))->not->toBeNull();
});

// --- Isolamento e permessi ---

it('prevents an admin from editing a node of another tenant', function () {
    [$enteA, $adminA] = enteWithAdmin('Ente A');
    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $nodeB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create(['nome' => 'Dip B']);

    expect(fn () => Livewire::actingAs($adminA)->test(Albero::class)->call('edit', $nodeB->id))
        ->toThrow(ModelNotFoundException::class);
});

it('forbids a view-only Tenant from creating nodes', function () {
    [$ente, $admin] = enteWithAdmin();
    $tenant = User::factory()->create(['tenant_id' => $ente->id]);
    $tenant->assignRole('Tenant');

    Livewire::actingAs($tenant)->test(Albero::class)
        ->call('addChild', $ente->id)
        ->assertForbidden();
});

it('shows a Responsabile only its assigned subtree', function () {
    [$ente, $admin] = enteWithAdmin();
    $dip1 = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => 'Reparto Mio']);
    UnitaOrganizzativa::factory()->sottolaboratorio()->under($dip1)->create(['nome' => 'Lab Mio']);
    UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => 'Reparto Altrui']);

    $resp = User::factory()->create(['tenant_id' => $ente->id]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($dip1->id);

    Livewire::actingAs($resp)->test(Albero::class)
        ->assertSee('Reparto Mio')
        ->assertSee('Lab Mio')
        ->assertDontSee('Reparto Altrui');
});
