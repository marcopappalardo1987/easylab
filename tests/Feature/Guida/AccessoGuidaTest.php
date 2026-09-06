<?php

use App\Livewire\Guida\Manuale;
use App\Support\Guide\Manuale as Libreria;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * Chi entra nella Guida, e chi no.
 *
 * ⚠️ **Il gate qui non protegge niente**: un manuale non è il dato di nessuno,
 * e la rotta si aprirà a chiunque sia autenticato quando le guide saranno
 * abbastanza. È un cancello di rilascio, e questo file lo congela finché dura —
 * così il giorno in cui si toglie, si toglie **per decisione** e non per
 * distrazione: il rosso di questi negativi è la domanda «era ora?».
 *
 * Il permesso scelto è `tenants.view_all` perché è nel **set bloccato** di
 * `config/rbac.php`: l'editor ruoli non può concederlo, quindi nessuno apre il
 * manuale a un cliente per sbaglio. Un permesso ridistribuibile sarebbe la
 * stessa svista già evitata sul registro di audit (`AccessoRegistroAuditTest`).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

// --- Negativi ---

it('refuses the guide to every role without the platform permission', function (string $ruolo) {
    $this->actingAs(utenteConRuolo($ruolo))
        ->get(route('guida'))
        ->assertForbidden();
})->with(RUOLI_SENZA_PIATTAFORMA);

it('sends a guest to the login', function () {
    $this->get(route('guida'))->assertRedirect(route('login'));
});

it('hides the menu entry from who cannot open the page', function () {
    // La voce di menù e la rotta si gatano sullo stesso permesso: offrire un
    // collegamento che porta a un 403 è peggio che non offrirlo.
    $this->actingAs(utenteConRuolo('Admin'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('href="'.route('guida').'"', escape: false);
});

// --- Positivi ---

it('opens the guide to the platform roles', function (string $ruolo) {
    $this->actingAs(utenteConRuolo($ruolo))
        ->get(route('guida'))
        ->assertOk()
        ->assertSee('Guida');
})->with(RUOLI_CON_PIATTAFORMA);

it('offers the menu entry to who can open the page', function () {
    $this->actingAs(utenteConRuolo('Superadmin'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('href="'.route('guida').'"', escape: false);
});

it('lists every published guide, grouped by argomento', function () {
    $pubblicate = Libreria::tutte();

    // Se un giorno nessuna guida fosse pubblicata questo test resterebbe verde
    // senza provare nulla: la riga qui sotto lo rende rosso invece che vuoto.
    expect($pubblicate)->not->toBeEmpty();

    $pagina = Livewire::actingAs(utenteConRuolo('Superadmin'))->test(Manuale::class);

    foreach ($pubblicate as $guida) {
        $pagina->assertSee($guida['titolo']);
    }
});
