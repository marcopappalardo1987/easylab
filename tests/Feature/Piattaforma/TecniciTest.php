<?php

use App\Livewire\Piattaforma\Tecnici;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Utenti\Assegnabili;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/**
 * 🔴 `/piattaforma/tecnici` — le persone di EasyLab e i clienti su cui lavorano
 * (🔗 ADR-038, che attua la metà scoperta di 🔗 ADR-007/030).
 *
 * È la prima interfaccia del progetto che scrive `tecnico_cliente`, e quel pivot
 * **non è una nota organizzativa**: una riga in più apre a una persona tutte le
 * macchine di una sede di un cliente (`AccessoTecnico`) e la fa comparire nella
 * tendina «Assegnatario» di quel cliente (`Assegnabili`). Il rischio di questa
 * pagina non è la colonna storta — è la riga di pivot scritta per l'utente
 * sbagliato, o per una sede che non doveva essere offerta.
 *
 * ⛔ **E non c'è nessun global scope a fare da rete**: 🔗 ADR-018 esente `users`
 * dal `TenantScope`, quindi il confine «è un tecnico di EasyLab» è scritto a
 * mano (`tenant_id IS NULL` + ruolo `Tecnico`). Metà di questo file esiste per
 * provare che quel confine tiene contro gli id che arrivano dal browser.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // ⚠️ **Niente `->fresh()`**: `re-authorizes every action` revoca il ruolo a
    // pagina già aperta, e per farlo ha bisogno che `auth()->user()` sia
    // *questa* istanza, non una sua copia rimasta con la relazione caricata.
    $this->superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $this->actingAs($this->superadmin);

    $this->ospedale = Account::factory()->create(['ragione_sociale' => 'Ospedale San Marco']);
    $this->sanMarco = UnitaOrganizzativa::factory()->ente()->perAccount($this->ospedale)
        ->create(['nome' => 'Sede di Milano']);

    $this->laboratorio = Account::factory()->create(['ragione_sociale' => 'Laboratori Bianchi']);
    $this->bergamo = UnitaOrganizzativa::factory()->ente()->perAccount($this->laboratorio)
        ->create(['nome' => 'Sede di Bergamo']);
});

/** Un tecnico di EasyLab già in tabella: ruolo `Tecnico`, nessun Ente. */
function tecnicoDiEasyLab(string $nome, ?string $email = null): User
{
    $tecnico = User::factory()->create([
        'name' => $nome,
        'email' => $email ?? str($nome)->slug().'@easylab.test',
        'tenant_id' => null,
        'email_verified_at' => now(),
    ]);
    $tecnico->assignRole(User::TECNICO_ROLE);

    return $tecnico->fresh();
}

/** Le righe di pivot di un tecnico, lette senza passare da Eloquent. */
function portafoglioDi(User $tecnico): array
{
    return DB::table('tecnico_cliente')->where('tecnico_id', $tecnico->id)
        ->orderBy('ente_id')->pluck('ente_id')->map(fn ($id) => (int) $id)->all();
}

// ─── Chi entra, e chi no ─────────────────────────────────────────────────────

it('opens the page to whoever holds tenants.view_all', function () {
    $this->get(route('piattaforma.tecnici'))
        ->assertOk()
        // La stringa del **corpo**, non «Tecnici»: quella parola la stampa la
        // sub-nav su ogni pagina di piattaforma, quindi un'asserzione su di lei
        // direbbe soltanto che si è atterrati da qualche parte.
        ->assertSee('Le persone di EasyLab che lavorano sulle macchine dei clienti');
});

it('refuses the page to every role without tenants.view_all', function (string $ruolo) {
    $this->actingAs(utenteConRuolo($ruolo));

    $this->get(route('piattaforma.tecnici'))->assertForbidden();
})->with(RUOLI_SENZA_PIATTAFORMA);

it('refuses even the component to whoever lacks the permission', function () {
    // Il gate di rotta non copre tutto: chi montasse il componente altrove
    // arriverebbe comunque alla porta di `VistaPiattaforma`.
    $this->actingAs(utenteConRuolo('Admin'));

    // ⚠️ Livewire non lascia salire l'eccezione dal primo render: la passa
    // all'handler, che la rende un **403**. Asserire su `toThrow()` qui darebbe
    // un test verde solo per il tipo di errore sbagliato.
    Livewire::test(Tecnici::class)->assertForbidden();
});

