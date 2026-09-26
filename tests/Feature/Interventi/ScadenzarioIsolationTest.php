<?php

use App\Livewire\Interventi\Scadenzario;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * 🔴 I test NEGATIVI dello Scadenzario: tenancy e autorizzazioni, cioè l'area
 * rossa della Policy di Code Review (🔗 ADR-007/018/030).
 *
 * La pagina è una vista **aggregata cross-macchina**, quindi è esattamente la
 * forma in cui una fuga si nota meno: non serve mostrare la riga di un altro
 * cliente per farne uscire il dato: basta **contarla**. I contatori sono perciò
 * asseriti quanto le righe.
 *
 * ⚠️ Ogni fixture di un secondo Ente nasce **prima** di autenticare chiunque:
 * con un utente in sessione `BelongsToTenant::creating` riscriverebbe il
 * `tenant_id` su quello di chi guarda, e la macchina «dell'altro cliente»
 * nascerebbe dentro il proprio — il test resterebbe verde dicendo la cosa
 * sbagliata.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->deptA = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create(['nome' => 'Microbiologia']);
    $this->altroDeptA = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create(['nome' => 'Chimica']);

    $this->enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $this->deptB = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create(['nome' => 'Radiologia']);

    $this->macchinaA = Strumento::factory()->forNode($this->deptA)->create(['nome' => 'Autoclave A']);
    $this->macchinaAltroDeptA = Strumento::factory()->forNode($this->altroDeptA)->create(['nome' => 'Cappa Chimica']);
    $this->macchinaB = Strumento::factory()->forNode($this->deptB)->create(['nome' => 'TAC B']);

    $this->admin = User::factory()->create([
        'tenant_id' => $this->enteA->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('Admin');
});

it('never lets an Admin see another Ente, in the rows or in the counters', function () {
    Intervento::factory()->forStrumento($this->macchinaA)->scaduto()
        ->create(['descrizione' => 'Lavoro del mio Ente']);

    // Tre interventi dell'altro cliente, uno per partizione: se un contatore
    // sfuggisse agli scope, il numero uscirebbe anche senza una riga visibile.
    Intervento::factory()->forStrumento($this->macchinaB)->scaduto()
        ->create(['descrizione' => 'Scaduto di un altro cliente']);
    Intervento::factory()->forStrumento($this->macchinaB)
        ->create(['data_scadenza' => today()->addDays(3)->toDateString(), 'descrizione' => 'Imminente di un altro cliente']);
    Intervento::factory()->forStrumento($this->macchinaB)
        ->create(['data_scadenza' => today()->addDays(90)->toDateString(), 'descrizione' => 'Lontano di un altro cliente']);

    $componente = Livewire::actingAs($this->admin)->test(Scadenzario::class);

    $componente->assertSee('Lavoro del mio Ente')
        ->assertDontSee('Scaduto di un altro cliente')
        ->assertDontSee('Imminente di un altro cliente')
        ->assertDontSee('Lontano di un altro cliente')
        ->assertDontSee('TAC B');

    expect($componente->viewData('interventi')->total())->toBe(1)
        ->and($componente->viewData('contatori'))->toBe([
            'scaduti' => 1,
            'in_scadenza' => 0,
            'oltre' => 0,
        ])
        // Le righe ci sono davvero: senza questo, «non vede nulla» sarebbe vero
        // anche per un database vuoto.
        ->and(Intervento::withoutGlobalScopes()->count())->toBe(4);
});

it('shows a Responsabile Reparto only its own subtree, in the rows and in the counters', function () {
    Intervento::factory()->forStrumento($this->macchinaA)->scaduto()
        ->create(['descrizione' => 'Nel mio reparto']);
    Intervento::factory()->forStrumento($this->macchinaAltroDeptA)->scaduto()
        ->create(['descrizione' => 'In un reparto non mio']);

    $resp = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->deptA->id);

    $componente = Livewire::actingAs($resp->fresh())->test(Scadenzario::class);

    $componente->assertSee('Nel mio reparto')
        ->assertDontSee('In un reparto non mio');

    expect($componente->viewData('interventi')->total())->toBe(1)
        ->and($componente->viewData('contatori')['scaduti'])->toBe(1);
});

