<?php

use App\Livewire\Piattaforma\Cabina;
use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Il segno «manutenzione gestita da EasyLab» dalla cabina (🔗 ADR-046).
 *
 * 🔴 È la leva di questa pagina che **cambia chi legge i dati di un cliente**:
 * accenderla apre macchine, interventi e documenti di tutte le sue sedi al
 * Superadmin. I negativi contano quindi più dei positivi — chi può muoverla, su
 * quali account, e con quale traccia.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // ⚠️ Il mondo nasce **prima** di `actingAs`: con un utente autenticato
    // `BelongsToTenant` forzerebbe il `tenant_id` della macchina sull'Ente di
    // chi guarda, e il cliente si ritroverebbe senza la sua.
    $this->cliente = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->sede = UnitaOrganizzativa::factory()->ente()->perAccount($this->cliente)->create(['nome' => 'Sede di Milano']);
    $reparto = UnitaOrganizzativa::factory()->dipartimento()->under($this->sede)->create();
    Strumento::factory()->forNode($reparto)->create(['nome' => 'Autoclave Rossi']);

    $this->suoEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'EasyLab']);
    $this->superadmin = User::factory()->create([
        'tenant_id' => $this->suoEnte->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->superadmin->assignRole('Superadmin');
    $this->actingAs($this->superadmin->fresh());

    Notification::fake();
});

it('entrusts the maintenance from the panel, and the data open at the next query', function () {
    expect(Strumento::count())->toBe(0);

    Livewire::test(Cabina::class)
        ->assertDontSeeHtml('data-gestita="'.$this->cliente->id.'"')
        ->call('apriManutenzione', $this->cliente->id)
        ->assertSee('Affida a EasyLab')
        ->call('affidaManutenzione')
        ->assertSet('pannello', '')
        ->assertSeeHtml('data-gestita="'.$this->cliente->id.'"');

    expect($this->cliente->fresh()->manutenzione_gestita)->toBeTrue()
        ->and(Strumento::pluck('nome')->all())->toBe(['Autoclave Rossi']);

    // Chi l'ha accesa, e quando: è la riga che si va a cercare fra sei mesi.
    $riga = Activity::where('log_name', AuditLog::NAME)
        ->where('subject_type', (new Account)->getMorphClass())
        ->where('subject_id', $this->cliente->id)->latest('id')->first();

    expect($riga->causer_id)->toBe($this->superadmin->id)
        ->and($riga->attribute_changes['attributes']['manutenzione_gestita'])->toBeTrue();
});

it('takes the maintenance back from the same panel, and the data close again', function () {
    $this->cliente->affidaManutenzione();
    expect(Strumento::count())->toBe(1);

    Livewire::test(Cabina::class)
        ->call('apriManutenzione', $this->cliente->id)
        ->assertSee('Ritira la gestione')
        ->assertDontSee('Affida a EasyLab')
        ->call('ritiraManutenzione')
        ->assertDontSeeHtml('data-gestita="'.$this->cliente->id.'"');

    expect($this->cliente->fresh()->manutenzione_gestita)->toBeFalse()
        ->and(Strumento::count())->toBe(0);
});

it('refuses the lever to whoever may see the page but not provision', function () {
    // `tenants.view_all` apre la cabina; a muovere questa leva serve
    // `tenants.provision`, che è nel set bloccato.
    $osservatore = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $osservatore->givePermissionTo('tenants.view_all');
    $this->actingAs($osservatore->fresh());

    Livewire::test(Cabina::class)
        ->assertDontSee('Gestione')
        ->call('apriManutenzione', $this->cliente->id)
        ->assertForbidden();

    // ⚠️ E l'azione che scrive, senza passare dall'apertura: le property sono
    // pubbliche.
    foreach (['affidaManutenzione', 'ritiraManutenzione'] as $azione) {
        Livewire::test(Cabina::class)
            ->set('accountInLavorazione', $this->cliente->id)
            ->set('pannello', 'manutenzione')
            ->call($azione)
            ->assertForbidden();
    }

    expect($this->cliente->fresh()->manutenzione_gestita)->toBeFalse();
});

