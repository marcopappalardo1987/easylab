<?php

use App\Livewire\Anagrafica\Albero;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * Il filtro di testo sulle card della pagina «Laboratori» (🔗 ADR-060).
 * Restringe ciò che la persona vede già: non può mostrare un nodo in più.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ospedale']);
    foreach (['Virologia', 'Chimica clinica', 'Chimica organica', 'Città della Salute'] as $nome) {
        UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => $nome]);
    }

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    $this->pagina = fn (?User $chi = null) => Livewire::actingAs($chi ?? $this->admin)->test(Albero::class)
        ->call('open', $this->ente->id);
});

it('offers the filter where there is something to filter, and lists everything until one types', function () {
    ($this->pagina)()
        ->assertSeeHtml('wire:model.live.debounce.300ms="cerca"')
        ->assertDontSeeHtml('data-cerca-conto')
        ->assertSee(['Virologia', 'Chimica clinica', 'Chimica organica', 'Città della Salute']);
});

it('keeps the cards whose name contains the text, whatever the case and the accents', function (string $testo, array $restano, array $spariscono, string $conto) {
    ($this->pagina)()
        ->set('cerca', $testo)
        ->assertSee($restano)
        ->assertDontSee($spariscono)
        ->assertSee($conto);
})->with([
    'una parte del nome' => ['chim', ['Chimica clinica', 'Chimica organica'], ['Virologia', 'Città della Salute'], '(2 di 4)'],
    'maiuscole' => ['VIRO', ['Virologia'], ['Chimica clinica'], '(1 di 4)'],
    'senza accento' => ['citta', ['Città della Salute'], ['Virologia'], '(1 di 4)'],
    'con accento su un nome che non lo ha' => ['vìrologia', ['Virologia'], ['Chimica clinica'], '(1 di 4)'],
    'in mezzo al nome' => ['organ', ['Chimica organica'], ['Chimica clinica'], '(1 di 4)'],
    'due parole, in qualunque ordine' => ['clinica chim', ['Chimica clinica'], ['Chimica organica'], '(1 di 4)'],
    'spazi intorno' => ['  viro  ', ['Virologia'], ['Chimica clinica'], '(1 di 4)'],
]);

it('treats a text of spaces only as no filter', function () {
    ($this->pagina)()->set('cerca', '   ')->assertSee(['Virologia', 'Chimica clinica'])->assertDontSeeHtml('data-cerca-conto');
});

it('says that nothing matches and offers to remove the filter, instead of saying the place is empty', function () {
    ($this->pagina)()
        ->set('cerca', 'radiologia')
        ->assertSeeHtml('data-cerca-vuota')
        ->assertSee('Nessun nome contiene «radiologia».')
        ->assertDontSee('Nessun laboratorio qui.')
        ->assertDontSee('Virologia')
        // Il campo resta: senza, non ci sarebbe modo di correggere il testo.
        ->assertSeeHtml('wire:model.live.debounce.300ms="cerca"')
        ->call('togliCerca')
        ->assertSet('cerca', '')
        ->assertSee('Virologia');
});

it('empties the filter when one moves to another level', function () {
    $virologia = UnitaOrganizzativa::where('nome', 'Virologia')->sole();

    ($this->pagina)()->set('cerca', 'viro')->call('open', $virologia->id)->assertSet('cerca', '')
        ->set('cerca', 'x')->call('goTo', $this->ente->id)->assertSet('cerca', '')
        ->assertSee('Chimica clinica');
});

it('shows no filter in a place with nothing inside', function () {
    $virologia = UnitaOrganizzativa::where('nome', 'Virologia')->sole();

    ($this->pagina)()->call('open', $virologia->id)
        ->assertDontSeeHtml('wire:model.live.debounce.300ms="cerca"')
        ->assertDontSeeHtml('data-cerca-vuota');
});

it('filters at the root too, and never brings in a laboratorio the person does not see', function () {
    // 🔴 ADR-006/057: un Responsabile Reparto vede i soli laboratori assegnati,
    // e alla radice trova quelli (con uno solo ci entrerebbe direttamente).
    $chi = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $chi->assignRole('Responsabile Reparto');
    $chi->unitaResponsabili()->attach(UnitaOrganizzativa::whereIn('nome', ['Virologia', 'Città della Salute'])->pluck('id'));

    Livewire::actingAs($chi->fresh())->test(Albero::class)
        ->assertSet('currentId', null)
        ->assertSee(['Virologia', 'Città della Salute'])
        ->set('cerca', 'salute')
        ->assertSee('Città della Salute')
        ->assertDontSee('Virologia')
        ->assertSee('(1 di 2)')
        ->set('cerca', 'chim')
        ->assertDontSee('Chimica clinica')
        ->assertDontSee('Chimica organica')
        ->assertSeeHtml('data-cerca-vuota');
});
