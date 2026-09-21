<?php

use App\Models\Account;
use App\Models\Fornitore;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * T2B-3. `easylab:provision-tenant` senza `--account` crea un cliente nuovo.
 * Un indirizzo già in uso da chi non amministra nessun contratto (un Tenant di
 * un altro cliente, un tecnico esterno) riceveva `assignRole('Admin')` — ruolo
 * globale, `teams = false` — restando sul proprio tenant, e il comando
 * stampava un successo. Ora il comando rifiuta.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $accountY = Account::factory()->create(['ragione_sociale' => 'Laboratorio Ypsilon']);
    $this->enteY = UnitaOrganizzativa::factory()->ente()->perAccount($accountY)->create(['nome' => 'Sede Ypsilon']);
    $this->enteY->refresh();
    Fornitore::factory()->forTenant($this->enteY)->create(['ragione_sociale' => 'Fornitore Ypsilon']);
});

function tecnicoEsternoDiYpsilon(UnitaOrganizzativa $enteY): User
{
    $tecnico = User::factory()->create(['email' => 'tecnico@esterno.test', 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole(User::TECNICO_ROLE);
    $tecnico->portafoglioClienti()->attach($enteY->id);

    return $tecnico;
}

it('does not promote a Tenant of another customer to Admin of their own Ente', function () {
    $referente = User::factory()->create(['email' => 'referente@ypsilon.test', 'two_factor_confirmed_at' => now()]);
    $referente->forceFill(['tenant_id' => $this->enteY->id])->save();
    $referente->assignRole('Tenant');

    $this->artisan('easylab:provision-tenant', ['nome' => 'Studio Zeta', '--admin-email' => 'referente@ypsilon.test'])
        ->assertFailed();

    expect($referente->fresh()->tenant_id)->toBe($this->enteY->id)
        ->and($referente->fresh()->hasRole('Admin'))->toBeFalse();
    $this->assertDatabaseMissing('unita_organizzativa', ['nome' => 'Studio Zeta']);
});

it('does not hand Admin powers over every portfolio client to an external technician', function () {
    $tecnico = tecnicoEsternoDiYpsilon($this->enteY);

    $this->artisan('easylab:provision-tenant', ['nome' => 'Studio Zeta', '--admin-email' => 'tecnico@esterno.test'])
        ->assertFailed();

    expect($tecnico->fresh()->can('fornitori.delete'))->toBeFalse()
        ->and($tecnico->fresh()->getRoleNames()->all())->toBe([User::TECNICO_ROLE]);
});

it('keeps the supplier registry of a portfolio client closed to the technician after the attempt', function () {
    $tecnico = tecnicoEsternoDiYpsilon($this->enteY);
    $this->actingAs($tecnico)->get('/fornitori')->assertForbidden();

    $this->artisan('easylab:provision-tenant', ['nome' => 'Studio Zeta', '--admin-email' => 'tecnico@esterno.test']);
    auth()->forgetUser();

    $risposta = $this->actingAs($tecnico->fresh())->get('/fornitori');
    expect($risposta->status())->toBe(403)
        ->and($risposta->getContent())->not->toContain('Fornitore Ypsilon');
});

it('still provisions a brand new customer with a new address (positive control)', function () {
    $this->artisan('easylab:provision-tenant', ['nome' => 'Studio Zeta', '--admin-email' => 'nuovo@zeta.test'])
        ->assertSuccessful();

    expect(User::where('email', 'nuovo@zeta.test')->first()?->hasRole('Admin'))->toBeTrue();
});
