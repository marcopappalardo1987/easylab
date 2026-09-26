<?php

use App\Livewire\Piattaforma\RegistroAudit;
use App\Support\Rbac;
use App\Support\Tenancy\VistaPiattaforma;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * Chi entra nel registro di audit, e chi no (S6).
 *
 * La domanda che questo file esiste per congelare non è «il Superadmin entra?»
 * ma **su quale permesso**. `audit.view` esiste a catalogo, sembra fatto
 * apposta, e ce l'ha anche l'Admin: gatarci sopra una vista **cross-tenant**
 * aprirebbe il registro di ogni cliente a ogni Admin.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

// `utenteConRuolo()` e i due dataset vivono in `tests/Pest.php`: finché
// stavano nei file di test, questo file non girava da solo (`DatasetMissing`).

// --- Negativi ---

it('refuses the register to an Admin, who does hold audit.view', function () {
    // 🔴 Il test dell'intera decisione. Le due asserzioni stanno **nello stesso
    // corpo** di proposito: chi un giorno vorrà «riparare» il permesso
    // inutilizzato gatandoci sopra la rotta troverà questo rosso con la ragione
    // accanto, invece di un 403 senza spiegazione.
    expect(Rbac::permissionsForRole('Admin'))->toContain('audit.view');

    $this->actingAs(utenteConRuolo('Admin'))
        ->get(route('piattaforma.audit'))
        ->assertForbidden();
});

it('refuses the route to every role without the platform permission', function (string $ruolo) {
    $this->actingAs(utenteConRuolo($ruolo))
        ->get(route('piattaforma.audit'))
        ->assertForbidden();
})->with(RUOLI_SENZA_PIATTAFORMA);

it('sends a guest to the login', function () {
    $this->get(route('piattaforma.audit'))->assertRedirect(route('login'));
});

it('gates the render itself, not only the route', function (string $ruolo) {
    // `Livewire::test()` **disabilita i middleware**: se il gate vivesse solo
    // sulla rotta, questo passerebbe. È la porta dentro `render()` a rispondere.
    Livewire::actingAs(utenteConRuolo($ruolo))
        ->test(RegistroAudit::class)
        ->assertForbidden();
})->with(RUOLI_SENZA_PIATTAFORMA);

it('keeps the permission on a real Livewire update', function () {
    // L'unica strada che copre un'azione con `skipRender()`: il `can:` di rotta
    // è fra i middleware persistenti di Livewire, e va provato con un POST vero.
    $utente = utenteConRuolo('Superadmin');

    $html = $this->actingAs($utente)->get(route('piattaforma.audit'))->assertOk()->getContent();
    $snapshot = snapshotDa($html, 'piattaforma.registro-audit');

    expect($snapshot)->not->toBe('');

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

// --- Positivi e struttura ---

it('lets in exactly the roles that hold the platform permission', function (string $ruolo) {
    // Il set arriva dalla costante che un test esistente tiene onesta contro la
    // matrice RBAC: elencarlo a mano lascerebbe scoperto il Developer.
    $this->actingAs(utenteConRuolo($ruolo))
        ->get(route('piattaforma.audit'))
        ->assertOk()
        // Una stringa del **corpo**, non «Registro di audit», che la sub-nav
        // stampa su tutte le pagine di piattaforma e renderebbe il test verde anche
        // atterrando sulla cabina.
        ->assertSee('Chi ha fatto cosa, e quando, su tutta la piattaforma.', false);
})->with(RUOLI_CON_PIATTAFORMA);

it('says from which date the attribution can be trusted', function () {
    // Non è una nota di colore: è il limite di affidabilità del dato. Le righe
    // scritte durante un'impersonazione prima del timbro nominano l'impersonato.
    $this->actingAs(utenteConRuolo('Superadmin'))
        ->get(route('piattaforma.audit'))
        ->assertSee('durante un\'impersonazione', false)
        ->assertSee('22 Ago 2026');
});

it('keeps the register behind auth, lockout, two-factor and the platform permission', function () {
    $middleware = Route::getRoutes()->getByName('piattaforma.audit')->gatherMiddleware();

    expect($middleware)->toContain('can:'.VistaPiattaforma::PERMESSO)
        ->and($middleware)->toContain('auth')
        ->and($middleware)->toContain('account.lockout')
        ->and($middleware)->toContain('two-factor.enforce')
        // ⚠️ E **non** su `audit.view`: quel permesso non è nel set bloccato,
        // quindi l'editor di S6 potrà ridistribuirlo. Gatare una vista
        // cross-tenant su un permesso ridistribuibile è una falla ad
        // attivazione differita.
        ->and($middleware)->not->toContain('can:audit.view')
        ->and(array_search('account.lockout', $middleware, true))
        ->toBeLessThan(array_search('two-factor.enforce', $middleware, true));
});
