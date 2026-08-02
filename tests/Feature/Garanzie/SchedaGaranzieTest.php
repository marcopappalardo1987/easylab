<?php

use App\Enums\TipoScadenzaGaranzia;
use App\Models\Garanzia;
use App\Models\LetturaContaore;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Tab Garanzie e letture contaore in scheda (S3 punti 7-8).
 * Aree rosse: permessi per ruolo e isolamento sulle scritture.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    $this->conRuolo = function (string $ruolo): User {
        $u = User::factory()->create(['tenant_id' => $this->ente->id]);
        $u->assignRole($ruolo);

        return $u;
    };
});

// --- CRUD felice ---

it('creates a garanzia a data and computes the effective date', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovaGaranzia')
        ->set('garanziaForm.tipo_scadenza', TipoScadenzaGaranzia::Data->value)
        ->set('garanziaForm.data_inizio', '2026-03-01')
        ->set('garanziaForm.durata_mesi', 36)
        ->call('saveGaranzia')
        ->assertHasNoErrors()
        ->assertSet('showGaranziaForm', false);

    $garanzia = $this->strumento->garanzie()->sole();

    expect($garanzia->data_scadenza_effettiva->toDateString())->toBe('2029-03-01')
        ->and($garanzia->soggetto->value)->toBe('macchina')
        ->and($garanzia->tenant_id)->toBe($this->ente->id);
});

it('creates a garanzia a ore using the manually entered prevista', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovaGaranzia')
        ->set('garanziaForm.tipo_scadenza', TipoScadenzaGaranzia::Ore->value)
        ->set('garanziaForm.data_inizio', '2026-01-01')
        ->set('garanziaForm.soglia_ore', 8000)
        ->set('garanziaForm.data_scadenza_prevista', '2027-09-30')
        ->call('saveGaranzia')
        ->assertHasNoErrors();

    $garanzia = $this->strumento->garanzie()->sole();

    expect($garanzia->data_scadenza_effettiva->toDateString())->toBe('2027-09-30')
        ->and($garanzia->soglia_ore)->toBe(8000)
        ->and($garanzia->durata_mesi)->toBeNull();
});

it('requires the fields that match the chosen tipo', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovaGaranzia')
        ->set('garanziaForm.tipo_scadenza', TipoScadenzaGaranzia::Ore->value)
        ->set('garanziaForm.soglia_ore', null)
        ->set('garanziaForm.data_scadenza_prevista', '')
        ->call('saveGaranzia')
        ->assertHasErrors(['garanziaForm.soglia_ore', 'garanziaForm.data_scadenza_prevista']);

    expect($this->strumento->garanzie()->count())->toBe(0);
});

it('edits and deletes a garanzia', function () {
    $garanzia = Garanzia::factory()->forStrumento($this->strumento)->create(['durata_mesi' => 12]);

    scheda($this->admin, $this->strumento)
        ->call('openModificaGaranzia', $garanzia->id)
        ->set('garanziaForm.durata_mesi', 48)
        ->call('saveGaranzia')
        ->assertHasNoErrors();

    expect($garanzia->fresh()->durata_mesi)->toBe(48);

    scheda($this->admin, $this->strumento)
        ->call('openEliminaGaranzia', $garanzia->id)
        ->assertSet('deletingGaranziaId', $garanzia->id)
        ->call('eliminaGaranzia')
        ->assertSet('deletingGaranziaId', null);

    expect(Garanzia::find($garanzia->id))->toBeNull()
        ->and(Garanzia::withoutGlobalScopes()->withTrashed()->find($garanzia->id)->trashed())->toBeTrue();
});

