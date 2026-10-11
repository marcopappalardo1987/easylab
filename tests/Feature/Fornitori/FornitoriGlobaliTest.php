<?php

use App\Livewire\Anagrafica\Albero;
use App\Livewire\Fornitori\ElencoFornitori;
use App\Models\Account;
use App\Models\Fornitore;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Fornitori\FornitoriGlobali;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * I fornitori di EasyLab, usabili nel catalogo di qualunque cliente
 * (🔗 ADR-062). Area rossa: è l'unico punto in cui un dato passa dal catalogo
 * di una sede a quello di un'altra, e passa in una direzione sola.
 *
 * Mondo: EasyLab con tre fornitori suoi; **Alfa** e **Beta**, clienti gestiti;
 * **Gamma**, che si gestisce da sé.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $easylab = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);
    $this->enteEasylab = UnitaOrganizzativa::factory()->ente()->perAccount($easylab)->create(['nome' => 'EasyLab']);

    $this->agilent = Fornitore::factory()->forTenant($this->enteEasylab)->create([
        'ragione_sociale' => 'Agilent Italia', 'email' => 'ordini@agilent.test', 'telefono' => '02 1234', 'note' => 'Sconto riservato 30%',
    ]);
    $this->thermo = Fornitore::factory()->forTenant($this->enteEasylab)->create(['ragione_sociale' => 'Thermo Fisher']);
    $this->zeiss = Fornitore::factory()->forTenant($this->enteEasylab)->create(['ragione_sociale' => 'Zeiss']);

    $cliente = function (string $nome, bool $gestito): array {
        $account = Account::factory()->create(['ragione_sociale' => "{$nome} srl"]);
        if ($gestito) {
            $account->affidaManutenzione();
        }
        $sede = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => "Sede {$nome}"]);
        $reparto = UnitaOrganizzativa::factory()->dipartimento()->under($sede)->create(['nome' => "Chimica {$nome}"]);
        $admin = User::factory()->create(['tenant_id' => $sede->id, 'two_factor_confirmed_at' => now()]);
        $admin->assignRole('Admin');

        return compact('account', 'sede', 'reparto', 'admin');
    };

    $this->alfa = $cliente('Alfa', true);
    $this->beta = $cliente('Beta', true);
    $this->gamma = $cliente('Gamma', false);

    $this->superadmin = User::factory()->create(['tenant_id' => $this->enteEasylab->id, 'two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');

    $this->gestore = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $this->gestore->assignRole('Gestore');
    $this->gestore->portafoglioClienti()->attach([$this->alfa['sede']->id, $this->beta['sede']->id]);

    $this->selettore = fn (User $chi, UnitaOrganizzativa $reparto) => Livewire::actingAs($chi)->test(Albero::class)
        ->call('open', $reparto->id)
        ->call('addStrumento')
        ->call('apriFornitori', 'strumento');

    $this->catalogo = fn (UnitaOrganizzativa $sede) => Fornitore::withoutGlobalScopes()->where('tenant_id', $sede->id)->orderBy('id')->get();
});

dataset('chi lavora per EasyLab', [
    'Superadmin' => [fn () => $this->superadmin],
    'Gestore' => [fn () => $this->gestore],
]);

// ─── Ciò che viene offerto ───────────────────────────────────────────────────

it('offers the suppliers of EasyLab on the machine of a customer, apart from the catalogue of the customer', function (User $chi) {
    Fornitore::factory()->forTenant($this->alfa['sede'])->create(['ragione_sociale' => 'Fornitore di Alfa']);

    ($this->selettore)($chi, $this->alfa['reparto'])
        ->assertSee('Fornitore di Alfa')
        ->assertSeeHtml('data-fornitori-globali')
        ->assertSee('Dai fornitori di EasyLab')
        ->assertSeeInOrder(['Fornitore di Alfa', 'Dai fornitori di EasyLab', 'Agilent Italia', 'Thermo Fisher', 'Zeiss'])
        ->assertSeeHtml('wire:click="scegliFornitoreGlobale('.$this->agilent->id.')"')
        // Non sono righe di questo catalogo: non si scelgono come le altre.
        ->assertDontSeeHtml('wire:click="scegliFornitore('.$this->agilent->id.')"');
})->with('chi lavora per EasyLab');