it('re-authorizes every action, so a permission revoked after the page was opened stops the write', function () {
    // 🔴 La prova che le azioni **riautorizzano** invece di fidarsi del gate di
    // rotta. Senza il `Gate::authorize()` dentro `risolviTecnico()` la
    // cancellazione avverrebbe comunque — l'eccezione arriverebbe dopo, dal
    // render, e la riga sarebbe già nel cestino.
    $tecnico = tecnicoDiEasyLab('Luca Ferri');

    $pagina = Livewire::test(Tecnici::class);

    $this->superadmin->removeRole('Superadmin');
    $this->superadmin->unsetRelation('roles');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    try {
        $pagina->call('cestina', $tecnico->id);
    } catch (AuthorizationException) {
        // Attesa: il render la solleva comunque. Ciò che si misura è la riga.
    }

    expect($tecnico->fresh()->trashed())->toBeFalse();
});

// ─── L'invito, e la forma «esterna» ──────────────────────────────────────────

it('creates a technician with no Ente, which is exactly what makes them assignable', function () {
    // 🔴 Il test che lega le due metà di ADR-030: la **scrittura** (ruolo
    // Tecnico, `tenant_id` NULL) e la **lettura** che la consuma
    // (`Assegnabili::perSede`). Un tecnico creato dentro l'Ente EasyLab
    // passerebbe le prime due asserzioni e fallirebbe la terza — ed è
    // precisamente il difetto che nessuno noterebbe fino alla tendina vuota.
    Livewire::test(Tecnici::class)
        ->call('apriInvito')
        ->set('nuovo.nome', '  Giulia Neri  ')
        ->set('nuovo.email', '  Giulia.Neri@easylab.test ')
        ->call('invitaTecnico')
        ->assertHasNoErrors();

    $tecnico = User::where('email', 'giulia.neri@easylab.test')->firstOrFail();

    expect($tecnico->name)->toBe('Giulia Neri')
        ->and($tecnico->tenant_id)->toBeNull()
        ->and($tecnico->hasRole(User::TECNICO_ROLE))->toBeTrue()
        // «Invitato, mai entrato» non è una colonna: è ADR-012.
        ->and($tecnico->email_verified_at)->toBeNull();

    // Non è ancora assegnabile da nessuna parte: il portafoglio è vuoto.
    expect(Assegnabili::perSede($this->sanMarco->id)->pluck('id')->all())->not->toContain($tecnico->id);

    $tecnico->portafoglioClienti()->attach($this->sanMarco->id);

    expect(Assegnabili::perSede($this->sanMarco->id)->pluck('id')->all())->toContain($tecnico->id);
});

it('never confers a platform role, even when the payload is forged', function (string $ruolo) {
    // 🔴 Superadmin e Developer non sono conferibili da **nessuna** interfaccia
    // (ADR-038): il rifiuto vive nel codice, non nell'assenza da una tendina —
    // che è a un `$wire.set()` di distanza.
    $primaDelleAssegnazioni = DB::table('model_has_roles')->count();

    Livewire::test(Tecnici::class)
        ->call('apriInvito')
        ->set('nuovo.nome', 'Marco Intruso')
        ->set('nuovo.email', 'intruso@easylab.test')
        // ⚠️ Il valore del dataset, **non** «Superadmin» ribattuto a mano: la
        // closure non lo prendeva, quindi il caso `Developer` non è mai stato
        // eseguito e il test girava due volte identico. Un dataset che non
        // varia è un test che non può fallire sul caso che non prova.
        ->set('nuovoRuolo', $ruolo)
        ->call('invitaTecnico')
        ->assertHasErrors('nuovoRuolo');

    expect(User::withTrashed()->where('email', 'intruso@easylab.test')->exists())->toBeFalse()
        ->and(DB::table('model_has_roles')->count())->toBe($primaDelleAssegnazioni);
})->with(['Superadmin', 'Developer']);

