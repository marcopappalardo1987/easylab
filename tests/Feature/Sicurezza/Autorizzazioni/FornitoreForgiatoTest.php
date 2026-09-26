<?php

use App\Livewire\Anagrafica\Albero;
use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Fornitore;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * T1a (S7) — il fornitore di una macchina, scritto da chi non lo vede.
 *
 * La regola su `strumentoForm.fornitore_id` è condizionata a `fornitori.view`
 * (ADR-023): chi non vede i fornitori non può essere bloccato da un campo che
 * non ha. Ma il payload lo scriveva comunque, e senza la regola l'id non era
 * più vincolato a niente: una property forgiata cambiava il fornitore della
 * macchina (o lo toglieva), e un id di un altro Ente arrivava fino
 * all'invariante del model, cioè a un 500. Oggi nessun ruolo di bootstrap ha
 * `strumenti.create|update` senza `fornitori.view`, ma la matrice si modifica a
 * runtime dall'editor ruoli (ADR-016 §7): la combinazione è a un clic.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Laboratorio Rossi']);
    $this->depA = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create(['nome' => 'Microbiologia']);
    $this->fornitoreA = Fornitore::factory()->forTenant($this->enteA)->create();
    $this->strumentoA = Strumento::factory()->forNode($this->depA)->create([
        'nome' => 'Autoclave',
        'fornitore_id' => $this->fornitoreA->id,
    ]);

    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Clinica Aurora']);
    $this->fornitoreB = Fornitore::factory()->forTenant($enteB)->create();

    $this->admin = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

/** La matrice personalizzata dall'editor ruoli: l'Admin non vede più i fornitori. */
function adminSenzaFornitori(): void
{
    Role::findByName('Admin')->revokePermissionTo('fornitori.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

it('keeps the supplier of the machine when a user who cannot see suppliers forges one', function () {
    adminSenzaFornitori();
    $altroDellEnte = Fornitore::factory()->forTenant($this->enteA)->create();

    Livewire::actingAs($this->admin)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumentoA])
        ->call('edit')
        ->set('strumentoForm.nome', 'Autoclave X')
        ->set('strumentoForm.fornitore_id', $altroDellEnte->id)
        ->call('save')
        ->assertHasNoErrors();

    $crudo = Strumento::withoutGlobalScopes()->findOrFail($this->strumentoA->id);
    expect($crudo->nome)->toBe('Autoclave X');
    expect($crudo->fornitore_id)->toBe($this->fornitoreA->id);
});

it('does not attach a forged foreign supplier to a new machine', function () {
    adminSenzaFornitori();

    Livewire::actingAs($this->admin)
        ->test(Albero::class)
        ->call('open', $this->depA->id)
        ->call('addStrumento')
        ->set('strumentoForm.nome', 'Centrifuga')
        ->set('strumentoForm.fornitore_id', $this->fornitoreB->id)
        ->call('saveStrumento')
        ->assertHasNoErrors();

    $nuova = Strumento::withoutGlobalScopes()->where('nome', 'Centrifuga')->firstOrFail();
    expect($nuova->tenant_id)->toBe($this->enteA->id);
    expect($nuova->fornitore_id)->toBeNull();
});

it('still refuses a foreign supplier to a user who can see suppliers', function () {
    // Il ramo con la regola: il vincolo al tenant resta quello di ADR-023.
    Livewire::actingAs($this->admin)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumentoA])
        ->call('edit')
        ->set('strumentoForm.fornitore_id', $this->fornitoreB->id)
        ->call('save')
        ->assertHasErrors(['strumentoForm.fornitore_id']);

    expect(Strumento::withoutGlobalScopes()->findOrFail($this->strumentoA->id)->fornitore_id)
        ->toBe($this->fornitoreA->id);
});

// T1aA-2 / T1aB-6: la regola `exists` guardava il tenant ma non il cestino, e
// `$correnteId` non era usato. Stessa lista di `fornitoriSelezionabili()`.

it('refuses a binned supplier forged onto an existing machine', function () {
    $cestinato = Fornitore::factory()->forTenant($this->enteA)->create();
    $cestinato->delete();

    Livewire::actingAs($this->admin)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumentoA])
        ->call('edit')
        ->set('strumentoForm.fornitore_id', $cestinato->id)
        ->call('save')
        ->assertHasErrors(['strumentoForm.fornitore_id']);

    expect(Strumento::withoutGlobalScopes()->findOrFail($this->strumentoA->id)->fornitore_id)
        ->toBe($this->fornitoreA->id);
});

it('refuses a binned supplier forged onto a new machine', function () {
    $cestinato = Fornitore::factory()->forTenant($this->enteA)->create();
    $cestinato->delete();

    Livewire::actingAs($this->admin)
        ->test(Albero::class)
        ->call('open', $this->depA->id)
        ->call('addStrumento')
        ->set('strumentoForm.nome', 'Centrifuga')
        ->set('strumentoForm.fornitore_id', $cestinato->id)
        ->call('saveStrumento')
        ->assertHasErrors(['strumentoForm.fornitore_id']);

    expect(Strumento::withoutGlobalScopes()->where('nome', 'Centrifuga')->exists())->toBeFalse();
});

it('still accepts the binned supplier the machine already had', function () {
    // È il senso di `$correnteId`: salvare una macchina non obbliga a cambiarle
    // un fornitore cestinato dopo l'associazione.
    // Stato storico: il model oggi rifiuta di cestinare un fornitore associato,
    // quindi lo si scrive a mano come lo troverebbe una riga di prima.
    Fornitore::withoutGlobalScopes()->whereKey($this->fornitoreA->id)->update(['deleted_at' => now()]);

    Livewire::actingAs($this->admin)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumentoA])
        ->call('edit')
        ->set('strumentoForm.nome', 'Autoclave Y')
        ->call('save')
        ->assertHasNoErrors();

    expect(Strumento::withoutGlobalScopes()->findOrFail($this->strumentoA->id)->nome)->toBe('Autoclave Y');
});