it('filters them with the same text as the rest of the list', function () {
    ($this->selettore)($this->superadmin, $this->alfa['reparto'])
        ->set('cercaFornitore', 'THERMO')
        ->assertSee('Thermo Fisher')
        ->assertDontSee('Agilent Italia')
        ->assertDontSee('Zeiss')
        ->set('cercaFornitore', 'nessuno così')
        ->assertDontSeeHtml('data-fornitori-globali');
});

it('never offers them to the people of the customer', function () {
    // 🔴 Il cliente vede il solo proprio catalogo: né i nomi, né il titolo.
    ($this->selettore)($this->alfa['admin'], $this->alfa['reparto'])
        ->assertDontSeeHtml('data-fornitori-globali')
        ->assertDontSee('Agilent Italia')
        ->assertDontSee('Thermo Fisher');

    $this->actingAs($this->alfa['admin']);

    expect(FornitoriGlobali::perChiGuarda())->toBeFalse()
        ->and(FornitoriGlobali::offribiliA($this->alfa['sede']->id)->count())->toBe(0);
});

it('does not offer them to a Tecnico, who works by portfolio but does not keep catalogues', function () {
    $tecnico = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole('Tecnico');
    $tecnico->portafoglioClienti()->attach($this->alfa['sede']->id);
    $this->actingAs($tecnico);

    expect(FornitoriGlobali::perChiGuarda())->toBeFalse()
        ->and(FornitoriGlobali::offribiliA($this->alfa['sede']->id)->count())->toBe(0);
});

it('offers nothing inside the catalogue of EasyLab itself, where they are the rows already', function () {
    $reparto = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteEasylab)->create(['nome' => 'Officina']);

    ($this->selettore)($this->superadmin, $reparto)
        ->assertSee('Agilent Italia')
        ->assertDontSeeHtml('data-fornitori-globali')
        ->assertSeeHtml('wire:click="scegliFornitore('.$this->agilent->id.')"');
});

it('counts as of EasyLab only the suppliers of a sede of the platform account', function () {
    // Un fornitore di Beta non è «globale» per Alfa, nemmeno per chi li segue entrambi.
    Fornitore::factory()->forTenant($this->beta['sede'])->create(['ragione_sociale' => 'Fornitore di Beta']);
    // E nemmeno uno cestinato di EasyLab.
    $this->zeiss->delete();

    $this->actingAs($this->superadmin);

    expect(FornitoriGlobali::offribiliA($this->alfa['sede']->id)->pluck('ragione_sociale')->all())
        ->toBe(['Agilent Italia', 'Thermo Fisher'])
        ->and(FornitoriGlobali::sediDiPiattaforma())->toBe([$this->enteEasylab->id]);
});

// ─── Sceglierne uno: la copia ────────────────────────────────────────────────

it('copies the supplier into the catalogue of the customer and picks the copy', function (User $chi) {
    $form = ($this->selettore)($chi, $this->alfa['reparto'])
        ->call('scegliFornitoreGlobale', $this->agilent->id)
        ->assertHasNoErrors()
        ->assertSet('selettoreFornitore', null);

    $copia = ($this->catalogo)($this->alfa['sede'])->sole();

    expect($copia->id)->not->toBe($this->agilent->id)
        ->and($copia->tenant_id)->toBe($this->alfa['sede']->id)
        ->and($copia->globale_id)->toBe($this->agilent->id)
        ->and($copia->vieneDaEasyLab())->toBeTrue()
        ->and($copia->ragione_sociale)->toBe('Agilent Italia')
        ->and($copia->email)->toBe('ordini@agilent.test')
        ->and($copia->telefono)->toBe('02 1234')
        // 🔴 Le note sono appunti di EasyLab: non passano al cliente.
        ->and($copia->note)->toBeNull();

    $form->assertSet('strumentoForm.fornitore_id', $copia->id)
        ->set('strumentoForm.nome', 'HPLC')
        ->call('saveStrumento')
        ->assertHasNoErrors();

    expect(Strumento::withoutGlobalScopes()->where('nome', 'HPLC')->sole()->fornitore_id)->toBe($copia->id)
        // Il fornitore di EasyLab resta dov'era, e uguale a prima.
        ->and($this->agilent->fresh()->tenant_id)->toBe($this->enteEasylab->id)
        ->and($this->agilent->fresh()->note)->toBe('Sconto riservato 30%')
        // E negli altri cataloghi non è comparso niente.
        ->and(($this->catalogo)($this->beta['sede']))->toBeEmpty();
})->with('chi lavora per EasyLab');