it('offers restoring a trashed technician instead of failing the invitation', function () {
    // L'unique su `users.email` è **senza condizione**: una persona cestinata
    // continua a occupare l'indirizzo, e la cosa giusta da offrire è rimetterla
    // in servizio.
    $tecnico = tecnicoDiEasyLab('Sara Verdi', 'sara@easylab.test');
    $tecnico->delete();

    $pagina = Livewire::test(Tecnici::class)
        ->call('apriInvito')
        ->set('nuovo.nome', 'Sara Verdi')
        ->set('nuovo.email', 'sara@easylab.test')
        ->call('invitaTecnico')
        ->assertHasErrors('nuovo.email');

    expect($pagina->get('ripristinabile'))->toBe($tecnico->id);

    $pagina->call('ripristina', $tecnico->id);

    expect($tecnico->fresh()->trashed())->toBeFalse();
});

it('never offers the restore of somebody who is not an EasyLab technician', function () {
    // La stessa email cestinata può essere dell'Admin di un cliente: offrire
    // «ripristina» lì significherebbe rimettere in servizio qualcuno di un altro
    // perimetro, da una pagina che non lo amministra.
    $admin = User::factory()->create(['email' => 'admin@ospedale.test', 'tenant_id' => $this->sanMarco->id]);
    $admin->assignRole('Admin');
    $admin->delete();

    $pagina = Livewire::test(Tecnici::class)
        ->call('apriInvito')
        ->set('nuovo.nome', 'Chiunque')
        ->set('nuovo.email', 'admin@ospedale.test')
        ->call('invitaTecnico')
        ->assertHasErrors('nuovo.email');

    expect($pagina->get('ripristinabile'))->toBeNull();
});

// ─── Il portafoglio ──────────────────────────────────────────────────────────

it('writes and revokes tecnico_cliente, and the customer dropdown follows immediately', function () {
    $tecnico = tecnicoDiEasyLab('Luca Ferri');

    Livewire::test(Tecnici::class)
        ->call('apriPortafoglio', $tecnico->id)
        ->set('portafoglioSedi', [$this->sanMarco->id, $this->bergamo->id])
        ->call('salvaPortafoglio');

    expect(portafoglioDi($tecnico))->toBe([$this->sanMarco->id, $this->bergamo->id]);
    expect(Assegnabili::perSede($this->sanMarco->id)->pluck('id')->all())->toContain($tecnico->id);

    // La revoca ha effetto **subito**: portafoglio e tendina sono sottoquery,
    // non liste in cache.
    Livewire::test(Tecnici::class)
        ->call('apriPortafoglio', $tecnico->id)
        ->set('portafoglioSedi', [$this->bergamo->id])
        ->call('salvaPortafoglio');

    expect(portafoglioDi($tecnico))->toBe([$this->bergamo->id])
        ->and(Assegnabili::perSede($this->sanMarco->id)->pluck('id')->all())->not->toContain($tecnico->id)
        ->and(Assegnabili::perSede($this->bergamo->id)->pluck('id')->all())->toContain($tecnico->id);
});

it('agrees the plural, because one sede is not «1 sedi»', function () {
    // Il messaggio diceva «1 sedi aperte, 0 chiuse» a chi ne spuntava una sola,
    // cioè nel caso più comune e nel gesto che apre a una persona tutte le
    // macchine di un cliente. Nessun test lo copriva: era invisibile.
    $tecnico = tecnicoDiEasyLab('Luca Ferri');

    // Si guarda l'HTML e non la sessione: il messaggio è un flash che la vista
    // rende nella stessa risposta, ed è quello che l'utente legge.
    $una = Livewire::test(Tecnici::class)
        ->call('apriPortafoglio', $tecnico->id)
        ->set('portafoglioSedi', [$this->sanMarco->id])
        ->call('salvaPortafoglio')
        ->html();

    // ⚠️ Un ago per asserzione: `toContain()` è variadico, e un secondo
    // argomento sarebbe un secondo ago e non un messaggio.
    expect($una)->toContain('1 sede in più');
    expect($una)->not->toContain('1 sedi');

    // Il plurale vero, o un test sul solo singolare resterebbe verde anche
    // scrivendo «sede» sempre — cioè proverebbe metà regola.
    $due = Livewire::test(Tecnici::class)
        ->call('apriPortafoglio', $tecnico->id)
        ->set('portafoglioSedi', [$this->sanMarco->id, $this->bergamo->id])
        ->call('salvaPortafoglio')
        ->html();

    expect($due)->toContain('1 sede in più');

    // E la revoca parla di ciò che toglie, senza far leggere uno zero per
    // sapere che dall'altro lato non è successo niente.
    $revoca = Livewire::test(Tecnici::class)
        ->call('apriPortafoglio', $tecnico->id)
        ->set('portafoglioSedi', [])
        ->call('salvaPortafoglio')
        ->html();

    expect($revoca)->toContain('2 sedi in meno');
    expect($revoca)->not->toContain('in più');
});

