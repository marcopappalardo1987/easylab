<?php

use App\Livewire\Piattaforma\EditorRuoli;
use App\Models\User;
use App\Support\Rbac;
use App\Support\Tenancy\VistaPiattaforma;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * 🔴 Chi entra nell'editor della matrice ruolo→permesso (S6).
 *
 * Il gemello di `AccessoRegistroAuditTest`, con il **verdetto rovesciato sulla
 * stessa domanda**: non «il Superadmin entra?» ma **su quale permesso**. Là si
 * scartò `audit.view` perché non è nel set bloccato — quindi questo editor
 * potrà ridistribuirlo — e qui si sceglie `roles.manage` perché **lo è**. Il
 * criterio è uno solo per entrambe le pagine: *si gata su un permesso del set
 * bloccato*; il registro ci arrivò per esclusione, questo per elezione.
 *
 * ⚠️ La pagina di questo blocco **non mostra nulla**: è un guscio con la sola
 * intestazione. È deliberato — una pagina vuota ma già gatata rende il gate
 * dimostrabile prima che ci sia qualcosa da proteggere, mentre l'ordine opposto
 * mette la guardia addosso a una vista già scritta e la prova diventa «non
 * sembra rotto». Questo file è quindi l'intero valore del blocco.
 */
/**
 * Gli elenchi dei dataset, **propri di questo file e per significato**.
 *
 * Non si riusano `RUOLI_CON_PIATTAFORMA` / `RUOLI_SENZA_PIATTAFORMA` perché
 * partizionano su una domanda **diversa**: quelli su `tenants.view_all`, questi
 * su `roles.manage`. Oggi le due partizioni coincidono, e proprio per questo
 * fonderle sarebbe un errore che non si vedrebbe: il giorno in cui un ruolo
 * avesse l'una e non l'altra, metà dei negativi smetterebbe di provare ciò che
 * dice di provare. A tenerli onesti — contro la matrice RBAC **e** l'uno contro
 * l'altro — c'è `keeps the hand-written role lists honest against the matrix`.
 *
 * *La prima stesura dava una ragione **meccanica** (le costanti condivise non
 * esistevano ancora quando Pest risolveva i `->with()` di questo file, che si
 * carica alfabeticamente prima di `AccessoPiattaformaTest`). Era vera, ed è
 * stata risolta alla radice il 23 Ago 2026 spostando quelle costanti in
 * `tests/Pest.php` — dove risolvono anche il difetto pre-esistente per cui
 * `AccessoRegistroAuditTest` non girava da solo. Resta la ragione di
 * significato, che regge da sé.*
 */
const RUOLI_CON_EDITOR = ['Developer', 'Superadmin'];
const RUOLI_SENZA_EDITOR = ['Admin', 'Responsabile Reparto', 'Tenant', 'Tecnico'];

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Closure e **non** una funzione globale: `utenteConRuolo()` esiste già in
    // `AccessoRegistroAuditTest`, e i file di test si caricano tutti insieme —
    // una seconda dichiarazione con lo stesso nome è un fatal di ridichiarazione.
    // Spostarla in `tests/Pest.php` sarebbe l'alternativa pulita, ma toccherebbe
    // un file condiviso per una comodità di due suite: si veda la nota di
    // `snapshotDa()`, che là è finita per necessità, non per simmetria.
    //
    // `two_factor_confirmed_at` valorizzato: Developer, Superadmin e Admin hanno
    // il 2FA obbligatorio (`Rbac::twoFactorRequiredRoles()`) e senza finirebbero
    // su /settings/security invece che dove il test guarda.
    $this->utente = fn (string $ruolo) => tap(
        User::factory()->create(['two_factor_confirmed_at' => now()]),
        fn (User $u) => $u->assignRole($ruolo)
    )->fresh();
});

// ─── I negativi ──────────────────────────────────────────────────────────────

it('refuses the editor to an Admin, who does hold audit.view but not roles.manage', function () {
    // 🔴 Il negativo del gate, con la **ragione nello stesso corpo**: l'Admin è
    // il ruolo che rende non ovvia la scelta del permesso, perché di permessi di
    // amministrazione ne ha (`audit.view` compreso) e potrebbe sembrare il
    // destinatario naturale di una pagina «di gestione». Chi un giorno vorrà
    // «aprire l'editor agli amministratori» troverà qui il rosso con la
    // spiegazione accanto, invece di un 403 muto: `roles.manage` è bloccato
    // proprio per impedire l'auto-delega (ADR-016).
    expect(Rbac::permissionsForRole('Admin'))->toContain('audit.view')
        ->and(Rbac::permissionsForRole('Admin'))->not->toContain(EditorRuoli::PERMESSO)
        ->and(Rbac::isLocked(EditorRuoli::PERMESSO))->toBeTrue();

    $this->actingAs(($this->utente)('Admin'))
        ->get(route('piattaforma.ruoli'))
        ->assertForbidden();
});

