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

// --- Soglia di obsolescenza sul nodo Ente (S3 punto 9, ADR-014) ---

it('lets an admin change the obsolescence threshold on the Ente', function () {
    [$ente, $admin] = enteWithAdmin();

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('edit', $ente->id)
        ->assertSet('sogliaObsolescenzaAnni', 10)   // default della colonna
        ->set('sogliaObsolescenzaAnni', 15)
        ->call('save')
        ->assertHasNoErrors();

    expect($ente->fresh()->soglia_obsolescenza_anni)->toBe(15);
});

it('validates the obsolescence threshold', function () {
    [$ente, $admin] = enteWithAdmin();

    foreach ([0, 51] as $valore) {
        Livewire::actingAs($admin)->test(Albero::class)
            ->call('edit', $ente->id)
            ->set('sogliaObsolescenzaAnni', $valore)
            ->call('save')
            ->assertHasErrors('sogliaObsolescenzaAnni');
    }

    expect($ente->fresh()->soglia_obsolescenza_anni)->toBe(10);
});

it('shows the threshold field only when editing the Ente', function () {
    [$ente, $admin] = enteWithAdmin();
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create();

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('edit', $ente->id)
        ->assertSee('Soglia obsolescenza')
        ->call('edit', $dip->id)
        ->assertDontSee('Soglia obsolescenza')
        ->assertSet('sogliaObsolescenzaAnni', null);
});

it('never writes the threshold on a node that is not the Ente', function () {
    // Le proprietà Livewire arrivano dal browser: la guardia deve stare sul
    // tipo riletto dal DB, non sullo stato del componente.
    [$ente, $admin] = enteWithAdmin();
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create();

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('edit', $dip->id)
        ->set('sogliaObsolescenzaAnni', 3)
        ->call('save')
        ->assertHasNoErrors();

    expect($dip->fresh()->soglia_obsolescenza_anni)->toBe(10);   // default, intatto
});

// --- Scopribilità: dove nasce un Ente ---
//
// 🧭 L'albero organizza l'INTERNO di un Ente e non ne crea mai uno: `rules()`
// ammette solo Dipartimento e Sottolaboratorio. Un Ente nasce dal provisioning.
// Al livello radice, però, l'intestazione della lista dice «Enti» — e lì non
// c'è nessun pulsante, perché `addChild()` vuole un padre. Da qui la
// segnalazione «come Developer non posso creare Enti»: la pagina era un vicolo
// cieco muto. Questi tre test tengono in piedi il cartello.
//
// ⚠️ Il Developer è il ruolo che ci finisce dentro: `tenant_id` NULL e nessun
// Tecnico → `TenantScope` mette `1 = 0`, quindi zero radici visibili e
// `mount()` non entra in automatico da nessuna parte.

it('sends a provisioner from the empty root of the tree to the platform', function () {
    $developer = User::factory()->create([
        'tenant_id' => null,
        'two_factor_confirmed_at' => now(),
    ]);
    $developer->assignRole('Developer');

    Livewire::actingAs($developer->fresh())
        ->test(Albero::class)
        ->assertSet('currentId', null)
        ->assertSee('Crea un Ente dalla Piattaforma');
});

it('does not dangle the platform link in front of who cannot provision', function () {
    // Stessa posizione — radice, nessun nodo visibile — ma senza la leva:
    // indicare una pagina che risponderebbe 403 sarebbe la seconda strada
    // senza uscita, non la via d'uscita dalla prima.
    $spettatore = User::factory()->create([
        'tenant_id' => null,
        'two_factor_confirmed_at' => now(),
    ]);
    $spettatore->givePermissionTo('unita_organizzativa.view');

    expect($spettatore->fresh()->can('tenants.provision'))->toBeFalse();

    Livewire::actingAs($spettatore->fresh())
        ->test(Albero::class)
        ->assertSet('currentId', null)
        ->assertDontSee('Crea un Ente dalla Piattaforma');
});

it('drops the pointer once inside an Ente, where it would be noise', function () {
    // Il cartello vive alla radice e basta: dentro un Ente la pagina fa già il
    // suo mestiere e il pulsante «Aggiungi dipartimento» c'è.
    //
    // ⚠️ Conseguenza dichiarata: il Superadmin, che ha un Ente proprio
    // (ADR-018), viene portato dentro da `mount()` e il cartello non lo vede
    // mai. Per lui la Piattaforma è già in barra laterale — è il Developer,
    // senza Ente, quello che restava senza indicazioni.
    [$ente, $admin] = enteWithAdmin('Ente Cartello');
    $admin->givePermissionTo('tenants.provision');

    Livewire::actingAs($admin->fresh())
        ->test(Albero::class)
        ->assertSet('currentId', $ente->id)
        ->assertDontSee('Crea un Ente dalla Piattaforma');
});
