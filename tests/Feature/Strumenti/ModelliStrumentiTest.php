<?php

use App\Livewire\Strumenti\ModelliStrumenti;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->labA = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Lab A']);
    $this->labB = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Lab B']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

it('redirects guests to login', function () {
    $this->get(route('strumenti.modelli'))->assertRedirect(route('login'));
});

it('forbids users without strumenti.view', function () {
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

    $this->actingAs($user)->get(route('strumenti.modelli'))->assertForbidden();
});

it('groups units by modello with per-lab counts', function () {
    Strumento::factory()->forNode($this->labA)->count(2)->create(['modello' => 'AC-200']);
    Strumento::factory()->forNode($this->labB)->create(['modello' => 'AC-200']);
    Strumento::factory()->forNode($this->labA)->create(['modello' => 'CF-12']);

    $component = Livewire::actingAs($this->admin)->test(ModelliStrumenti::class);

    $modelli = collect($component->viewData('modelli'));

    $ac = $modelli->firstWhere('modello', 'AC-200');
    expect($ac['totale'])->toBe(3);
    expect(collect($ac['laboratori'])->pluck('conteggio', 'nome')->all())
        ->toBe(['Lab A' => 2, 'Lab B' => 1]);

    $cf = $modelli->firstWhere('modello', 'CF-12');
    expect($cf['totale'])->toBe(1);

    $component->assertSee('AC-200')->assertSee('CF-12')->assertSee('Lab A')->assertSee('Lab B');
});

it('searches by modello (case-insensitive)', function () {
    Strumento::factory()->forNode($this->labA)->create(['modello' => 'AC-200']);
    Strumento::factory()->forNode($this->labA)->create(['modello' => 'CF-12']);

    Livewire::actingAs($this->admin)->test(ModelliStrumenti::class)
        ->set('search', 'ac-200')
        ->assertSee('AC-200')
        ->assertDontSee('CF-12');
});

it('groups instruments without a modello under a dedicated label', function () {
    Strumento::factory()->forNode($this->labA)->create(['modello' => null, 'nome' => 'Senza modello']);

    Livewire::actingAs($this->admin)->test(ModelliStrumenti::class)
        ->assertSee(ModelliStrumenti::SENZA_MODELLO);
});

it('does not count units of another tenant', function () {
    Strumento::factory()->forNode($this->labA)->create(['modello' => 'AC-200']);

    $enteB = UnitaOrganizzativa::factory()->ente()->create();
    $labAltrui = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create(['nome' => 'Lab Altrui']);
    Strumento::factory()->forNode($labAltrui)->count(3)->create(['modello' => 'AC-200']);

    $component = Livewire::actingAs($this->admin)->test(ModelliStrumenti::class);

    $ac = collect($component->viewData('modelli'))->firstWhere('modello', 'AC-200');
    expect($ac['totale'])->toBe(1); // solo la propria unità
    $component->assertDontSee('Lab Altrui');
});

it('limits a Responsabile to its subtree', function () {
    Strumento::factory()->forNode($this->labA)->create(['modello' => 'AC-200']);
    Strumento::factory()->forNode($this->labB)->create(['modello' => 'AC-200']);

    $resp = User::factory()->create(['tenant_id' => $this->ente->id]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->labA->id);

    $component = Livewire::actingAs($resp)->test(ModelliStrumenti::class);

    $ac = collect($component->viewData('modelli'))->firstWhere('modello', 'AC-200');
    expect($ac['totale'])->toBe(1);
    $component->assertSee('Lab A')->assertDontSee('Lab B');
});

it('links each lab to the filtered elenco', function () {
    Strumento::factory()->forNode($this->labA)->create(['modello' => 'AC-200']);

    Livewire::actingAs($this->admin)->test(ModelliStrumenti::class)
        // assertSee escapa il needle → combacia con &amp; nell'href renderizzato.
        ->assertSee(route('strumenti.index', ['search' => 'AC-200', 'ubicazioneId' => $this->labA->id]));
});
