<?php

use App\Enums\TipoUnitaOrganizzativa;
use App\Livewire\Anagrafica\Albero;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function enteWithAdmin(string $nome = 'Ente A'): array
{
    $ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => $nome]);
    $admin = User::factory()->create([
        'tenant_id' => $ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('Admin');

    return [$ente, $admin];
}

// --- Accesso alla rotta ---

it('redirects guests to login', function () {
    $this->get(route('anagrafica.index'))->assertRedirect(route('login'));
});

it('forbids users without the view permission', function () {
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    // nessun ruolo → nessun permesso

    $this->actingAs($user)->get(route('anagrafica.index'))->assertForbidden();
});

it('renders the tree for an admin', function () {
    [$ente, $admin] = enteWithAdmin();

    $this->actingAs($admin)->get(route('anagrafica.index'))
        ->assertOk()
        ->assertSee('Ente A');
});

// --- CRUD ---

it('lets an admin create a dipartimento under the ente', function () {
    [$ente, $admin] = enteWithAdmin();

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('addChild', $ente->id)
        ->assertSet('showForm', true)
        ->assertSet('tipo', TipoUnitaOrganizzativa::Dipartimento->value)
        ->set('nome', 'Diagnostica')
        ->call('save')
        ->assertSet('showForm', false);

    $dip = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Diagnostica')->first();
    expect($dip->parent_id)->toBe($ente->id);
    expect($dip->tipo)->toBe(TipoUnitaOrganizzativa::Dipartimento);
    expect($dip->tenant_id)->toBe($ente->id);
});

it('derives sottolaboratorio when creating under a dipartimento', function () {
    [$ente, $admin] = enteWithAdmin();
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => 'Dip']);

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('addChild', $dip->id)
        ->assertSet('tipo', TipoUnitaOrganizzativa::Sottolaboratorio->value)
        ->set('nome', 'Lab 1')
        ->call('save');

    $lab = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Lab 1')->first();
    expect($lab->tipo)->toBe(TipoUnitaOrganizzativa::Sottolaboratorio);
    expect($lab->parent_id)->toBe($dip->id);
});

it('validates the node name', function () {
    [$ente, $admin] = enteWithAdmin();

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('addChild', $ente->id)
        ->set('nome', '')
        ->call('save')
        ->assertHasErrors(['nome' => 'required']);
});

it('lets an admin rename a node', function () {
    [$ente, $admin] = enteWithAdmin();
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => 'Vecchio']);

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('edit', $dip->id)
        ->set('nome', 'Nuovo')
        ->call('save');

    expect($dip->fresh()->nome)->toBe('Nuovo');
});

it('lets an admin rename the ente node', function () {
    [$ente, $admin] = enteWithAdmin('Ente Vecchio');

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('edit', $ente->id)
        ->set('nome', 'Ente Nuovo')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    expect($ente->fresh()->nome)->toBe('Ente Nuovo');
});

it('soft-deletes a leaf node', function () {
    [$ente, $admin] = enteWithAdmin();
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => 'Foglia']);

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('confirmDelete', $dip->id)
        ->assertSet('deletingId', $dip->id)
        ->call('delete')
        ->assertSet('deletingId', null);

    $row = UnitaOrganizzativa::withoutGlobalScopes()->find($dip->id);
    expect($row)->not->toBeNull();
    expect($row->trashed())->toBeTrue();
});

it('blocks deleting a node that has children', function () {
    [$ente, $admin] = enteWithAdmin();
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => 'Padre']);
    UnitaOrganizzativa::factory()->sottolaboratorio()->under($dip)->create(['nome' => 'Figlio']);

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('confirmDelete', $dip->id)
        ->call('delete')
        ->assertSet('notice', 'Elimina prima le unità interne.');

    expect(UnitaOrganizzativa::withoutGlobalScopes()->find($dip->id))->not->toBeNull();
});

it('blocks deleting the ente node', function () {
    [$ente, $admin] = enteWithAdmin();

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('confirmDelete', $ente->id)
        ->call('delete')
        ->assertSet('notice', 'L\'Ente non può essere eliminato.');

    expect(UnitaOrganizzativa::withoutGlobalScopes()->find($ente->id))->not->toBeNull();
});

// --- Isolamento e permessi ---

it('prevents an admin from editing a node of another tenant', function () {
    [$enteA, $adminA] = enteWithAdmin('Ente A');
    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $nodeB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create(['nome' => 'Dip B']);

    expect(fn () => Livewire::actingAs($adminA)->test(Albero::class)->call('edit', $nodeB->id))
        ->toThrow(ModelNotFoundException::class);
});

