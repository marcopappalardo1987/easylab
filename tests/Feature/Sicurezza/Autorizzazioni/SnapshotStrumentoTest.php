<?php

use App\Enums\TipoIntervento;
use App\Livewire\Strumenti\SchedaStrumento;
use App\Livewire\Strumenti\StampaQr;
use App\Models\Fornitore;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/*
 * T1a (S7) — la macchina che vive nello snapshot Livewire.
 *
 * `public Strumento $strumento` torna dal browser **reidratata** da
 * `ModelSynth::hydrate()`, cioè con `newQueryForRestoration()` =
 * `newQueryWithoutScopes()`: TenantScope, DepartmentScope e soft delete non si
 * applicano. Il legame macchina↔contesto era verificato dal binding di rotta e
 * mai più. Una scheda rimasta aperta mentre il contesto cambia (uscita da
 * un'impersonazione, cambio di sede, tecnico esterno) continuava a scrivere
 * sulla macchina di un altro Ente. Stessa classe di difetto già chiusa su
 * `MarchioEnte` (ADR-018): qui si ripete con due Admin di due Enti.
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

    $this->enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Clinica Aurora']);
    $this->depB = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create(['nome' => 'Ematologia']);
    $this->fornitoreB = Fornitore::factory()->forTenant($this->enteB)->create();

    $this->adminA = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $this->adminA->assignRole('Admin');
    $this->adminB = User::factory()->create(['tenant_id' => $this->enteB->id, 'two_factor_confirmed_at' => now()]);
    $this->adminB->assignRole('Admin');

    // Il contesto cambia sotto una scheda aperta: da qui in poi agisce B.
    $this->passaAB = function (): void {
        $this->actingAs($this->adminB);
        Livewire::actingAs($this->adminB);
    };
});

/** La macchina di A riletta fuori da ogni scope: ciò che è davvero a database. */
function strumentoACrudo(int $id): Strumento
{
    return Strumento::withoutGlobalScopes()->findOrFail($id);
}

it('refuses to save the machine of a stale snapshot', function () {
    $scheda = Livewire::actingAs($this->adminA)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumentoA])
        ->call('edit')
        ->set('strumentoForm.nome', 'Riscritta da B');

    ($this->passaAB)();

    $scheda->call('save')->assertNotFound();

    expect(strumentoACrudo($this->strumentoA->id)->nome)->toBe('Autoclave');
});

it('refuses to delete the machine of a stale snapshot', function () {
    $scheda = Livewire::actingAs($this->adminA)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumentoA]);

    ($this->passaAB)();

    $scheda->call('delete')->assertNotFound();

    expect(strumentoACrudo($this->strumentoA->id)->trashed())->toBeFalse();
});

it('refuses to move the machine of a stale snapshot into a node of another tenant', function () {
    $scheda = Livewire::actingAs($this->adminA)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumentoA])
        ->call('openMove')
        ->set('destinazioneId', $this->depB->id);

    ($this->passaAB)();

    // Il nodo di destinazione è di B, quindi visibile a B: senza la guardia la
    // macchina di A finiva appesa all'albero di B con `tenant_id` di A.
    $scheda->call('move')->assertNotFound();

    expect(strumentoACrudo($this->strumentoA->id)->unita_organizzativa_id)->toBe($this->depA->id);
});

it('refuses to create an intervento on the machine of a stale snapshot', function () {
    $scheda = Livewire::actingAs($this->adminA)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumentoA])
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Intervento forgiato')
        ->set('interventoForm.tipo', TipoIntervento::cases()[0]->value)
        ->set('interventoForm.data_scadenza', now()->addMonth()->toDateString());

    ($this->passaAB)();

    $scheda->call('saveIntervento')->assertNotFound();

    expect(Intervento::withoutGlobalScopes()->where('strumento_id', $this->strumentoA->id)->exists())->toBeFalse();
});

it('does not render the machine of a stale snapshot to the new context', function () {
    $scheda = Livewire::actingAs($this->adminA)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumentoA]);

    ($this->passaAB)();

    // Un'azione innocua basta a far rigirare `render()`: niente dati di A a B.
    $scheda->call('closeForm')->assertNotFound();
});

it('refuses to regenerate the QR token of a stale snapshot', function () {
    $prima = $this->strumentoA->qr_token;

    $foglio = Livewire::actingAs($this->adminA)
        ->test(StampaQr::class, ['strumento' => $this->strumentoA])
        ->call('openRigenera');

    ($this->passaAB)();

    // Rigenerare invalida le etichette già incollate sulla macchina di A.
    $foglio->call('rigenera')->assertNotFound();

    expect(strumentoACrudo($this->strumentoA->id)->qr_token)->toBe($prima);
});

it('refuses to act on a machine binned after the page was opened', function () {
    $scheda = Livewire::actingAs($this->adminA)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumentoA])
        ->call('edit')
        ->set('strumentoForm.nome', 'Resuscitata');

    Strumento::withoutGlobalScopes()->whereKey($this->strumentoA->id)->first()->delete();

    $scheda->call('save')->assertNotFound();

    expect(strumentoACrudo($this->strumentoA->id)->nome)->toBe('Autoclave');
});

it('keeps working for the user whose context did not change', function () {
    // Controllo positivo: la guardia non deve rompere il giro normale.
    Livewire::actingAs($this->adminA)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumentoA])
        ->call('edit')
        ->set('strumentoForm.nome', 'Autoclave X')
        ->call('save')
        ->assertHasNoErrors();

    expect(strumentoACrudo($this->strumentoA->id)->nome)->toBe('Autoclave X');

    $prima = $this->strumentoA->fresh()->qr_token;
    Livewire::actingAs($this->adminA)
        ->test(StampaQr::class, ['strumento' => $this->strumentoA])
        ->call('rigenera')
        ->assertOk();

    expect(strumentoACrudo($this->strumentoA->id)->qr_token)->not->toBe($prima);
});

it('answers 404, not 403, to a Tecnico for a machine outside the portfolio', function () {
    // Livewire::test monta senza passare dal binding di rotta; dalla prima
    // richiesta successiva la rilettura scopata dà lo stesso 404 della rotta.
    $tecnico = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $tecnico->assignRole('Tecnico');

    $this->actingAs($tecnico)->get(route('strumenti.show', $this->strumentoA))->assertNotFound();

    Livewire::actingAs($tecnico)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumentoA])
        ->call('openForza')
        ->assertNotFound();
});
