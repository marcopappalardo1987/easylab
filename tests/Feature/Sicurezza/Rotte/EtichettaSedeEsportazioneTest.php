<?php

use App\Livewire\Tenancy\SwitcherEnte;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;

/**
 * D-T2-3 sul PDF dell'elenco documenti (security pass S7, T1b — 🔗 ADR-018,
 * ADR-032). Impersonando, lo switcher sposta il contesto in sessione e non tocca
 * `users.tenant_id`: il foglio elencava i documenti della sede scelta sotto il
 * nome della sede di partenza del cliente. Stessa forma di
 * `Sicurezza/Autorizzazioni/EtichettaSedeImpersonataTest` (dashboard).
 *
 * Il nome si legge dai dati passati alla vista: il PDF di dompdf è compresso e
 * un `assertSee` sui byte non proverebbe niente.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $account = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->enteA = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => 'Sede Nord']);
    $this->enteB = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => 'Sede Sud']);

    $this->membro = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $this->membro->assignRole('Admin');
    $account->aggiungiMembro($this->membro);

    $this->enteSulFoglio = null;
    View::creator('pdf.elenco-documenti', fn ($vista) => $this->enteSulFoglio = $vista->getData()['ente'] ?? null);
});

it('names the sede chosen during the impersonation on the exported sheet', function () {
    $superadmin = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');
    $this->actingAs($superadmin)->get(route('impersonate', $this->membro));

    Livewire::test(SwitcherEnte::class)->call('passa', $this->enteB->id);
    expect(CurrentTenant::id())->toBe($this->enteB->id);

    $this->get(route('documenti.export-pdf'))->assertOk();

    expect($this->enteSulFoglio)->toBe('Sede Sud');
});

it('still names the home sede outside any impersonation', function () {
    $this->actingAs($this->membro)->get(route('documenti.export-pdf'))->assertOk();

    expect($this->enteSulFoglio)->toBe('Sede Nord');
});
