<?php

use App\Livewire\Tenancy\SwitcherEnte;
use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Lo switcher fra i propri Enti (🔗 ADR-032 punto 4) — area rossa: è l'unico
 * punto nuovo che tocca la tenancy, non lo scope ma il dato su cui lo scope si
 * appoggia. Ogni guardia ha il suo test negativo, e ogni negativo asserisce
 * tre cose: `false`, `tenant_id` invariato, NESSUNA riga di audit.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->enteA = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Nord']);
    $this->enteB = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Sud']);

    $this->membro = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $this->membro->assignRole('Admin');
    $this->account->aggiungiMembro($this->membro);
});

function switchAudit(): ?Activity
{
    return Activity::inLog(AuditLog::NAME)
        ->where('description', 'Ente attivo cambiato')
        ->latest('id')->first();
}

it('lets a membro switch between the enti of their account, audited', function () {
    $this->actingAs($this->membro);

    expect($this->membro->passaAllEnte($this->enteB))->toBeTrue()
        ->and($this->membro->fresh()->tenant_id)->toBe($this->enteB->id);

    $riga = switchAudit();
    expect($riga)->not->toBeNull()
        ->and($riga->causer_id)->toBe($this->membro->id)
        ->and($riga->properties['da_tenant_id'])->toBe($this->enteA->id)
        ->and($riga->properties['a_tenant_id'])->toBe($this->enteB->id)
        ->and($riga->properties['account_id'])->toBe($this->account->id);
});

it('shows only the new tenant data after the switch (ADR-018 intact)', function () {
    $strumentoA = Strumento::factory()->forNode($this->enteA)->create(['nome' => 'Macchina Nord']);
    $strumentoB = Strumento::factory()->forNode($this->enteB)->create(['nome' => 'Macchina Sud']);

    $this->actingAs($this->membro);
    expect(Strumento::pluck('id')->all())->toBe([$strumentoA->id]);

    $this->membro->passaAllEnte($this->enteB);

    // Nuova "richiesta": ci si riautentica con l'utente aggiornato.
    auth()->logout();
    $this->actingAs($this->membro->fresh());
    expect(Strumento::pluck('id')->all())->toBe([$strumentoB->id]);
});

it('refuses a user who is not a membro', function () {
    $estraneo = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $estraneo->assignRole('Tenant');
    $this->actingAs($estraneo);

    expect($estraneo->passaAllEnte($this->enteB))->toBeFalse()
        ->and($estraneo->fresh()->tenant_id)->toBe($this->enteA->id)
        ->and(switchAudit())->toBeNull();
});

it('refuses an ente that belongs to somebody else account', function () {
    $altrui = UnitaOrganizzativa::factory()->ente()
        ->perAccount(Account::factory()->create())
        ->create();
    $this->actingAs($this->membro);

    expect($this->membro->passaAllEnte($altrui))->toBeFalse()
        ->and($this->membro->fresh()->tenant_id)->toBe($this->enteA->id)
        ->and(switchAudit())->toBeNull();
});

it('refuses an ente without an account', function () {
    $orfano = UnitaOrganizzativa::factory()->ente()->create();
    $this->actingAs($this->membro);

    expect($this->membro->passaAllEnte($orfano))->toBeFalse()
        ->and(switchAudit())->toBeNull();
});

it('refuses a non-ente node', function () {
    $dipartimento = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create();
    $this->actingAs($this->membro);

    expect($this->membro->passaAllEnte($dipartimento))->toBeFalse()
        ->and($this->membro->fresh()->tenant_id)->toBe($this->enteA->id)
        ->and(switchAudit())->toBeNull();
});

it('refuses a non-ente node even if it somehow carries an account_id', function () {
    // La guardia sul tipo è DIFESA IN PROFONDITÀ (stessa forma del tenant_id
    // del tecnico interno, ADR-030): normalmente un non-ente non può avere
    // account_id (invariante in booted()), quindi il check sull'appartenenza
    // basterebbe. Ma se un domani quell'invariante si allentasse — o il dato si
    // corrompesse da fuori Eloquent, come qui — lo switcher non deve comunque
    // poter puntare il tenant di qualcuno su un dipartimento. Senza questo
    // test, la mutazione che toglie la guardia sopravviveva.
    $dipartimento = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create();
    DB::table('unita_organizzativa')
        ->where('id', $dipartimento->id)
        ->update(['account_id' => $this->account->id]);
    $this->actingAs($this->membro);

    expect($this->membro->passaAllEnte($dipartimento->fresh()))->toBeFalse()
        ->and($this->membro->fresh()->tenant_id)->toBe($this->enteA->id)
        ->and(switchAudit())->toBeNull();
});

