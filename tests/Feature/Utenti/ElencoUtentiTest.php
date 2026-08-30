<?php

use App\Livewire\Utenti\ElencoUtenti;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\InvitoUtente;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * 🔴 `/utenti`, la schermata che **conferisce accessi** (🔗 ADR-038) — area
 * rossa piena: autorizzazioni, tenancy e identità nello stesso file.
 *
 * I positivi qui contano poco: che invitare qualcuno crei qualcuno è la parte
 * che si vede al primo giro. Quello che questa suite deve tenere fermo sono le
 * porte, e una porta si prova provando a passarci attraverso:
 *
 *   1. **il permesso** — e non solo sulla rotta: ogni azione riautorizza, perché
 *      `set` + `call` non passa dal middleware;
 *   2. **il ruolo conferibile** — `Superadmin` e `Developer` sono rifiutati dal
 *      codice, non nascosti da una `<select>`;
 *   3. **il confine dell'Ente** — `User` **non ha `TenantScope`** (è l'identità,
 *      non il dominio): qui non c'è nessuno scope che salvi una dimenticanza, e
 *      un id di un'altra sede deve dare 404 senza scrivere niente;
 *   4. **le invarianti che tolgono le chiavi di casa** — l'ultimo Admin, sé
 *      stessi, l'ultimo membro di un account (🔗 ADR-032).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->sede = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Rossi']);

    $this->admin = User::factory()->create([
        'name' => 'Anna Rossi',
        'email' => 'anna@rossi.test',
        'tenant_id' => $this->sede->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('Admin');
    $this->account->aggiungiMembro($this->admin);

    // L'altro cliente: esiste solo per essere il confine da non attraversare.
    $this->altroAccount = Account::factory()->create(['ragione_sociale' => 'Lab Bianchi']);
    $this->altraSede = UnitaOrganizzativa::factory()->ente()->perAccount($this->altroAccount)->create(['nome' => 'Sede Bianchi']);
    $this->estranea = User::factory()->create([
        'name' => 'Carla Bianchi',
        'email' => 'carla@bianchi.test',
        'tenant_id' => $this->altraSede->id,
    ]);
    $this->estranea->assignRole('Admin');
    $this->altroAccount->aggiungiMembro($this->estranea);
});

it('serializes both ways that can remove the last Admin and the last account member', function () {
    // SQLite ignora `FOR UPDATE`, quindi la concorrenza non è riproducibile in
    // modo onesto nella suite locale. La rete verifica l'SQL che deve esistere:
    // entrambe le azioni passano dallo stesso lock ordinato, e il distacco del
    // membro serializza sulla riga dell'Account.
    $componente = file_get_contents(app_path('Livewire/Utenti/ElencoUtenti.php'));
    $account = file_get_contents(app_path('Models/Account.php'));

    expect(substr_count($componente, '$this->bloccaPersoneAttiveDellEnte();'))->toBe(2)
        ->and($componente)->toContain('->lockForUpdate()')
        ->and($account)->toContain('self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail()');
});

it('keeps card-table cells structurally single and inline restore targets touchable', function () {
    $persona = User::factory()->create([
        'name' => 'Persona con due ruoli',
        'email' => 'due-ruoli@rossi.test',
        'tenant_id' => $this->sede->id,
    ]);
    $persona->assignRole(['Tenant', 'Tecnico']);

    $html = Livewire::actingAs($this->admin)->test(ElencoUtenti::class)->html();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
    $xpath = new DOMXPath($dom);
    $riga = $xpath->query("//tr[.//*[contains(text(), 'due-ruoli@rossi.test')]]")->item(0);

    expect($riga)->not->toBeNull();

    $celle = $xpath->query('./td', $riga);
    expect($celle->length)->toBe(4);

    foreach ($celle as $cella) {
        // Testa gli elementi, non whitespace e commenti Blade.
        expect($xpath->query('./*', $cella)->length)->toBe(1);
    }

    $vista = file_get_contents(resource_path('views/livewire/utenti/elenco-utenti.blade.php'));
    expect(substr_count($vista, 'inline-flex min-h-11 items-center'))->toBe(2);
});