it('reads the current portfolio from the pivot, not through a scoped relation', function () {
    // ⛔ `portafoglioClienti()` è Eloquent su `UnitaOrganizzativa` e ne applica i
    // global scope: per chi apre questa pagina — che un `tenant_id` proprio ce
    // l'ha — tornerebbe **vuota**, e il salvataggio successivo cancellerebbe
    // tutto senza che nessuno abbia chiesto niente.
    $this->superadmin->forceFill(['tenant_id' => $this->bergamo->id])->save();

    $tecnico = tecnicoDiEasyLab('Luca Ferri');
    $tecnico->portafoglioClienti()->attach($this->sanMarco->id);

    $pagina = Livewire::test(Tecnici::class)->call('apriPortafoglio', $tecnico->id);

    expect($pagina->get('portafoglioSedi'))->toBe([$this->sanMarco->id]);

    // E il salvataggio «senza toccare niente» non deve revocare nulla.
    $pagina->call('salvaPortafoglio');

    expect(portafoglioDi($tecnico))->toBe([$this->sanMarco->id]);
});

it('never writes a pivot row for a sede that does not exist or was trashed', function () {
    $tecnico = tecnicoDiEasyLab('Luca Ferri');

    $cestinata = UnitaOrganizzativa::factory()->ente()->perAccount($this->laboratorio)
        ->create(['nome' => 'Sede chiusa']);
    $cestinata->delete();

    // ⚠️ **Due salvataggi e non uno**: l'id inventato fa violare la FK, quindi
    // in un unico gesto l'eccezione coprirebbe il caso della sede cestinata —
    // che è l'altro, e il più insidioso, perché lì la FK **esiste ancora** (il
    // soft delete non cancella la riga) e l'intersezione con ciò che la porta
    // consegna è l'unica barriera.
    Livewire::test(Tecnici::class)
        ->call('apriPortafoglio', $tecnico->id)
        ->set('portafoglioSedi', [$cestinata->id, $this->sanMarco->id])
        ->call('salvaPortafoglio');

    expect(portafoglioDi($tecnico))->toBe([$this->sanMarco->id]);

    Livewire::test(Tecnici::class)
        ->call('apriPortafoglio', $tecnico->id)
        ->set('portafoglioSedi', [999999, $this->sanMarco->id])
        ->call('salvaPortafoglio');

    expect(portafoglioDi($tecnico))->toBe([$this->sanMarco->id]);
});

it('never silently revokes a portfolio row the page cannot show', function () {
    // Una sede cestinata **già in portafoglio** non è offerta dalla modale: un
    // `sync()` sulle sole caselle spuntate la staccherebbe come effetto
    // collaterale di un salvataggio che riguardava altro.
    $tecnico = tecnicoDiEasyLab('Luca Ferri');

    $cestinata = UnitaOrganizzativa::factory()->ente()->perAccount($this->laboratorio)
        ->create(['nome' => 'Sede chiusa']);
    $tecnico->portafoglioClienti()->attach($cestinata->id);
    $cestinata->delete();

    Livewire::test(Tecnici::class)
        ->call('apriPortafoglio', $tecnico->id)
        ->set('portafoglioSedi', [$this->sanMarco->id])
        ->call('salvaPortafoglio');

    expect(portafoglioDi($tecnico))->toContain($cestinata->id)
        ->and(portafoglioDi($tecnico))->toContain($this->sanMarco->id);
});