it('refuses the enti of an account in lockout', function () {
    $bloccato = Account::factory()->bloccato()->create();
    $sede = UnitaOrganizzativa::factory()->ente()->perAccount($bloccato)->create();
    $bloccato->aggiungiMembro($this->membro);
    $this->actingAs($this->membro);

    expect($this->membro->passaAllEnte($sede))->toBeFalse()
        ->and($this->membro->fresh()->tenant_id)->toBe($this->enteA->id)
        ->and(switchAudit())->toBeNull();
});

it('refuses to switch while impersonating', function () {
    $superadmin = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');

    $this->actingAs($superadmin)->get(route('impersonate', $this->membro));

    $impersonato = auth()->user();
    expect($impersonato->id)->toBe($this->membro->id)
        ->and($impersonato->passaAllEnte($this->enteB))->toBeFalse()
        ->and($this->membro->fresh()->tenant_id)->toBe($this->enteA->id)
        ->and(switchAudit())->toBeNull();
});

it('never lets tenant_id be mass-assigned', function () {
    // Fuori dalle factory (che girano unguarded): il percorso di un form.
    $creato = User::create([
        'name' => 'Forgiato',
        'email' => 'forgiato@test.test',
        'password' => bcrypt('password'),
        'tenant_id' => $this->enteA->id,
    ]);
    expect($creato->tenant_id)->toBeNull();

    $this->membro->update(['tenant_id' => $this->enteB->id, 'name' => 'Rinominato']);
    expect($this->membro->fresh()->tenant_id)->toBe($this->enteA->id)
        ->and($this->membro->fresh()->name)->toBe('Rinominato');
});

it('shows the ente name without a tendina to a single-sede user', function () {
    $solo = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $solo->assignRole('Tenant');
    $this->actingAs($solo);

    Livewire\Livewire::test(SwitcherEnte::class)
        ->assertSet('sediRaggiungibili', 0)
        ->assertSee('Sede Nord')
        ->assertDontSee('Le tue sedi');
});

it('lists the other sedi in the tendina for a membro', function () {
    $this->actingAs($this->membro);

    Livewire\Livewire::test(SwitcherEnte::class)
        ->assertSet('sediRaggiungibili', 1)
        ->assertSee('Sede Nord')
        ->call('apri')
        ->assertSee('Sede Sud');
});

it('switches and redirects to the dashboard from the tendina', function () {
    $this->actingAs($this->membro);

    Livewire\Livewire::test(SwitcherEnte::class)
        ->call('passa', $this->enteB->id)
        ->assertRedirect(route('dashboard'));

    expect($this->membro->fresh()->tenant_id)->toBe($this->enteB->id);
});

it('does not redirect on an illegitimate target', function () {
    $altrui = UnitaOrganizzativa::factory()->ente()
        ->perAccount(Account::factory()->create())
        ->create();
    $this->actingAs($this->membro);

    Livewire\Livewire::test(SwitcherEnte::class)
        ->call('passa', $altrui->id)
        ->assertNoRedirect();

    expect($this->membro->fresh()->tenant_id)->toBe($this->enteA->id);
});

// --- Lo spostamento durante un'impersonazione: effimero, non permanente ---
//
// 🔴 **Questa regola è cambiata il 28 Ago 2026, su richiesta di Marco.** Fino a
// quel giorno la tendina era soppressa mentre si impersonava, e chi impersonava
// un cliente con più sedi non poteva vederle — cioè non poteva fare la cosa per
// cui l'impersonazione esiste.
//
// La ragione della soppressione restava però valida, ed è quella che il nuovo
// comportamento conserva: `passaAllEnte()` **scrive** `users.tenant_id`, e
// scriverlo impersonando è una modifica permanente fatta per conto del cliente
// — che si ritroverebbe, al proprio prossimo accesso, in una sede che non ha
// scelto lui. Stessa famiglia di gesti per cui il 2FA è chiuso impersonando.
//
// Quindi: la tendina c'è, lo spostamento vive nella SESSIONE, e il database del
// cliente non viene toccato.

it('lets an impersonator move between the sedi WITHOUT touching the customer record', function () {
    $superadmin = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');
    $this->actingAs($superadmin)->get(route('impersonate', $this->membro));

    Livewire\Livewire::test(SwitcherEnte::class)
        ->assertSee('Sede Nord')
        ->call('apri')
        ->call('passa', $this->enteB->id)
        ->assertRedirect(route('dashboard'));

    // 🔴 Il cuore: il contesto SEGUE lo spostamento…
    expect(CurrentTenant::id())->toBe($this->enteB->id)
        // …e la riga del cliente NON è stata riscritta.
        ->and($this->membro->fresh()->tenant_id)->toBe($this->enteA->id);
});