/** Una persona dell'Ente di prova, col ruolo che le si dà. */
function personaDellEnte(string $nome, string $ruolo, array $attributi = []): User
{
    $utente = User::factory()->create(array_merge([
        'name' => $nome,
        'tenant_id' => test()->sede->id,
        'two_factor_confirmed_at' => now(),
    ], $attributi));

    $utente->assignRole($ruolo);

    return $utente;
}

// ─── 1. Il permesso, sulla rotta e dentro ogni azione ────────────────────────

it('shuts the page to anyone without utenti.view', function () {
    $tenant = personaDellEnte('Ospite Tenant', 'Tenant');

    $this->actingAs($tenant)->get(route('utenti.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('utenti.index'))->assertOk();
});

it('does not show a platform user a link to the tenant-bound people page', function () {
    $developer = User::factory()->create([
        'tenant_id' => null,
        'two_factor_confirmed_at' => now(),
    ]);
    $developer->assignRole('Developer');

    $html = $this->actingAs($developer)->get(route('dashboard'))->assertOk()->getContent();

    preg_match('/<nav class="flex-1 space-y-1[^"]*">.*?<\/nav>/s', $html, $blocco);

    expect($blocco)->not->toBeEmpty();
    expect($blocco[0])->toContain(route('piattaforma.index'))
        ->not->toContain(route('utenti.index'));

    $this->get(route('utenti.index'))->assertForbidden();
});

it('refuses the invite to someone who may only look, even with the fields already forged', function () {
    // 🔴 Il `can:` di rotta non è la guardia dell'azione: `utenti.view` e
    // `utenti.create` sono due permessi, e l'editor dei ruoli può darne uno
    // senza l'altro. Qui la persona ha la pagina e **non** il gesto, e arriva
    // all'azione con le property già piene — che è esattamente ciò che fa un
    // `$wire.set()` dalla console del browser.
    $lettore = personaDellEnte('Solo Lettura', 'Tenant');
    $lettore->givePermissionTo('utenti.view');

    Livewire::actingAs($lettore)
        ->test(ElencoUtenti::class)
        ->set('nome', 'Intrusa Nuova')
        ->set('email', 'intrusa@rossi.test')
        ->set('ruolo', 'Tenant')
        ->call('invita')
        ->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'intrusa@rossi.test']);
});

it('refuses the role change and the bin to someone who may only look', function (string $azione) {
    $lettore = personaDellEnte('Solo Lettura', 'Tenant');
    $lettore->givePermissionTo('utenti.view');

    $bersaglio = personaDellEnte('Bersaglio', 'Tenant');

    Livewire::actingAs($lettore)
        ->test(ElencoUtenti::class)
        ->call($azione, $bersaglio->id)
        ->assertForbidden();

    expect($bersaglio->fresh()->trashed())->toBeFalse()
        ->and($bersaglio->fresh()->hasRole('Tenant'))->toBeTrue();
})->with(['apriRuolo', 'confermaCestino', 'reinvia']);

// ─── 2. I due ruoli che nessuna interfaccia conferisce ───────────────────────

it('never confers a platform role from a forged payload, on the invite', function (string $ruolo) {
    // ⛔ La `<select>` non mostra questi due, e non è quello il punto: un ruolo
    // assente da una tendina è a un `$wire.set()` di distanza, e Superadmin e
    // Developer portano `tenants.view_all` — la lettura sulle righe di *tutti*
    // i clienti.
    Livewire::actingAs($this->admin)
        ->test(ElencoUtenti::class)
        ->set('nome', 'Scalatrice')
        ->set('email', 'scalatrice@rossi.test')
        ->set('ruolo', $ruolo)
        ->call('invita')
        ->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'scalatrice@rossi.test']);
})->with(['Superadmin', 'Developer']);