it('records grants and revocations in the audit log, by hand', function () {
    // `User` è **esente** dal trait `AuditsDomainWrites` (ADR-027): se queste
    // righe non le scrive l'azione, non le scrive nessuno.
    $tecnico = tecnicoDiEasyLab('Luca Ferri');

    Livewire::test(Tecnici::class)
        ->call('apriPortafoglio', $tecnico->id)
        ->set('portafoglioSedi', [$this->sanMarco->id])
        ->call('salvaPortafoglio');

    Livewire::test(Tecnici::class)
        ->call('apriPortafoglio', $tecnico->id)
        ->set('portafoglioSedi', [])
        ->call('salvaPortafoglio');

    $righe = Activity::where('log_name', AuditLog::NAME)
        ->whereIn('description', ['Clienti conferiti al tecnico', 'Clienti revocati al tecnico'])
        ->orderBy('id')
        ->get();

    expect($righe)->toHaveCount(2)
        ->and($righe[0]->description)->toBe('Clienti conferiti al tecnico')
        ->and($righe[0]->causer_id)->toBe($this->superadmin->id)
        ->and($righe[0]->subject_id)->toBe($tecnico->id)
        ->and($righe[0]->properties['ente_ids'])->toBe([$this->sanMarco->id])
        ->and($righe[1]->description)->toBe('Clienti revocati al tecnico')
        ->and($righe[1]->properties['ente_ids'])->toBe([$this->sanMarco->id]);
});

// ─── Il confine: chi NON è un tecnico di piattaforma ─────────────────────────

it('never touches a user who is not an EasyLab technician', function (string $azione) {
    // ⛔ `users` non ha global scope di tenancy: ciò che il codice non esclude,
    // **entra**. Sono i due modi di non essere un tecnico di EasyLab — non avere
    // il ruolo, o avere un Ente (il tecnico *interno* di ADR-030).
    $adminCliente = User::factory()->create(['tenant_id' => $this->sanMarco->id]);
    $adminCliente->assignRole('Admin');

    $tecnicoInterno = User::factory()->create(['tenant_id' => $this->sanMarco->id]);
    $tecnicoInterno->assignRole(User::TECNICO_ROLE);

    // 🔴 Il terzo caso, quello che le due condizioni del confine coprono una per
    // uno: **nessun Ente ma nemmeno il ruolo**. Senza di lui il `whereHas` si
    // potrebbe togliere e la suite resterebbe verde — e da questa pagina si
    // cestinerebbe un Developer.
    $developer = utenteConRuolo('Developer');

    foreach ([$adminCliente, $tecnicoInterno, $developer] as $estraneo) {
        expect(fn () => Livewire::test(Tecnici::class)->call($azione, $estraneo->id))
            ->toThrow(ModelNotFoundException::class);

        expect($estraneo->fresh()->trashed())->toBeFalse()
            ->and(portafoglioDi($estraneo))->toBe([]);
    }
})->with(['apriPortafoglio', 'cestina', 'ripristina']);

it('never lets a forged portafoglioTecnico point the modal at an outsider', function () {
    // La property è l'altra strada verso la modale: senza l'hook `updating*`
    // l'intestazione porterebbe il nome di quella persona, e il rifiuto
    // arriverebbe solo al salvataggio, a caselle già spuntate.
    $adminCliente = User::factory()->create(['tenant_id' => $this->sanMarco->id]);
    $adminCliente->assignRole('Admin');

    expect(fn () => Livewire::test(Tecnici::class)->set('portafoglioTecnico', $adminCliente->id))
        ->toThrow(ModelNotFoundException::class);

    // E anche forzandola oltre l'hook, la scrittura rilegge dal database.
    expect(fn () => Livewire::test(Tecnici::class)
        ->call('salvaPortafoglio'))->toThrow(ModelNotFoundException::class);

    expect(portafoglioDi($adminCliente))->toBe([]);
});

// ─── Cestino, ripristino, elenco ─────────────────────────────────────────────

it('trashes a technician, who then disappears from the customer dropdown', function () {
    $tecnico = tecnicoDiEasyLab('Luca Ferri');
    $tecnico->portafoglioClienti()->attach($this->sanMarco->id);

    Livewire::test(Tecnici::class)->call('cestina', $tecnico->id);

    expect($tecnico->fresh()->trashed())->toBeTrue()
        // Nessun `if` nuovo: il global scope del soft delete lo toglie da solo.
        ->and(Assegnabili::perSede($this->sanMarco->id)->pluck('id')->all())->not->toContain($tecnico->id)
        // Il portafoglio resta: ripristinarlo deve ridargli ciò che aveva.
        ->and(portafoglioDi($tecnico))->toBe([$this->sanMarco->id]);

    Livewire::test(Tecnici::class)->call('ripristina', $tecnico->id);

    expect($tecnico->fresh()->trashed())->toBeFalse()
        ->and(Assegnabili::perSede($this->sanMarco->id)->pluck('id')->all())->toContain($tecnico->id);
});