it('refuses an ephemeral move toward a sede outside the customer contract', function () {
    // ⛔ La guardia sta dove la chiave si SCRIVE, perché `CurrentTenant` a valle
    // non ricontrolla (costerebbe una query per richiesta). Se questa riga si
    // allentasse, la sessione diventerebbe un varco verso un Ente qualunque.
    $estraneo = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente Estraneo']);

    $superadmin = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');
    $this->actingAs($superadmin)->get(route('impersonate', $this->membro));

    Livewire\Livewire::test(SwitcherEnte::class)
        ->call('passa', $estraneo->id)
        ->assertNoRedirect();

    expect(CurrentTenant::id())->toBe($this->enteA->id);
});

it('drops the ephemeral sede the moment the impersonation ends', function () {
    // ⛔ La chiave di sessione porta l'id dell'utente per cui è stata scritta, e
    // si applica solo se si sta impersonando ADESSO **e** l'utente è ancora
    // quello. Una chiave rimasta appesa non può quindi scopare nessuno verso
    // l'Ente di un altro — che è il modo in cui questa scorciatoia potrebbe
    // diventare un buco.
    $superadmin = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');
    $this->actingAs($superadmin)->get(route('impersonate', $this->membro));

    Livewire\Livewire::test(SwitcherEnte::class)->call('passa', $this->enteB->id);
    expect(CurrentTenant::id())->toBe($this->enteB->id);

    // Si esce dall'impersonazione: la chiave resta in sessione, ma è spenta.
    $this->get(route('impersonate.leave'));

    expect(session(CurrentTenant::SEDE_IMPERSONATA))->not->toBeNull()
        ->and(CurrentTenant::id())->toBe($this->enteA->id);
});

it('never lets a leftover key move the CUSTOMER, when they log in themselves', function () {
    // 🔴 **Il caso che rende falsificabile la guardia `isImpersonating()`, e che
    // il test qui sopra NON coglieva** — trovato per mutazione: togliendo quel
    // controllo la suite restava verde, perché dopo l'uscita l'utente
    // autenticato è l'impersonatore e la chiave veniva spenta dal confronto
    // sull'id, non da quello sull'impersonazione.
    //
    // Lo scenario vero è un altro, ed è quello che conta: la chiave è stata
    // scritta PER il cliente, e poi è il cliente stesso ad autenticarsi nella
    // stessa sessione. Qui i due id COMBACIANO, quindi l'unica cosa che
    // impedisce di scoparlo verso una sede che non ha scelto è
    // `isImpersonating()`. Senza, un cliente si troverebbe in un'altra sede per
    // una chiave lasciata da un tecnico dell'assistenza.
    $superadmin = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');
    $this->actingAs($superadmin)->get(route('impersonate', $this->membro));

    Livewire\Livewire::test(SwitcherEnte::class)->call('passa', $this->enteB->id);
    $this->get(route('impersonate.leave'));

    // Ora entra il cliente, di persona, con la chiave ancora in sessione.
    $this->actingAs($this->membro->fresh());

    expect(session(CurrentTenant::SEDE_IMPERSONATA))->not->toBeNull()
        ->and(CurrentTenant::id())->toBe($this->enteA->id);
});

it('renders nothing for a user without a tenant', function () {
    $esterno = User::factory()->create(['tenant_id' => null]);
    $esterno->assignRole('Tecnico');
    $this->actingAs($esterno);

    Livewire\Livewire::test(SwitcherEnte::class)
        ->assertSet('nomeEnte', null);
});

it('appears in the app shell', function () {
    $tenant = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $tenant->assignRole('Tenant');

    // ⚠️ **Il nome dell'Ente si asserisce sul COMPONENTE, non sulla pagina.**
    // Dal 25 Ago la dashboard stampa da sé «Lo stato delle macchine di Sede
    // Nord.», quindi un `assertSee('Sede Nord')` sulla shell è verde anche se lo
    // switcher smette del tutto di nominare l'Ente — misurato sostituendo
    // `{{ $nomeEnte }}` con una costante. È la stessa trappola che
    // `DashboardTest` documenta dal verso opposto.
    $this->actingAs($tenant)->get('/dashboard')
        ->assertOk()
        ->assertSeeLivewire(SwitcherEnte::class);

    Livewire\Livewire::actingAs($tenant)->test(SwitcherEnte::class)
        ->assertSee('Sede Nord');
});