it('refuses the route to every role without roles.manage', function (string $ruolo) {
    $this->actingAs(($this->utente)($ruolo))
        ->get(route('piattaforma.ruoli'))
        ->assertForbidden();
})->with(RUOLI_SENZA_EDITOR);

it('sends a guest to the login', function () {
    $this->get(route('piattaforma.ruoli'))->assertRedirect(route('login'));
});

it('gates the render itself, not only the route', function (string $ruolo) {
    // `Livewire::test()` **disabilita i middleware**: se il gate vivesse solo
    // sulla rotta, questo passerebbe. A rispondere è il `Gate::authorize()` in
    // testa a `render()` — che qui va scritto a mano, perché a differenza del
    // registro di audit non c'è nessuna porta (`VistaPiattaforma`) che lo chieda
    // per conto della pagina: `roles` e `permissions` non hanno tenancy.
    Livewire::actingAs(($this->utente)($ruolo))
        ->test(EditorRuoli::class)
        ->assertForbidden();
})->with(RUOLI_SENZA_EDITOR);

it('keeps the permission on a real Livewire update', function () {
    // 🔴 **L'unica strada che copre un'azione con `skipRender()`**: un POST vero
    // a `/livewire/update`. Livewire rilegge `memo.path`, rimatcha la rotta e
    // riapplica i middleware **persistenti**, fra cui `Authorize` (il `can:`).
    // È il test che rende vera la scelta di dare a questa pagina una rotta
    // propria invece di un tab dentro `Cabina`: i toggle del Blocco 4 girano
    // prima di `render()`, e con `skipRender()` `render()` non gira affatto —
    // quindi il gate di rotta è la sola guardia che resta.
    //
    // ⚠️ Si toglie il **ruolo intero** e non il solo `roles.manage`: qui si prova
    // che la rotta è protetta, non *da quale* permesso. La ragione la congela
    // l'assert strutturale in fondo al file, ed è una divisione voluta — un
    // comportamento che distingue i due permessi renderebbe rossa la prova di
    // mutazione dove deve restare verde.
    $utente = ($this->utente)('Superadmin');

    $html = $this->actingAs($utente)->get(route('piattaforma.ruoli'))->assertOk()->getContent();
    $snapshot = snapshotDa($html);

    expect($snapshot)->not->toBe('');

    // Il permesso sparisce mentre la pagina è aperta: lo snapshot è firmato e
    // resta valido, quindi è esattamente il caso che il middleware deve fermare.
    $utente->removeRole('Superadmin');
    auth()->logout();
    $this->actingAs($utente->fresh());

    $this->withHeader('X-Livewire', '1')->postJson(route('default-livewire.update'), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]],
    ])->assertForbidden();
});

it('never shows the way in to someone who cannot go in', function () {
    // La voce di nav non deve comparire a chi prenderebbe 403 — una voce che
    // porta a un 403 è un invito a bussare.
    //
    // ⚠️ Il caso si costruisce **a mano**, e va detto perché: la nav vive solo
    // sulle pagine di piattaforma, quindi chi non ha `tenants.view_all` non la
    // vede affatto e non proverebbe niente. L'unico soggetto interessante è chi
    // guarda la piattaforma **ma non governa i ruoli** — uno stato che oggi
    // nessun ruolo del catalogo realizza e che l'editor stesso non può produrre,
    // perché `roles.manage` è bloccato. Lo si fabbrica con l'API di spatie, che
    // è codice, cioè la sola via che il progetto lascia aperta (ADR-027 fece
    // esattamente così su `garanzie.ricambio.*`).
    Role::findByName('Superadmin', 'web')->revokePermissionTo(EditorRuoli::PERMESSO);

    $this->actingAs(($this->utente)('Superadmin'))
        ->get(route('piattaforma.index'))
        ->assertOk()
        // Le altre due voci ci sono ancora: senza questa riga il test passerebbe
        // anche se la nav fosse sparita del tutto, o se la pagina fosse un 403.
        ->assertSee(route('piattaforma.audit'))
        ->assertDontSee(route('piattaforma.ruoli'))
        ->assertDontSee('Ruoli e permessi');
});

// ─── I positivi ──────────────────────────────────────────────────────────────

it('lets in exactly the roles that hold roles.manage', function (string $ruolo) {
    $this->actingAs(($this->utente)($ruolo))
        ->get(route('piattaforma.ruoli'))
        ->assertOk()
        // Una stringa del **corpo**, non «Ruoli e permessi», che la sub-nav
        // stampa su tutte e tre le pagine e renderebbe il test verde anche
        // atterrando sulla cabina.
        ->assertSee('Chi può fare cosa, per tutti i clienti insieme.', false);
})->with(RUOLI_CON_EDITOR);

// ─── Gli elenchi e la catena, asseriti per struttura ─────────────────────────