it('never confers a platform role from a forged payload, on the role change', function (string $ruolo) {
    $bersaglio = personaDellEnte('Bersaglio', 'Tenant');

    Livewire::actingAs($this->admin)
        ->test(ElencoUtenti::class)
        ->call('apriRuolo', $bersaglio->id)
        ->set('nuovoRuolo', $ruolo)
        ->call('cambiaRuolo')
        ->assertForbidden();

    // 🔴 Non basta che l'azione risponda 403: si guarda il **pivot**, che è il
    // posto in cui il difetto sarebbe visibile.
    $idRuolo = DB::table('roles')->where('name', $ruolo)->value('id');

    $this->assertDatabaseMissing('model_has_roles', [
        'role_id' => $idRuolo,
        'model_id' => $bersaglio->id,
    ]);

    expect($bersaglio->fresh()->hasRole('Tenant'))->toBeTrue();
})->with(['Superadmin', 'Developer']);

// ─── 2-bis. E nemmeno li si toglie: la guardia dell'altra direzione ──────────

/** Il Developer dell'Ente: la chiave di riserva della piattaforma (🔗 ADR-016). */
function developerDellEnte(): User
{
    // ⚠️ `email_verified_at` a `null` di proposito: `reinvia()` ha una
    // rilettura propria che pretende «mai entrata», e con la data valorizzata
    // quel caso morirebbe su un 404 **prima** di arrivare alla guardia — un
    // verde per il motivo sbagliato.
    return personaDellEnte('Dev Riserva', 'Developer', [
        'email' => 'dev@rossi.test',
        'email_verified_at' => null,
    ]);
}

it('never lets a platform role be taken away either, which is the same hole from the other side', function (string $azione) {
    // 🔴 `RuoliAssegnabili::ammesso()` guarda il ruolo di **destinazione**:
    // ferma chi prova a *darsi* Superadmin, non chi prova a *toglierlo*. Un
    // `syncRoles(['Tenant'])` puntato sul Developer passava quella guardia
    // senza un sussulto, e `cestina()` non le passava nemmeno accanto.
    //
    // ⛔ Non è un caso di scuola: il Superadmin è tenant-bound sull'Ente di
    // piattaforma (🔗 ADR-018, `SuperadminSeeder`) **insieme** al Developer, e
    // ha tutti e quattro gli `utenti.*` — quindi si trova la chiave di riserva
    // in elenco sulla propria `/utenti`, coi bottoni accanto. E non c'è nessun
    // `Gate::before` da super-admin che la rimetta dentro dopo.
    $superadmin = personaDellEnte('Super Visore', 'Superadmin');
    $developer = developerDellEnte();

    Livewire::actingAs($superadmin)->test(ElencoUtenti::class)
        ->call($azione, $developer->id)
        ->assertForbidden();

    expect($developer->fresh()->hasRole('Developer'))->toBeTrue()
        ->and($developer->fresh()->trashed())->toBeFalse();
})->with(['apriRuolo', 'confermaCestino', 'reinvia']);

it('closes the property road to a Developer too, not only the action', function (string $property) {
    // ⛔ `$wire.set()` **non passa dall'azione**: senza gli hook `updating*` la
    // modale si aprirebbe sulla chiave di riserva e solo l'invio fallirebbe.
    // Il negativo che conta è il **pivot**, che è dove il difetto sarebbe
    // visibile.
    $superadmin = personaDellEnte('Super Visore', 'Superadmin');
    $developer = developerDellEnte();

    Livewire::actingAs($superadmin)->test(ElencoUtenti::class)
        ->set($property, $developer->id)
        ->assertForbidden();

    $idDeveloper = DB::table('roles')->where('name', 'Developer')->value('id');

    $this->assertDatabaseHas('model_has_roles', [
        'role_id' => $idDeveloper,
        'model_id' => $developer->id,
    ]);

    expect($developer->fresh()->trashed())->toBeFalse();
})->with(['utenteRuolo', 'utenteCestino']);

