<?php

use App\Models\Account;
use App\Models\UnitaOrganizzativa;
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
    $superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
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
        // ⚠️ Con un Ente (🔗 ADR-046): senza, e senza clienti da seguire, le
        // voci operative non si disegnano affatto — e il positivo qui sotto
        // misurerebbe una barra che nessun utente vero vede.
        $u = User::factory()->create([
            'tenant_id' => UnitaOrganizzativa::factory()->ente()->create()->id,
            'two_factor_confirmed_at' => now(),
        ]);
        $u->assignRole($ruolo);

        $html = $this->actingAs($u->fresh())->get(route('dashboard'))->assertOk()->getContent();

        preg_match('/<nav[^>]*aria-label="Menù principale".*?<\/nav>/s', $html, $blocco);

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

it('keeps the personal pages out of the sidebar, where only the Ente data lives', function () {
    // 🗓️ Deciso il 28 Ago 2026: «Sicurezza» stava sia in barra laterale sia nel
    // menù utente. Non è solo deduplicazione — la barra elenca le **aree di
    // dato dell'Ente** (anagrafica, strumenti, documenti, fornitori), mentre
    // sicurezza, preferenze e abbonamento riguardano **chi guarda**, non ciò
    // che guarda. Averla in due posti diceva che fossero due cose diverse.
    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $admin = User::factory()->create([
        'tenant_id' => $ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('Admin');

    $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

    // ⚠️ Si asserisce sul solo blocco <nav> della barra, estratto: «Sicurezza» è
    // una parola che vive legittimamente altrove nella stessa pagina — nel menù
    // utente, che è precisamente il posto in cui deve stare.
    preg_match('/<nav[^>]*aria-label="Menù principale"[^>]*>(.*?)<\/nav>/s', $html, $trovato);

    expect($trovato)->not->toBeEmpty();
    expect($trovato[1])->not->toContain('Sicurezza');
    expect($trovato[1])->not->toContain('Abbonamento');

    // …ma la pagina resta raggiungibile: togliere la voce non toglie la rotta,
    // ed è lì che il middleware manda chi deve ancora attivare il 2FA.
    expect($html)->toContain(route('settings.security'));
});

// ─── Chi vede quali voci (🔗 ADR-046) ────────────────────────────────────────

/** Il blocco `<nav>` della barra laterale, per la pagina data. */
function barraLaterale(string $html): string
{
    preg_match('/<nav[^>]*aria-label="Menù principale".*?<\/nav>/s', $html, $blocco);

    expect($blocco)->not->toBeEmpty();

    return $blocco[0];
}

it('leaves a developer without an ente with the platform and the guide only', function () {
    // Non ha un Ente e non segue clienti: otto voci operative lo porterebbero a
    // otto liste vuote. Il suo lavoro comincia dalla cabina, da cui impersona.
    $developer = User::factory()->create(['tenant_id' => null]);
    $developer->assignRole('Developer');

    $nav = barraLaterale($this->actingAs($developer)->get(route('piattaforma.index'))->assertOk()->getContent());

    expect($nav)->toContain('Piattaforma', 'Guida')
        ->and($nav)->not->toContain('Dashboard', 'Laboratori', 'Strumenti', 'Documenti', 'Fornitori', 'Scadenzario', 'Ricambi', 'Campo');
});

it('sends a developer without an ente from the dashboard to the platform', function () {
    $developer = User::factory()->create(['tenant_id' => null]);
    $developer->assignRole('Developer');

    $this->actingAs($developer)->get(route('dashboard'))->assertRedirect(route('piattaforma.index'));
});

it('keeps the dashboard for whoever has an ente or clients to follow', function (string $ruolo, bool $conEnte) {
    $utente = User::factory()->create([
        'tenant_id' => $conEnte ? UnitaOrganizzativa::factory()->ente()->create()->id : null,
        'two_factor_confirmed_at' => now(),
    ]);
    $utente->assignRole($ruolo);

    $this->actingAs($utente)->get(route('dashboard'))->assertOk();
})->with([
    'Developer con un Ente' => ['Developer', true],
    'Superadmin senza Ente' => ['Superadmin', false],
    'Gestore' => ['Gestore', false],
    'Tecnico esterno' => ['Tecnico', false],
    'Admin' => ['Admin', true],
]);

it('never sends to the platform somebody who cannot open it', function () {
    // Un Admin rimasto senza Ente non ha nulla da vedere, ma la piattaforma non
    // è casa sua: resta sulla dashboard, e non in un giro di 403.
    $admin = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');

    $this->actingAs($admin)->get(route('dashboard'))->assertOk();
});

it('gives a gestore the working pages, without people and without the platform', function () {
    $gestore = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $gestore->assignRole('Gestore');

    $nav = barraLaterale($this->actingAs($gestore)->get(route('dashboard'))->assertOk()->getContent());

    expect($nav)->toContain('Dashboard', 'Laboratori', 'Strumenti', 'Documenti', 'Fornitori', 'Scadenzario', 'Ricambi')
        ->and($nav)->not->toContain('Persone', 'Piattaforma', 'Guida');
});

it('names the managed clients in the perimeter of the superadmin dashboard', function () {
    // Il totale conta anche loro: «le macchine di EasyLab» sopra quel numero
    // sarebbe una frase falsa.
    $ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'EasyLab']);
    $superadmin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');

    $this->actingAs($superadmin)->get(route('dashboard'))
        ->assertSee('Lo stato delle macchine di EasyLab.')
        ->assertDontSee('manutenzione gestita');

    $cliente = Account::factory()->create();
    UnitaOrganizzativa::factory()->ente()->perAccount($cliente)->create();
    $cliente->affidaManutenzione();

    $this->actingAs($superadmin)->get(route('dashboard'))
        ->assertSee('Lo stato delle macchine di EasyLab e dei clienti con manutenzione gestita da EasyLab.');
});
