<?php

use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Provisioning\ProvisionaEnte;
use App\Support\Provisioning\ProvisioningRifiutato;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Il provisioning estratto da console (S6 — ADR-012, ADR-032).
 *
 * ⚠️ **Questi test fanno ciò che i test del comando non hanno mai fatto:
 * girano con un utente autenticato.** `ProvisionTenantTest` usa
 * `$this->artisan()` senza `actingAs()`, dove `CurrentTenant::shouldScope()` è
 * falso — quindi l'unico percorso che la UI di S6 percorrerà è anche l'unico
 * che nessuno aveva mai esercitato.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->suoEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'EasyLab']);
    $this->superadmin = User::factory()->create(['tenant_id' => $this->suoEnte->id]);
    $this->superadmin->assignRole('Superadmin');
});

it('roots the new Ente on itself even when the provisioner is authenticated', function () {
    // 🔴 Il difetto latente che l'estrazione rende raggiungibile. Il Superadmin
    // è **tenant-bound come chiunque** (ADR-018 non concede bypass), quindi
    // `BelongsToTenant::creating` timbrerebbe il nodo nuovo col `tenant_id` di
    // chi sta scrivendo: l'Ente del cliente nascerebbe **dentro l'Ente di
    // EasyLab**, e da lì in poi ogni sua riga sarebbe visibile a chi non deve.
    //
    // Il codice era già corretto — ma per effetto collaterale di
    // `saveQuietly()`, mai per decisione, e mai provato con un utente in
    // sessione. Ora il gesto ha un nome (`radicaComeEnte`) e questo test.
    $this->actingAs($this->superadmin->fresh());

    $esito = (new ProvisionaEnte(
        nome: 'Ospedale San Giovanni',
        adminEmail: 'admin@sangiovanni.test',
        adminName: 'Anna Bianchi',
    ))->esegui();

    expect($esito->ente->tenant_id)->toBe($esito->ente->id)
        ->and($esito->ente->tenant_id)->not->toBe($this->suoEnte->id)
        ->and($esito->ente->account_id)->toBe($esito->account->id)
        // E il timbro regge anche riletto dal DB: `saveQuietly()` scavalca
        // `updating`, che altrimenti ripristinerebbe il valore sbagliato.
        ->and(UnitaOrganizzativa::withoutGlobalScopes()->find($esito->ente->id)->tenant_id)
        ->toBe($esito->ente->id);
});

it('leaves the new admin on the new Ente, not on the provisioner one', function () {
    $this->actingAs($this->superadmin->fresh());

    $esito = (new ProvisionaEnte(
        nome: 'Clinica Aurora',
        adminEmail: 'admin@aurora.test',
        adminName: 'Bruno Neri',
    ))->esegui();

    expect($esito->admin->tenant_id)->toBe($esito->ente->id)
        ->and($esito->admin->hasRole('Admin'))->toBeTrue()
        ->and($esito->account->membri()->pluck('users.id')->all())->toBe([$esito->admin->id]);
});

it('writes nothing at all when the plan limit refuses the Ente', function () {
    // La guardia sta **fuori dalla transazione**: chi viene rifiutato non deve
    // lasciarsi dietro né un account né un utente. Si conta, non si guarda.
    $this->actingAs($this->superadmin->fresh());

    $cliente = Account::factory()->create(['ragione_sociale' => 'Lab Rossi']); // free: max 1 Ente
    UnitaOrganizzativa::factory()->ente()->perAccount($cliente)->create();

    $prima = [
        'accounts' => Account::count(),
        'nodi' => UnitaOrganizzativa::withoutGlobalScopes()->count(),
        'utenti' => User::count(),
    ];

    expect(fn () => (new ProvisionaEnte(
        nome: 'Seconda Sede',
        adminEmail: 'seconda@rossi.test',
        adminName: 'Carla Verdi',
        accountId: $cliente->id,
    ))->esegui())->toThrow(ProvisioningRifiutato::class);

    expect(Account::count())->toBe($prima['accounts'])
        ->and(UnitaOrganizzativa::withoutGlobalScopes()->count())->toBe($prima['nodi'])
        ->and(User::count())->toBe($prima['utenti']);
});

it('refuses an account id that does not exist, before writing anything', function () {
    $this->actingAs($this->superadmin->fresh());

    $prima = UnitaOrganizzativa::withoutGlobalScopes()->count();

    expect(fn () => (new ProvisionaEnte(
        nome: 'Fantasma',
        adminEmail: 'x@y.test',
        adminName: 'X',
        accountId: 999999,
    ))->esegui())->toThrow(ProvisioningRifiutato::class);

    expect(UnitaOrganizzativa::withoutGlobalScopes()->count())->toBe($prima);
});

it('refuses to guess which account, when the admin belongs to more than one', function () {
    // L'ambiguità non si indovina: sceglierne uno «ragionevole» è il tipo di
    // decisione che si scopre sbagliata mesi dopo, guardando una sede sulla
    // fattura del cliente sbagliato.
    $this->actingAs($this->superadmin->fresh());

    $utente = User::factory()->create(['email' => 'multi@cliente.test']);
    Account::factory()->count(2)->create()->each->aggiungiMembro($utente);

    expect(fn () => (new ProvisionaEnte(
        nome: 'Terza Sede',
        adminEmail: 'multi@cliente.test',
        adminName: 'Multi',
    ))->esegui())->toThrow(ProvisioningRifiutato::class);
});

it('hangs the Ente on the account the existing admin already has', function () {
    $this->actingAs($this->superadmin->fresh());

    $cliente = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $prima = UnitaOrganizzativa::factory()->ente()->perAccount($cliente)->create();
    $suoAdmin = User::factory()->create(['email' => 'admin@rossi.test', 'tenant_id' => $prima->id]);
    $cliente->aggiungiMembro($suoAdmin);

    $esito = (new ProvisionaEnte(
        nome: 'Sede di Bergamo',
        adminEmail: 'admin@rossi.test',
        adminName: 'ignorato, esiste già',
    ))->esegui();

    expect($esito->account->id)->toBe($cliente->id)
        ->and($esito->accountNuovo)->toBeFalse()
        // ⚠️ Il `tenant_id` di un utente **esistente** non si tocca: riscriverlo
        // lo strapperebbe al suo Ente per effetto collaterale. La sede nuova la
        // raggiunge con lo switcher.
        ->and($esito->admin->fresh()->tenant_id)->toBe($prima->id);
});