it('still lists the platform roles, because hiding them would be a different lie', function () {
    // La riga si vede — chi amministra l'Ente ha diritto di sapere chi c'è —
    // ma i comandi no: l'azione risponderebbe 403, e offrire un gesto che
    // verrà rifiutato è il difetto che questa vista evita altrove
    // (`$ultimoAdmin`, `$eSeStesso`).
    $superadmin = personaDellEnte('Super Visore', 'Superadmin');
    $developer = developerDellEnte();

    $componente = Livewire::actingAs($superadmin)->test(ElencoUtenti::class);

    expect($componente->viewData('persone')->pluck('id')->all())->toContain($developer->id)
        ->and($componente->viewData('amministrabili')[$developer->id])->toBeFalse()
        ->and($componente->viewData('amministrabili')[$this->admin->id])->toBeTrue();

    // ⚠️ Un ago solo per asserzione: `toContain()` è variadico, e un secondo
    // argomento sarebbe un secondo ago, non un messaggio. E il confronto è
    // **differenziale** — la stessa stringa, con l'altro id — o «non c'è il
    // bottone» sarebbe soddisfatto anche da una tabella vuota.
    expect($componente->html())->not->toContain('wire:click="apriRuolo('.$developer->id.')"');
    expect($componente->html())->toContain('wire:click="apriRuolo('.$this->admin->id.')"');
});

// ─── 3. Il confine dell'Ente, che nessuno scope difende ──────────────────────

it('lists the people of this Ente and nobody else', function () {
    $dentro = personaDellEnte('Giulia Verdi', 'Tenant');

    $vista = Livewire::actingAs($this->admin)->test(ElencoUtenti::class);

    $ids = $vista->viewData('persone')->pluck('id')->all();

    expect($ids)->toContain($this->admin->id)
        ->and($ids)->toContain($dentro->id)
        ->and($ids)->not->toContain($this->estranea->id);
});

it('gives 404 for a person of another Ente, on every action that takes an id', function (string $azione) {
    // ⛔ `User` non ha `TenantScope` — è l'identità, non il dominio — quindi il
    // confine è scritto a mano in `personeDellEnte()` e non c'è nessuna rete
    // sotto. 404 e non 403 di proposito: un «non ti è permesso» confermerebbe
    // che quella persona esiste.
    Livewire::actingAs($this->admin)->test(ElencoUtenti::class)
        ->call($azione, $this->estranea->id);
})->with(['apriRuolo', 'confermaCestino', 'reinvia', 'ripristina'])
    ->throws(ModelNotFoundException::class);

it('gives 404 when a foreign id arrives through the property instead of the action', function (string $property) {
    // 🔴 `$wire.set()` **non passa dall'azione**: senza l'hook `updating*` la
    // modale si aprirebbe su una persona di un altro cliente, e solo l'invio
    // fallirebbe — la modale direbbe una cosa e l'azione ne farebbe un'altra.
    Livewire::actingAs($this->admin)->test(ElencoUtenti::class)
        ->set($property, $this->estranea->id);
})->with(['utenteRuolo', 'utenteCestino', 'ripristinabile'])
    ->throws(ModelNotFoundException::class);

it('writes nothing when a foreign id is pushed at the role change', function () {
    try {
        Livewire::actingAs($this->admin)->test(ElencoUtenti::class)
            ->set('utenteRuolo', $this->estranea->id)
            ->set('nuovoRuolo', 'Tenant')
            ->call('cambiaRuolo');
    } catch (ModelNotFoundException) {
        // Atteso: è la rilettura vincolata all'Ente che non trova nulla.
    }

    expect($this->estranea->fresh()->hasRole('Admin'))->toBeTrue()
        ->and($this->estranea->fresh()->trashed())->toBeFalse();
});

// ─── 4. Le invarianti che tolgono le chiavi di casa ──────────────────────────