it('keeps the hand-written role lists honest against the matrix', function () {
    // ⚠️ **I dataset sono statici, e non è pigrizia.** La forma «robusta»
    // (`->with(fn () => collect(Rbac::roleNames())->reject(...))`) è **peggiore**:
    // i closure dei dataset si risolvono a *collection time*, prima che Laravel
    // sia avviato, quindi `config('rbac.roles')` è vuoto, il dataset nasce vuoto
    // e Pest **scarta i test** — sei negativi che non girano, col reporter che
    // dice «failed» senza elencare nulla. È già successo in
    // `AccessoPiattaformaTest`, ed è questo test a fare da rete.
    //
    // ⚠️ E la rete è doppia. La prima metà tiene gli elenchi allineati alla
    // matrice: senza, un ruolo nuovo (la Dashboard Developer è la voce di
    // roadmap successiva) resterebbe scoperto per omissione — nascerebbe, non
    // avrebbe `roles.manage`, e nessun dataset lo proverebbe. La seconda metà
    // congela che **oggi** la partizione di `roles.manage` coincide con quella
    // di `tenants.view_all`: è il fatto su cui poggia la scelta di non mettere i
    // due permessi in AND, e il giorno in cui divergesse va rivisto qui invece
    // di essere scoperto in produzione.
    $partizione = fn (string $permesso) => collect(Rbac::roleNames())
        ->partition(fn (string $r) => in_array($permesso, Rbac::permissionsForRole($r), true))
        ->map(fn ($insieme) => $insieme->values()->all());

    [$conEditor, $senzaEditor] = $partizione(EditorRuoli::PERMESSO);
    [$conPiattaforma, $senzaPiattaforma] = $partizione(VistaPiattaforma::PERMESSO);

    expect($conEditor)->toEqualCanonicalizing(RUOLI_CON_EDITOR)
        ->and($senzaEditor)->toEqualCanonicalizing(RUOLI_SENZA_EDITOR)
        // La coincidenza fra le due partizioni: asserita, non supposta.
        ->and($conEditor)->toEqualCanonicalizing($conPiattaforma)
        ->and($senzaEditor)->toEqualCanonicalizing($senzaPiattaforma);
});

it('keeps the editor behind auth, lockout, two-factor and roles.manage', function () {
    // Assert **strutturale** e non di comportamento, e la differenza è il punto:
    // oggi `roles.manage` e `tenants.view_all` stanno sugli stessi due ruoli,
    // quindi scambiare il `can:` di rotta non cambierebbe **nessun** verdetto di
    // 403. Ciò che si congela qui è la **ragione**, non l'effetto: la pagina è
    // gatata sul permesso che ADR-016 nomina per questa UI, non su quello della
    // cabina — e il giorno in cui i due divergessero, la pagina sarebbe già dal
    // lato giusto.
    $middleware = Route::getRoutes()->getByName('piattaforma.ruoli')->gatherMiddleware();

    expect($middleware)->toContain('can:'.EditorRuoli::PERMESSO)
        ->and($middleware)->toContain('auth')
        // DENTRO il gruppo protetto, e non fuori «perché è del Superadmin»: il
        // Superadmin è un utente tenant-bound con un account proprio, e se
        // quell'account fosse in lockout deve vedere /bloccato come chiunque.
        ->and($middleware)->toContain('account.lockout')
        ->and($middleware)->toContain('two-factor.enforce')
        // ⚠️ E **non** in AND con il permesso della cabina: non aggiungerebbe
        // protezione (chi passa il primo ha già il secondo) e darebbe un modo di
        // rompere la pagina — un 403 sull'editor per una ragione che non c'entra
        // col suo confine.
        ->and($middleware)->not->toContain('can:'.VistaPiattaforma::PERMESSO)
        // L'**ordine**, che è l'unica cosa che ADR-013 dice e che `toContain`
        // scarta: la condizione più forte parla per prima.
        ->and(array_search('account.lockout', $middleware, true))
        ->toBeLessThan(array_search('two-factor.enforce', $middleware, true));
});

it('shows all three platform tabs to someone who governs the whole platform', function () {
    // 🔴 Il rovescio del negativo qui sopra, e non è simmetria di cortesia: il
    // filtro della nav è nato per la **terza** voce ma si applica a tutte e tre,
    // e un permesso sbagliato sulla **prima** la farebbe sparire per tutti senza
    // che un solo test di autorizzazione se ne accorga. Una voce di menù che
    // scompare non rompe niente: semplicemente, un giorno, nessuno trova più i
    // clienti.
    //
    // ⚠️ **Si asserisce dentro il blocco della nav, non sulla pagina.** La prima
    // stesura faceva `assertSee('Clienti')` sull'HTML intero ed era verde
    // *anche con la voce rimossa*, perché quella parola compare altrove nella
    // cabina — un test che non poteva fallire, trovato mutando e non
    // rileggendolo.
    $html = $this->actingAs(utenteConRuolo('Superadmin'))
        ->get(route('piattaforma.index'))
        ->assertOk()
        ->getContent();

    preg_match('/<nav[^>]*aria-label="Sezioni della piattaforma".*?<\/nav>/s', $html, $blocco);

    expect($blocco)->not->toBeEmpty()
        ->and($blocco[0])->toContain('Clienti')
        ->and($blocco[0])->toContain('Registro di audit')
        ->and($blocco[0])->toContain('Ruoli e permessi');
});