it('forbids a view-only Tenant from creating nodes', function () {
    [$ente, $admin] = enteWithAdmin();
    $tenant = User::factory()->create(['tenant_id' => $ente->id]);
    $tenant->assignRole('Tenant');

    Livewire::actingAs($tenant)->test(Albero::class)
        ->call('addChild', $ente->id)
        ->assertForbidden();
});

it('shows a Responsabile only its assigned subtree', function () {
    [$ente, $admin] = enteWithAdmin();
    $dip1 = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => 'Reparto Mio']);
    UnitaOrganizzativa::factory()->sottolaboratorio()->under($dip1)->create(['nome' => 'Lab Mio']);
    UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => 'Reparto Altrui']);

    $resp = User::factory()->create(['tenant_id' => $ente->id]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($dip1->id);

    Livewire::actingAs($resp)->test(Albero::class)
        ->assertSee('Reparto Mio')
        ->assertSee('Lab Mio')
        ->assertDontSee('Reparto Altrui');
});

// --- Soglia di obsolescenza sul nodo Ente (S3 punto 9, ADR-014) ---

it('lets an admin change the obsolescence threshold on the Ente', function () {
    [$ente, $admin] = enteWithAdmin();

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('edit', $ente->id)
        ->assertSet('sogliaObsolescenzaAnni', 10)   // default della colonna
        ->set('sogliaObsolescenzaAnni', 15)
        ->call('save')
        ->assertHasNoErrors();

    expect($ente->fresh()->soglia_obsolescenza_anni)->toBe(15);
});

it('validates the obsolescence threshold', function () {
    [$ente, $admin] = enteWithAdmin();

    foreach ([0, 51] as $valore) {
        Livewire::actingAs($admin)->test(Albero::class)
            ->call('edit', $ente->id)
            ->set('sogliaObsolescenzaAnni', $valore)
            ->call('save')
            ->assertHasErrors('sogliaObsolescenzaAnni');
    }

    expect($ente->fresh()->soglia_obsolescenza_anni)->toBe(10);
});

it('shows the threshold field only when editing the Ente', function () {
    [$ente, $admin] = enteWithAdmin();
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create();

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('edit', $ente->id)
        ->assertSee('Soglia obsolescenza')
        ->call('edit', $dip->id)
        ->assertDontSee('Soglia obsolescenza')
        ->assertSet('sogliaObsolescenzaAnni', null);
});

it('never writes the threshold on a node that is not the Ente', function () {
    // Le proprietà Livewire arrivano dal browser: la guardia deve stare sul
    // tipo riletto dal DB, non sullo stato del componente.
    [$ente, $admin] = enteWithAdmin();
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create();

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('edit', $dip->id)
        ->set('sogliaObsolescenzaAnni', 3)
        ->call('save')
        ->assertHasNoErrors();

    expect($dip->fresh()->soglia_obsolescenza_anni)->toBe(10);   // default, intatto
});

// --- Scopribilità: dove nasce un Ente ---
//
// 🧭 L'albero organizza l'INTERNO di un Ente e non ne crea mai uno: `rules()`
// ammette solo Dipartimento e Sottolaboratorio. Un Ente nasce dal provisioning.
// Al livello radice, però, l'intestazione della lista dice «Enti» — e lì non
// c'è nessun pulsante, perché `addChild()` vuole un padre. Da qui la
// segnalazione «come Developer non posso creare Enti»: la pagina era un vicolo
// cieco muto. Questi tre test tengono in piedi il cartello.
//
// ⚠️ Il Developer è il ruolo che ci finisce dentro: `tenant_id` NULL e nessun
// Tecnico → `TenantScope` mette `1 = 0`, quindi zero radici visibili e
// `mount()` non entra in automatico da nessuna parte.

it('sends a provisioner from the empty root of the tree to the platform', function () {
    $developer = User::factory()->create([
        'tenant_id' => null,
        'two_factor_confirmed_at' => now(),
    ]);
    $developer->assignRole('Developer');

    Livewire::actingAs($developer->fresh())
        ->test(Albero::class)
        ->assertSet('currentId', null)
        ->assertSee('Crea un Ente dalla Piattaforma');
});

it('does not dangle the platform link in front of who cannot provision', function () {
    // Stessa posizione — radice, nessun nodo visibile — ma senza la leva:
    // indicare una pagina che risponderebbe 403 sarebbe la seconda strada
    // senza uscita, non la via d'uscita dalla prima.
    $spettatore = User::factory()->create([
        'tenant_id' => null,
        'two_factor_confirmed_at' => now(),
    ]);
    $spettatore->givePermissionTo('unita_organizzativa.view');

    expect($spettatore->fresh()->can('tenants.provision'))->toBeFalse();

    Livewire::actingAs($spettatore->fresh())
        ->test(Albero::class)
        ->assertSet('currentId', null)
        ->assertDontSee('Crea un Ente dalla Piattaforma');
});