it('lets the customer see the suppliers EasyLab used there and its own, and none of the others', function () {
    Fornitore::factory()->forTenant($this->alfa['sede'])->create(['ragione_sociale' => 'Fornitore di Alfa']);
    ($this->selettore)($this->superadmin, $this->alfa['reparto'])->call('scegliFornitoreGlobale', $this->agilent->id);

    Livewire::actingAs($this->alfa['admin'])->test(ElencoFornitori::class)
        ->assertSee('Fornitore di Alfa')
        ->assertSee('Agilent Italia')
        ->assertSee('da EasyLab')
        ->assertDontSee('Thermo Fisher')
        ->assertDontSee('Zeiss')
        ->assertDontSee('Sconto riservato');

    // Nel selettore del cliente è una riga come le altre.
    ($this->selettore)($this->alfa['admin'], $this->alfa['reparto'])
        ->assertSee('Agilent Italia')
        ->assertDontSee('Thermo Fisher');

    // L'altro cliente non ne sa niente.
    Livewire::actingAs($this->beta['admin'])->test(ElencoFornitori::class)->assertDontSee('Agilent Italia');
});

it('marks as da EasyLab the copies only', function () {
    $suo = Fornitore::factory()->forTenant($this->alfa['sede'])->create(['ragione_sociale' => 'Fornitore di Alfa']);
    ($this->selettore)($this->superadmin, $this->alfa['reparto'])->call('scegliFornitoreGlobale', $this->agilent->id);
    $copia = ($this->catalogo)($this->alfa['sede'])->firstWhere('globale_id', $this->agilent->id);

    Livewire::actingAs($this->alfa['admin'])->test(ElencoFornitori::class)
        ->assertSeeHtml('data-da-easylab="'.$copia->id.'"')
        ->assertDontSeeHtml('data-da-easylab="'.$suo->id.'"');
});

it('stops offering a supplier once it is in the catalogue, and never copies it twice', function () {
    ($this->selettore)($this->superadmin, $this->alfa['reparto'])->call('scegliFornitoreGlobale', $this->agilent->id);
    $copia = ($this->catalogo)($this->alfa['sede'])->sole();

    ($this->selettore)($this->superadmin, $this->alfa['reparto'])
        ->assertSeeHtml('wire:click="scegliFornitore('.$copia->id.')"')
        ->assertDontSeeHtml('wire:click="scegliFornitoreGlobale('.$this->agilent->id.')"')
        ->assertSeeHtml('wire:click="scegliFornitoreGlobale('.$this->thermo->id.')"')
        // Una richiesta ripetuta a mano non ne crea una seconda.
        ->call('scegliFornitoreGlobale', $this->agilent->id)
        ->assertNotFound();

    expect(($this->catalogo)($this->alfa['sede']))->toHaveCount(1)
        // In un altro cliente invece resta da portare.
        ->and(FornitoriGlobali::offribiliA($this->beta['sede']->id)->pluck('id')->all())->toContain($this->agilent->id);
});

it('does not offer a supplier the customer already has under the same name', function () {
    Fornitore::factory()->forTenant($this->alfa['sede'])->create(['ragione_sociale' => 'AGILENT italia']);
    $this->actingAs($this->superadmin);

    expect(FornitoriGlobali::offribiliA($this->alfa['sede']->id)->pluck('ragione_sociale')->all())->toBe(['Thermo Fisher', 'Zeiss']);
});

it('brings back the copy from the bin instead of making a second one', function () {
    $this->actingAs($this->superadmin);
    $copia = FornitoriGlobali::portaIn($this->agilent->id, $this->alfa['sede']->id);
    $copia->delete();

    expect(FornitoriGlobali::offribiliA($this->alfa['sede']->id)->pluck('id')->all())->toContain($this->agilent->id);

    $tornata = FornitoriGlobali::portaIn($this->agilent->id, $this->alfa['sede']->id);

    expect($tornata->id)->toBe($copia->id)
        ->and($tornata->trashed())->toBeFalse()
        ->and(Fornitore::withoutGlobalScopes()->withTrashed()->where('tenant_id', $this->alfa['sede']->id)->count())->toBe(1);
});

