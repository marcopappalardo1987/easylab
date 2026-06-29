<?php

use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave', 'modello' => 'AC-200']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

it('shows the scheda to an admin', function () {
    $this->actingAs($this->admin)->get(route('strumenti.show', $this->strumento))
        ->assertOk()
        ->assertSee('Autoclave')
        ->assertSee('AC-200');
});

it('returns 404 for a strumento of another tenant', function () {
    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();
    $strB = Strumento::factory()->forNode($deptB)->create();

    $this->actingAs($this->admin)->get(route('strumenti.show', $strB))->assertNotFound();
});

it('lets an admin edit the strumento including parametri', function () {
    Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('edit')
        ->set('strumentoForm.nome', 'Autoclave X')
        ->call('addParametro')
        ->set('parametri.0.chiave', 'Tensione')
        ->set('parametri.0.valore', '220V')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $this->strumento->fresh();
    expect($fresh->nome)->toBe('Autoclave X');
    expect($fresh->parametri_tecnici)->toBe(['Tensione' => '220V']);
});

it('lets an admin delete the strumento (soft delete + redirect)', function () {
    Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('delete')
        ->assertRedirect(route('anagrafica.index'));

    expect(Strumento::find($this->strumento->id))->toBeNull();
    expect(Strumento::withoutGlobalScopes()->find($this->strumento->id)->trashed())->toBeTrue();
});

it('forbids a Tenant from editing', function () {
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');

    Livewire::actingAs($tenant)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('save')
        ->assertForbidden();
});

it('lets a Responsabile edit a strumento in its subtree but 404 outside', function () {
    $resp = User::factory()->create(['tenant_id' => $this->ente->id]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dept->id);

    Livewire::actingAs($resp)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('edit')
        ->set('strumentoForm.nome', 'Rinominato')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->strumento->fresh()->nome)->toBe('Rinominato');

    // Strumento in un dipartimento fratello (fuori dal sotto-albero del Responsabile).
    $altro = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $strFuori = Strumento::factory()->forNode($altro)->create();

    $this->actingAs($resp)->get(route('strumenti.show', $strFuori))->assertNotFound();
});
