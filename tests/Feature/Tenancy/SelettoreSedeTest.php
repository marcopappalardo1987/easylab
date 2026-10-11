<?php

use App\Livewire\Fornitori\ElencoFornitori;
use App\Livewire\Strumenti\ImportStrumenti;
use App\Models\Account;
use App\Models\Fornitore;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Testo\Ricerca;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * La tendina in cui chi segue più clienti sceglie dove lavora, filtrabile
 * scrivendo (🔗 ADR-061; ADR-046). Area rossa: l'id della scelta arriva dal
 * browser, e il filtro non deve poter aprire ciò che la persona non segue.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $sede = function (string $cliente, string $nome, bool $gestito = true): UnitaOrganizzativa {
        $account = Account::firstWhere('ragione_sociale', $cliente) ?? Account::factory()->create(['ragione_sociale' => $cliente]);
        if ($gestito) {
            $account->affidaManutenzione();
        }

        return UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => $nome]);
    };

    $this->bergamo = $sede('Gruppo Lombardo', 'Presidio di Bergamo');
    $this->lecco = $sede('Gruppo Lombardo', 'Presidio di Lecco');
    $this->ancona = $sede('Istituto Adriatico', 'Città di Ancona');
    $this->estranea = $sede('Altri srl', 'Sede di Bergamo Alta', gestito: false);

    $this->gestore = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $this->gestore->assignRole('Gestore');
    $this->gestore->portafoglioClienti()->attach([$this->bergamo->id, $this->lecco->id, $this->ancona->id]);

    $this->modulo = fn () => Livewire::actingAs($this->gestore)->test(ElencoFornitori::class)->call('nuovo');
});

it('calls it laboratorio in the supplier form, closed until one opens it', function () {
    ($this->modulo)()
        ->assertSee('Laboratorio')
        ->assertSee('Scegli il laboratorio…')
        ->assertDontSee('Scegli la sede…')
        ->assertSeeHtml('data-selettore-sede')
        ->assertDontSeeHtml('data-pannello-sedi')
        ->assertDontSeeHtml('<select id="sede-fornitore"');
});

it('lists every followed sede when opened, and only those', function () {
    ($this->modulo)()
        ->call('apriSedi')
        ->assertSeeHtml('data-pannello-sedi')
        ->assertSeeHtml('wire:model.live.debounce.250ms="cercaSede"')
        ->assertSee(['Gruppo Lombardo — Presidio di Bergamo', 'Gruppo Lombardo — Presidio di Lecco', 'Istituto Adriatico — Città di Ancona'])
        ->assertSee('3 di 3')
        ->assertSeeHtml('wire:click="scegliSede('.$this->lecco->id.')"')
        ->assertDontSeeHtml('wire:click="scegliSede('.$this->estranea->id.')"')
        ->assertDontSee('Sede di Bergamo Alta');
});

it('filters by what one writes, on the name of the client and of the sede', function (string $testo, array $restano, array $spariscono, string $conto) {
    ($this->modulo)()
        ->call('apriSedi')
        ->set('cercaSede', $testo)
        ->assertSee($restano)
        ->assertDontSee($spariscono)
        ->assertSee($conto);
})->with([
    'la sede' => ['lecco', ['Presidio di Lecco'], ['Presidio di Bergamo', 'Città di Ancona'], '1 di 3'],
    'il cliente' => ['LOMBARDO', ['Presidio di Bergamo', 'Presidio di Lecco'], ['Città di Ancona'], '2 di 3'],
    'senza accento' => ['citta', ['Città di Ancona'], ['Presidio di Lecco'], '1 di 3'],
    'cliente e sede insieme' => ['bergamo gruppo', ['Presidio di Bergamo'], ['Presidio di Lecco'], '1 di 3'],
]);

it('says that nothing matches, and never finds a sede that is not followed', function () {
    ($this->modulo)()
        ->call('apriSedi')
        ->set('cercaSede', 'alta')
        ->assertSeeHtml('data-nessuna-sede')
        ->assertSee('Nessun nome contiene «alta».')
        ->assertDontSee('Sede di Bergamo Alta');
});