it('does not even show the panel to whoever cannot move the lever', function () {
    // ⚠️ Con `billing.manage_global`, apposta: chi può toccare i dati fiscali
    // di ogni cliente non per questo può aprirne i dati operativi. Senza, il
    // test passerebbe anche se il pannello chiedesse l'ability sbagliata.
    $osservatore = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $osservatore->givePermissionTo(['tenants.view_all', 'billing.manage_global']);
    $this->actingAs($osservatore->fresh());

    Livewire::test(Cabina::class)
        ->set('accountInLavorazione', $this->cliente->id)
        ->set('pannello', 'manutenzione')
        ->assertDontSee('Affida a EasyLab');
});

it('refuses the lever on an account that is not a customer', function () {
    $piattaforma = Account::factory()->create(['di_piattaforma' => true]);

    expect(fn () => Livewire::test(Cabina::class)->call('apriManutenzione', $piattaforma->id))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => Livewire::test(Cabina::class)
        ->set('accountInLavorazione', $piattaforma->id)
        ->set('pannello', 'manutenzione')
        ->call('affidaManutenzione'))
        ->toThrow(ModelNotFoundException::class);

    expect($piattaforma->fresh()->manutenzione_gestita)->toBeFalse();
});

it('lets the developer move the lever, without opening the data to the developer', function () {
    // Il Developer ha `tenants.provision` e quindi la leva. I dati no: quelli
    // li apre il ruolo Superadmin, non il permesso.
    $developer = User::factory()->create(['tenant_id' => null]);
    $developer->assignRole('Developer');
    $this->actingAs($developer->fresh());

    Livewire::test(Cabina::class)
        ->call('apriManutenzione', $this->cliente->id)
        ->call('affidaManutenzione')
        ->assertHasNoErrors();

    expect($this->cliente->fresh()->manutenzione_gestita)->toBeTrue()
        ->and(Strumento::count())->toBe(0);
});

// --- Alla nascita del cliente ---

it('marks a new client as managed when the box is ticked', function () {
    Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        ->set('nuovo.nome', 'Laboratori Verdi')
        ->set('nuovo.adminEmail', 'admin@verdi.test')
        ->set('nuovo.adminName', 'Anna Verdi')
        ->set('nuovo.gestita', true)
        ->call('creaCliente')
        ->assertHasNoErrors()
        ->assertSee('La manutenzione è gestita da EasyLab.');

    expect(Account::where('ragione_sociale', 'Laboratori Verdi')->firstOrFail()->manutenzione_gestita)->toBeTrue();
});

it('leaves a new client on its own when the box is not ticked', function (mixed $valore) {
    // ⚠️ Compreso l'array annidato: `nuovo` è un array pubblico, e un valore
    // non scalare non deve valere «sì» — né far esplodere la richiesta.
    Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        ->set('nuovo.nome', 'Laboratori Blu')
        ->set('nuovo.adminEmail', 'admin@blu.test')
        ->set('nuovo.adminName', 'Bruno Blu')
        ->set('nuovo.gestita', $valore)
        ->call('creaCliente')
        ->assertHasNoErrors()
        ->assertDontSee('La manutenzione è gestita da EasyLab.');

    expect(Account::where('ragione_sociale', 'Laboratori Blu')->firstOrFail()->manutenzione_gestita)->toBeFalse();
})->with([
    'non toccata' => [''],
    'falso' => [false],
    'zero' => ['0'],
    'array annidato' => [['1']],
]);

it('never opens the data of an existing client by adding a sede to it', function () {
    // 🔴 Agganciando una sede la casella non c'è: onorarla aprirebbe i dati di
    // un cliente in essere con un valore che l'operatore non ha visto.
    Livewire::test(Cabina::class)
        ->call('apriProvisioning', $this->cliente->id)
        ->assertDontSee('Manutenzione gestita da EasyLab')
        ->set('nuovo.nome', 'Sede di Torino')
        ->set('nuovo.adminEmail', 'torino@rossi.test')
        ->set('nuovo.adminName', 'Tina Torino')
        ->set('nuovo.gestita', true)
        ->call('creaCliente')
        ->assertHasNoErrors();

    expect($this->cliente->fresh()->manutenzione_gestita)->toBeFalse();
});
