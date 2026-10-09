<?php

use App\Livewire\Utenti\ElencoUtenti;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\InvitoUtente;
use App\Support\AuditLog;
use App\Support\Tenancy\EntePiattaforma;
use App\Support\Utenti\RuoliAssegnabili;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Un Superadmin ne crea un altro, o promuove una persona (🔗 ADR-048, decisione
 * di Marco del 9 Ott 2026; supera in questo punto ADR-038).
 *
 * 🔴 **È il gesto più potente di tutta l'applicazione**: chi lo riceve governa
 * clienti, piani, ruoli e accessi di chiunque. Per questo contano i negativi —
 * chi NON può conferirlo, dove NON si può conferire, e cosa resta rifiutato
 * anche quando il valore arriva forgiato dal browser.
 *
 * Mondo: l'Ente di piattaforma (EasyLab) con un Superadmin, un Admin, un
 * Tecnico e un Developer; un cliente con il suo Admin e un Tenant.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->piattaforma = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);
    $this->easylab = UnitaOrganizzativa::factory()->ente()->perAccount($this->piattaforma)->create(['nome' => 'EasyLab']);

    $this->cliente = Account::factory()->create(['ragione_sociale' => 'Cliente srl']);
    $this->enteCliente = UnitaOrganizzativa::factory()->ente()->perAccount($this->cliente)->create(['nome' => 'Cliente']);

    $persona = function (string $nome, string $ruolo, UnitaOrganizzativa $ente, ?Account $membroDi = null): User {
        $u = User::factory()->create([
            'name' => $nome,
            'email' => str($nome)->slug().'@esempio.test',
            'tenant_id' => $ente->id,
            'two_factor_confirmed_at' => now(),
            'email_verified_at' => now(),
        ]);
        $u->assignRole($ruolo);
        $membroDi?->aggiungiMembro($u);

        return $u->fresh();
    };

    $this->superadmin = $persona('Sara Superadmin', 'Superadmin', $this->easylab, $this->piattaforma);
    $this->adminEasylab = $persona('Ada Admin', 'Admin', $this->easylab, $this->piattaforma);
    $this->tecnico = $persona('Tito Tecnico', 'Tecnico', $this->easylab);
    $this->developer = $persona('Dario Developer', 'Developer', $this->easylab);

    $this->adminCliente = $persona('Carlo Cliente', 'Admin', $this->enteCliente, $this->cliente);
    $this->tenantCliente = $persona('Tina Tenant', 'Tenant', $this->enteCliente);

    Notification::fake();
});

// ─── La regola ───────────────────────────────────────────────────────────────

it('lets a superadmin confer the role only on the platform ente, and nobody confer the developer', function () {
    $conAnche = ['Admin', 'Responsabile Reparto', 'Tenant', 'Tecnico', 'Superadmin'];
    $soloCliente = ['Admin', 'Responsabile Reparto', 'Tenant', 'Tecnico'];

    expect(RuoliAssegnabili::perEnte($this->superadmin, $this->easylab))->toBe($conAnche)
        ->and(RuoliAssegnabili::perEnte($this->developer, $this->easylab))->toBe($conAnche)
        // 🔴 L'Admin dell'Ente di piattaforma ha `utenti.update`, e non basta.
        ->and(RuoliAssegnabili::perEnte($this->adminEasylab, $this->easylab))->toBe($soloCliente)
        // 🔴 Fuori dall'Ente di piattaforma nemmeno un Superadmin.
        ->and(RuoliAssegnabili::perEnte($this->superadmin, $this->enteCliente))->toBe($soloCliente)
        ->and(RuoliAssegnabili::perEnte($this->adminCliente, $this->enteCliente))->toBe($soloCliente)
        ->and(RuoliAssegnabili::perEnte(null, $this->easylab))->toBe($soloCliente)
        ->and(RuoliAssegnabili::perEnte($this->superadmin, null))->toBe($soloCliente);

    foreach ([$this->superadmin, $this->developer] as $chi) {
        expect(RuoliAssegnabili::perEnte($chi, $this->easylab))->not->toContain('Developer');
    }
});

// ─── Promuovere ──────────────────────────────────────────────────────────────

