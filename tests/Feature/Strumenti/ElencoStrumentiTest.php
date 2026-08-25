<?php

use App\Enums\StatoSemaforo;
use App\Enums\TipoIntervento;
use App\Livewire\Strumenti\ElencoStrumenti;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dip1 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip 1']);
    $this->lab1 = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->dip1)->create(['nome' => 'Lab 1']);
    $this->dip2 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip 2']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

it('redirects guests to login', function () {
    $this->get(route('strumenti.index'))->assertRedirect(route('login'));
});

it('forbids users without strumenti.view', function () {
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

    $this->actingAs($user)->get(route('strumenti.index'))->assertForbidden();
});

it('lists the tenant strumenti', function () {
    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Autoclave']);
    Strumento::factory()->forNode($this->dip2)->create(['nome' => 'Centrifuga']);

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->assertSee('Autoclave')
        ->assertSee('Centrifuga');
});

it('searches by nome, modello and matricola (case-insensitive)', function () {
    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Autoclave', 'modello' => 'AC-200', 'matricola' => 'SN-999']);
    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Centrifuga', 'modello' => 'CF-12', 'matricola' => 'SN-111']);

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('search', 'autoclave')
        ->assertSee('Autoclave')
        ->assertDontSee('Centrifuga')
        ->set('search', 'CF-12')
        ->assertSee('Centrifuga')
        ->assertDontSee('Autoclave')
        ->set('search', 'sn-999')
        ->assertSee('Autoclave')
        ->assertDontSee('Centrifuga');
});

it('filters by ubicazione including descendants', function () {
    Strumento::factory()->forNode($this->lab1)->create(['nome' => 'Sotto Lab1']); // discendente di dip1
    Strumento::factory()->forNode($this->dip2)->create(['nome' => 'In Dip2']);

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('ubicazioneId', $this->dip1->id)
        ->assertSee('Sotto Lab1')      // dip1 → lab1 (discendente)
        ->assertDontSee('In Dip2');
});

it('sorts by column and toggles direction', function () {
    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Alfa']);
    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Zeta']);

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->assertSeeInOrder(['Alfa', 'Zeta'])   // default nome asc
        ->call('sort', 'nome')                 // → desc
        ->assertSeeInOrder(['Zeta', 'Alfa']);
});

it('does not list strumenti of another tenant', function () {
    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Mio']);

    $enteB = UnitaOrganizzativa::factory()->ente()->create();
    $dipB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();
    Strumento::factory()->forNode($dipB)->create(['nome' => 'Altrui']);

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->assertSee('Mio')
        ->assertDontSee('Altrui');
});

// --- Filtro Ente (a cascata) ---

it('hides the ente filter when the user sees a single ente', function () {
    // ADR-018: ogni utente è tenant-bound → un solo Ente → select inutile.
    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->assertDontSee('Tutti gli Enti');
});

it('filters by ente and cannot leak another tenant', function () {
    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Mio']);

    $enteB = UnitaOrganizzativa::factory()->ente()->create();

    // Il proprio Ente: la riga resta.
    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('enteId', $this->ente->id)
        ->assertSee('Mio');

    // Un altro Ente: il filtro è in AND con lo scope → nessun risultato, nessuna fuga.
    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('enteId', $enteB->id)
        ->assertDontSee('Mio');
});

it('resets the ubicazione filter when the ente changes', function () {
    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('ubicazioneId', $this->dip1->id)
        ->set('enteId', $this->ente->id)
        ->assertSet('ubicazioneId', null);
});

it('shows a Responsabile only its subtree strumenti', function () {
    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Nel reparto']);
    Strumento::factory()->forNode($this->dip2)->create(['nome' => 'Fuori reparto']);

    $resp = User::factory()->create(['tenant_id' => $this->ente->id]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dip1->id);

    Livewire::actingAs($resp)->test(ElencoStrumenti::class)
        ->assertSee('Nel reparto')
        ->assertDontSee('Fuori reparto');
});

it('shows the stato and prossima scadenza columns', function () {
    $conScaduta = Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Con taratura scaduta']);
    Intervento::factory()->forStrumento($conScaduta)->scaduto()
        ->create(['tipo' => TipoIntervento::TaraturaECertificazione]);

    $imminente = Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Con manutenzione vicina']);
    Intervento::factory()->forStrumento($imminente)
        ->create(['tipo' => TipoIntervento::ManutenzioneFullRisk, 'data_scadenza' => today()->addDays(4)->toDateString()]);

    Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Senza interventi']);

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->assertSee('Prossima scadenza')
        ->assertSee('Taratura e certificazione — scaduta')
        // Etichetta da `label()`, non da `ucfirst($value)`: con il valore grezzo
        // qui si leggerebbe "Manutenzione_full_risk" (ADR-021).
        ->assertSee('Manutenzione full risk tra 4 gg')
        ->assertSee('Azione richiesta')   // etichetta sr-only del dot
        ->assertSee('In regola');         // gli strumenti senza interventi
});

it('shows the nearest of several imminent scadenze in the elenco', function () {
    $s = Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Multi scadenze']);
    Intervento::factory()->forStrumento($s)
        ->create(['tipo' => TipoIntervento::TaraturaECertificazione, 'data_scadenza' => today()->addDays(20)->toDateString()]);
    Intervento::factory()->forStrumento($s)
        ->create(['tipo' => TipoIntervento::ManutenzioneStraordinaria, 'data_scadenza' => today()->addDays(2)->toDateString()]);

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->assertSee('Manutenzione straordinaria tra 2 gg')
        ->assertDontSee('Taratura e certificazione tra 20 gg');
});

