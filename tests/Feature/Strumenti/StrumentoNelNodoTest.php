<?php

use App\Livewire\Anagrafica\Albero;
use App\Models\Fornitore;
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

    // Il fornitore è obbligatorio nel form dal 15 Ago 2026 (ADR-023): i casi che
    // creano una macchina dall'anagrafica devono sceglierne uno. L'obbligo vive
    // nella validazione e non in schema — le righe storiche restano senza.
    $this->fornitore = Fornitore::factory()->forTenant($this->ente)->create();
});

it('lists strumenti of the selected node', function () {
    Strumento::factory()->forNode($this->dept)->create(['nome' => 'Microscopio']);

    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('open', $this->dept->id)
        ->assertSee('Microscopio');
});

it('lets an admin add a strumento to a node with parametri', function () {
    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('open', $this->dept->id)
        ->call('addStrumento')
        ->set('strumentoForm.fornitore_id', $this->fornitore->id)
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
        ->call('open', $this->ente->id)
        ->call('addStrumento')
        ->set('strumentoForm.fornitore_id', $this->fornitore->id)
        ->assertSet('showStrumentoForm', false)
        ->assertSet('notice', "Aggiungi gli strumenti a un dipartimento o sotto-laboratorio, non all'Ente.");
});

it('forbids a Tenant from adding a strumento', function () {
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');

    // Nessun `set` dopo il rifiuto: `addStrumento` risponde 403 e il componente
    // non ha più uno snapshot valido da aggiornare — il caso verifica la
    // porta chiusa, non il form.
    Livewire::actingAs($tenant)->test(Albero::class)
        ->call('open', $this->dept->id)
        ->call('addStrumento')
        ->assertForbidden();
});
