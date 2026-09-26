<?php

use App\Livewire\Piattaforma\Cabina;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\InvitoUtente;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * La quarta leva: creare un cliente, o una sede in più (S6 — ADR-012, ADR-032).
 *
 * Chiude l'ultima riga aperta della DoD di S5 — «Free chiavi in mano» — e con
 * essa l'ultima funzione che viveva solo in un terminale.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->suoEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'EasyLab']);
    $this->superadmin = User::factory()->create([
        'tenant_id' => $this->suoEnte->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->superadmin->assignRole('Superadmin');
    $this->actingAs($this->superadmin->fresh());

    Notification::fake();
});

// --- Negativi ---

it('refuses the whole lever to whoever may see the page but not provision', function () {
    $osservatore = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $osservatore->givePermissionTo('tenants.view_all');
    $this->actingAs($osservatore->fresh());

    Livewire::test(Cabina::class)->assertDontSee('Nuovo cliente');
    Livewire::test(Cabina::class)->call('apriProvisioning')->assertForbidden();

    // ⚠️ E anche l'azione che **scrive**, chiamata senza passare dall'apertura:
    // le property sono pubbliche, quindi `set` + `call` è a un `$wire` di
    // distanza. È il difetto trovato sulle tre leve precedenti, dove togliere
    // il controllo dalle azioni lasciava la suite interamente verde.
    Livewire::test(Cabina::class)
        ->set('provisioningAperto', true)
        ->set('nuovo', ['nome' => 'Abusivo', 'adminEmail' => 'x@y.test', 'adminName' => 'X'])
        ->call('creaCliente')
        ->assertForbidden();

    expect(UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Abusivo')->exists())->toBeFalse();
});

it('refuses to attach a sede to an account that is not a customer', function () {
    $piattaforma = Account::factory()->create(['di_piattaforma' => true]);

    expect(fn () => Livewire::test(Cabina::class)->call('apriProvisioning', $piattaforma->id))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => Livewire::test(Cabina::class)
        ->set('provisioningAperto', true)
        ->set('provisioningAccount', $piattaforma->id)
        ->set('nuovo', ['nome' => 'Sede', 'adminEmail' => 'x@y.test', 'adminName' => 'X'])
        ->call('creaCliente'))
        ->toThrow(ModelNotFoundException::class);
});

