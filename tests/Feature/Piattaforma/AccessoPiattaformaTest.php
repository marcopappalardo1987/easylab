<?php

use App\Livewire\Piattaforma\Cabina;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Piattaforma\RiepilogoPiattaforma;
use App\Support\Rbac;
use App\Support\Tenancy\VistaPiattaforma;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * 🔴 Chi entra nella cabina di regia (S6).
 *
 * L'unica schermata che guarda oltre il proprio Ente, quindi l'unica dove un
 * errore di autorizzazione non è un 403 mancato ma **dati di altri clienti in
 * pagina**. I negativi vengono prima, e coprono le due strade separatamente: la
 * rotta (chi digita l'URL) e il **montaggio diretto del componente**, che non
 * passa dal middleware.
 */
/**
 * Gli elenchi sono **costanti condivise** fra i dataset e il test che li tiene
 * onesti: la prima stesura li ripeteva, e la prova di mutazione ha mostrato che
 * un dataset ridotto lasciava il test verde — controllava una copia, non
 * l'elenco davvero usato.
 *
 * ⚠️ Dal 23 Ago 2026 **vivono in `tests/Pest.php`**: finché stavano qui, un file
 * che le riusava non girava da solo, e uno che si carica prima di questo non
 * poteva riusarle affatto. La nota per esteso è là.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->ente = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create();

    // ⚠️ L'account di piattaforma **nella fixture**: senza, `accounts()` e
    // `accountsInclusaPiattaforma()` danno lo stesso numero e sostituirli in
    // `render()` lascerebbe tutto verde. È il difetto già trovato sulla porta,
    // che senza questa riga rientrerebbe dalla finestra sul chiamante — cioè
    // proprio dove il numero finisce in pagina davanti a qualcuno.
    Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);

    // `two_factor_confirmed_at` valorizzato: Admin, Superadmin e Developer hanno
    // il 2FA obbligatorio (`Rbac::twoFactorRequiredRoles()`), e senza finirebbero
    // su /settings/security invece che dove il test guarda. È il middleware che
    // parla per primo — giusto in produzione, rumore qui.
    $this->utente = fn (string $ruolo) => tap(
        User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]),
        fn (User $u) => $u->assignRole($ruolo)
    )->fresh();
});

// ─── I negativi ──────────────────────────────────────────────────────────────

it('sends a guest to the login instead of the cabin', function () {
    $this->get(route('piattaforma.index'))->assertRedirect(route('login'));
});

it('refuses the route to everyone without the platform permission', function (string $ruolo) {
    $this->actingAs(($this->utente)($ruolo))
        ->get(route('piattaforma.index'))
        ->assertForbidden();
})->with(RUOLI_SENZA_PIATTAFORMA);

it('gates the render itself, not only the route', function (string $ruolo) {
    // `Livewire::test()` gira **senza middleware** (il pacchetto disabilita
    // tutto per le richieste finte), quindi questo NON prova la strada di
    // `/livewire/update` — la prova quella sotto, con un POST vero. Qui si
    // congela una cosa diversa e comunque utile: il `render()` è gatato **di
    // per sé**, indipendentemente da come ci si arriva.
    //
    // ⚠️ La prima stesura di questo commento diceva che il middleware di rotta
    // non arriva sugli update Livewire. È falso: `Authorize` è fra i
    // persistenti del pacchetto. Il test resta, il racconto no.
    Livewire::actingAs(($this->utente)($ruolo))
        ->test(Cabina::class)
        ->assertForbidden();
})->with(RUOLI_SENZA_PIATTAFORMA);

it('never shows the way in to someone who cannot go in', function (string $ruolo) {
    // Il permesso governa anche la **visibilità** della voce, non solo
    // l'accesso: una voce che porta a un 403 è un invito a bussare.
    $this->actingAs(($this->utente)($ruolo))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('piattaforma.index'))
        ->assertDontSee('Vai alla cabina di regia');
})->with(RUOLI_SENZA_PIATTAFORMA);

// ─── I positivi ──────────────────────────────────────────────────────────────

it('lets in whoever holds the platform permission', function (string $ruolo) {
    $this->actingAs(($this->utente)($ruolo))
        ->get(route('piattaforma.index'))
        ->assertOk()
        // Il **corpo**, non la parola «Piattaforma»: quella la stampa anche la
        // sidebar, quindi svuotare la cabina lascerebbe il test verde.
        ->assertSee('Ricavo mensile');
})->with(RUOLI_CON_PIATTAFORMA);

it('shows the way in on the dashboard to whoever can walk it', function () {
    $this->actingAs(($this->utente)('Superadmin'))
        ->get(route('dashboard'))
        ->assertOk()
        // Stringa che vive **solo** nella card: l'URL da solo non basterebbe,
        // perché lo emette anche la voce di sidebar sullo stesso layout —
        // quindi togliere la card avrebbe lasciato il test verde.
        ->assertSee('Vai alla cabina di regia')
        ->assertSee(route('piattaforma.index'));
});

it('counts sedi across every tenant, which is what proves the boundary was crossed', function () {
    // ⚠️ **Il conteggio dei clienti non proverebbe niente**: `Account` non ha
    // global scope, quindi anche una query nuda di un Tenant qualunque ne
    // vedrebbe due. La sonda che morde è quella sulle **sedi**, dove
    // `TenantScope` è attivo e la porta lo toglie per nome. (Stessa lezione del
    // blocco B, dove la sonda scelta era l'unico componente già non-scopato.)
    $altro = Account::factory()->create(['ragione_sociale' => 'Lab Bianchi']);
    UnitaOrganizzativa::factory()->ente()->perAccount($altro)->create();

    // Si asserisce sull'**oggetto** e non sul markup: un `assertSee('2')` su una
    // pagina con quattro numeri passerebbe per il motivo sbagliato, e cambiare
    // una label renderebbe rosso un test che parla di confini.
    Livewire::actingAs(($this->utente)('Superadmin'))
        ->test(Cabina::class)
        ->assertViewHas('riepilogo', fn (RiepilogoPiattaforma $r) => $r->clienti === 2   // EasyLab esclusa: 3 col metodo sbagliato
            && $r->sedi === 2                                                            // l'altra è di un tenant che nessuna schermata mostra
        );
});

it('keeps the hand-written role lists honest against the matrix', function () {
    // ⚠️ **I due dataset qui sopra sono scritti a mano, e la prima stesura no.**
    // Erano `->with(fn () => collect(Rbac::roleNames())->reject(...))`, per
    // aggiornarsi da soli se S6 aggiungesse un ruolo. Sembrava più robusto ed
    // era **peggio**: i closure dei dataset si risolvono a *collection time*,
    // prima che Laravel sia avviato, quindi `config('rbac.roles')` era vuoto,
    // il dataset nasceva vuoto e Pest **scartava i due test**. Sei casi negativi
    // — fra cui tutti i 403 sulla rotta — non giravano, e il conteggio della
    // suite non se ne accorgeva: 9 test eseguiti invece di 15.
    //
    // Elenco statico, quindi, più questo test che lo tiene onesto contro la
    // matrice: è la stessa forma dei conteggi ripetuti in `RbacSeederTest`, dove
    // «se cambia qui deve cambiare anche là, o uno dei due sta mentendo».
    $senzaPermesso = collect(Rbac::roleNames())
        ->reject(fn (string $r) => in_array(VistaPiattaforma::PERMESSO, Rbac::permissionsForRole($r), true))
        ->values()->all();

    $conPermesso = collect(Rbac::roleNames())
        ->filter(fn (string $r) => in_array(VistaPiattaforma::PERMESSO, Rbac::permissionsForRole($r), true))
        ->values()->all();

    expect($senzaPermesso)->toEqualCanonicalizing(RUOLI_SENZA_PIATTAFORMA)
        ->and($conPermesso)->toEqualCanonicalizing(RUOLI_CON_PIATTAFORMA);
});

it('keeps the platform permission on a real Livewire update', function () {
    // ⚠️ **La strada vera**, quella che `Livewire::test()` non percorre: un POST
    // a `/livewire/update`. Livewire rilegge `memo.path`, rimatcha la rotta e
    // riapplica i middleware **persistenti** — fra cui `Illuminate\Auth\
    // Middleware\Authorize`, cioè il `can:`. Il precedente è
    // `LockoutEnforcementTest`, scritto per lo stesso motivo su ADR-013.
    //
    // Serve perché i blocchi successivi porteranno **azioni**: quelle girano
    // prima di `render()`, e con `skipRender()` `render()` non gira affatto —
    // quindi il gate di rotta è la sola guardia che resta, non la ridondante.
    $superadmin = ($this->utente)('Superadmin');

    $html = $this->actingAs($superadmin)->get(route('piattaforma.index'))->assertOk()->getContent();
    $snapshot = snapshotDa($html);
    expect($snapshot)->not->toBe('');

    // Il permesso sparisce mentre la pagina è aperta: lo snapshot è firmato e
    // resta valido, quindi è esattamente il caso che il middleware deve fermare.
    $superadmin->removeRole('Superadmin');
    auth()->logout();
    $this->actingAs($superadmin->fresh());

    $this->withHeader('X-Livewire', '1')->postJson(route('default-livewire.update'), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]],
    ])->assertForbidden();
});

// ─── La catena di middleware, asserita per struttura ─────────────────────────

it('keeps the cabin behind auth, lockout, two-factor and the platform permission', function () {
    // Assert **strutturale** e non di comportamento: i quattro middleware
    // coprono quattro domande diverse, e tre di esse hanno già i loro test
    // altrove (login, lockout, 2FA). Qui si congela che la cabina non sia stata
    // messa fuori dal gruppo protetto «perché è del Superadmin» — che è la
    // scorciatoia plausibile, e sbagliata: il Superadmin ha un account proprio,
    // e se fosse in lockout deve vedere /bloccato come chiunque.
    $middleware = Route::getRoutes()->getByName('piattaforma.index')->gatherMiddleware();

    expect($middleware)->toContain('can:'.VistaPiattaforma::PERMESSO)
        ->and($middleware)->toContain('auth')
        ->and($middleware)->toContain('account.lockout')
        ->and($middleware)->toContain('two-factor.enforce')
        // L'**ordine**, che è l'unica cosa che ADR-013 dice e che `toContain`
        // scarta: la condizione più forte parla per prima, quindi un Admin
        // bloccato e senza 2FA deve finire su /bloccato e non sul setup.
        ->and(array_search('account.lockout', $middleware, true))
        ->toBeLessThan(array_search('two-factor.enforce', $middleware, true));
});
