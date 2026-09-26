<?php

use App\Livewire\Piattaforma\Cabina;
use App\Livewire\Tenancy\SwitcherEnte;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * 🔴 «Aggiungi una sede» dalla cabina non promuove né aggancia chi c'è già
 * (🔗 ADR-018, ADR-032; caccia T1aB).
 *
 * `ProvisionaEnte` con `accountId` tornava presto da `risolviAccount()` e
 * arrivava all'`assignRole('Admin')` su chiunque avesse quell'indirizzo: un
 * utente Tenant di un altro cliente diventava Admin nel proprio tenant, e il
 * Superadmin con la propria email diventava membro del cliente, entrando dallo
 * switcher senza impersonazione. I casi diretti (e l'Admin del cliente
 * accettato) sono in `tests/Feature/Registrazione/ProvisionaEnteIndirizzoEsistenteTest.php`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $suoEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'EasyLab']);
    $this->superadmin = User::factory()->create(['tenant_id' => $suoEnte->id, 'two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');

    $this->clienteX = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    UnitaOrganizzativa::factory()->ente()->perAccount($this->clienteX)->create(['nome' => 'Sede di Milano']);

    $clienteY = Account::factory()->saas()->create(['ragione_sociale' => 'Studio Bianchi']);
    $this->enteY = UnitaOrganizzativa::factory()->ente()->perAccount($clienteY)->create(['nome' => 'Studio Bianchi']);

    $this->referente = User::factory()->create(['tenant_id' => $this->enteY->id, 'email' => 'referente@bianchi.test']);
    $this->referente->assignRole(User::TENANT_ROLE);
});

function aggiungiSedeConEmail(Account $cliente, User $superadmin, string $email): void
{
    Livewire::actingAs($superadmin->fresh())
        ->test(Cabina::class)
        ->call('apriProvisioning', $cliente->id)
        ->set('nuovo', ['nome' => 'Sede di Bergamo', 'adminEmail' => $email, 'adminName' => 'Chiunque'])
        ->call('creaCliente');
}

it('does not promote a read-only user of another customer to Admin when adding a sede', function () {
    aggiungiSedeConEmail($this->clienteX, $this->superadmin, 'referente@bianchi.test');

    expect($this->referente->fresh()->hasRole('Admin'))->toBeFalse();
});

it('does not make the Superadmin a member of a customer contract by typing their own email', function () {
    aggiungiSedeConEmail($this->clienteX, $this->superadmin, $this->superadmin->email);

    expect($this->clienteX->membri()->whereKey($this->superadmin->id)->exists())->toBeFalse();

    // Da membro, lo switcher lo porta nel cliente senza impersonazione (ADR-018).
    $milano = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Sede di Milano')->firstOrFail();
    Livewire::actingAs($this->superadmin->fresh())->test(SwitcherEnte::class)->call('passa', $milano->id);

    expect($this->superadmin->fresh()->tenant_id)->not->toBe($milano->id);
});