it('never lets somebody trash themselves', function () {
    // Oggi la figura elencata qui non è quella che apre la pagina, ma
    // `tenants.view_all` è ridistribuibile a runtime dall'editor dei ruoli.
    $tecnico = tecnicoDiEasyLab('Luca Ferri');
    $tecnico->givePermissionTo(Tecnici::PERMESSO);
    $this->actingAs($tecnico->fresh());

    Livewire::test(Tecnici::class)->call('cestina', $tecnico->id);

    expect($tecnico->fresh()->trashed())->toBeFalse();
});

it('shows each technician state and how many customers they work on', function () {
    $attivo = tecnicoDiEasyLab('Luca Ferri');
    $attivo->portafoglioClienti()->attach([$this->sanMarco->id, $this->bergamo->id]);

    $invitato = tecnicoDiEasyLab('Sara Verdi');
    $invitato->forceFill(['email_verified_at' => null])->save();

    $cestinato = tecnicoDiEasyLab('Marco Rossi');
    $cestinato->delete();

    $pagina = Livewire::test(Tecnici::class)->set('conCestinati', true);

    expect($pagina->viewData('tecnici')->pluck('id')->all())
        ->toBe([$attivo->id, $cestinato->id, $invitato->id])
        ->and($pagina->viewData('clientiPerTecnico')[$attivo->id])->toBe(2)
        ->and($pagina->viewData('clientiPerTecnico')->get($invitato->id))->toBeNull();

    $pagina->assertSee('Invitato, mai entrato')->assertSee('Cestinato')->assertSee('Attivo');

    // Per difetto i cestinati non ci sono.
    expect(Livewire::test(Tecnici::class)->viewData('tecnici')->pluck('id')->all())
        ->toBe([$attivo->id, $invitato->id]);
});

it('never counts a trashed sede among the customers a technician works on', function () {
    $tecnico = tecnicoDiEasyLab('Luca Ferri');
    $tecnico->portafoglioClienti()->attach([$this->sanMarco->id, $this->bergamo->id]);
    $this->bergamo->delete();

    expect(Livewire::test(Tecnici::class)->viewData('clientiPerTecnico')[$tecnico->id])->toBe(1);
});

it('never offers EasyLab itself among the customers a technician can work on', function () {
    $piattaforma = Account::factory()->create(['ragione_sociale' => 'EasyLab', 'di_piattaforma' => true]);
    $sedeEasyLab = UnitaOrganizzativa::factory()->ente()->perAccount($piattaforma)->create(['nome' => 'EasyLab HQ']);

    $tecnico = tecnicoDiEasyLab('Luca Ferri');

    Livewire::test(Tecnici::class)
        ->call('apriPortafoglio', $tecnico->id)
        ->set('portafoglioSedi', [$sedeEasyLab->id])
        ->call('salvaPortafoglio');

    expect(portafoglioDi($tecnico))->toBe([]);
});

// ─── Costo ───────────────────────────────────────────────────────────────────

it('keeps the query count flat from two technicians to twelve', function () {
    // ⚠️ Scaldare la cache dei permessi prima di misurare: al primo render
    // spatie carica ruoli e permessi, e senza questa riga il confronto
    // misurerebbe l'ordine dei due render invece del numero di tecnici.
    Livewire::test(Tecnici::class);

    $conta = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(Tecnici::class);
        $n = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'from "users"')
                || str_contains($q['query'], 'from "unita_organizzativa"')
                || str_contains($q['query'], 'from "accounts"')
                || str_contains($q['query'], 'from "tecnico_cliente"'))
            ->count();
        DB::disableQueryLog();

        return $n;
    };

    tecnicoDiEasyLab('Tecnico Uno')->portafoglioClienti()->attach($this->sanMarco->id);
    tecnicoDiEasyLab('Tecnico Due')->portafoglioClienti()->attach($this->bergamo->id);

    $conDue = $conta();

    for ($i = 3; $i <= 12; $i++) {
        tecnicoDiEasyLab("Tecnico {$i}")->portafoglioClienti()->attach([$this->sanMarco->id, $this->bergamo->id]);
    }

    expect($conta())->toBe($conDue);
});
