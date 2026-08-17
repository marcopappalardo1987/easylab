<?php

use App\Models\Documento;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Accesso dei Tecnici: portafoglio ∪ assegnazione (🔗 ADR-007, esteso da
 * ADR-030 — S4 blocco 9).
 *
 * **Area rossa, la più rossa dello sprint**: qui si tocca `TenantScope`, il file
 * su cui poggia l'isolamento fra clienti. Un errore non si presenta come un bug,
 * si presenta come un cliente che legge i dati di un altro — quindi ogni caso è
 * scritto per FALSIFICARE una regola, non per confermarla.
 *
 * Albero: Ente A → Dip → due macchine; Ente B → Dip → una macchina.
 *
 * Le tre figure che i casi mettono a confronto:
 *   - tecnico ESTERNO  (`tenant_id` NULL, staff EasyLab) con portafoglio;
 *   - tecnico ESTERNO senza nulla — il fail-closed di ADR-018;
 *   - tecnico INTERNO  (`tenant_id` valorizzato, dipendente del laboratorio),
 *     per cui il proprio Ente resta una barriera in AND e NON un lasciapassare.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->deptA = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create();
    $this->autoclave = Strumento::factory()->forNode($this->deptA)->create(['nome' => 'Autoclave']);
    $this->cappa = Strumento::factory()->forNode($this->deptA)->create(['nome' => 'Cappa chimica']);

    $this->enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $this->deptB = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create();
    $this->centrifuga = Strumento::factory()->forNode($this->deptB)->create(['nome' => 'Centrifuga']);

    $this->tecnico = function (?UnitaOrganizzativa $ente = null): User {
        $u = User::factory()->create([
            'tenant_id' => $ente?->id,
            'two_factor_confirmed_at' => now(),
        ]);
        $u->assignRole('Tecnico');

        return $u;
    };
});

// --- I due canali, presi da soli ---

it('shows a tecnico every machine of an Ente in the portfolio', function () {
    $tecnico = ($this->tecnico)();
    $tecnico->portafoglioClienti()->attach($this->enteA);

    $this->actingAs($tecnico);

    expect(Strumento::pluck('nome')->all())->toEqualCanonicalizing(['Autoclave', 'Cappa chimica']);
});

it('shows a tecnico the machine of an assigned intervento, and only that one', function () {
    $tecnico = ($this->tecnico)();
    Intervento::factory()->forStrumento($this->autoclave)->create(['tecnico_id' => $tecnico->id]);

    $this->actingAs($tecnico);

    // La Cappa è la macchina accanto, stesso Ente e stesso reparto: se comparisse,
    // l'assegnazione starebbe funzionando come un permesso sull'Ente.
    expect(Strumento::pluck('nome')->all())->toBe(['Autoclave']);
});

/**
 * L'unione è un OR e non un AND, ed è la sola forma che dà ADR-007. In AND si
 * otterrebbe «le macchine assegnate DEGLI Enti in portafoglio», cioè meno di
 * ciascun canale preso da solo: un tecnico con un intervento presso un cliente
 * fuori portafoglio non vedrebbe la macchina su cui deve andare.
 */
it('unites the two channels instead of intersecting them', function () {
    $tecnico = ($this->tecnico)();
    $tecnico->portafoglioClienti()->attach($this->enteA);
    Intervento::factory()->forStrumento($this->centrifuga)->create(['tecnico_id' => $tecnico->id]);

    $this->actingAs($tecnico);

    expect(Strumento::pluck('nome')->all())
        ->toEqualCanonicalizing(['Autoclave', 'Cappa chimica', 'Centrifuga']);
});

// --- I negativi: è qui che il blocco si difende ---

it('never shows a tecnico an Ente outside the portfolio', function () {
    $tecnico = ($this->tecnico)();
    $tecnico->portafoglioClienti()->attach($this->enteA);

    $this->actingAs($tecnico);

    expect(Strumento::pluck('nome')->all())->not->toContain('Centrifuga');
});

it('shows nothing at all to a tecnico with neither portfolio nor assignments', function () {
    // Il fail-closed di ADR-018 sopravvive per COSTRUZIONE: entrambi i canali
    // sono `IN (sottoquery)`, e due sottoquery vuote danno falso senza bisogno
    // di un ramo dedicato che qualcuno debba ricordarsi di scrivere.
    $this->actingAs(($this->tecnico)());

    expect(Strumento::count())->toBe(0)
        ->and(Intervento::count())->toBe(0)
        ->and(Garanzia::count())->toBe(0);
});

