<?php

use App\Livewire\Anagrafica\Albero;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

it('lists strumenti of the selected node', function () {
    Strumento::factory()->forNode($this->dept)->create(['nome' => 'Microscopio']);

    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('select', $this->dept->id)
        ->assertSee('Microscopio');
});

it('lets an admin add a strumento to a node with parametri', function () {
    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('select', $this->dept->id)
        ->call('addStrumento')
        ->assertSet('showStrumentoForm', true)
        ->set('strumentoForm.nome', 'Bilancia')
        ->call('addParametro')
        ->set('parametri.0.chiave', 'Portata')
        ->set('parametri.0.valore', '5kg')
        ->call('saveStrumento')
        ->assertSet('showStrumentoForm', false);

    $s = Strumento::withoutGlobalScopes()->where('nome', 'Bilancia')->first();
    expect($s->tenant_id)->toBe($this->ente->id);
    expect($s->unita_organizzativa_id)->toBe($this->dept->id);
    expect($s->parametri_tecnici)->toBe(['Portata' => '5kg']);
});

it('does not allow adding a strumento on the ente node', function () {
    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('select', $this->ente->id)
        ->call('addStrumento')
        ->assertSet('showStrumentoForm', false)
        ->assertSet('notice', "Aggiungi gli strumenti a un dipartimento o sotto-laboratorio, non all'Ente.");
});

it('forbids a Tenant from adding a strumento', function () {
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');

    Livewire::actingAs($tenant)->test(Albero::class)
        ->call('select', $this->dept->id)
        ->call('addStrumento')
        ->assertForbidden();
});
