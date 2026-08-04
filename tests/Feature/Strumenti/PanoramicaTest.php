<?php

use App\Enums\StatoSemaforo;
use App\Enums\TipoIntervento;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Semaforo;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

/**
 * Tab Panoramica (S3-bis punto E — ADR-024): il semaforo spiega sé stesso.
 * Come gli altri tab è Alpine (x-show), quindi il pannello è sempre
 * renderizzato server-side: gli assertSee non simulano il click.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

// --- Il tab è primo e di default ---

it('opens the scheda on the Panoramica tab', function () {
    scheda($this->admin, $this->strumento)
        ->assertSee('Panoramica')
        ->assertSeeHtml("x-data=\"{ tab: 'panoramica' }\"");
});

it('leaves the Panoramica panel visible before Alpine boots, and cloaks Anagrafica', function () {
    // x-cloak nasconde in CSS finché Alpine non monta: sul pannello di default
    // lascerebbe la scheda vuota all'apertura, su tutti gli altri evita il flash.
    $html = scheda($this->admin, $this->strumento)->html();

    expect($html)->toContain('x-show="tab === \'panoramica\'" class=')      // niente x-cloak
        ->and($html)->toContain('x-show="tab === \'anagrafica\'" x-cloak');
});

// --- Motivi ---

it('lists every motivo that lights the semaforo, most urgent first', function () {
    Intervento::factory()->forStrumento($this->strumento)->scaduto()
        ->create(['descrizione' => 'Taratura annuale', 'data_scadenza' => today()->subDays(10)->toDateString()]);
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['descrizione' => 'Controllo pressione', 'data_scadenza' => today()->addDays(5)->toDateString()]);
    Garanzia::factory()->forStrumento($this->strumento)->imminente()->create();

    scheda($this->admin, $this->strumento)
        ->assertSee('Motivi (3)')
        ->assertSee('Intervento scaduto il')
        ->assertSee('Intervento in scadenza il')
        ->assertSee('Garanzia macchina in scadenza il')
        ->assertSeeInOrder(['Taratura annuale', 'Controllo pressione']);   // ordinati per scadenza
});

it('ignores scadenze beyond the soglia', function () {
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDays(Semaforo::giorniImminente() + 1)->toDateString()]);

    scheda($this->admin, $this->strumento)
        ->assertSee('In regola')
        ->assertSee('Nessuna scadenza aperta o imminente.')
        ->assertDontSee('Motivi (');
});

it('links each motivo back to the tab that owns it', function () {
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();
    Garanzia::factory()->forStrumento($this->strumento)->scaduta()->create();

    $html = scheda($this->admin, $this->strumento)->html();

    expect($html)->toContain('x-on:click="tab = \'interventi\'"')
        ->and($html)->toContain('x-on:click="tab = \'garanzie\'"');
});

// --- Forzatura: la Panoramica mostra ENTRAMBI gli stati (ADR-005) ---

it('shows both the forced state and the calculated one with its motivi', function () {
    Intervento::factory()->forStrumento($this->strumento)->scaduto()
        ->create(['descrizione' => 'Taratura annuale']);

    $this->actingAs($this->admin);
    $this->strumento->forzaSemaforo(StatoSemaforo::Rosso, 'Guasto in verifica');

    scheda($this->admin, $this->strumento->fresh())
        ->assertSee('Stato forzato')
        ->assertSee('Non idoneo')          // il forzato, che vince
        ->assertSee('Azione richiesta')    // il calcolato, che resta
        ->assertSee('Guasto in verifica')
        ->assertSee($this->admin->name)
        ->assertSee('Intervento scaduto il')   // il problema reale non sparisce
        ->assertSee('Taratura annuale');
});

it('renders no forzatura block at all when the semaforo is not forced', function () {
    scheda($this->admin, $this->strumento)->assertDontSee('Stato forzato');
});

// --- Sintesi interventi ---

it('shows the nearest planned intervento, not an overdue one', function () {
    Intervento::factory()->forStrumento($this->strumento)->scaduto()
        ->create(['descrizione' => 'Vecchia taratura']);
    Intervento::factory()->forStrumento($this->strumento)->create([
        'descrizione' => 'Controllo pressione',
        'tipo' => TipoIntervento::ManutenzioneFullRisk,
        'data_scadenza' => today()->addDays(9)->toDateString(),
    ]);
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['descrizione' => 'Lontano', 'data_scadenza' => today()->addYear()->toDateString()]);

    $componente = scheda($this->admin, $this->strumento);

    expect($componente->viewData('prossimoIntervento')->descrizione)->toBe('Controllo pressione');
    $componente->assertSee('Manutenzione full risk');
});

it('shows the last executed intervento and explicit empty states', function () {
    scheda($this->admin, $this->strumento)
        ->assertSee('Nessuno pianificato.')
        ->assertSee('Nessun intervento eseguito.');

    Intervento::factory()->forStrumento($this->strumento)->fatto()
        ->create(['data_esecuzione' => today()->subMonths(2)->toDateString()]);
    $recente = Intervento::factory()->forStrumento($this->strumento)->fatto()
        ->create(['data_esecuzione' => today()->subDays(3)->toDateString()]);

    expect(scheda($this->admin, $this->strumento)->viewData('ultimoIntervento')->id)->toBe($recente->id);
});

it('counts the last 12 months by execution date, and never calls a done job overdue', function () {
    // Eseguiti dentro la finestra dei 12 mesi.
    Intervento::factory()->forStrumento($this->strumento)->fatto()
        ->create(['data_esecuzione' => today()->subMonths(3)->toDateString()]);
    Intervento::factory()->forStrumento($this->strumento)->fatto()
        ->create(['data_esecuzione' => today()->subMonths(6)->toDateString()]);

    // Eseguito due anni fa: fuori finestra.
    Intervento::factory()->forStrumento($this->strumento)->fatto()
        ->create(['data_esecuzione' => today()->subYears(2)->toDateString()]);

    // Scaduto e ancora aperto: l'unico che deve contare come "scaduto non fatto".
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();

    // Scaduto ma ESEGUITO (in ritardo): la sua scadenza è nel passato, però il
    // lavoro è stato fatto. Conta fra gli eseguiti, mai fra gli scaduti aperti —
    // è il caso che distingue `isScaduto()` da un confronto di date.
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->fatto()
        ->create(['data_esecuzione' => today()->subMonth()->toDateString()]);

    expect(scheda($this->admin, $this->strumento)->viewData('statInterventi'))
        ->toBe(['dodiciMesi' => 3, 'scadutiAperti' => 1]);
});

// --- Garanzia e anagrafica ---

it('shows the garanzia macchina with its state, and an empty state without one', function () {
    scheda($this->admin, $this->strumento)->assertSee('Nessuna garanzia macchina.');

    Garanzia::factory()->forStrumento($this->strumento)->scaduta()->create();

    scheda($this->admin, $this->strumento)
        ->assertSee('Garanzia macchina')
        ->assertSee('Scaduta');
});

it('adds no garanzia CTA of its own: the Panoramica is a summary, not a form', function () {
    // Serve un ruolo in sola lettura sulle garanzie (il Tenant ha `view` ma non
    // `manage`), altrimenti il bottone del tab Garanzie renderebbe la verifica
    // impossibile: i pannelli stanno tutti nel DOM insieme.
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');

    Garanzia::factory()->forStrumento($this->strumento)->scaduta()->create();

    scheda($tenant, $this->strumento)
        ->assertSee('Garanzia macchina')     // il blocco di sintesi c'è
        ->assertDontSee('+ Nuova garanzia'); // nessuna azione, né qui né altrove
});

it('treats obsolescenza as information, never as a motivo of the semaforo', function () {
    // ADR-014: il badge ⏳ convive col pallino invece di alterarlo.
    $vecchio = Strumento::factory()->forNode($this->dept)
        ->create(['nome' => 'Vecchia', 'data_installazione' => today()->subYears(12)->toDateString()]);

    scheda($this->admin, $vecchio)
        ->assertSee('Obsoleto')
        ->assertSee('In regola')
        ->assertDontSee('Motivi (');
});

// --- Permessi: ogni blocco è gated dalla propria area (🔴) ---

it('degrades a motivo to neutral text for who lacks the area permission', function () {
    Role::create(['name' => 'Ospite'])->givePermissionTo('strumenti.view');
    $ospite = User::factory()->create(['tenant_id' => $this->ente->id]);
    $ospite->assignRole('Ospite');

    Intervento::factory()->forStrumento($this->strumento)->scaduto()
        ->create(['descrizione' => 'Taratura annuale']);

    $componente = scheda($ospite, $this->strumento);

    // Il pallino e il motivo sì (è un aggregato dovuto a tutti)...
    $componente->assertSee('Azione richiesta')
        ->assertSee('Intervento scaduto il')
        // ...il dettaglio, il link e i blocchi d'area no.
        ->assertDontSee('Taratura annuale')
        ->assertDontSee('Nessuno pianificato.')
        ->assertDontSee('Statistiche')
        ->assertDontSee('Nessuna garanzia macchina.');

    expect($componente->html())->not->toContain('x-on:click="tab = \'interventi\'"');
});

it('never leaks a tecnico of another tenant in the prossimo intervento', function () {
    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $estraneo = User::factory()->create(['tenant_id' => $enteB->id, 'name' => 'Mario Estraneo']);

    Intervento::factory()->forStrumento($this->strumento)->assegnatoA($estraneo)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);

    scheda($this->admin, $this->strumento)->assertDontSee('Mario Estraneo');
});