it('registers a lettura contaore attributed to the authenticated user', function () {
    scheda($this->admin, $this->strumento)
        ->call('openLettura')
        ->assertSet('letturaForm.data', today()->toDateString())
        ->set('letturaForm.ore', 4200)
        ->call('registraLettura')
        ->assertHasNoErrors();

    $lettura = $this->strumento->lettureContaore()->sole();

    // registrata_da non è una proprietà del form: arriva da auth().
    expect($lettura->ore)->toBe(4200)
        ->and($lettura->registrata_da)->toBe($this->admin->id)
        ->and($lettura->tenant_id)->toBe($this->ente->id);
});

it('refuses a lettura dated in the future', function () {
    scheda($this->admin, $this->strumento)
        ->call('openLettura')
        ->set('letturaForm.data', today()->addWeek()->toDateString())
        ->set('letturaForm.ore', 100)
        ->call('registraLettura')
        ->assertHasErrors(['letturaForm.data']);
});

// --- Permessi (🔴) ---

it('shows the tab to a Tenant in read-only, with no actions', function () {
    Garanzia::factory()->forStrumento($this->strumento)->create();
    $tenant = ($this->conRuolo)('Tenant');

    scheda($tenant, $this->strumento)
        ->assertSee('Garanzie')
        ->assertSee('Scadenza effettiva')
        ->assertDontSee('+ Nuova garanzia')
        ->assertDontSee('Registra lettura');   // il Tenant non ha letture_contaore.create
});

it('forbids a Tenant from every garanzia and lettura action', function () {
    $garanzia = Garanzia::factory()->forStrumento($this->strumento)->create();
    $tenant = ($this->conRuolo)('Tenant');

    foreach ([
        ['openNuovaGaranzia'],
        ['openModificaGaranzia', $garanzia->id],
        ['saveGaranzia'],
        ['openEliminaGaranzia', $garanzia->id],
        ['eliminaGaranzia'],
        ['openLettura'],
        ['registraLettura'],
    ] as $azione) {
        scheda($tenant, $this->strumento)->call(...$azione)->assertForbidden();
    }

    expect($garanzia->fresh())->not->toBeNull();
});

it('lets a Tecnico register letture but not manage garanzie', function () {
    $tecnico = ($this->conRuolo)('Tecnico');

    scheda($tecnico, $this->strumento)
        ->call('openLettura')
        ->set('letturaForm.ore', 900)
        ->call('registraLettura')
        ->assertHasNoErrors();

    expect($this->strumento->lettureContaore()->count())->toBe(1);

    foreach ([['openNuovaGaranzia'], ['saveGaranzia']] as $azione) {
        scheda($tecnico, $this->strumento)->call(...$azione)->assertForbidden();
    }
});

// --- Isolamento sulle scritture (🔴) ---

it('does not resolve a garanzia of another strumento or tenant', function () {
    $altroStrumento = Strumento::factory()->forNode($this->dept)->create();
    $altrui = Garanzia::factory()->forStrumento($altroStrumento)->create();

    $enteB = UnitaOrganizzativa::factory()->ente()->create();
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();
    $strumentoB = Strumento::factory()->forNode($deptB)->create();
    $garanziaB = Garanzia::factory()->forStrumento($strumentoB)->create();

    foreach ([$altrui->id, $garanziaB->id] as $id) {
        expect(fn () => scheda($this->admin, $this->strumento)->call('openModificaGaranzia', $id))
            ->toThrow(ModelNotFoundException::class);
    }

    expect(Garanzia::withoutGlobalScopes()->find($altrui->id))->not->toBeNull()
        ->and(Garanzia::withoutGlobalScopes()->find($garanziaB->id))->not->toBeNull();
});

it('shows the letture storico newest first', function () {
    LetturaContaore::factory()->forStrumento($this->strumento)->create(['data' => today()->subYear()->toDateString(), 'ore' => 500]);
    LetturaContaore::factory()->forStrumento($this->strumento)->create(['data' => today()->toDateString(), 'ore' => 3100]);

    scheda($this->admin, $this->strumento)
        ->assertSeeInOrder(['3.100 h', '500 h']);
});