it('promotes a person of the platform ente to superadmin, and writes who did it', function () {
    $this->actingAs($this->superadmin);

    Livewire::test(ElencoUtenti::class)
        ->call('apriRuolo', $this->tecnico->id)
        ->assertSeeHtml('<option value="Superadmin">')
        ->set('nuovoRuolo', 'Superadmin')
        // L'avviso arriva prima del gesto: da qui il ruolo non si toglie.
        ->assertSeeHtml('data-avviso-superadmin')
        ->call('cambiaRuolo')
        ->assertHasNoErrors()
        ->assertSee('Tito Tecnico ora è Superadmin.')
        ->assertSee('secondo fattore');

    $dopo = $this->tecnico->fresh();

    expect($dopo->getRoleNames()->all())->toBe(['Superadmin'])
        // Come il Superadmin nato dal seeder: membro dell'account di piattaforma.
        ->and($this->piattaforma->membri()->whereKey($dopo->id)->exists())->toBeTrue();

    $riga = Activity::where('log_name', AuditLog::NAME)->where('description', 'Ruolo cambiato')->latest('id')->first();

    expect($riga->causer_id)->toBe($this->superadmin->id)
        ->and($riga->subject_id)->toBe($dopo->id)
        ->and($riga->properties['da'])->toBe(['Tecnico'])
        ->and($riga->properties['a'])->toBe('Superadmin');
});

it('lets the developer do the same', function () {
    $this->actingAs($this->developer);

    Livewire::test(ElencoUtenti::class)
        ->call('apriRuolo', $this->tecnico->id)
        ->set('nuovoRuolo', 'Superadmin')
        ->call('cambiaRuolo')
        ->assertHasNoErrors();

    expect($this->tecnico->fresh()->hasRole('Superadmin'))->toBeTrue();
});

it('promotes the only admin of the platform ente without leaving it uncovered', function () {
    // Un Superadmin sa fare tutto ciò che fa un Admin: la guardia «ultimo
    // Admin» non deve fermare questa promozione, né toglierlo dai membri.
    $this->actingAs($this->superadmin);

    Livewire::test(ElencoUtenti::class)
        ->call('apriRuolo', $this->adminEasylab->id)
        ->set('nuovoRuolo', 'Superadmin')
        ->call('cambiaRuolo')
        ->assertHasNoErrors()
        ->assertSee('Ada Admin ora è Superadmin.');

    expect($this->adminEasylab->fresh()->getRoleNames()->all())->toBe(['Superadmin'])
        ->and($this->piattaforma->membri()->whereKey($this->adminEasylab->id)->exists())->toBeTrue();
});

it('still refuses to turn the only admin into something that cannot administer', function () {
    // La controprova: la guardia «ultimo Admin» resta per ogni altro ruolo.
    $this->actingAs($this->superadmin);

    Livewire::test(ElencoUtenti::class)
        ->call('apriRuolo', $this->adminEasylab->id)
        ->set('nuovoRuolo', 'Tenant')
        ->call('cambiaRuolo')
        ->assertSee("è l'unico Admin di questo Ente");

    expect($this->adminEasylab->fresh()->getRoleNames()->all())->toBe(['Admin']);
});

// ─── Creare ──────────────────────────────────────────────────────────────────

it('invites a new superadmin, who chooses their own password', function () {
    $this->actingAs($this->superadmin);

    Livewire::test(ElencoUtenti::class)
        ->call('apriInvito')
        ->assertSeeHtml('<option value="Superadmin">')
        ->set('nome', 'Nora Nuova')
        ->set('email', 'nora@easylab.test')
        ->set('ruolo', 'Superadmin')
        ->assertSeeHtml('data-avviso-superadmin')
        ->call('invita')
        ->assertHasNoErrors()
        ->assertSee('Invito in consegna a Nora Nuova.')
        ->assertSee('secondo fattore');

    $nuova = User::where('email', 'nora@easylab.test')->firstOrFail();

    expect($nuova->getRoleNames()->all())->toBe(['Superadmin'])
        ->and($nuova->tenant_id)->toBe($this->easylab->id)
        // Mai entrata: la password la sceglie lei dal link dell'invito.
        ->and($nuova->email_verified_at)->toBeNull()
        ->and($this->piattaforma->membri()->whereKey($nuova->id)->exists())->toBeTrue();

    Notification::assertSentTo($nuova, InvitoUtente::class);

    $riga = Activity::where('log_name', AuditLog::NAME)->where('description', 'Persona invitata')->latest('id')->first();

    expect($riga->causer_id)->toBe($this->superadmin->id)
        ->and($riga->properties['ruolo'])->toBe('Superadmin');
});