it('drops the pointer once inside an Ente, where it would be noise', function () {
    // Il cartello vive alla radice e basta: dentro un Ente la pagina fa già il
    // suo mestiere e il pulsante «Aggiungi dipartimento» c'è.
    //
    // ⚠️ Conseguenza dichiarata: il Superadmin, che ha un Ente proprio
    // (ADR-018), viene portato dentro da `mount()` e il cartello non lo vede
    // mai. Per lui la Piattaforma è già in barra laterale — è il Developer,
    // senza Ente, quello che restava senza indicazioni.
    [$ente, $admin] = enteWithAdmin('Ente Cartello');
    $admin->givePermissionTo('tenants.provision');

    Livewire::actingAs($admin->fresh())
        ->test(Albero::class)
        ->assertSet('currentId', $ente->id)
        ->assertDontSee('Crea un Ente dalla Piattaforma');
});

// --- Soglia di obsolescenza: l'alert non deve poter rompere il salvataggio ---
//
// Dal 27 Ago 2026 salvare una soglia più bassa fa partire l'avviso di
// obsolescenza (ADR-014, `AvvisiObsolescenza`). Questo caso appartiene alla
// sezione «Soglia di obsolescenza» qui sopra e sta in coda solo per non
// riscrivere il file: serve a impedire che l'alert diventi un modo per far
// fallire il gesto che lo innesca. Il comportamento dell'alert vero è misurato
// in `tests/Feature/Notifiche/SogliaObsolescenzaTest.php`.

it('still saves the threshold when no machine crosses the line', function () {
    Notification::fake();

    [$ente, $admin] = enteWithAdmin('Ente Senza Macchine');

    Livewire::actingAs($admin->fresh())
        ->test(Albero::class)
        ->call('edit', $ente->id)
        ->set('sogliaObsolescenzaAnni', 4)
        ->call('save')
        ->assertHasNoErrors();

    expect($ente->fresh()->soglia_obsolescenza_anni)->toBe(4);
    Notification::assertNothingSent();
});

// --- Il cliente aggiunge una sede al proprio contratto (ADR-032) ---
//
// 🔴 Segnalato da Marco il 28 Ago 2026: un cliente deve poter aggiungere Enti
// da sé, entro il tetto del suo piano. Fino a quel giorno un Ente nasceva SOLO
// dal provisioning, cioè da EasyLab.
//
// ⛔ Il permesso è `manage` sul PROPRIO account e non `tenants.provision`:
// quest'ultimo è cross-tenant e nel set bloccato, e darlo all'Admin
// significherebbe dargli la piattaforma. Aggiungere una sede consuma uno slot
// del piano, cioè tocca il contratto — e «questo account è tuo» è esattamente
// la domanda che `AccountPolicy::manage` fa già.

