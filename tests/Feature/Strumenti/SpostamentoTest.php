<?php

use App\Enums\TipoSpostamento;
use App\Livewire\Anagrafica\Albero;
use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept1 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Reparto 1']);
    $this->dept2 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Reparto 2']);
    $this->strumento = Strumento::factory()->forNode($this->dept1)->create(['nome' => 'Autoclave']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

// --- Provenienza esterna (ingresso) alla creazione ---

it('records an ingresso movement when a strumento is created with external provenance', function () {
    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('open', $this->dept1->id)
        ->call('addStrumento')
        ->set('strumentoForm.nome', 'Microscopio')
        ->set('provenienza', 'Ospedale San Paolo')
        ->call('saveStrumento');

    $nuovo = Strumento::withoutGlobalScopes()->where('nome', 'Microscopio')->first();
    $sp = SpostamentoStrumento::withoutGlobalScopes()->where('strumento_id', $nuovo->id)->first();

    expect($sp)->not->toBeNull();
    expect($sp->tipo_spostamento)->toBe(TipoSpostamento::Ingresso);
    expect($sp->da_esterno)->toBe('Ospedale San Paolo');
    expect($sp->da_nodo_id)->toBeNull();
    expect($sp->a_nodo_id)->toBe($this->dept1->id);
});

it('does not record a movement when no provenance is given', function () {
    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('open', $this->dept1->id)
        ->call('addStrumento')
        ->set('strumentoForm.nome', 'Bilancia')
        ->call('saveStrumento');

    $nuovo = Strumento::withoutGlobalScopes()->where('nome', 'Bilancia')->first();
    expect(SpostamentoStrumento::withoutGlobalScopes()->where('strumento_id', $nuovo->id)->exists())->toBeFalse();
});

// --- Spostamento interno (nodo → nodo) ---

it('moves a strumento between nodes and logs it', function () {
    Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openMove')
        ->set('destinazioneId', $this->dept2->id)
        ->set('notaSpostamento', 'Riorganizzazione')
        ->call('move')
        ->assertHasNoErrors();

    expect($this->strumento->fresh()->unita_organizzativa_id)->toBe($this->dept2->id);

    $sp = SpostamentoStrumento::withoutGlobalScopes()->where('strumento_id', $this->strumento->id)->first();
    expect($sp->tipo_spostamento)->toBe(TipoSpostamento::Interno);
    expect($sp->da_nodo_id)->toBe($this->dept1->id);
    expect($sp->a_nodo_id)->toBe($this->dept2->id);
    expect($sp->eseguito_da)->toBe($this->admin->id);
});

it('rejects moving to the same node', function () {
    Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openMove')
        ->set('destinazioneId', $this->dept1->id)
        ->call('move')
        ->assertHasErrors('destinazioneId');

    expect($this->strumento->fresh()->unita_organizzativa_id)->toBe($this->dept1->id);
});

it('rejects moving onto the ente node', function () {
    Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openMove')
        ->set('destinazioneId', $this->ente->id)
        ->call('move')
        ->assertHasErrors('destinazioneId');
});

it('shows the movement history on the scheda', function () {
    SpostamentoStrumento::factory()->forStrumento($this->strumento)->create([
        'da_nodo_id' => $this->dept1->id,
        'a_nodo_id' => $this->dept2->id,
        'nota' => 'Trasloco reparto',
    ]);

    Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->assertSee('Spostamenti')
        ->assertSee('Trasloco reparto');
});

// --- Isolamento e permessi ---

it('cannot move to a node of another tenant', function () {
    $enteB = UnitaOrganizzativa::factory()->ente()->create();
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();

    expect(fn () => Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openMove')
        ->set('destinazioneId', $deptB->id)
        ->call('move'))
        ->toThrow(ModelNotFoundException::class);
});

it('does not let an admin see movements of another tenant', function () {
    $enteB = UnitaOrganizzativa::factory()->ente()->create();
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();
    $strB = Strumento::factory()->forNode($deptB)->create();
    SpostamentoStrumento::factory()->forStrumento($strB)->create();

    $this->actingAs($this->admin);
    expect(SpostamentoStrumento::count())->toBe(0);
});

it('lets a Responsabile move within its subtree but not outside', function () {
    $resp = User::factory()->create(['tenant_id' => $this->ente->id]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dept1->id);
    $sublab = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->dept1)->create(['nome' => 'Lab 1']);

    Livewire::actingAs($resp)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openMove')
        ->set('destinazioneId', $sublab->id)
        ->call('move')
        ->assertHasNoErrors();

    expect($this->strumento->fresh()->unita_organizzativa_id)->toBe($sublab->id);

    // dept2 è fuori dal sotto-albero del Responsabile → non risolvibile.
    expect(fn () => Livewire::actingAs($resp)->test(SchedaStrumento::class, ['strumento' => $this->strumento->fresh()])
        ->call('openMove')
        ->set('destinazioneId', $this->dept2->id)
        ->call('move'))
        ->toThrow(ModelNotFoundException::class);
});

it('forbids a Tenant from moving', function () {
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');

    Livewire::actingAs($tenant)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openMove')
        ->assertForbidden();
});

// --- Append-only ---

it('is append-only: update and delete throw', function () {
    $sp = SpostamentoStrumento::factory()->forStrumento($this->strumento)->create();

    expect(fn () => $sp->update(['nota' => 'x']))->toThrow(RuntimeException::class);
    expect(fn () => $sp->delete())->toThrow(RuntimeException::class);
});
