<?php

use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Tab Interventi della scheda strumento (S3 punto 2): lista read-only.
 * I tab sono Alpine (x-show), quindi il pannello è sempre renderizzato
 * server-side: gli assertSee non devono simulare il click sul tab.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

it('shows the interventi tab and the list to an admin', function () {
    Intervento::factory()->forStrumento($this->strumento)->scaduto()
        ->create(['descrizione' => 'Taratura annuale']);
    Intervento::factory()->forStrumento($this->strumento)->pianificato()
        ->create(['descrizione' => 'Controllo pressione']);
    Intervento::factory()->forStrumento($this->strumento)->fatto()
        ->create(['descrizione' => 'Sostituzione guarnizioni']);

    Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->assertSee('Interventi')
        ->assertSee('Taratura annuale')
        ->assertSee('Controllo pressione')
        ->assertSee('Sostituzione guarnizioni')
        ->assertSee('Scaduto')
        ->assertSee('Pianificato')
        ->assertSee('Fatto');
});

it('orders interventi by urgency: overdue first, then planned, then done', function () {
    // Creati in ordine sparso: conta solo l'ordinamento finale.
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['descrizione' => 'Pianificato lontano', 'data_scadenza' => today()->addMonths(6)->toDateString()]);
    Intervento::factory()->forStrumento($this->strumento)->fatto(today()->subDays(3)->toDateString())
        ->create(['descrizione' => 'Fatto di recente', 'data_scadenza' => today()->subDays(5)->toDateString()]);
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['descrizione' => 'Scaduto da poco', 'data_scadenza' => today()->subDays(2)->toDateString()]);
    Intervento::factory()->forStrumento($this->strumento)->fatto(today()->subMonths(4)->toDateString())
        ->create(['descrizione' => 'Fatto tempo fa', 'data_scadenza' => today()->subMonths(4)->toDateString()]);
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['descrizione' => 'Pianificato vicino', 'data_scadenza' => today()->addDays(3)->toDateString()]);
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['descrizione' => 'Scaduto da tanto', 'data_scadenza' => today()->subMonths(2)->toDateString()]);

    $interventi = Livewire::actingAs($this->admin)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->viewData('interventi');

    expect($interventi->pluck('descrizione')->all())->toBe([
        'Scaduto da tanto',    // scaduti: scadenza più vecchia in cima
        'Scaduto da poco',
        'Pianificato vicino',  // pianificati: scadenza più vicina in cima
        'Pianificato lontano',
        'Fatto di recente',    // storico: eseguiti di recente in cima
        'Fatto tempo fa',
    ]);
});

it('loads the tecnici in a single query, whatever the number of interventi (no N+1)', function () {
    // `relationLoaded()` non basterebbe: la view lazy-carica la relazione durante
    // il render, quindi risulterebbe caricata anche senza eager-load. L'unica
    // guardia vera è che il numero di query su `users` non cresca con le righe.
    $queryUtenti = function (Strumento $strumento): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $strumento]);

        $n = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'from "users"'))
            ->count();
        DB::disableQueryLog();

        return $n;
    };

    $unIntervento = Strumento::factory()->forNode($this->dept)->create();
    Intervento::factory()->forStrumento($unIntervento)
        ->assegnatoA(User::factory()->create(['tenant_id' => $this->ente->id]))->create();

    $treInterventi = Strumento::factory()->forNode($this->dept)->create();
    foreach (range(1, 3) as $i) {
        Intervento::factory()->forStrumento($treInterventi)
            ->assegnatoA(User::factory()->create(['tenant_id' => $this->ente->id]))->create();
    }

    expect($queryUtenti($treInterventi))->toBe($queryUtenti($unIntervento));
});

it('shows an empty state when the strumento has no interventi', function () {
    Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->assertSee('Nessuna attività registrata.');
});

it('shows the assigned tecnico, and an em dash when there is none', function () {
    $tecnico = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Luca Bianchi']);
    Intervento::factory()->forStrumento($this->strumento)->assegnatoA($tecnico)
        ->create(['descrizione' => 'Con tecnico']);
    Intervento::factory()->forStrumento($this->strumento)->create(['descrizione' => 'Senza tecnico']);

    Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->assertSee('Luca Bianchi')
        ->assertSee('Senza tecnico')
        ->assertSee('—');
});

it('never shows the name of a tecnico belonging to another ente', function () {
    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $estraneo = User::factory()->create(['tenant_id' => $enteB->id, 'name' => 'Mario Rossi']);
    Intervento::factory()->forStrumento($this->strumento)->assegnatoA($estraneo)
        ->create(['descrizione' => 'Taratura annuale']);

    Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->assertSee('Taratura annuale')
        ->assertDontSee('Mario Rossi');
});

it('shows the list to a Tenant but no actions (read-only, punto 2)', function () {
    // Congela il perimetro: i bottoni arrivano col punto 3, che aggiornerà
    // questo test consapevolmente.
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');

    Intervento::factory()->forStrumento($this->strumento)->create(['descrizione' => 'Taratura annuale']);

    Livewire::actingAs($tenant)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->assertSee('Interventi')
        ->assertSee('Taratura annuale')
        ->assertDontSee('Nuovo intervento')
        ->assertDontSee('Segna come fatto');
});

it('shows the list to a Tecnico of the ente', function () {
    $tecnico = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tecnico->assignRole('Tecnico');

    Intervento::factory()->forStrumento($this->strumento)->create(['descrizione' => 'Taratura annuale']);

    Livewire::actingAs($tecnico)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->assertSee('Taratura annuale');
});

it('does not render the interventi tab without the interventi.view permission', function () {
    // Nessun ruolo del seeder è privo di `interventi.view`: serve un ruolo ad hoc.
    Role::create(['name' => 'Ospite'])->givePermissionTo('strumenti.view');
    $ospite = User::factory()->create(['tenant_id' => $this->ente->id]);
    $ospite->assignRole('Ospite');

    Intervento::factory()->forStrumento($this->strumento)->create(['descrizione' => 'Taratura annuale']);

    Livewire::actingAs($ospite)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->assertDontSee('Taratura annuale')
        ->assertDontSee('Nessuna attività registrata.');
});

it('never shows interventi of another tenant', function () {
    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();
    $strumentoB = Strumento::factory()->forNode($deptB)->create();
    Intervento::factory()->forStrumento($strumentoB)->create(['descrizione' => 'Segreto B']);

    // Dalla rotta: 404 (route-model binding scopato).
    $this->actingAs($this->admin)->get(route('strumenti.show', $strumentoB))->assertNotFound();

    // Forzando il componente con lo strumento di B: la relazione riapplica i
    // global scope di Intervento → lista vuota.
    Livewire::actingAs($this->admin)
        ->test(SchedaStrumento::class, ['strumento' => Strumento::withoutGlobalScopes()->find($strumentoB->id)])
        ->assertDontSee('Segreto B')
        ->assertSee('Nessuna attività registrata.');
});
