<?php

use App\Livewire\Strumenti\ElencoStrumenti;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dip1 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip 1']);
    $this->lab1 = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->dip1)->create(['nome' => 'Lab 1']);
    $this->dip2 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip 2']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

it('redirects guests to login', function () {
    $this->get(route('strumenti.index'))->assertRedirect(route('login'));
});

it('forbids users without strumenti.view', function () {
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

    $this->actingAs($user)->get(route('strumenti.index'))->assertForbidden();
});

it('lists the tenant strumenti', function () {
    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Autoclave']);
    Strumento::factory()->forNode($this->dip2)->create(['nome' => 'Centrifuga']);

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->assertSee('Autoclave')
        ->assertSee('Centrifuga');
});

it('searches by nome, modello and matricola (case-insensitive)', function () {
    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Autoclave', 'modello' => 'AC-200', 'matricola' => 'SN-999']);
    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Centrifuga', 'modello' => 'CF-12', 'matricola' => 'SN-111']);

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('search', 'autoclave')
        ->assertSee('Autoclave')
        ->assertDontSee('Centrifuga')
        ->set('search', 'CF-12')
        ->assertSee('Centrifuga')
        ->assertDontSee('Autoclave')
        ->set('search', 'sn-999')
        ->assertSee('Autoclave')
        ->assertDontSee('Centrifuga');
});

it('filters by ubicazione including descendants', function () {
    Strumento::factory()->forNode($this->lab1)->create(['nome' => 'Sotto Lab1']); // discendente di dip1
    Strumento::factory()->forNode($this->dip2)->create(['nome' => 'In Dip2']);

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('ubicazioneId', $this->dip1->id)
        ->assertSee('Sotto Lab1')      // dip1 → lab1 (discendente)
        ->assertDontSee('In Dip2');
});

it('sorts by column and toggles direction', function () {
    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Alfa']);
    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Zeta']);

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->assertSeeInOrder(['Alfa', 'Zeta'])   // default nome asc
        ->call('sort', 'nome')                 // → desc
        ->assertSeeInOrder(['Zeta', 'Alfa']);
});

it('does not list strumenti of another tenant', function () {
    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Mio']);

    $enteB = UnitaOrganizzativa::factory()->ente()->create();
    $dipB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();
    Strumento::factory()->forNode($dipB)->create(['nome' => 'Altrui']);

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->assertSee('Mio')
        ->assertDontSee('Altrui');
});

it('shows a Responsabile only its subtree strumenti', function () {
    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Nel reparto']);
    Strumento::factory()->forNode($this->dip2)->create(['nome' => 'Fuori reparto']);

    $resp = User::factory()->create(['tenant_id' => $this->ente->id]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dip1->id);

    Livewire::actingAs($resp)->test(ElencoStrumenti::class)
        ->assertSee('Nel reparto')
        ->assertDontSee('Fuori reparto');
});