it('makes every column sortable, derived ones included', function () {
    $colonne = ['stato', 'nome', 'modello', 'matricola', 'ubicazione', 'data_installazione', 'prossima_scadenza'];

    $componente = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class);

    foreach ($colonne as $col) {
        $componente->call('sort', $col)
            ->assertSet('sortBy', $col)
            ->assertSet('sortDir', 'asc')   // prima click: crescente
            ->call('sort', $col)
            ->assertSet('sortDir', 'desc'); // secondo click: decrescente
    }
});

it('ignores sort clicks on columns that do not exist', function () {
    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->call('sort', 'password')
        ->assertSet('sortBy', 'nome')
        ->call('sort', 'tenant_id')
        ->assertSet('sortBy', 'nome');
});

it('goes back to the first page when the sorting changes', function () {
    // Riordinare rimescola tutte le righe: restare in pagina 2 farebbe
    // atterrare a metà elenco.
    foreach (range(1, 25) as $n) {
        Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Strumento '.str_pad($n, 2, '0', STR_PAD_LEFT)]);
    }

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->call('sort', 'nome')
        ->assertSet('paginators.page', 1);
});

// --- Righe per pagina ---

it('says the list is empty for a filter, not empty full stop', function () {
    // 🔴 Il difetto che la dashboard di S6 rende quotidiano: i suoi riquadri
    // portano qui con `stato` o `soloObsoleti` nella query string, e la
    // condizione del vuoto guardava solo ricerca e ubicazione. Chi cliccava
    // «Non idoneo 0» leggeva «Nessuno strumento.» avendone trecento.
    //
    // Le due frasi non sono l'una sottostringa dell'altra, quindi
    // l'`assertDontSee` morde davvero — il confronto per sottostringa è una
    // delle forme di falso verde già viste in questo progetto.
    // Installate di recente e senza scadenze: né rosse né obsolete, così
    // entrambi i filtri trovano zero righe su un parco che ne ha tre.
    Strumento::factory()->count(3)->forNode($this->dip1)
        ->create(['data_installazione' => today()->subYear()->toDateString()]);

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('stato', StatoSemaforo::Rosso->value)
        ->assertSee('Nessun risultato per i filtri applicati.')
        ->assertDontSee('Nessuno strumento.');

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('soloObsoleti', true)
        ->assertSee('Nessun risultato per i filtri applicati.')
        ->assertDontSee('Nessuno strumento.');
});

it('says the list is empty full stop when there is nothing and no filter', function () {
    // L'altra metà: senza filtri il messaggio non deve mandare a cercare un
    // filtro da togliere che non c'è.
    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->assertSee('Nessuno strumento.')
        ->assertDontSee('Nessun risultato per i filtri applicati.');
});

it('shows 20 rows per page by default', function () {
    foreach (range(1, 25) as $n) {
        Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Strumento '.str_pad($n, 2, '0', STR_PAD_LEFT)]);
    }

    $componente = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->assertSet('perPage', 20);

    expect($componente->viewData('strumenti')->count())->toBe(20);
});

it('lets the user choose 50 or 100 rows per page', function () {
    foreach (range(1, 120) as $n) {
        Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Strumento '.str_pad($n, 3, '0', STR_PAD_LEFT)]);
    }

    foreach ([50, 100] as $scelta) {
        $righe = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
            ->set('perPage', $scelta)
            ->viewData('strumenti');

        expect($righe->count())->toBe($scelta)
            ->and($righe->perPage())->toBe($scelta);
    }
});

it('never exceeds 100 rows per page, whatever the query string says', function () {
    // `perPage` è #[Url]: un valore arbitrario non deve far chiedere al DB
    // l'intero elenco (con le sue sottoquery per riga).
    foreach (range(1, 120) as $n) {
        Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Strumento '.str_pad($n, 3, '0', STR_PAD_LEFT)]);
    }

    foreach ([999999, 101, 0, -5, 37] as $malevolo) {
        $righe = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
            ->set('perPage', $malevolo)
            ->viewData('strumenti');

        expect($righe->perPage())->toBe(20, "perPage={$malevolo} non è stato ricondotto al default");
    }
});

it('goes back to the first page when the page size changes', function () {
    foreach (range(1, 60) as $n) {
        Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Strumento '.str_pad($n, 2, '0', STR_PAD_LEFT)]);
    }

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->call('setPage', 3)
        ->set('perPage', 100)
        ->assertSet('paginators.page', 1);
});

it('renders the pagination in italian, with no duplicated counter', function () {
    foreach (range(1, 45) as $n) {
        Strumento::factory()->forNode($this->dip1)->create(['nome' => 'Strumento '.str_pad($n, 2, '0', STR_PAD_LEFT)]);
    }

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->assertSee('Righe per pagina')
        ->assertSee('Successiva ›')
        ->assertSee('1–20 di 45')       // conteggio, una volta sola
        ->assertDontSee('Showing')      // testo della vista di default
        ->assertDontSee('results')
        ->assertDontSee('Previous')
        ->assertDontSee('Next');
});