// ─── I negativi: chi non può, e dove ─────────────────────────────────────────

it('never lets anyone but a superadmin confer the role, even with a forged payload', function (string $chi) {
    // 🔴 `utenti.update` e `utenti.create` li ha anche l'Admin — quello
    // dell'Ente di piattaforma compreso. La tendina non lo offre, e il valore
    // forzato dal browser viene rifiutato dall'azione che scrive.
    $attore = $this->{$chi};
    $bersaglio = $chi === 'adminCliente' ? $this->tenantCliente : $this->tecnico;
    $this->actingAs($attore);

    Livewire::test(ElencoUtenti::class)
        ->call('apriRuolo', $bersaglio->id)
        ->assertDontSeeHtml('<option value="Superadmin">')
        ->set('nuovoRuolo', 'Superadmin')
        ->call('cambiaRuolo')
        ->assertForbidden();

    Livewire::test(ElencoUtenti::class)
        ->call('apriInvito')
        ->assertDontSeeHtml('<option value="Superadmin">')
        ->set('nome', 'Intruso')
        ->set('email', 'intruso@esempio.test')
        ->set('ruolo', 'Superadmin')
        ->call('invita')
        ->assertForbidden();

    expect($bersaglio->fresh()->hasRole('Superadmin'))->toBeFalse()
        ->and(User::withTrashed()->where('email', 'intruso@esempio.test')->exists())->toBeFalse()
        ->and(DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('roles.name', 'Superadmin')->count())->toBe(1);

    Notification::assertNothingSent();
})->with(['adminEasylab', 'adminCliente']);

it('never lets a superadmin confer the role outside the platform ente', function () {
    // Un Superadmin che ha per Ente quello di un cliente: nessuna interfaccia
    // lo produce, ma la regola non deve dipendere dal fatto che non esista.
    $fuoriPosto = User::factory()->create(['tenant_id' => $this->enteCliente->id, 'two_factor_confirmed_at' => now()]);
    $fuoriPosto->assignRole('Superadmin');
    $this->actingAs($fuoriPosto->fresh());

    Livewire::test(ElencoUtenti::class)
        ->call('apriRuolo', $this->tenantCliente->id)
        ->assertDontSeeHtml('<option value="Superadmin">')
        ->set('nuovoRuolo', 'Superadmin')
        ->call('cambiaRuolo')
        ->assertForbidden();

    expect($this->tenantCliente->fresh()->hasRole('Superadmin'))->toBeFalse();
});

it('never confers the developer, not even to a superadmin on the platform ente', function (string $chi) {
    // La chiave di riserva resta di console (🔗 ADR-016): ADR-048 apre il
    // Superadmin, non lei.
    $this->actingAs($this->{$chi});

    Livewire::test(ElencoUtenti::class)
        ->call('apriRuolo', $this->tecnico->id)
        ->assertDontSeeHtml('<option value="Developer">')
        ->set('nuovoRuolo', 'Developer')
        ->call('cambiaRuolo')
        ->assertForbidden();

    Livewire::test(ElencoUtenti::class)
        ->call('apriInvito')
        ->set('nome', 'Intruso')
        ->set('email', 'intruso@esempio.test')
        ->set('ruolo', 'Developer')
        ->call('invita')
        ->assertForbidden();

    expect($this->tecnico->fresh()->hasRole('Developer'))->toBeFalse()
        ->and(User::withTrashed()->where('email', 'intruso@esempio.test')->exists())->toBeFalse();
})->with(['superadmin', 'developer']);

