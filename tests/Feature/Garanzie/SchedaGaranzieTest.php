<?php

use App\Models\Garanzia;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Tab Garanzie in scheda (S3 punto 7 — ADR-004/019).
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

it('creates a garanzia and computes the effective date', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovaGaranzia')
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

it('requires inizio and durata', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovaGaranzia')
        ->set('garanziaForm.data_inizio', '')
        ->set('garanziaForm.durata_mesi', null)
        ->call('saveGaranzia')
        ->assertHasErrors(['garanziaForm.data_inizio', 'garanziaForm.durata_mesi']);

    expect($this->strumento->garanzie()->count())->toBe(0);
});

it('rejects a durata below one with a validation error, not an exception', function () {
    // Il model ha la stessa guardia, ma lì è un'eccezione: l'utente deve vedere
    // un errore di campo. Senza il `min:1` nel form arriverebbe una pagina rotta
    // invece di un messaggio — ed è per questo che la regola sta in due posti.
    scheda($this->admin, $this->strumento)
        ->call('openNuovaGaranzia')
        ->set('garanziaForm.durata_mesi', 0)
        ->call('saveGaranzia')
        ->assertHasErrors(['garanziaForm.durata_mesi']);

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

// --- Permessi (🔴) ---

it('shows the tab to a Tenant in read-only, with no actions', function () {
    Garanzia::factory()->forStrumento($this->strumento)->create();
    $tenant = ($this->conRuolo)('Tenant');

    scheda($tenant, $this->strumento)
        ->assertSee('Garanzie')
        ->assertSee('Scadenza effettiva')
        ->assertDontSee('+ Nuova garanzia');
});

it('forbids a Tenant from every garanzia action', function () {
    $garanzia = Garanzia::factory()->forStrumento($this->strumento)->create();
    $tenant = ($this->conRuolo)('Tenant');

    foreach ([
        ['openNuovaGaranzia'],
        ['openModificaGaranzia', $garanzia->id],
        ['saveGaranzia'],
        ['openEliminaGaranzia', $garanzia->id],
        ['eliminaGaranzia'],
    ] as $azione) {
        scheda($tenant, $this->strumento)->call(...$azione)->assertForbidden();
    }

    expect($garanzia->fresh())->not->toBeNull();
});

it('lets a Tecnico read garanzie but never manage them', function () {
    $tecnico = ($this->conRuolo)('Tecnico');
    // S7 (T1a): la scheda rilegge la macchina con gli scope a ogni richiesta, e
    // il Tecnico la vede solo da portafoglio o assegnazione (ADR-007/030) —
    // senza, anche via HTTP prende 404. Col portafoglio il 403 misura il
    // permesso e non lo scope, che è ciò che questo caso vuole.
    $tecnico->portafoglioClienti()->attach($this->ente->id);

    scheda($tecnico, $this->strumento)->assertSee('Scadenza effettiva');

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
