<?php

use App\Http\Middleware\RequireSameOriginNavigation;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;

/**
 * Le rotte che FANNO entrare in casa d'altri (security pass S7, T1b — 🔗
 * ADR-016, ADR-018).
 *
 * ⚠️ I blocchi «registrazione» sono ROSSI finché l'orchestratore non applica le
 * due richieste in board su `routes/web.php`:
 *  - `Route::impersonate()` dentro `two-factor.enforce` (bypass del 2FA);
 *  - `RequireSameOriginNavigation` sulle due rotte di ingresso (CSRF via GET).
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

it('falls back to the referer when the browser sends no fetch metadata', function () {
    $this->get('/_prova/navigazione', ['Referer' => 'https://pagina-malevola.example/trappola'])->assertForbidden();
    $this->get('/_prova/navigazione', ['Referer' => url('/piattaforma')])->assertOk();
});

it('never trusts a referer that only starts like our host', function () {
    $ospite = parse_url(url('/'), PHP_URL_HOST);

    $this->get('/_prova/navigazione', ['Referer' => "http://{$ospite}.malevolo.example/"])->assertForbidden();
});

// --- Registrazione sulle rotte vere (ROSSO finché routes/web.php non cambia) ---

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

it('keeps the exit from an impersonation open, whatever the two factor state of the client', function () {
    $clienteSenza2fa = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => null]);
    $clienteSenza2fa->assignRole('Admin');

    $this->actingAs($this->superadmin)
        ->get(route('impersonate', $clienteSenza2fa), ['Sec-Fetch-Site' => 'same-origin'])
        ->assertRedirect('/');

    $this->get(route('impersonate.leave'))->assertRedirect('/');
    expect(app('impersonate')->isImpersonating())->toBeFalse();
});
