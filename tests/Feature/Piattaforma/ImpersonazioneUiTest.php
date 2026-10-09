<?php

use App\Livewire\Piattaforma\Cabina;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * L'impersonazione dalla cabina di regia (S6 — Wireframe §4, DS §5.8).
 *
 * Si impersona **una persona, non un contratto**: l'Account non ha sessione né
 * permessi. Il rischio di questo blocco non è l'accesso negato — è quello
 * concesso a chi non doveva, e concesso in silenzio.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superadmin = User::factory()->create(['name' => 'Direzione EasyLab', 'two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');

    $this->cliente = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->sede = UnitaOrganizzativa::factory()->ente()->perAccount($this->cliente)->create(['nome' => 'Sede di Milano']);

    $this->admin = User::factory()->create(['name' => 'Anna Bianchi', 'tenant_id' => $this->sede->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
    $this->cliente->aggiungiMembro($this->admin);

    $this->actingAs($this->superadmin->fresh());
});

// --- Negativi ---

it('never offers the Developer, and refuses them by redirect rather than by 403', function () {
    // ⚠️ **Il rifiuto di lab404 non è un 403.** In `ImpersonateController::take()`
    // un `canBeImpersonated()` falso non aborta: cade fuori dall'`if` e fa
    // `redirect()->back()`. Con `take_redirect_to => '/'` e nessun referer,
    // **successo e rifiuto sono risposte identiche** — quindi un test scritto
    // come `assertForbidden()` fallirebbe, e la via di minor resistenza sarebbe
    // «aggiustare» `canBeImpersonated()`, cioè rompere la guardia per far
    // passare il test. Si asserisce sullo **stato della sessione**.
    $developer = User::factory()->create(['name' => 'Il Developer', 'tenant_id' => $this->sede->id]);
    $developer->assignRole('Developer');
    $this->cliente->aggiungiMembro($developer);

    // ⚠️ Asserito **dentro la scelta**, dove i nomi si vedono. La prima stesura
    // guardava la tabella: lì un candidato solo rende un link col nome nel
    // `title`, ma due rendono un bottone «Impersona (2)» **senza nomi** — quindi
    // togliere il filtro faceva sparire il nome invece di mostrarlo, e il test
    // restava verde proprio nel caso che doveva prendere. Provato per mutazione.
    Livewire::test(Cabina::class)
        ->call('apriScelta', $this->cliente->id)
        ->assertSee('Anna Bianchi')
        ->assertDontSee('Il Developer');

    $this->get(route('impersonate', $developer));

    expect(app('impersonate')->isImpersonating())->toBeFalse()
        ->and(auth()->id())->toBe($this->superadmin->id);
});

it('never offers whoever is already looking at the page', function () {
    // Il Superadmin è membro del proprio account: impersonare sé stessi non è un
    // gesto, e lab404 lo accetterebbe.
    $mio = Account::factory()->create(['ragione_sociale' => 'EasyLab Clienti']);
    $mio->aggiungiMembro($this->superadmin);
    UnitaOrganizzativa::factory()->ente()->perAccount($mio)->create();

    $candidati = Livewire::test(Cabina::class)->viewData('candidatiPerAccount');

    expect($candidati[$mio->id]->pluck('id')->all())->not->toContain($this->superadmin->id);
});

it('offers nobody to whoever is already impersonating', function () {
    // Il caso è raggiungibile: un Developer che impersona un Superadmin **arriva
    // alla cabina**, perché `@can` e il `can:` di rotta interrogano
    // l'impersonato, che `tenants.view_all` ce l'ha. Senza questo filtro si
    // troverebbe un pulsante «Impersona» che porta a un 403 secco del
    // pacchetto, fuori da qualsiasi UI e senza spiegazione.
    $developer = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $developer->assignRole('Developer');

    $this->actingAs($developer)->get(route('impersonate', $this->superadmin));

    expect(app('impersonate')->isImpersonating())->toBeTrue();

    $this->get(route('piattaforma.index'))->assertOk();

    Livewire::test(Cabina::class)
        ->assertSee('Gruppo Rossi')
        ->assertDontSee('Impersona');
});

it('refuses the member choice to whoever may see the page but not impersonate', function () {
    // 🔴 `tenants.view_all` e `utenti.impersonate` sono permessi **diversi**:
    // stare nella cabina non è poter entrare in casa di un cliente. La porta
    // di `VistaPiattaforma` gata sul primo, quindi senza una verifica nell'azione
    // la sola guardia vivrebbe nel `@can` del Blade — il posto più facile da
    // aggirare — e la modale raggiungerebbe nome ed email dei membri.
    $osservatore = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $osservatore->givePermissionTo('tenants.view_all');

    $this->actingAs($osservatore->fresh());

    // ⚠️ Livewire **traduce** `AuthorizationException` in una risposta 403
    // invece di lasciarla salire: asserire `toThrow()` qui darebbe un test che
    // non può fallire, perché l'eccezione attesa non arriva mai al chiamante.
    Livewire::test(Cabina::class)
        ->call('apriScelta', $this->cliente->id)
        ->assertForbidden();

    Livewire::test(Cabina::class)
        ->set('sceltaImpersonazione', $this->cliente->id)
        ->assertForbidden();

    // E la modale non si apre comunque: la property resta al suo posto.
    expect(Livewire::test(Cabina::class)->get('sceltaImpersonazione'))->toBeNull();
});

it('keeps the open choice pointing at its customer when the list changes underneath', function () {
    // La modale si rilegge dalla porta, non dalla pagina: filtrando con la
    // scelta aperta, pescarla dall'elenco lascerebbe a schermo un guscio col
    // titolo troncato («Impersona un membro di ») e la lista vuota. È uno stato
    // che si raggiunge da soli — si apre, si ripensa, si digita nella ricerca.
    $secondo = User::factory()->create(['name' => 'Bruno Neri', 'tenant_id' => $this->sede->id]);
    $secondo->assignRole('Tenant');
    $this->cliente->aggiungiMembro($secondo);

    Livewire::test(Cabina::class)
        ->call('apriScelta', $this->cliente->id)
        ->set('search', 'zzz-nessun-cliente')
        ->assertSee('Impersona un membro di Gruppo Rossi')
        ->assertSee('Anna Bianchi')
        ->assertSee('Nessun cliente con questi filtri');
});

it('hides the control from whoever may see the page but not impersonate', function () {
    // Le due cose sono permessi distinti: stare nella cabina non è poter entrare
    // in casa di un cliente. Un utente con il solo `tenants.view_all` esiste il
    // giorno in cui i permessi si modificano da UI (S6, editor ruoli).
    $osservatore = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $osservatore->givePermissionTo('tenants.view_all');

    $this->actingAs($osservatore->fresh());

    Livewire::test(Cabina::class)
        ->assertSee('Gruppo Rossi')
        ->assertDontSee('Impersona');
});

it('refuses to open the member choice for an account that does not exist or is in the bin', function () {
    // ⚠️ Fino al 9 Ott 2026 questo test rifiutava anche l'account di
    // piattaforma, cioè teneva fermo il difetto: la striscia di EasyLab offre
    // «Impersona (N)» e quel bottone rispondeva 404 (vedi il test qui sotto).
    // Resta rifiutato ciò che la porta non restituisce: un id inventato e un
    // cliente cestinato.
    $cestinato = Account::factory()->create(['ragione_sociale' => 'Cliente Cestinato']);
    $cestinato->delete();

    foreach ([999_999, $cestinato->id] as $id) {
        expect(fn () => Livewire::test(Cabina::class)->call('apriScelta', $id))
            ->toThrow(ModelNotFoundException::class);

        // E per l'altra strada, la property, che Livewire accetta dal browser.
        expect(fn () => Livewire::test(Cabina::class)->set('sceltaImpersonazione', $id))
            ->toThrow(ModelNotFoundException::class);
    }
});

/**
 * EasyLab con due Superadmin e un Developer fra i membri: il caso in cui la
 * striscia della cabina rende il bottone «Impersona (2)» e non il link diretto.
 *
 * @return array{account: Account, primo: User, secondo: User, developer: User}
 */
function piattaformaConDueSuperadmin(): array
{
    $account = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);
    $ente = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => 'EasyLab']);

    $persona = function (string $nome, string $ruolo) use ($account, $ente): User {
        $u = User::factory()->create(['name' => $nome, 'tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);
        $account->aggiungiMembro($u);

        return $u->fresh();
    };

    return [
        'account' => $account,
        'primo' => $persona('Sara Prima', 'Superadmin'),
        'secondo' => $persona('Sergio Secondo', 'Superadmin'),
        'developer' => $persona('Dora Developer', 'Developer'),
    ];
}

it('opens the member choice of the platform account, which is how a Developer reaches a Superadmin', function () {
    // 🔴 Segnalato da Marco il 9 Ott 2026: «se da developer faccio
    // impersonificazione verso superadmin ottengo errore 404». Con un solo
    // Superadmin la striscia rende un link diretto e il difetto non si vede:
    // è comparso il giorno in cui i Superadmin sono diventati due (ADR-048).
    $mondo = piattaformaConDueSuperadmin();

    $developer = User::factory()->create(['name' => 'Il Developer']);
    $developer->assignRole('Developer');
    $this->actingAs($developer->fresh());

    Livewire::test(Cabina::class)
        // Il bottone che la pagina offre davvero, con l'id che manda.
        ->assertSeeHtml('wire:click="apriScelta('.$mondo['account']->id.')"')
        ->assertSee('Impersona (2)')
        ->call('apriScelta', $mondo['account']->id)
        ->assertSet('sceltaImpersonazione', $mondo['account']->id)
        ->assertSee('Impersona un membro di EasyLab')
        ->assertSeeHtml(route('impersonate', $mondo['primo']))
        ->assertSeeHtml(route('impersonate', $mondo['secondo']))
        // Il Developer resta l'unico che non si impersona, nemmeno da qui.
        ->assertDontSeeHtml(route('impersonate', $mondo['developer']))
        ->assertDontSee('Dora Developer');

    // L'altra strada, la property.
    Livewire::test(Cabina::class)
        ->set('sceltaImpersonazione', $mondo['account']->id)
        ->assertSee('Impersona un membro di EasyLab')
        ->assertSee('Sergio Secondo');

    // E il gesto arriva in fondo: si entra come il Superadmin scelto.
    $this->get(route('impersonate', $mondo['secondo']));

    expect(app('impersonate')->isImpersonating())->toBeTrue()
        ->and(auth()->id())->toBe($mondo['secondo']->id);
});

it('still keeps the platform members away from whoever may see the page but not impersonate', function () {
    // 🔴 Il negativo della riga sopra: aprire la porta all'account di
    // piattaforma non deve aprirla a chi ha il solo `tenants.view_all`. Dietro
    // quella modale ci sono nome ed email di chi governa la piattaforma.
    $mondo = piattaformaConDueSuperadmin();

    $osservatore = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $osservatore->givePermissionTo('tenants.view_all');
    $this->actingAs($osservatore->fresh());

    Livewire::test(Cabina::class)
        ->assertDontSee('Sara Prima')
        ->assertDontSee('Impersona')
        ->call('apriScelta', $mondo['account']->id)
        ->assertForbidden();

    Livewire::test(Cabina::class)
        ->set('sceltaImpersonazione', $mondo['account']->id)
        ->assertForbidden();
});

it('never lists a member of another customer in the choice', function () {
    $altro = Account::factory()->create(['ragione_sociale' => 'Bianchi SRL']);
    $sedeAltro = UnitaOrganizzativa::factory()->ente()->perAccount($altro)->create();
    // Due membri e non uno: con un solo candidato la sua riga renderizza il link
    // diretto, che porta il nome nel `title` — e l'asserzione qui sotto
    // passerebbe o fallirebbe per il tooltip di un'altra riga invece che per il
    // contenuto della modale, che è la cosa in prova.
    foreach (['Carlo Verdi', 'Dina Gialli'] as $nome) {
        $suo = User::factory()->create(['name' => $nome, 'tenant_id' => $sedeAltro->id]);
        $suo->assignRole('Admin');
        $altro->aggiungiMembro($suo);
    }

    $secondo = User::factory()->create(['name' => 'Bruno Neri', 'tenant_id' => $this->sede->id]);
    $secondo->assignRole('Tenant');
    $this->cliente->aggiungiMembro($secondo);

    Livewire::test(Cabina::class)
        ->call('apriScelta', $this->cliente->id)
        ->assertSee('Anna Bianchi')
        ->assertSee('Bruno Neri')
        ->assertDontSee('Carlo Verdi');
});

// --- Positivi ---

it('offers a direct link when the customer has one member', function () {
    Livewire::test(Cabina::class)
        ->assertSee(route('impersonate', $this->admin))
        ->assertSee('Impersona');
});

it('lets a Superadmin be impersonated: only the Developer is untouchable', function () {
    $developer = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $developer->assignRole('Developer');

    $this->actingAs($developer)->get(route('impersonate', $this->superadmin));

    expect(app('impersonate')->isImpersonating())->toBeTrue()
        ->and(auth()->id())->toBe($this->superadmin->id);
});

it('says both names in the banner, not just the impersonated one', function () {
    // Chi impersona lo sa. Non lo sa il collega davanti allo stesso schermo, né
    // chi legge lo screenshot allegato a un ticket sei mesi dopo — dove il nome
    // che manca è l'unico che serve per ricostruire il gesto.
    $this->get(route('impersonate', $this->admin));

    // ⚠️ **Asserito sulla frase composta, non sui tre nomi sciolti.** Ognuno di
    // essi compare anche altrove nel guscio — «Anna Bianchi» nel menù utente,
    // che durante l'impersonazione mostra già l'impersonato, e «Sede di Milano»
    // nello switcher Ente in top bar. Con `assertSee` separati il test era verde
    // anche sostituendo l'intero contenuto del banner: provato per mutazione.
    $this->get('/dashboard')
        ->assertOk()
        ->assertSeeInOrder(['Stai impersonando', 'Anna Bianchi', 'Sede di Milano', 'sei', 'Direzione EasyLab'], false);
});

it('takes the platform away from the impersonator, with no guard of its own', function () {
    // Effetto voluto e non presidiato: `@can` interroga `Auth::user()`, che
    // lab404 ha **sostituito**. Non serve una guardia in più — serve un test che
    // dimostri che non serve, perché il giorno in cui lab404 cambiasse strada
    // questa è l'unica cosa che se ne accorgerebbe.
    $this->get(route('impersonate', $this->admin));

    $this->get('/dashboard')->assertOk()->assertDontSee(route('piattaforma.index'));
    $this->get(route('piattaforma.index'))->assertForbidden();
});

it('keeps the candidate lookup flat from one customer to twelve', function () {
    // `canBeImpersonated()` chiama `hasRole()`, che carica la relazione `roles`:
    // senza l'eager load è un N+1 **mascherato da `filter()` in PHP**, cioè uno
    // di quelli che non si vedono leggendo il codice.
    Livewire::test(Cabina::class);

    $conta = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(Cabina::class);
        $n = collect(DB::getQueryLog())
            // Due tabelle e non tre: il pivot entra come `inner join
            // "account_user"`, mai come `from` — una clausola che non matcha mai
            // dà al conteggio una precisione che non ha.
            ->filter(fn ($q) => str_contains($q['query'], 'from "users"')
                || str_contains($q['query'], 'from "roles"'))
            ->count();
        DB::disableQueryLog();

        return $n;
    };

    $conUno = $conta();

    for ($i = 0; $i < 11; $i++) {
        $account = Account::factory()->create();
        $sede = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create();
        $membro = User::factory()->create(['tenant_id' => $sede->id]);
        $membro->assignRole('Admin');
        $account->aggiungiMembro($membro);
    }

    expect($conta())->toBe($conUno);
});