it('writes the chosen sede, closes, shows it, and saves the supplier there', function () {
    ($this->modulo)()
        ->call('apriSedi')
        ->set('cercaSede', 'lecco')
        ->call('scegliSede', $this->lecco->id)
        ->assertSet('sedeId', $this->lecco->id)
        ->assertSet('selettoreSede', false)
        ->assertSet('cercaSede', '')
        ->assertDontSeeHtml('data-pannello-sedi')
        ->assertSeeHtml('data-sede-scelta')
        ->assertSee('Gruppo Lombardo — Presidio di Lecco')
        ->set('form.ragione_sociale', 'Reagenti Lecco')
        ->call('save')
        ->assertHasNoErrors();

    expect(Fornitore::withoutGlobalScopes()->where('ragione_sociale', 'Reagenti Lecco')->value('tenant_id'))->toBe($this->lecco->id);
});

it('refuses a forged choice with a 404, and writes nothing', function () {
    // 🔴 404 e non 403: un «non ti è permesso» confermerebbe che la sede esiste.
    ($this->modulo)()
        ->call('apriSedi')
        ->call('scegliSede', $this->estranea->id)
        ->assertNotFound();

    ($this->modulo)()->call('apriSedi')->call('scegliSede', 999999)->assertNotFound();
});

it('clears the error on the field once a sede is chosen', function () {
    $modulo = ($this->modulo)()
        ->set('form.ragione_sociale', 'Senza nome del posto')
        ->call('save')
        ->assertHasErrors('sedeId');

    // Il messaggio chiama il campo come lo chiama il modulo.
    expect($modulo->errors()->first('sedeId'))->toContain('laboratorio')->not->toContain('sede');

    $modulo
        ->call('apriSedi')
        ->call('scegliSede', $this->ancona->id)
        ->assertHasNoErrors('sedeId');
});

it('starts closed and empty the next time the form is opened', function () {
    ($this->modulo)()
        ->call('apriSedi')
        ->set('cercaSede', 'lecco')
        ->call('closeForm')
        ->call('nuovo')
        ->assertSet('selettoreSede', false)
        ->assertSet('cercaSede', '')
        ->assertSet('sedeId', null);

    // E riaprendo la tendina il testo di prima non c'è più.
    ($this->modulo)()->call('apriSedi')->set('cercaSede', 'lecco')->call('chiudiSedi')->call('apriSedi')
        ->assertSet('cercaSede', '')->assertSee('3 di 3');
});

it('has nothing to open for who works in one sede only', function () {
    $admin = User::factory()->create(['tenant_id' => $this->bergamo->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');

    Livewire::actingAs($admin)->test(ElencoFornitori::class)
        ->call('nuovo')
        ->assertDontSeeHtml('data-selettore-sede')
        ->call('apriSedi')
        ->assertNotFound();
});

it('offers the same selector on the import page, where it stays a sede', function () {
    Livewire::actingAs($this->gestore)->test(ImportStrumenti::class)
        ->assertSee('Sede in cui importare')
        ->assertSee('Scegli la sede…')
        ->assertDontSeeHtml('<select id="sede-import"')
        ->call('apriSedi')
        ->set('cercaSede', 'ancona')
        ->assertSee('1 di 3')
        ->call('scegliSede', $this->ancona->id)
        ->assertSet('sedeId', $this->ancona->id)
        ->assertSee('Istituto Adriatico — Città di Ancona');
});

it('matches every word, in any order, whatever the case and the accents', function (string $testo, string $cercato, bool $esito) {
    expect(Ricerca::contiene($testo, $cercato))->toBe($esito);
})->with([
    ['Città della Salute', 'citta', true],
    ['Città della Salute', 'SALUTE città', true],
    ['Citta della Salute', 'città', true],
    ['Città della Salute', 'salute torino', false],
    ['Città della Salute', '', true],
    ['Città della Salute', '   ', true],
    ['Virologia', 'logia', true],
    ['Virologia', 'viro logia x', false],
]);