it('leaves a Responsabile fail-safe in the ente they switched into', function () {
    // Nota affermativa, non un bug: le assegnazioni `responsabile_unita` sono
    // per-nodo di UN ente. Nell'ente di arrivo il Responsabile non ha nodi →
    // il fail-safe esistente ([] = non vede nulla) fa esattamente il suo
    // lavoro, finché qualcuno non gli assegna un reparto anche lì.
    $resp = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $resp->assignRole('Responsabile Reparto');
    $dipartimento = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create();
    $resp->unitaResponsabili()->attach($dipartimento->id);
    $this->account->aggiungiMembro($resp);

    Strumento::factory()->forNode($dipartimento)->create();
    Strumento::factory()->forNode($this->enteB)->create();

    $this->actingAs($resp);
    expect(Strumento::count())->toBe(1);

    $resp->passaAllEnte($this->enteB);
    auth()->logout();
    $this->actingAs($resp->fresh());

    expect(Strumento::count())->toBe(0);
});

it('renders the sede names in the panel once opened, while impersonating', function () {
    $superadmin = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');
    $this->actingAs($superadmin)->get(route('impersonate', $this->membro));

    Livewire\Livewire::test(SwitcherEnte::class)
        ->assertSee('Sede Nord')
        ->call('apri')
        ->assertSet('aperto', true)
        ->assertSee('Le tue sedi')
        ->assertSee('Sede Sud');
});

// --- Dove si atterra dopo il cambio di sede ---
//
// 🗓️ Chiesto da Marco il 28 Ago 2026: «vorrei restare nella pagina in cui ho
// switchato, ma della sede che ho selezionato». Fino a quel giorno si tornava
// sempre in dashboard, e la ragione scritta era vera solo a metà — vale per una
// pagina che nomina un id dell'altra sede, non per un elenco.

it('stays on the same page when that page makes sense in the new sede', function () {
    $this->actingAs($this->membro);

    session()->setPreviousUrl(route('scadenzario.index'));

    Livewire::test(SwitcherEnte::class)
        ->call('passa', $this->enteB->id)
        ->assertRedirect(route('scadenzario.index'));
});

it('falls back to the dashboard when the page names something of the sede just left', function () {
    // ⛔ `/strumenti/42` è una macchina dell'altra sede: dopo il cambio darebbe
    // un 404 dal messaggio incomprensibile. Il criterio è meccanico — se la
    // rotta ha parametri, quel parametro è sempre l'id di qualcosa che stava
    // di là — così non c'è nessun elenco di eccezioni da tenere aggiornato.
    $strumento = Strumento::factory()->forNode($this->enteA)->create();

    $this->actingAs($this->membro);

    session()->setPreviousUrl(route('strumenti.show', $strumento));

    Livewire::test(SwitcherEnte::class)
        ->call('passa', $this->enteB->id)
        ->assertRedirect(route('dashboard'));
});

it('drops the query string, which names things of the sede just left', function () {
    // ⚠️ Un filtro come `?strumentoId=7` nomina una macchina dell'altra sede:
    // portarselo dietro mostrerebbe un elenco vuoto con un filtro attivo che
    // non si capisce — il difetto che l'archivio documenti aveva già avuto.
    $this->actingAs($this->membro);

    session()->setPreviousUrl(route('documenti.index').'?strumentoId=7');

    Livewire::test(SwitcherEnte::class)
        ->call('passa', $this->enteB->id)
        ->assertRedirect(route('documenti.index'));
});

it('names the sede you are ACTUALLY in, and offers the one you came from', function () {
    // 🔴 Difetto introdotto dallo spostamento effimero, e segnalato da Marco lo
    // stesso giorno (28 Ago 2026): lo switcher leggeva `users.tenant_id`, che
    // durante un'impersonazione NON cambia. Risultato: la barra diceva il nome
    // della sede di partenza mentre la pagina mostrava già i dati dell'altra, e
    // l'elenco offriva come «altra sede» proprio quella in cui ci si trovava —
    // mentre quella da cui si era partiti spariva, cioè non si poteva tornare.
    $superadmin = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');
    $this->actingAs($superadmin)->get(route('impersonate', $this->membro));

    Livewire::test(SwitcherEnte::class)->call('passa', $this->enteB->id);

    Livewire::test(SwitcherEnte::class)
        // Il nome è quello della sede in cui si è ADESSO…
        ->assertSet('nomeEnte', 'Sede Sud')
        ->call('alterna')
        // …e l'elenco offre la strada del RITORNO, non un doppione di sé.
        //
        // ⚠️ Si asserisce sui BERSAGLI dei bottoni e non sui nomi: «Sede Sud»
        // compare comunque in pagina, ed è giusto — è l'etichetta del bottone
        // che dice dove sei. Un `assertDontSee('Sede Sud')` sarebbe stato rosso
        // per la ragione sbagliata, cioè avrebbe misurato l'etichetta invece
        // dell'elenco.
        ->assertSee("passa({$this->enteA->id})")
        ->assertDontSee("passa({$this->enteB->id})");
});