it('never lets an Admin bin themselves', function () {
    // Chi si cestina perde l'accesso nell'atto stesso di perderlo, e non ha più
    // la schermata da cui rimediare. Il secondo Admin esiste perché sia questa
    // la regola a scattare, e non quella dell'ultimo Admin.
    personaDellEnte('Secondo Admin', 'Admin');

    $componente = Livewire::actingAs($this->admin)->test(ElencoUtenti::class)
        ->call('confermaCestino', $this->admin->id)
        ->call('cestina');

    expect((string) $componente->get('errore'))->toContain('te stesso')
        ->and($this->admin->fresh()->trashed())->toBeFalse();
});

it('never lets the last Admin of an Ente be binned', function () {
    // L'attore non è l'Admin, o a scattare sarebbe la regola su sé stessi: è un
    // Superadmin della stessa sede — tenant-bound come chiunque (🔗 ADR-018).
    $superadmin = personaDellEnte('Super Visore', 'Superadmin');
    $this->account->aggiungiMembro($superadmin);

    $componente = Livewire::actingAs($superadmin)->test(ElencoUtenti::class)
        ->call('confermaCestino', $this->admin->id)
        ->call('cestina');

    expect((string) $componente->get('errore'))->toContain('unico Admin')
        ->and($this->admin->fresh()->trashed())->toBeFalse();
});

it('never lets the last Admin be demoted either, which is the same hole from the other door', function () {
    $superadmin = personaDellEnte('Super Visore', 'Superadmin');

    $componente = Livewire::actingAs($superadmin)->test(ElencoUtenti::class)
        ->call('apriRuolo', $this->admin->id)
        ->set('nuovoRuolo', 'Tenant')
        ->call('cambiaRuolo');

    expect((string) $componente->get('errore'))->toContain('unico Admin')
        ->and($this->admin->fresh()->hasRole('Admin'))->toBeTrue();
});

it('says that the last member of an account cannot leave, instead of blowing the page up', function () {
    // 🔴 `Account::rimuoviMembro()` **lancia** (🔗 ADR-032: ogni account ha
    // sempre almeno un membro). Senza intercettarlo la pagina esploderebbe con
    // una `RuntimeException`, che non dice a nessuno cosa fare.
    //
    // ⚠️ E il distacco serve davvero: `membri()` è una belongsToMany verso
    // `User`, quindi applica il global scope del cestino — una persona
    // cestinata sparirebbe dai membri senza che l'invariante se ne accorga.
    $superadmin = personaDellEnte('Super Visore', 'Superadmin');
    personaDellEnte('Secondo Admin', 'Admin');

    // `$this->admin` resta l'unico membro dell'account.
    $componente = Livewire::actingAs($superadmin)->test(ElencoUtenti::class)
        ->call('confermaCestino', $this->admin->id)
        ->call('cestina');

    expect((string) $componente->get('errore'))->toContain('amministra il contratto')
        ->and($this->admin->fresh()->trashed())->toBeFalse()
        ->and($this->account->membri()->whereKey($this->admin->id)->exists())->toBeTrue();
});

// ─── 5. Invitare: gli esiti che non sono il caso felice ──────────────────────

it('creates an invited person with the role that was asked for, and says «in consegna»', function () {
    Notification::fake();

    $componente = Livewire::actingAs($this->admin)->test(ElencoUtenti::class)
        ->call('apriInvito')
        ->set('nome', 'Giulia Verdi')
        ->set('email', '  Giulia@Rossi.TEST ')
        ->set('ruolo', 'Tecnico')
        ->call('invita');

    $nuova = User::where('email', 'giulia@rossi.test')->firstOrFail();

    expect($nuova->tenant_id)->toBe($this->sede->id)
        ->and($nuova->email_verified_at)->toBeNull()
        ->and($nuova->hasRole('Tecnico'))->toBeTrue()
        // ⚠️ «In consegna», non «inviato»: la notifica è `ShouldQueue` e chi
        // chiama non può sapere se è partita (🔗 `EsitoInvito`).
        ->and($componente->get('notice'))->toContain('in consegna')
        ->and($componente->get('showInvito'))->toBeFalse();

    Notification::assertSentTo($nuova, InvitoUtente::class);
});