it('gives the role from this page but does not take it away', function () {
    // «Ciò che questa interfaccia non sa togliere» resta vero per chi è già
    // Superadmin: nessun bottone sulla riga, e le azioni forzate rifiutano.
    // Revocarlo resta un gesto di console.
    $this->actingAs($this->superadmin);

    Livewire::test(ElencoUtenti::class)
        ->call('apriRuolo', $this->tecnico->id)
        ->set('nuovoRuolo', 'Superadmin')
        ->call('cambiaRuolo')
        ->assertHasNoErrors();

    Livewire::test(ElencoUtenti::class)->call('apriRuolo', $this->tecnico->id)->assertForbidden();
    Livewire::test(ElencoUtenti::class)->call('confermaCestino', $this->tecnico->id)->assertForbidden();

    Livewire::test(ElencoUtenti::class)
        ->set('nuovoRuolo', 'Tenant')
        ->set('utenteRuolo', $this->tecnico->id)
        ->assertForbidden();

    expect($this->tecnico->fresh()->getRoleNames()->all())->toBe(['Superadmin'])
        ->and($this->tecnico->fresh()->trashed())->toBeFalse();
});

it('demands the second factor from the person who was just promoted', function () {
    $this->tecnico->forceFill(['two_factor_confirmed_at' => null])->save();
    $this->actingAs($this->superadmin);

    Livewire::test(ElencoUtenti::class)
        ->call('apriRuolo', $this->tecnico->id)
        ->set('nuovoRuolo', 'Superadmin')
        ->call('cambiaRuolo')
        ->assertHasNoErrors();

    $this->actingAs($this->tecnico->fresh())->get(route('piattaforma.index'))->assertRedirect(route('settings.security'));
});

// ─── Chi governa la piattaforma senza un Ente proprio ────────────────────────
//
// 🔴 Chiesto da Marco il 9 Ott 2026: «anche il developer deve potere cambiare
// ruolo in superadmin». La regola lo ammetteva già per ruolo, ma il Developer
// nasce **senza Ente**, e la pagina Persone gli rispondeva 403: i test qui
// sopra lo provavano con un Developer che un Ente ce l'ha, cioè con una figura
// che in produzione non esiste.

/** Chi governa la piattaforma com'è davvero: senza Ente e senza account. */
function governanteSenzaEnte(string $ruolo): User
{
    $u = User::factory()->create([
        'name' => 'Gina Governante',
        'email' => 'gina@esempio.test',
        'tenant_id' => null,
        'two_factor_confirmed_at' => now(),
        'email_verified_at' => now(),
    ]);
    $u->assignRole($ruolo);

    return $u->fresh();
}

/** La barra laterale, e non la pagina: i nomi delle voci compaiono anche altrove. */
function menuPrincipaleDi(string $html): string
{
    preg_match('/<nav[^>]*aria-label="Menù principale".*?<\/nav>/s', $html, $blocco);

    expect($blocco)->not->toBeEmpty();

    return $blocco[0];
}

it('gives whoever governs without an ente the people of the platform ente, and only those', function (string $ruolo) {
    $this->actingAs(governanteSenzaEnte($ruolo));

    $this->get(route('utenti.index'))
        ->assertOk()
        ->assertSee('Sara Superadmin')
        ->assertSee('Tito Tecnico')
        // 🔴 «Senza Ente» non diventa «di tutti»: le persone di un cliente no.
        ->assertDontSee('Carlo Cliente')
        ->assertDontSee('Tina Tenant');
})->with(['Developer', 'Superadmin']);

it('lets a developer without an ente promote a person to superadmin, in their own name', function () {
    $developer = governanteSenzaEnte('Developer');
    $this->actingAs($developer);

    Livewire::test(ElencoUtenti::class)
        ->call('apriRuolo', $this->tecnico->id)
        ->assertSeeHtml('<option value="Superadmin">')
        ->set('nuovoRuolo', 'Superadmin')
        ->assertSeeHtml('data-avviso-superadmin')
        ->call('cambiaRuolo')
        ->assertHasNoErrors()
        ->assertSee('Tito Tecnico ora è Superadmin.');

    expect($this->tecnico->fresh()->getRoleNames()->all())->toBe(['Superadmin'])
        ->and($this->piattaforma->membri()->whereKey($this->tecnico->id)->exists())->toBeTrue();

    // Il motivo per cui non basta «impersona un Superadmin e fallo da lì»: nel
    // registro il gesto deve portare il nome di chi l'ha compiuto.
    $riga = Activity::where('log_name', AuditLog::NAME)->where('description', 'Ruolo cambiato')->latest('id')->first();

    expect($riga->causer_id)->toBe($developer->id)
        ->and($riga->properties['a'])->toBe('Superadmin');
});

