<?php

use App\Livewire\Campo\Home;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Home della vista di campo (🔗 ADR-003/007/030 — Wireframe §3, schermata di
 * sinistra — S4 blocco 10).
 *
 * L'unica schermata NUOVA del blocco: la scheda della macchina non è stata
 * duplicata in versione mobile, è stata resa responsive, perché ADR-003 vuole
 * che viva in un posto solo con una sola catena di autorizzazione.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Microbiologia']);
    $this->autoclave = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);
    $this->cappa = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Cappa chimica']);

    $this->tecnico = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->tecnico->assignRole('Tecnico');
});

it('redirects guests to login', function () {
    $this->get(route('campo.index'))->assertRedirect(route('login'));
});

it('forbids anyone without interventi.view', function () {
    // Nessun ruolo del seeder ne è privo: serve un ruolo ad hoc.
    Role::create(['name' => 'Magazziniere'])->givePermissionTo('strumenti.view');
    $u = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $u->assignRole('Magazziniere');

    $this->actingAs($u)->get(route('campo.index'))->assertForbidden();
});

it('lists the open interventi assigned to whoever is looking, most urgent first', function () {
    $fra30 = Intervento::factory()->forStrumento($this->autoclave)
        ->create(['tecnico_id' => $this->tecnico->id, 'data_scadenza' => today()->addDays(30), 'descrizione' => 'Controllo pressione']);
    $scaduto = Intervento::factory()->forStrumento($this->cappa)->scaduto()
        ->create(['tecnico_id' => $this->tecnico->id, 'descrizione' => 'Sostituzione filtro']);

    Livewire::actingAs($this->tecnico)->test(Home::class)
        ->assertSee('Controllo pressione')
        ->assertSee('Sostituzione filtro')
        // Lo scaduto è il più urgente, quindi in cima: separarli in due elenchi
        // avrebbe raddoppiato la lettura senza aggiungere nulla.
        ->assertSeeInOrder(['Sostituzione filtro', 'Controllo pressione'])
        ->tap(fn ($c) => expect($c->viewData('interventi')->pluck('id')->all())
            ->toBe([$scaduto->id, $fra30->id]));
});

it('never lists an intervento assigned to somebody else', function () {
    // È la differenza fra «i miei interventi» e «gli interventi che posso
    // vedere»: un Responsabile li vedrebbe tutti, e questa lista non sarebbe
    // più sua.
    $altro = User::factory()->create(['tenant_id' => $this->ente->id]);
    $altro->assignRole('Tecnico');
    Intervento::factory()->forStrumento($this->autoclave)
        ->create(['tecnico_id' => $altro->id, 'descrizione' => 'Lavoro di un collega']);

    Livewire::actingAs($this->tecnico)->test(Home::class)
        ->assertDontSee('Lavoro di un collega')
        ->tap(fn ($c) => expect($c->viewData('interventi'))->toBeEmpty());
});

it('drops an intervento as soon as it is done', function () {
    $intervento = Intervento::factory()->forStrumento($this->autoclave)
        ->create(['tecnico_id' => $this->tecnico->id, 'descrizione' => 'Taratura annuale']);

    Livewire::actingAs($this->tecnico)->test(Home::class)->assertSee('Taratura annuale');

    $intervento->segnaFatto(today());

    Livewire::actingAs($this->tecnico)->test(Home::class)
        ->assertDontSee('Taratura annuale')
        ->assertSee('Nessun intervento assegnato a te da fare.');
});

it('tells an external tecnico where the machine is', function () {
    // Sul campo l'ubicazione è come si trova la macchina, ed è la ragione per
    // cui ADR-030 è stato emendato: senza, la riga diceva il nome e non il posto.
    $esterno = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $esterno->assignRole('Tecnico');
    Intervento::factory()->forStrumento($this->autoclave)->create(['tecnico_id' => $esterno->id]);

    Livewire::actingAs($esterno)->test(Home::class)
        ->assertSee('Autoclave')
        ->assertSee('Ente A › Microbiologia');
});

it('reads the locations in a number of queries that does not grow with the list', function () {
    // ⚠️ **Si contano le sole letture di `unita_organizzativa`, non tutte le
    // query**, e la differenza non è pignoleria: la prima stesura contava
    // tutto, e il totale cambiava fra esecuzione isolata e suite intera per
    // ragioni che con questa pagina non c'entrano — la cache dei permessi di
    // spatie, scaldata o meno a seconda di cosa è girato prima. Un test che
    // dipende da quell'ordine è rumore travestito da guardia. Qui si misura
    // esattamente ciò che il caso afferma.
    $letturNodi = function (): int {
        $n = 0;
        DB::listen(function ($q) use (&$n) {
            if (str_contains($q->sql, 'unita_organizzativa')) {
                $n++;
            }
        });
        Livewire::actingAs($this->tecnico)->test(Home::class);

        return $n;
    };

    Intervento::factory()->forStrumento($this->autoclave)->create(['tecnico_id' => $this->tecnico->id]);

    // Non «una query»: gli scope leggono a loro volta l'albero per collocare le
    // macchine. Ciò che si afferma è che quel numero **non cresca con la lista**.
    $conUno = $letturNodi();

    // Sei macchine in due nodi diversi: la risalita ingenua costerebbe due
    // query a riga — dipartimento e Ente — ed è esattamente ciò che faceva la
    // prima versione di questa pagina, scoperta da questo caso.
    $altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    foreach (range(1, 5) as $i) {
        $s = Strumento::factory()->forNode($i % 2 === 0 ? $altroDept : $this->dept)->create();
        Intervento::factory()->forStrumento($s)->create(['tecnico_id' => $this->tecnico->id]);
    }

    expect($letturNodi())->toBe($conUno);
});

it('caps the list and says so, instead of truncating in silence', function () {
    // Il numero in testa resta quello VERO: una lista tagliata senza avviso si
    // legge come una lista completa, ed è lo stesso principio del tetto della
    // ricerca ricambi.
    foreach (range(1, Home::MAX_INTERVENTI + 3) as $i) {
        $s = Strumento::factory()->forNode($this->dept)->create();
        Intervento::factory()->forStrumento($s)->create([
            'tecnico_id' => $this->tecnico->id,
            'data_scadenza' => today()->addDays($i),
        ]);
    }

    Livewire::actingAs($this->tecnico)->test(Home::class)
        ->assertSee('I miei interventi ('.(Home::MAX_INTERVENTI + 3).')')
        ->assertSee('più urgenti')
        ->tap(fn ($c) => expect($c->viewData('interventi'))->toHaveCount(Home::MAX_INTERVENTI)
            ->and($c->viewData('totale'))->toBe(Home::MAX_INTERVENTI + 3));
});

it('says nothing about a cap when there is nothing to cap', function () {
    Intervento::factory()->forStrumento($this->autoclave)->create(['tecnico_id' => $this->tecnico->id]);

    Livewire::actingAs($this->tecnico)->test(Home::class)->assertDontSee('più urgenti');
});