it('takes the access away as soon as the portfolio is revoked', function () {
    $tecnico = ($this->tecnico)();
    $tecnico->portafoglioClienti()->attach($this->enteA);

    $this->actingAs($tecnico);
    expect(Strumento::count())->toBe(2);

    // Nessuna cache da invalidare: il portafoglio è una sottoquery SQL, quindi
    // una revoca ha effetto sulla richiesta successiva senza altri passaggi.
    $tecnico->portafoglioClienti()->detach($this->enteA);

    expect(Strumento::count())->toBe(0);
});

it('keeps an internal tecnico inside their own Ente even if the portfolio says otherwise', function () {
    // Difesa in profondità (ADR-030): per chi ha un `tenant_id` il proprio Ente
    // resta in AND. Un errore nel portafoglio — una riga inserita per sbaglio
    // dalla pagina permessi di S6 — non deve poter portare un dipendente del
    // laboratorio A dentro i dati del laboratorio B.
    $interno = ($this->tecnico)($this->enteA);
    $interno->portafoglioClienti()->attach([$this->enteA->id, $this->enteB->id]);

    $this->actingAs($interno);

    expect(Strumento::pluck('nome')->all())->toEqualCanonicalizing(['Autoclave', 'Cappa chimica'])
        ->and(Strumento::pluck('nome')->all())->not->toContain('Centrifuga');
});

it('does not let belonging to an Ente be an access by itself', function () {
    // La restrizione NUOVA di ADR-030, e quella che ha cambiato cinque test
    // preesistenti: prima un tecnico interno vedeva tutto il proprio Ente.
    $this->actingAs(($this->tecnico)($this->enteA));

    expect(Strumento::count())->toBe(0);
});

it('never lets the criterion loosen the scope of any other role', function () {
    // La mutazione «applica il criterio a chiunque, non al solo Tecnico» deve
    // avere un test che la fa cadere: senza, allargare la porta sarebbe gratis.
    $admin = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $admin->assignRole('Admin');
    $admin->portafoglioClienti()->attach($this->enteB); // riga assurda, e ininfluente

    $this->actingAs($admin);

    expect(Strumento::pluck('nome')->all())->toEqualCanonicalizing(['Autoclave', 'Cappa chimica'])
        ->and(Strumento::pluck('nome')->all())->not->toContain('Centrifuga');
});

// --- Il canale assegnazione arriva a TUTTI i modelli appesi allo strumento ---

it('reaches interventi, garanzie, ricambi and documenti of an assigned machine', function () {
    $tecnico = ($this->tecnico)();
    Intervento::factory()->forStrumento($this->autoclave)->create(['tecnico_id' => $tecnico->id]);

    $utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->autoclave)
        ->forRicambio(Ricambio::factory()->forTenant($this->enteA)->create(['nome' => 'Guarnizione']))
        ->create();
    Garanzia::factory()->forStrumento($this->autoclave)->create();
    // ⚠️ La garanzia del PEZZO ha `strumento_id` NULL: la raggiunge solo il
    // doppio salto di `Garanzia::vincolaAStrumenti()`. È il caso per cui il
    // contratto chiede una restrizione e non una colonna.
    Garanzia::factory()->forRicambio($utilizzo)->create();
    Documento::factory()->perStrumento($this->autoclave)->create();

    $this->actingAs($tecnico);

    expect(Intervento::count())->toBe(1)
        ->and(RicambioUtilizzo::count())->toBe(1)
        ->and(Documento::count())->toBe(1)
        ->and(Garanzia::count())->toBe(2);
});

it('does not reach the rows of a machine that is neither assigned nor in the portfolio', function () {
    $tecnico = ($this->tecnico)();
    Intervento::factory()->forStrumento($this->autoclave)->create(['tecnico_id' => $tecnico->id]);

    // Righe della macchina ACCANTO, che il tecnico non deve raggiungere.
    Intervento::factory()->forStrumento($this->cappa)->create();
    Garanzia::factory()->forStrumento($this->cappa)->create();
    Documento::factory()->perStrumento($this->cappa)->create();

    $this->actingAs($tecnico);

    expect(Intervento::count())->toBe(1)
        ->and(Garanzia::count())->toBe(0)
        ->and(Documento::count())->toBe(0);
});