it('shows nothing at all to a Responsabile without any node assigned', function () {
    // Fail-safe del livello 2: senza nodi non vede il proprio Ente, vede zero.
    Intervento::factory()->forStrumento($this->macchinaA)->scaduto()->create(['descrizione' => 'Di qualcuno']);

    $resp = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $resp->assignRole('Responsabile Reparto');

    $componente = Livewire::actingAs($resp->fresh())->test(Scadenzario::class);

    expect($componente->viewData('interventi')->total())->toBe(0)
        ->and($componente->viewData('contatori'))->toBe([
            'scaduti' => 0,
            'in_scadenza' => 0,
            'oltre' => 0,
        ]);
});

it('counts zero, not everything, for an authenticated user without a tenant', function () {
    // 🔴 Fail-closed di ADR-018: è l'errore che si presenta come «vede tutto».
    // Vale anche per un Admin, che è il ruolo con più permessi fra i tenant-bound.
    Intervento::factory()->forStrumento($this->macchinaA)->scaduto()->create(['descrizione' => 'Di qualcuno']);
    Intervento::factory()->forStrumento($this->macchinaB)
        ->create(['data_scadenza' => today()->addDays(3)->toDateString(), 'descrizione' => 'Di qualcun altro']);

    $orfano = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $orfano->assignRole('Admin');

    $componente = Livewire::actingAs($orfano->fresh())->test(Scadenzario::class);

    $componente->assertDontSee('Di qualcuno')
        ->assertDontSee('Di qualcun altro');

    expect($componente->viewData('interventi')->total())->toBe(0)
        ->and($componente->viewData('contatori'))->toBe([
            'scaduti' => 0,
            'in_scadenza' => 0,
            'oltre' => 0,
        ]);
});

it('gives a Tecnico portfolio union assignment, and not a whole Ente', function () {
    // ADR-007/030: il perimetro del Tecnico è «gli Enti del portafoglio» ∪ «le
    // macchine su cui ha un intervento assegnato». Un intervento dell'Ente A su
    // una macchina fuori portafoglio e non assegnata a lui NON deve comparire —
    // né contare.
    $tecnico = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole('Tecnico');
    $tecnico->portafoglioClienti()->attach($this->enteB->id);

    Intervento::factory()->forStrumento($this->macchinaB)->scaduto()
        ->create(['descrizione' => 'Del portafoglio']);
    Intervento::factory()->forStrumento($this->macchinaA)->scaduto()->assegnatoA($tecnico)
        ->create(['descrizione' => 'Assegnato a me']);
    Intervento::factory()->forStrumento($this->macchinaAltroDeptA)->scaduto()
        ->create(['descrizione' => 'Ne mio ne assegnato']);

    $componente = Livewire::actingAs($tecnico->fresh())->test(Scadenzario::class);

    $componente->assertSee('Del portafoglio')
        ->assertSee('Assegnato a me')
        ->assertDontSee('Ne mio ne assegnato');

    expect($componente->viewData('interventi')->total())->toBe(2)
        ->and($componente->viewData('contatori')['scaduti'])->toBe(2);
});

it('never bypasses a global scope in the component', function () {
    // Coerente con `BypassNudiGuardrailTest`: qui non c'è nessuna porta
    // cross-tenant da aprire, e il giorno in cui qualcuno ne aprisse una questa
    // riga glielo fa dichiarare. I commenti si tolgono prima di cercare, o il
    // docblock che spiega perché NON si bypassa nulla renderebbe rosso il test:
    // un guardrail che legge il testo invece del codice punisce chi documenta.
    $sorgente = file_get_contents(app_path('Livewire/Interventi/Scadenzario.php'));

    $codice = collect(token_get_all($sorgente))
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');

    // ⚠️ `toContain()` è VARIADICO: nessun messaggio qui dentro, o il testo
    // diventerebbe un secondo ago e l'asserzione negativa sarebbe sempre
    // soddisfatta. La spiegazione sta in questo commento.
    expect($codice)->not->toContain('withoutGlobalScope')
        // Il positivo, senza cui la riga sopra sarebbe verde su un file vuoto.
        ->and($codice)->toContain('Intervento::query()');
});