it('never attaches a brand-new customer to somebody else contract', function () {
    // 🔴 Il buco che il confronto ha trovato. `ProvisionaEnte` ha **due** modi di
    // trovare un account: l'id esplicito e — se l'email dell'amministratore
    // appartiene già a qualcuno — il suo. Il secondo non passava né da
    // `VistaPiattaforma` né da `manage`, e il form intitolato «Nuovo cliente»
    // mostrava un messaggio di successo per una sede finita sul contratto di un
    // terzo che l'operatore non aveva mai nominato.
    $altro = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $suoAdmin = User::factory()->create(['email' => 'mario@rossi.test']);
    $altro->aggiungiMembro($suoAdmin);

    Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        ->set('nuovo', ['nome' => 'Clinica Nuova', 'adminEmail' => 'mario@rossi.test', 'adminName' => 'Mario'])
        ->call('creaCliente')
        ->assertHasErrors('nuovo.nome')
        // Il messaggio nomina il cliente e la strada, non un id che la tabella
        // non mostra.
        ->assertSee('Gruppo Rossi');

    expect($altro->fresh()->enti()->count())->toBe(0)
        ->and(UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Clinica Nuova')->exists())->toBeFalse();
});

it('never attaches a brand-new customer to the platform account itself', function () {
    // Il caso peggiore della stessa strada: il Superadmin è membro dell'account
    // di piattaforma, quindi «Nuovo cliente» con la propria email creava una
    // sede **dentro EasyLab**.
    $piattaforma = Account::factory()->create(['ragione_sociale' => 'EasyLab', 'di_piattaforma' => true]);
    $piattaforma->aggiungiMembro($this->superadmin);

    Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        ->set('nuovo', ['nome' => 'Finto Cliente', 'adminEmail' => $this->superadmin->email, 'adminName' => 'Io'])
        ->call('creaCliente')
        ->assertHasErrors('nuovo.nome');

    expect($piattaforma->fresh()->enti()->count())->toBe(0);
});

it('does not blow up on a customer whose plan left the catalogue', function () {
    // 🔴 `puoAggiungereEnte()` passa da `Piani::maxEnti()`, che **lancia** su un
    // codice sconosciuto. La cabina è l'unica schermata da cui quel dato si
    // ripara: morire proprio lì è il modo peggiore di segnalarlo.
    $rotto = Account::factory()->create(['ragione_sociale' => 'Piano Dismesso SPA', 'piano' => 'legacy_2024']);
    UnitaOrganizzativa::factory()->ente()->perAccount($rotto)->create();

    Livewire::test(Cabina::class)
        ->call('apriProvisioning', $rotto->id)
        ->set('nuovo', ['nome' => 'Seconda Sede', 'adminEmail' => 'due@dismesso.test', 'adminName' => 'Due'])
        ->call('creaCliente')
        ->assertHasErrors('nuovo.nome')
        ->assertSee('non è più a catalogo');
});

it('says so when the invitation did not leave', function () {
    // 🟠 Leggendo il solo `invitoAccodato`, un SMTP giù dava lo **stesso**
    // messaggio del caso legittimo «l'utente è già attivo, nessun invito da
    // mandare»: l'operatore chiudeva convinto e l'amministratore aspettava una
    // mail che non sarebbe arrivata. Il comando in console avvisa da sempre.
    Notification::shouldReceive('send')->andThrow(new RuntimeException('SMTP irraggiungibile'));

    Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        ->set('nuovo', ['nome' => 'Clinica Aurora', 'adminEmail' => 'admin@aurora.test', 'adminName' => 'Bruno'])
        ->call('creaCliente')
        ->assertHasNoErrors()
        ->assertSee('NON è partito');

    // E il cliente **è** stato creato: le scritture sono committate, l'invito no.
    expect(UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Clinica Aurora')->exists())->toBeTrue();
});

it('turns the plan limit into an error beside the form, and writes nothing', function () {
    // 🔴 «Il piano non consente un'altra sede» è una cosa che l'operatore deve
    // leggere accanto al campo, non una pagina di errore. E il rifiuto arriva
    // **prima della transazione**: chi viene respinto non deve lasciarsi dietro
    // né un account né un utente.
    $cliente = Account::factory()->create(['ragione_sociale' => 'Lab Rossi']); // free: 1 Ente
    UnitaOrganizzativa::factory()->ente()->perAccount($cliente)->create();

    $prima = [
        'nodi' => UnitaOrganizzativa::withoutGlobalScopes()->count(),
        'utenti' => User::count(),
        'account' => Account::count(),
    ];

    Livewire::test(Cabina::class)
        ->call('apriProvisioning', $cliente->id)
        ->set('nuovo', ['nome' => 'Seconda Sede', 'adminEmail' => 'due@rossi.test', 'adminName' => 'Bruno Neri'])
        ->call('creaCliente')
        ->assertHasErrors('nuovo.nome')
        ->assertSee('limite raggiunto');

    expect(UnitaOrganizzativa::withoutGlobalScopes()->count())->toBe($prima['nodi'])
        ->and(User::count())->toBe($prima['utenti'])
        ->and(Account::count())->toBe($prima['account']);
});

it('never offers a plan to choose from', function () {
    // I piani a pagamento passano da Stripe: un menù offrirebbe un'opzione che
    // fallisce al salvataggio, o creerebbe un account marcato «saas» senza
    // subscription — un cliente che risulta pagante e non paga.
    Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        ->assertSee('nasce sul piano')
        ->assertDontSeeHtml('wire:model="nuovo.piano"');
});

it('refuses an incomplete form without touching the database', function () {
    $prima = UnitaOrganizzativa::withoutGlobalScopes()->count();

    Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        ->set('nuovo', ['nome' => '', 'adminEmail' => 'non-una-email', 'adminName' => ''])
        ->call('creaCliente')
        ->assertHasErrors(['nuovo.nome', 'nuovo.adminEmail', 'nuovo.adminName']);

    expect(UnitaOrganizzativa::withoutGlobalScopes()->count())->toBe($prima);
});

// --- Positivi ---

it('creates a Free customer with its Ente, its Admin and an invitation', function () {
    Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        ->set('nuovo', [
            'nome' => 'Ospedale San Giovanni',
            'adminEmail' => '  ANNA.BIANCHI@SanGiovanni.it ',
            'adminName' => 'Anna Bianchi',
        ])
        ->call('creaCliente')
        ->assertHasNoErrors()
        ->assertSet('provisioningAperto', false)
        // ⚠️ «In consegna» e **mai** «inviato»: la notifica è accodata, quindi
        // la pagina non sa se partirà. Affermarlo sarebbe una bugia che nessuno
        // può smentire guardando questa schermata — e la frase è l'unica prova
        // che l'operatore ha del gesto.
        ->assertSee('Invito in consegna a')
        ->assertDontSee('Invito inviato a');

    $ente = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Ospedale San Giovanni')->firstOrFail();
    // L'email si normalizza: due maiuscole in più creerebbero un secondo utente
    // per la stessa persona, e il `firstOrCreate` del provisioning non se ne
    // accorgerebbe.
    $admin = User::where('email', 'anna.bianchi@sangiovanni.it')->firstOrFail();

    expect($ente->tenant_id)->toBe($ente->id)
        ->and($ente->account->piano)->toBe('free')
        ->and($admin->tenant_id)->toBe($ente->id)
        ->and($admin->hasRole('Admin'))->toBeTrue()
        ->and($admin->email_verified_at)->toBeNull();

    Notification::assertSentTo($admin, InvitoUtente::class);
});

it('roots the new Ente on itself, with the Superadmin authenticated', function () {
    // 🔴 Il difetto latente che questa pagina rende raggiungibile per la prima
    // volta: il Superadmin è **tenant-bound** (ADR-018 non concede bypass), e da
    // console `CurrentTenant::shouldScope()` era sempre falso. Senza
    // `radicaComeEnte()`, `BelongsToTenant::creating` timbrerebbe l'Ente nuovo
    // col tenant di chi provisiona — cioè il cliente nascerebbe **dentro l'Ente
    // di EasyLab**, e da lì in poi ogni sua riga sarebbe visibile a chi non deve.
    Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        ->set('nuovo', ['nome' => 'Clinica Aurora', 'adminEmail' => 'admin@aurora.test', 'adminName' => 'Bruno Neri'])
        ->call('creaCliente');

    $ente = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Clinica Aurora')->firstOrFail();

    expect($ente->tenant_id)->toBe($ente->id)
        ->and($ente->tenant_id)->not->toBe($this->suoEnte->id);
});

it('adds a sede to an existing customer, on the same contract', function () {
    $cliente = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    UnitaOrganizzativa::factory()->ente()->perAccount($cliente)->create(['nome' => 'Sede di Milano']);

    Livewire::test(Cabina::class)
        ->call('apriProvisioning', $cliente->id)
        // ⚠️ Asserito su una frase che esiste **solo** dentro la modale: la
        // ragione sociale la tabella la stampa comunque, quindi
        // `assertSee('Gruppo Rossi')` era verde anche togliendo l'intero
        // paragrafo — provato per mutazione dal confronto.
        ->assertSee('— la sede — si aggiunge al contratto di')
        ->assertSee('sedi 1 / 5')
        ->set('nuovo', ['nome' => 'Sede di Bergamo', 'adminEmail' => 'bergamo@rossi.test', 'adminName' => 'Carla Verdi'])
        ->call('creaCliente')
        ->assertHasNoErrors();

    $nuova = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Sede di Bergamo')->firstOrFail();

    expect($nuova->account_id)->toBe($cliente->id)
        ->and($cliente->fresh()->enti()->count())->toBe(2);
});

it('shows the new customer without making anyone go looking for it', function () {
    // Una creazione che non si vede si ripete — e con un filtro addosso la
    // pagina diceva «Nessun cliente con questi filtri» **sotto** il messaggio di
    // successo.
    Account::factory()->count(25)->create();

    $t = Livewire::test(Cabina::class)->call('gotoPage', 2);

    $t->call('apriProvisioning')
        ->set('nuovo', ['nome' => 'Zeta Ultimo', 'adminEmail' => 'z@ultimo.test', 'adminName' => 'Zeta'])
        ->call('creaCliente');

    // ⚠️ Asserito su **ciò che si vede**, non sul numero di pagina. La prima
    // stesura verificava `currentPage() === 1`, che è un'altra cosa: con
    // l'ordinamento per ragione sociale il cliente nuovo cade dove capita, e
    // tornare a pagina 1 lo rende *meno* probabile da vedere, non più.
    expect($t->viewData('clienti')->getCollection()->pluck('ragione_sociale')->all())
        ->toContain('Zeta Ultimo');
});

// --- Scopribilità: la Piattaforma deve dire «Ente» ---
//
// 🧭 Questa pagina è l'UNICO posto dove un Ente nasce, ma parlava solo di
// «cliente» e di «sede»: chi cercava dove creare un Ente non trovava la parola
// da nessuna parte, e l'Anagrafica — che invece la usa — non lo crea. Il
// vocabolario diviso era il difetto, non il permesso.

it('names the Ente where the Ente is actually born', function () {
    Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        ->assertSee('Nuovo cliente e prima sede (Ente)');
});

it('names the Ente also when adding one to an existing customer', function () {
    $cliente = Account::factory()->create(['ragione_sociale' => 'Rossi SpA']);
    UnitaOrganizzativa::factory()->ente()->perAccount($cliente)->create(['nome' => 'Sede di Milano']);

    Livewire::test(Cabina::class)
        ->call('apriProvisioning', $cliente->id)
        ->assertSee('Nuovo Ente (sede) — Rossi SpA');
});

// --- Il nome della prima sede ---
//
// 🧭 Nel Parco clienti la colonna SEDE del primo Ente ripeteva la ragione
// sociale, perché il provisioning usava un valore solo per due cose diverse. Il
// nodo Ente non si può togliere (è la radice del tenant: `strumenti.tenant_id`
// e compagnia puntano lì), ma il suo nome non deve più essere imposto.

it('lets the first sede have a name of its own', function () {
    Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        // Il campo esiste, ed è dichiarato facoltativo dove lo si compila.
        ->assertSee('Nome della prima sede (facoltativo)')
        // E l'altro campo dice il vero: qui `nome` è la ragione sociale.
        ->assertSee('Ragione sociale del cliente')
        ->set('nuovo', [
            'nome' => 'Gruppo Rossi SpA',
            // Lo spazio in coda è il caso più comune di tutti (incollare da un
            // gestionale) e non deve produrre una sede col nome sporco.
            'nomeSede' => '  Laboratorio San Raffaele  ',
            'adminEmail' => 'admin@rossi.test',
            'adminName' => 'Anna Bianchi',
        ])
        ->call('creaCliente')
        ->assertHasNoErrors();

    $ente = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Laboratorio San Raffaele')->firstOrFail();

    // I **due** valori, entrambi: la ragione sociale è dell'Account, il nome
    // della sede è del nodo. Asserire solo il secondo lascerebbe passare una
    // versione che rinomina anche il cliente.
    expect($ente->account->ragione_sociale)->toBe('Gruppo Rossi SpA')
        ->and($ente->nome)->toBe('Laboratorio San Raffaele');
});

it('keeps today behaviour when the sede is left blank', function () {
    Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        ->set('nuovo', [
            'nome' => 'Clinica Aurora',
            'nomeSede' => '',
            'adminEmail' => 'aurora@demo.test',
            'adminName' => 'Bruno Neri',
        ])
        ->call('creaCliente')
        // ⚠️ Il campo è facoltativo: lasciato in bianco non deve inciampare in
        // `min:2`, che è ciò che accadrebbe senza la normalizzazione a `null`.
        ->assertHasNoErrors();

    $ente = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Clinica Aurora')->firstOrFail();

    expect($ente->account->ragione_sociale)->toBe('Clinica Aurora');
});

it('does not ask for a sede name when the sede IS the thing being named', function () {
    $cliente = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    UnitaOrganizzativa::factory()->ente()->perAccount($cliente)->create(['nome' => 'Sede di Milano']);

    Livewire::test(Cabina::class)
        ->call('apriProvisioning', $cliente->id)
        ->assertDontSee('Nome della prima sede')
        ->assertSee('Nome dell\'Ente (la sede)')
        ->set('nuovo', [
            'nome' => 'Sede di Bergamo',
            // 🔴 Forgiato: `nuovo` è pubblico, quindi il campo che la modale non
            // mostra si può inviare lo stesso. Non deve decidere il nome di un
            // nodo che l'operatore ha nominato nell'altro campo — altrimenti la
            // pagina scriverebbe qualcosa che nessuno ha visto.
            'nomeSede' => 'Nome Iniettato',
            'adminEmail' => 'bergamo@rossi.test',
            'adminName' => 'Carla Verdi',
        ])
        ->call('creaCliente')
        ->assertHasNoErrors();

    expect(UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Sede di Bergamo')->exists())->toBeTrue()
        ->and(UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Nome Iniettato')->exists())->toBeFalse();
});

it('calls the first field by what it actually is, in both branches', function () {
    // L'etichetta di validazione è l'unica parola che l'operatore legge quando
    // sbaglia: chiamare «nome della sede» un campo che chiede la **ragione
    // sociale** lo manda a correggere l'altro campo — quello che la sede la
    // chiede davvero, e che è lì accanto.
    $nuovo = Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        ->set('nuovo', ['nome' => '', 'adminEmail' => 'x@y.test', 'adminName' => 'Xy'])
        ->call('creaCliente');

    expect($nuovo->instance()->getErrorBag()->first('nuovo.nome'))->toContain('ragione sociale');

    $cliente = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    UnitaOrganizzativa::factory()->ente()->perAccount($cliente)->create(['nome' => 'Sede di Milano']);

    $sede = Livewire::test(Cabina::class)
        ->call('apriProvisioning', $cliente->id)
        ->set('nuovo', ['nome' => '', 'adminEmail' => 'x@y.test', 'adminName' => 'Xy'])
        ->call('creaCliente');

    // Nell'altro ramo `nome` È il nome della sede, e l'etichetta storica resta
    // quella giusta.
    expect($sede->instance()->getErrorBag()->first('nuovo.nome'))->toContain('nome della sede');
});