it('lets a developer without an ente invite a superadmin, who lands on the platform ente', function () {
    $this->actingAs(governanteSenzaEnte('Developer'));

    Livewire::test(ElencoUtenti::class)
        ->call('apriInvito')
        ->assertSeeHtml('<option value="Superadmin">')
        ->set('nome', 'Nora Nuova')
        ->set('email', 'nora@easylab.test')
        ->set('ruolo', 'Superadmin')
        ->call('invita')
        ->assertHasNoErrors()
        ->assertSee('Invito in consegna a Nora Nuova.');

    $nuova = User::where('email', 'nora@easylab.test')->firstOrFail();

    // 🔴 Sull'Ente di EasyLab, non «senza Ente» come chi l'ha invitata: un
    // Superadmin senza Ente non comparirebbe in questa stessa pagina.
    expect($nuova->getRoleNames()->all())->toBe(['Superadmin'])
        ->and($nuova->tenant_id)->toBe($this->easylab->id)
        ->and($this->piattaforma->membri()->whereKey($nuova->id)->exists())->toBeTrue();

    Notification::assertSentTo($nuova, InvitoUtente::class);
});

it('never lets a developer without an ente touch a person of a customer from here', function () {
    // Il confine resta l'Ente di EasyLab: fuori c'è un 404, come per chiunque.
    // Dai clienti il Developer entra impersonando, e resta scritto.
    $this->actingAs(governanteSenzaEnte('Developer'));

    expect(fn () => Livewire::test(ElencoUtenti::class)->call('apriRuolo', $this->tenantCliente->id))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => Livewire::test(ElencoUtenti::class)->call('confermaCestino', $this->tenantCliente->id))
        ->toThrow(ModelNotFoundException::class);

    expect($this->tenantCliente->fresh()->getRoleNames()->all())->toBe(['Tenant']);
});

it('still gives no people to anyone else without an ente, whatever they were granted', function (string $ruolo) {
    // 🔴 Tecnici e gestori di piattaforma nascono senza Ente (🔗 ADR-030), e
    // l'editor dei ruoli può dare `utenti.*` a chiunque: l'eccezione guarda il
    // ruolo, non il permesso. Un Admin rimasto senza Ente è lo stesso caso.
    $u = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $u->assignRole($ruolo);
    $u->givePermissionTo(['utenti.view', 'utenti.create', 'utenti.update', 'utenti.delete']);

    expect(EntePiattaforma::perChiGoverna($u->fresh()))->toBeNull();

    $risposta = $this->actingAs($u->fresh())->get(route('utenti.index'));

    $risposta->assertForbidden();
    expect($risposta->getContent())->not->toContain('Sara Superadmin');
})->with(['Tecnico', 'Gestore', 'Admin', 'Responsabile Reparto']);

it('chooses the platform ente by its account, never by being the first one', function () {
    // Il flag scambiato: EasyLab diventa un cliente qualunque, e a essere «la
    // piattaforma» è l'Ente nato DOPO. Una scelta fatta sull'ordine, o senza
    // guardare l'account, continuerebbe a rispondere col primo.
    $this->piattaforma->forceFill(['di_piattaforma' => false])->save();
    $this->cliente->forceFill(['di_piattaforma' => true])->save();

    expect($this->enteCliente->id)->toBeGreaterThan($this->easylab->id)
        ->and(EntePiattaforma::id())->toBe($this->enteCliente->id);

    $this->actingAs(governanteSenzaEnte('Developer'))
        ->get(route('utenti.index'))
        ->assertOk()
        ->assertSee('Carlo Cliente')
        ->assertDontSee('Sara Superadmin');
});

