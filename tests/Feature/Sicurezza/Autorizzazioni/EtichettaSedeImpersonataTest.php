<?php

use App\Livewire\Dashboard\Home;
use App\Livewire\Tenancy\SwitcherEnte;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/*
 * T1a (S7), D-T2-3 — il nome dell'Ente sulla dashboard.
 *
 * Impersonando, lo switcher sposta il contesto nella sessione e non tocca
 * `users.tenant_id`. La dashboard mostrava i dati della sede scelta sotto il
 * nome della sede di partenza: il nome deve venire da `CurrentTenant::id()`.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $account = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->enteA = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => 'Sede Nord']);
    $this->enteB = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => 'Sede Sud']);

    $this->membro = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $this->membro->assignRole('Admin');
    $account->aggiungiMembro($this->membro);
});

it('names the sede chosen during the impersonation, not the customer home sede', function () {
    $superadmin = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');
    $this->actingAs($superadmin)->get(route('impersonate', $this->membro));

    Livewire::test(SwitcherEnte::class)->call('passa', $this->enteB->id);
    expect(CurrentTenant::id())->toBe($this->enteB->id);

    Livewire::test(Home::class)
        ->assertSee('Lo stato delle macchine di Sede Sud.')
        ->assertDontSee('Lo stato delle macchine di Sede Nord.');
});

it('still names the home sede outside any impersonation', function () {
    Livewire::actingAs($this->membro)->test(Home::class)
        ->assertSee('Lo stato delle macchine di Sede Nord.');
});