function enteConAdminSuAccount(int $maxEnti = 5): array
{
    $account = Account::factory()->saas()->create();
    $ente = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => 'Sede di Milano']);

    $admin = User::factory()->create([
        'tenant_id' => $ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('Admin');
    $account->membri()->syncWithoutDetaching([$admin->id]);

    return [$account, $ente, $admin];
}

it('lets a customer add a sede of their own, within the plan', function () {
    [$account, $ente, $admin] = enteConAdminSuAccount();

    Livewire::actingAs($admin->fresh())
        ->test(Albero::class)
        ->call('apriNuovaSede')
        ->set('nomeSede', 'Sede di Bergamo')
        ->call('creaSede')
        ->assertHasNoErrors();

    expect($account->fresh()->enti()->pluck('nome'))->toContain('Sede di Bergamo');
});

it('refuses a sede beyond the plan cap, in the action and not only in the view', function () {
    // ⛔ Il tetto si rilegge NELL'AZIONE: fra l'apertura della modale e il
    // salvataggio una sede può essere nata da un'altra scheda. E le property
    // sono pubbliche, quindi `set` + `call` salta del tutto l'apertura.
    [$account, $ente, $admin] = enteConAdminSuAccount();

    // Si riempie il piano fino al tetto.
    while ($account->fresh()->puoAggiungereEnte()) {
        UnitaOrganizzativa::factory()->ente()->perAccount($account)->create();
    }

    Livewire::actingAs($admin->fresh())
        ->test(Albero::class)
        ->set('nomeSede', 'Una di troppo')
        ->call('creaSede')
        ->assertForbidden();

    expect($account->fresh()->enti()->where('nome', 'Una di troppo')->exists())->toBeFalse();
});

it('never lets someone add a sede to an account that is not theirs', function () {
    // Un utente che l'account non ce l'ha (nessuna riga sul pivot) non può
    // aggiungere sedi a nessuno — e l'account si deriva da lui, mai dal browser.
    [$account, $ente, $admin] = enteConAdminSuAccount();
    $account->membri()->detach($admin->id);

    Livewire::actingAs($admin->fresh())
        ->test(Albero::class)
        ->set('nomeSede', 'Abusiva')
        ->call('creaSede')
        ->assertForbidden();

    expect(UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Abusiva')->exists())->toBeFalse();
});

it('says WHY the sede button is gone, with the numbers', function () {
    // 🔴 La prima stesura scriveva solo «Sedi incluse nel piano: esaurite»: chi
    // la leggeva vedeva sparire un bottone senza sapere né a quante sedi avesse
    // diritto né cosa fare per averne di più. Segnalato da Marco il 28 Ago 2026
    // guardando un cliente Free, che di sedi ne ha una sola.
    $account = Account::factory()->create(['piano' => 'free']);
    $ente = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create();

    $admin = User::factory()->create([
        'tenant_id' => $ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('Admin');
    $account->membri()->syncWithoutDetaching([$admin->id]);

    expect($account->fresh()->puoAggiungereEnte())->toBeFalse();

    Livewire::actingAs($admin->fresh())
        ->test(Albero::class)
        // Il numero e il nome del piano, non un generico «esaurite».
        ->assertSee('include')
        ->assertSee('Free')
        // E la via d'uscita.
        ->assertSee('abbonamento');
});

it('actually RENDERS the sede modal when it is opened', function () {
    // 🔴 Il test che mancava, e la sua assenza è costata un difetto in
    // produzione di codice. I tre test precedenti chiamavano `apriNuovaSede`
    // e poi `creaSede` **saltando la vista**: provavano che l'azione funziona,
    // non che l'utente possa raggiungerla. La modale era stata scritta FUORI
    // dal `</div>` di radice del componente, e per Livewire ciò che segue
    // l'elemento radice non esiste — nessun errore, in pagina né in console:
    // si premeva il bottone e non si apriva nulla.
    //
    // ⛔ **E questo test NON è ciò che coglie quel difetto**, dichiarato qui
    // perché il suo nome prometterebbe il contrario. Provato per mutazione:
    // rimettendo la modale fuori dalla radice, questo test resta VERDE — il
    // renderer di prova di Livewire restituisce tutto l'output del Blade,
    // radice o no, mentre il browser vero ne scarta metà. La rete che coglie
    // davvero quel guasto guarda il SORGENTE, e vive in
    // `tests/Feature/RadiceLivewireGuardrailTest.php`.
    //
    // Questo resta perché prova un'altra cosa, che serve comunque: che
    // `apriNuovaSede()` accenda la modale invece di limitarsi a cambiare uno
    // stato che nessuno rende.
    [$account, $ente, $admin] = enteConAdminSuAccount();

    Livewire::actingAs($admin->fresh())
        ->test(Albero::class)
        ->assertDontSee('Nome della sede')
        ->call('apriNuovaSede')
        ->assertSet('showSedeForm', true)
        // ⛔ L'asserzione che conta: il titolo e il campo devono essere in
        // pagina, non solo la property a `true`.
        ->assertSee('Aggiungi una sede')
        ->assertSee('Nome della sede');
});

it('shows the other sedi of the same contract, so a new one is not invisible', function () {
    // 🔴 «Ho creato la sede ma non la vedo da nessuna parte» — Marco, 28 Ago
    // 2026. L'albero è scopato al proprio Ente (ADR-018), quindi una sede
    // sorella NON compare fra i nodi; viveva solo nella tendina dello
    // switcher, che durante un'impersonazione è soppressa apposta.
    [$account, $ente, $admin] = enteConAdminSuAccount();

    UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => 'Sede di Bergamo']);

    Livewire::actingAs($admin->fresh())
        ->test(Albero::class)
        ->assertSee('Altre sedi del tuo contratto')
        ->assertSee('Sede di Bergamo');
});

it('never shows the sedi of a contract that is not yours', function () {
    // ⛔ Il negativo: l'elenco passa da `manage` sul PROPRIO account, e le sedi
    // di un altro contratto non devono comparire nemmeno come nome.
    [$account, $ente, $admin] = enteConAdminSuAccount();

    $altroAccount = Account::factory()->saas()->create();
    UnitaOrganizzativa::factory()->ente()->perAccount($altroAccount)->create(['nome' => 'Sede Altrui']);

    Livewire::actingAs($admin->fresh())
        ->test(Albero::class)
        ->assertDontSee('Sede Altrui');
});