it('warns about the second factor before the Admin role is conferred, not after', function () {
    // 🔗 ADR-016: chi riceve `Admin` al primo accesso finisce su
    // `/settings/security` e non può andare altrove. È il comportamento voluto —
    // scoprirlo dalla telefonata di chi non riesce a entrare è un'altra cosa.
    Notification::fake();

    $componente = Livewire::actingAs($this->admin)->test(ElencoUtenti::class)
        ->call('apriInvito')
        ->set('ruolo', 'Admin');

    expect($componente->instance()->imponeSecondoFattore('Admin'))->toBeTrue()
        ->and($componente->instance()->imponeSecondoFattore('Tenant'))->toBeFalse();

    // L'avviso è nel markup della modale, accanto al campo, **prima** dell'invio.
    $componente->assertSee('secondo fattore');
});

it('refuses an address that is already taken, and creates nobody', function () {
    Notification::fake();

    $componente = Livewire::actingAs($this->admin)->test(ElencoUtenti::class)
        ->call('apriInvito')
        ->set('nome', 'Doppione')
        ->set('email', 'anna@rossi.test')
        ->set('ruolo', 'Tenant')
        ->call('invita');

    expect((string) $componente->get('errore'))->toContain('già di una persona')
        ->and($componente->get('ripristinabile'))->toBeNull()
        ->and(User::withTrashed()->where('email', 'anna@rossi.test')->count())->toBe(1);

    Notification::assertNothingSent();
});

it('offers the restore for a binned address, instead of a flat error', function () {
    // 🔴 `users.email` è unique **senza condizione**: la persona cestinata
    // occupa l'indirizzo, ma non compare in elenco — il cestino la nasconde. Un
    // «esiste già» secco manderebbe a cercare un difetto.
    Notification::fake();

    $uscita = personaDellEnte('Luca Bianchi', 'Tecnico', ['email' => 'uscito@rossi.test']);
    $uscita->delete();

    $componente = Livewire::actingAs($this->admin)->test(ElencoUtenti::class)
        ->call('apriInvito')
        ->set('nome', 'Luca Di Nuovo')
        ->set('email', 'uscito@rossi.test')
        ->set('ruolo', 'Tecnico')
        ->call('invita');

    expect($componente->get('ripristinabile'))->toBe($uscita->id)
        ->and($componente->get('errore'))->toContain('cestino')
        // ⛔ E **nessuna seconda persona** per lo stesso indirizzo.
        ->and(User::withTrashed()->where('email', 'uscito@rossi.test')->count())->toBe(1);

    Notification::assertNothingSent();
});

it('never offers the restore of somebody else customer, from a guessed address', function () {
    // ⚠️ L'indirizzo è unique su **tutta** la piattaforma, quindi il rifiuto
    // «è cestinata» può riguardare la persona di un altro cliente. Offrire lì un
    // ripristino sarebbe un gesto cross-tenant a partire da un'email indovinata,
    // e ripetere il messaggio dell'eccezione direbbe a un estraneo che quella
    // persona esiste.
    Notification::fake();

    $this->estranea->delete();

    $componente = Livewire::actingAs($this->admin)->test(ElencoUtenti::class)
        ->call('apriInvito')
        ->set('nome', 'Chiunque')
        ->set('email', 'carla@bianchi.test')
        ->set('ruolo', 'Tenant')
        ->call('invita');

    expect($componente->get('ripristinabile'))->toBeNull()
        ->and($componente->get('errore'))->toContain('già di una persona');

    // La risposta è quella di un indirizzo occupato, e non nomina il cestino.
    expect((string) $componente->get('errore'))->not->toContain('cestin');
});

// ─── 6. Reinviare, cestinare, ripristinare ───────────────────────────────────