it('leaves alone the copy another customer threw away', function () {
    $this->actingAs($this->superadmin);
    $diBeta = FornitoriGlobali::portaIn($this->agilent->id, $this->beta['sede']->id);
    $diBeta->delete();

    $diAlfa = FornitoriGlobali::portaIn($this->agilent->id, $this->alfa['sede']->id);

    expect($diAlfa->id)->not->toBe($diBeta->id)
        ->and($diAlfa->tenant_id)->toBe($this->alfa['sede']->id)
        ->and($diBeta->fresh()->trashed())->toBeTrue();
});

it('offers nothing in a second sede of EasyLab either: its catalogues are not customers', function () {
    $seconda = UnitaOrganizzativa::factory()->ente()->perAccount($this->enteEasylab->account)->create(['nome' => 'EasyLab Sud']);
    $this->actingAs($this->superadmin);

    expect(FornitoriGlobali::sediDiPiattaforma())->toEqualCanonicalizing([$this->enteEasylab->id, $seconda->id])
        ->and(FornitoriGlobali::offribiliA($seconda->id)->count())->toBe(0);
});

// ─── Ciò che non deve riuscire ───────────────────────────────────────────────

it('refuses the people of the customer with a 404, and copies nothing', function () {
    ($this->selettore)($this->alfa['admin'], $this->alfa['reparto'])
        ->call('scegliFornitoreGlobale', $this->agilent->id)
        ->assertNotFound();

    expect(($this->catalogo)($this->alfa['sede']))->toBeEmpty();
});

it('refuses an id that is not a supplier of EasyLab', function () {
    // 🔴 Il fornitore di un altro cliente, chiesto come se fosse globale.
    $diBeta = Fornitore::factory()->forTenant($this->beta['sede'])->create(['ragione_sociale' => 'Fornitore di Beta']);

    ($this->selettore)($this->superadmin, $this->alfa['reparto'])
        ->call('scegliFornitoreGlobale', $diBeta->id)
        ->assertNotFound();

    ($this->selettore)($this->superadmin, $this->alfa['reparto'])->call('scegliFornitoreGlobale', 999999)->assertNotFound();

    expect(($this->catalogo)($this->alfa['sede']))->toBeEmpty();
});

it('refuses a supplier of EasyLab that is in the bin', function () {
    $this->zeiss->delete();

    ($this->selettore)($this->superadmin, $this->alfa['reparto'])
        ->call('scegliFornitoreGlobale', $this->zeiss->id)
        ->assertNotFound();
});

it('never writes into the catalogue of a customer EasyLab does not work for', function (User $chi) {
    // 🔴 Gamma si gestisce da sé: né il Superadmin né il Gestore ci scrivono.
    // Il Gestore è il caso che conta: non ha un Ente proprio, quindi nessun
    // trait gli riscriverebbe il tenant.
    $this->actingAs($chi);

    expect(fn () => FornitoriGlobali::portaIn($this->agilent->id, $this->gamma['sede']->id))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->catalogo)($this->gamma['sede']))->toBeEmpty()
        ->and(($this->catalogo)($this->enteEasylab))->toHaveCount(3);
})->with('chi lavora per EasyLab');

it('never takes a reparto for a catalogue', function () {
    $this->actingAs($this->superadmin);

    expect(fn () => FornitoriGlobali::portaIn($this->agilent->id, $this->alfa['reparto']->id))
        ->toThrow(ModelNotFoundException::class);
});

it('throws for who does not work for EasyLab, whatever the catalogue', function () {
    $this->actingAs($this->alfa['admin']);

    expect(fn () => FornitoriGlobali::portaIn($this->agilent->id, $this->alfa['sede']->id))->toThrow(ModelNotFoundException::class);
});

it('keeps globale_id out of what a form can write', function () {
    expect((new Fornitore)->isFillable('globale_id'))->toBeFalse()
        ->and(Schema::hasColumn('fornitori', 'globale_id'))->toBeTrue();
});