it('takes the first ente of the platform when it has more than one, and says so in the query', function () {
    // Una seconda sede di EasyLab: vale la prima, quella su cui il seeder fa
    // nascere il Superadmin.
    UnitaOrganizzativa::factory()->ente()->perAccount($this->piattaforma)->create(['nome' => 'EasyLab Sud']);

    DB::enableQueryLog();
    $id = EntePiattaforma::id();
    $sql = collect(DB::getQueryLog())->pluck('query')
        ->first(fn (string $q) => str_contains($q, 'from "unita_organizzativa"'));
    DB::disableQueryLog();

    expect($id)->toBe($this->easylab->id)
        // ⚠️ Sull'SQL e non solo sul dato: senza `order by` SQLite restituisce
        // comunque la prima riga, Postgres quella che il piano gli fa trovare.
        ->and($sql)->toContain('order by "id" asc');
});

it('has no platform ente to offer when there is none, and answers 403', function (string $caso) {
    match ($caso) {
        'nessun account di piattaforma' => $this->piattaforma->forceFill(['di_piattaforma' => false])->save(),
        'account di piattaforma cestinato' => $this->piattaforma->delete(),
        'ente di piattaforma cestinato' => $this->easylab->delete(),
    };

    // Gli Enti dei clienti ci sono ancora, e nessuno di loro fa da ripiego.
    expect(EntePiattaforma::id())->toBeNull()
        ->and(EntePiattaforma::perChiGoverna(null))->toBeNull();

    $this->actingAs(governanteSenzaEnte('Developer'))->get(route('utenti.index'))->assertForbidden();
})->with(['nessun account di piattaforma', 'account di piattaforma cestinato', 'ente di piattaforma cestinato']);

it('keeps whoever has an ente on their own ente, even when they govern the platform', function () {
    // L'Ente di piattaforma è un ripiego per chi non ne ha uno, non una
    // precedenza: un Superadmin che ha per Ente quello di un cliente vede le
    // persone di quel cliente, come prima.
    $fuoriPosto = User::factory()->create(['tenant_id' => $this->enteCliente->id, 'two_factor_confirmed_at' => now()]);
    $fuoriPosto->assignRole('Superadmin');

    $this->actingAs($fuoriPosto->fresh())
        ->get(route('utenti.index'))
        ->assertOk()
        ->assertSee('Carlo Cliente')
        ->assertDontSee('Sara Superadmin');
});

it('shows the way to the platform people only to whoever governs without an ente', function () {
    $voce = 'Persone di EasyLab';

    // Chi governa senza Ente: la voce c'è, e porta alla pagina Persone.
    foreach (['Developer', 'Superadmin'] as $ruolo) {
        $u = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        $menu = menuPrincipaleDi($this->actingAs($u->fresh())->get(route('piattaforma.index'))->assertOk()->getContent());

        expect($menu)->toContain($voce)
            ->and($menu)->toContain(route('utenti.index'));
    }

    // Chi un Ente ce l'ha trova «Persone», la sua: mai due voci per la stessa pagina.
    $menu = menuPrincipaleDi($this->actingAs($this->superadmin)->get(route('piattaforma.index'))->assertOk()->getContent());

    expect($menu)->not->toContain($voce)
        ->and(substr_count($menu, route('utenti.index')))->toBe(1);

    // Un gestore di piattaforma, senza Ente e con `utenti.view` dato a mano: niente.
    $gestore = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $gestore->assignRole('Gestore');
    $gestore->givePermissionTo('utenti.view');

    $menu = menuPrincipaleDi($this->actingAs($gestore->fresh())->get(route('dashboard'))->assertOk()->getContent());

    expect($menu)->not->toContain($voce)
        ->and($menu)->not->toContain(route('utenti.index'));
});

it('hides the way to the platform people from a governor who may not see people', function () {
    // L'editor dei ruoli può togliere `utenti.view` al Superadmin: la voce
    // segue il permesso, come ogni altra della barra.
    Role::findByName('Superadmin')->revokePermissionTo('utenti.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $u = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $u->assignRole('Superadmin');

    $menu = menuPrincipaleDi($this->actingAs($u->fresh())->get(route('piattaforma.index'))->assertOk()->getContent());

    expect($menu)->not->toContain('Persone di EasyLab')
        ->and($menu)->not->toContain(route('utenti.index'));

    $this->get(route('utenti.index'))->assertForbidden();
});