it('sends the invitation again to somebody who never came in', function () {
    // Funzione che prima del 29 Ago 2026 non esisteva da nessuna parte: si
    // poteva reinvitare solo ripassando dal provisioning di un Ente.
    Notification::fake();

    $invitata = personaDellEnte('Mai Entrata', 'Tenant', ['email_verified_at' => null]);

    $componente = Livewire::actingAs($this->admin)->test(ElencoUtenti::class)
        ->call('reinvia', $invitata->id);

    expect($componente->get('notice'))->toContain('in consegna');

    Notification::assertSentTo($invitata, InvitoUtente::class);
});

it('has no invitation to send to somebody who is already in', function () {
    Notification::fake();

    $dentro = personaDellEnte('Già Entrata', 'Tenant');

    expect(fn () => Livewire::actingAs($this->admin)->test(ElencoUtenti::class)->call('reinvia', $dentro->id))
        ->toThrow(ModelNotFoundException::class);

    Notification::assertNothingSent();
});

it('bins and restores a person, keeping the row and the account membership straight', function () {
    $secondo = personaDellEnte('Secondo Admin', 'Admin', ['email' => 'secondo@rossi.test']);
    $this->account->aggiungiMembro($secondo);

    $componente = Livewire::actingAs($this->admin)->test(ElencoUtenti::class)
        ->call('confermaCestino', $secondo->id)
        ->call('cestina');

    expect($componente->get('errore'))->toBeNull()
        ->and($secondo->fresh()->trashed())->toBeTrue()
        // Il distacco è necessario: `membri()` nasconde già i cestinati, quindi
        // senza di esso l'invariante «almeno un membro» smetterebbe di reggere.
        ->and($this->account->membri()->whereKey($secondo->id)->exists())->toBeFalse()
        // ⛔ La riga **non** è cancellata: lo storico continua a nominarla.
        ->and(User::withTrashed()->whereKey($secondo->id)->exists())->toBeTrue();

    Livewire::actingAs($this->admin)->test(ElencoUtenti::class)->call('ripristina', $secondo->id);

    expect(User::whereKey($secondo->id)->exists())->toBeTrue()
        ->and($this->account->membri()->whereKey($secondo->id)->exists())->toBeTrue();
});

it('puts an invited Admin on the contract, or the first one could never be binned', function () {
    // 🔴 Un Admin è anche **chi amministra il contratto** (🔗 ADR-032), ed è la
    // condizione con cui `ProvisionaEnte` crea la prima appartenenza e con cui
    // `ripristina()` la rimette. L'invito la saltava, e la divergenza era un
    // **vicolo cieco**: il secondo Admin non era membro, quindi cestinare il
    // primo sbatteva su «aggiungine un'altra» — un consiglio che nessuna
    // schermata sapeva eseguire.
    Notification::fake();

    Livewire::actingAs($this->admin)->test(ElencoUtenti::class)
        ->call('apriInvito')
        ->set('nome', 'Secondo Admin')
        ->set('email', 'secondo@rossi.test')
        ->set('ruolo', 'Admin')
        ->call('invita');

    $secondo = User::where('email', 'secondo@rossi.test')->firstOrFail();

    expect($this->account->membri()->whereKey($secondo->id)->exists())->toBeTrue();

    // La prova che serviva a qualcosa: ora il primo Admin può uscire di scena.
    $componente = Livewire::actingAs($secondo)->test(ElencoUtenti::class)
        ->call('confermaCestino', $this->admin->id)
        ->call('cestina');

    expect($componente->get('errore'))->toBeNull()
        ->and($this->admin->fresh()->trashed())->toBeTrue();
});

it('puts a promoted Admin on the contract too, since the hole is the same from that door', function () {
    $promossa = personaDellEnte('Giulia Verdi', 'Tenant');

    Livewire::actingAs($this->admin)->test(ElencoUtenti::class)
        ->call('apriRuolo', $promossa->id)
        ->set('nuovoRuolo', 'Admin')
        ->call('cambiaRuolo');

    expect($promossa->fresh()->hasRole('Admin'))->toBeTrue()
        ->and($this->account->membri()->whereKey($promossa->id)->exists())->toBeTrue();
});

