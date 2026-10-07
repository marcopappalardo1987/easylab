<?php

use App\Http\Middleware\RequireSameOriginNavigation;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/**
 * Le rotte che FANNO entrare in casa d'altri (security pass S7, T1b — 🔗
 * ADR-016, ADR-018).
 *
 * ⚠️ Il blocco «dopo il login» e l'uscita da un sito esterno sono ROSSI finché
 * l'orchestratore non applica R-T1b-7 (priorità di `RequireSameOriginNavigation`
 * davanti ad `AuthenticatesRequests`) e R-T1b-10 (guardia su `leave`).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente Cliente']);
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Ematologia']);
    $this->strumento = Strumento::factory()->forNode($dip)->create(['nome' => 'Contaglobuli']);

    $this->cliente = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->cliente->assignRole('Admin');

    $this->superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');

    Route::middleware(['web', RequireSameOriginNavigation::class])->get('/_prova/navigazione', fn () => 'dentro');
});

// --- Il middleware, su una rotta di prova ---

it('lets through a navigation started from one of our pages', function () {
    $this->get('/_prova/navigazione', ['Sec-Fetch-Site' => 'same-origin'])->assertOk();
});

it('lets through an address typed or bookmarked by the user', function () {
    $this->get('/_prova/navigazione', ['Sec-Fetch-Site' => 'none'])->assertOk();
});

it('refuses a navigation started from another site, or a sibling subdomain', function (string $sito) {
    $this->get('/_prova/navigazione', ['Sec-Fetch-Site' => $sito])->assertForbidden();
})->with(['cross-site', 'same-site']);

it('accepts our own referer behind a tls-terminating proxy, whatever scheme and port the app sees', function () {
    // Sospetto S1 della caccia T1b: l'app dietro il proxy vede `http` e la porta
    // interna, il browser ha navigato in `https:443`.
    $this->get('http://easylab.test:8080/_prova/navigazione', ['Referer' => 'https://easylab.test/piattaforma'])->assertOk();
    $this->get('http://easylab.test/_prova/navigazione', [
        'Referer' => 'https://easylab.test/piattaforma',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Port' => '443',
    ])->assertOk();
    $this->get('http://easylab.test:8080/_prova/navigazione', ['Referer' => 'https://altro-sito.example/piattaforma'])->assertForbidden();
});

it('falls back to the referer when the browser sends no fetch metadata', function () {
    $this->get('/_prova/navigazione', ['Referer' => 'https://pagina-malevola.example/trappola'])->assertForbidden();
    $this->get('/_prova/navigazione', ['Referer' => url('/piattaforma')])->assertOk();
});

it('never trusts a referer that only starts like our host', function () {
    $ospite = parse_url(url('/'), PHP_URL_HOST);

    $this->get('/_prova/navigazione', ['Referer' => "http://{$ospite}.malevolo.example/"])->assertForbidden();
});

// --- Sulle rotte vere ---

it('refuses to start an impersonation from a link on another site', function () {
    $this->actingAs($this->superadmin)
        ->get(route('impersonate', $this->cliente), ['Sec-Fetch-Site' => 'cross-site'])
        ->assertForbidden();

    expect(app('impersonate')->isImpersonating())->toBeFalse();
});

it('refuses the jump to a machine from a link on another site', function () {
    $this->actingAs($this->superadmin)
        ->get(route('piattaforma.parco.impersona', ['utente' => $this->cliente->id, 'strumento' => $this->strumento->id]), [
            'Sec-Fetch-Site' => 'cross-site',
        ])
        ->assertForbidden();

    expect(app('impersonate')->isImpersonating())->toBeFalse();
});

it('still starts an impersonation from our own cabina', function () {
    $this->actingAs($this->superadmin)
        ->get(route('impersonate', $this->cliente), ['Sec-Fetch-Site' => 'same-origin'])
        ->assertRedirect('/');

    expect(app('impersonate')->isImpersonating())->toBeTrue();
});

it('never lets a superadmin without two factor enter somebody else\'s tenant', function () {
    // Il 2FA è obbligatorio per il Superadmin (`two_factor_required_roles`), e
    // ogni rotta della piattaforma lo impone. `impersonate/take` stava nel solo
    // gruppo `auth`: da lì, senza 2FA, si entrava in qualunque tenant — e una
    // volta dentro `two-factor.enforce` lascia passare tutto perché si impersona.
    $senza2fa = User::factory()->create(['two_factor_confirmed_at' => null]);
    $senza2fa->assignRole('Superadmin');

    $this->actingAs($senza2fa)
        ->get(route('impersonate', $this->cliente), ['Sec-Fetch-Site' => 'same-origin'])
        ->assertRedirect(route('settings.security'));

    expect(app('impersonate')->isImpersonating())->toBeFalse();
});

it('lets the developer start an impersonation without a second factor', function () {
    // 🔗 ADR-046, decisione di Marco del 6 Ott 2026: il Developer è l'account
    // con cui si fa debug, e dal 6 Ott non è più fra i
    // `two_factor_required_roles`. È il SOLO ruolo di piattaforma esentato: il
    // test qui sopra resta la prova che per il Superadmin non è cambiato nulla.
    $developer = User::factory()->create(['two_factor_confirmed_at' => null]);
    $developer->assignRole('Developer');

    $this->actingAs($developer)
        ->get(route('impersonate', $this->cliente), ['Sec-Fetch-Site' => 'same-origin'])
        ->assertRedirect('/');

    expect(app('impersonate')->isImpersonating())->toBeTrue();
});

it('still refuses the developer an impersonation started from another site', function () {
    // L'esenzione tocca il secondo fattore, non l'altra guardia della rotta:
    // un link su un altro sito non deve far entrare nessuno in un tenant.
    $developer = User::factory()->create(['two_factor_confirmed_at' => null]);
    $developer->assignRole('Developer');

    $this->actingAs($developer)
        ->get(route('impersonate', $this->cliente), ['Sec-Fetch-Site' => 'cross-site'])
        ->assertForbidden();

    expect(app('impersonate')->isImpersonating())->toBeFalse();
});

it('keeps the exit from an impersonation open, whatever the two factor state of the client', function () {
    $clienteSenza2fa = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => null]);
    $clienteSenza2fa->assignRole('Admin');

    $this->actingAs($this->superadmin)
        ->get(route('impersonate', $clienteSenza2fa), ['Sec-Fetch-Site' => 'same-origin'])
        ->assertRedirect('/');

    $this->get(route('impersonate.leave'))->assertRedirect('/');
    expect(app('impersonate')->isImpersonating())->toBeFalse();
});

// --- Il CSRF passato dal login (caccia T1bA-1) — ROSSO finché R-T1b-7 non è applicata ---
//
// Da sloggato, `auth` parcheggiava l'URL in `url.intended` PRIMA che la guardia
// di stessa origine lo vedesse, e dopo login e 2FA il redirect all'intended è
// una navigazione same-origin: l'impersonazione partiva lo stesso.

function entraComeSuperadminDopoIlLink($test, User $superadmin): void
{
    $test->post('/login', ['email' => $superadmin->email, 'password' => 'password-giusta-123'], ['Sec-Fetch-Site' => 'same-origin'])
        ->assertRedirect(route('two-factor.login'));

    $risposta = $test->post('/two-factor-challenge', ['recovery_code' => 'codice-di-recupero-1'], ['Sec-Fetch-Site' => 'same-origin']);
    $test->assertAuthenticatedAs($superadmin);

    $test->get($risposta->headers->get('Location'), ['Sec-Fetch-Site' => 'same-origin']);
}

function superadminConRecupero(): User
{
    $superadmin = User::factory()->create([
        'password' => Hash::make('password-giusta-123'),
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['codice-di-recupero-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $superadmin->assignRole('Superadmin');

    return $superadmin;
}

it('refuses a cross-site impersonation link opened while logged out, instead of parking it for after the login', function () {
    $this->get(route('impersonate', $this->cliente), ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();

    expect(session('url.intended'))->toBeNull();
});

it('never starts an impersonation the superadmin did not click, after the login that follows a cross-site link', function () {
    $this->get(route('impersonate', $this->cliente), ['Sec-Fetch-Site' => 'cross-site']);

    entraComeSuperadminDopoIlLink($this, superadminConRecupero());

    expect(app('impersonate')->isImpersonating())->toBeFalse();
});

it('never jumps into a client machine after the login that follows a cross-site parco link', function () {
    $this->get(route('piattaforma.parco.impersona', ['utente' => $this->cliente->id, 'strumento' => $this->strumento->id]), ['Sec-Fetch-Site' => 'cross-site']);

    entraComeSuperadminDopoIlLink($this, superadminConRecupero());

    expect(app('impersonate')->isImpersonating())->toBeFalse();
});

// --- L'uscita (caccia T1bA-4 / T1bB-S3) — il primo è ROSSO finché R-T1b-10 non è applicata ---

it('refuses to end an impersonation from a link on another site', function () {
    $this->actingAs($this->superadmin)->get(route('impersonate', $this->cliente), ['Sec-Fetch-Site' => 'same-origin']);

    $this->get(route('impersonate.leave'), ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
    expect(app('impersonate')->isImpersonating())->toBeTrue();
});

it('still ends an impersonation from the banner, which is a link of our own', function () {
    $this->actingAs($this->superadmin)->get(route('impersonate', $this->cliente), ['Sec-Fetch-Site' => 'same-origin']);

    $this->get(route('impersonate.leave'), ['Sec-Fetch-Site' => 'same-origin'])->assertRedirect('/');
    expect(app('impersonate')->isImpersonating())->toBeFalse();
});
