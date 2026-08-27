<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('renders the app shell for an authenticated user', function () {
    $user = User::factory()->create(['name' => 'Mario Rossi']);
    $user->assignRole('Tenant');

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertSee('Easy Lab')
        ->assertSee('Mario Rossi')
        ->assertSee(route('settings.security'))
        ->assertSee(route('logout'));
});

it('shows the impersonation banner while impersonating', function () {
    $superadmin = User::factory()->create();
    $superadmin->assignRole('Superadmin');
    $tenant = User::factory()->create(['name' => 'Cliente Impersonato']);
    $tenant->assignRole('Tenant');

    $this->actingAs($superadmin)->get(route('impersonate', $tenant));

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Stai impersonando')
        ->assertSee('Cliente Impersonato')
        ->assertSee(route('impersonate.leave'));
});

it('no longer promises a page nobody is writing', function () {
    // 🔴 La sezione «Prossimamente → Interventi» era la stessa bugia della card
    // tolta dalla dashboard il 21 Ago, in un altro punto dello schermo: gli
    // interventi esistono da S3, e ciò che non esisteva era un elenco
    // cross-macchina.
    //
    // ✅ Aggiornato il 27 Ago 2026: «cosa scade su tutto il parco» ora esiste,
    // è `/scadenzario`, ed è in barra laterale. L'asserzione qui sotto NON
    // cambia — anzi ora vale doppio: la voce si chiama «Scadenzario», e se
    // qualcuno la ribattezzasse «Interventi» questo test tornerebbe rosso,
    // che è esattamente il verso giusto. Resta scoperta l'altra metà della
    // lacuna, gli **spostamenti** (voce V1.1 in roadmap).
    //
    // ⚠️ Si asserisce sul **blocco `<nav>` della sidebar estratto**, non sulla
    // pagina: «Interventi» è una parola che vive altrove (il tab della scheda,
    // il testo del digest), quindi un `assertDontSee` sul documento intero
    // sarebbe rosso per il motivo sbagliato — o, peggio, verrebbe «aggiustato»
    // finché non dice più niente.
    //
    // Reso per **due ruoli**: la sezione stava sotto le voci gatate, quindi con
    // un solo ruolo si proverebbe metà del layout.
    $sidebar = function (string $ruolo): string {
        $u = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        $html = $this->actingAs($u->fresh())->get(route('dashboard'))->assertOk()->getContent();

        preg_match('/<nav class="flex-1 space-y-1 p-3">.*?<\/nav>/s', $html, $blocco);

        // Non `?? ''`: un blocco assente e un blocco vuoto vanno distinti, o
        // un `not->toContain()` sarebbe verde proprio quando la nav è sparita.
        expect($blocco)->not->toBeEmpty("Sidebar non trovata per il ruolo {$ruolo}");

        return $blocco[0];
    };

    foreach (['Tenant', 'Superadmin'] as $ruolo) {
        $nav = $sidebar($ruolo);

        expect($nav)->not->toContain('Prossimamente')
            ->and($nav)->not->toContain('aria-disabled')
            ->and($nav)->not->toContain('Interventi')
            // Il positivo, senza cui il test sarebbe verde anche con la sidebar
            // svuotata: le voci vere ci sono ancora.
            ->and($nav)->toContain('Dashboard')
            ->and($nav)->toContain('Strumenti');
    }
});

it('redirects guests away from the shell', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('no longer exposes the temporary TALL check page', function () {
    $this->get('/_tall-check')->assertNotFound();
});