it('never takes a demoted Admin off the contract, because that invariant is not this action to break', function () {
    // ⚠️ La direzione opposta è **voluta**: `membri()` è ciò che tiene in piedi
    // «un account non resta senza amministratori», e sfilare qui vorrebbe dire
    // poterla violare con un cambio di ruolo. L'appartenenza di troppo non apre
    // niente — i permessi li porta il ruolo, che è cambiato.
    $secondo = personaDellEnte('Secondo Admin', 'Admin', ['email' => 'secondo@rossi.test']);
    $this->account->aggiungiMembro($secondo);

    Livewire::actingAs($this->admin)->test(ElencoUtenti::class)
        ->call('apriRuolo', $secondo->id)
        ->set('nuovoRuolo', 'Tenant')
        ->call('cambiaRuolo');

    expect($secondo->fresh()->hasRole('Tenant'))->toBeTrue()
        ->and($this->account->membri()->whereKey($secondo->id)->exists())->toBeTrue();
});

it('shows the binned people with their own state, because otherwise nobody could bring them back', function () {
    $uscita = personaDellEnte('Luca Bianchi', 'Tecnico');
    $uscita->delete();

    $componente = Livewire::actingAs($this->admin)->test(ElencoUtenti::class);

    expect($componente->viewData('persone')->pluck('id')->all())->toContain($uscita->id)
        ->and($componente->viewData('stati')[$uscita->id]['testo'])->toBe('Cestinata');
});

it('reads the state from the two columns that mean it, since there is no state column', function () {
    // 🔗 ADR-012: «invitato» è `email_verified_at IS NULL` più una password
    // tappo di 64 caratteri che nessuno vedrà mai. Nessun `users.is_active`.
    $invitata = personaDellEnte('Mai Entrata', 'Tenant', ['email_verified_at' => null]);

    $componente = Livewire::actingAs($this->admin)->test(ElencoUtenti::class);

    expect($componente->viewData('stati')[$invitata->id]['testo'])->toBe('Invitata, mai entrata')
        ->and($componente->viewData('stati')[$this->admin->id]['testo'])->toBe('Attiva');
});

it('does not expose row presenters as Livewire actions on arbitrary user ids', function () {
    // `User` non e tenant-scoped. Un helper pubblico con un parametro `User`
    // viene esposto da Livewire e il model binding avviene prima del corpo:
    // sarebbe un oracle sull'esistenza di persone di altri Enti.
    $pubblici = collect((new ReflectionClass(ElencoUtenti::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $m) => $m->class === ElencoUtenti::class)
        ->pluck('name');

    expect($pubblici)->not->toContain('stato')
        ->and($pubblici)->not->toContain('amministrabile');
});

// ─── 7. L'elenco non fa una query per riga ───────────────────────────────────

it('keeps the query count flat from two people to twelve', function () {
    // ⚠️ Scaldare la cache dei permessi prima di misurare: al primo render
    // spatie carica ruoli e permessi, e senza questa riga il confronto
    // misurerebbe l'ordine dei due render invece del numero di persone.
    Livewire::actingAs($this->admin)->test(ElencoUtenti::class);

    $conta = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->admin)->test(ElencoUtenti::class);
        // Filtrato per tabella, come ogni altro conteggio del progetto: un
        // totale assoluto conta anche sessione e permessi, che variano fra
        // driver e fra prima e seconda chiamata.
        $n = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'from "users"')
                || str_contains($q['query'], 'from "roles"')
                || str_contains($q['query'], 'from "model_has_roles"'))
            ->count();
        DB::disableQueryLog();

        return $n;
    };

    $conDue = $conta();

    for ($i = 0; $i < 10; $i++) {
        personaDellEnte('Persona '.$i, 'Tenant');
    }

    expect($conta())->toBe($conDue);
});
