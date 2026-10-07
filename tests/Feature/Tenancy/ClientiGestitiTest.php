<?php

use App\Models\Account;
use App\Models\Documento;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Tenancy\SediSeguite;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/**
 * Il Superadmin sui clienti con manutenzione gestita da EasyLab (🔗 ADR-046).
 *
 * **Area rossa**: si tocca `TenantScope`. Un errore qui non è un bug, è EasyLab
 * che legge — o scrive — i dati di un cliente che non glieli ha affidati. Ogni
 * caso è scritto per FALSIFICARE la regola.
 *
 * Mondo: EasyLab (account di piattaforma, Ente del Superadmin) con una macchina
 * propria; il cliente **Gestito** con due sedi; il cliente **Autonomo**, che si
 * gestisce da sé e fa da controprova in ogni caso.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $sede = function (Account $account, string $nome, string $macchina): array {
        $ente = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => $nome]);
        $reparto = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => "Reparto {$nome}"]);

        return [$ente, $reparto, Strumento::factory()->forNode($reparto)->create(['nome' => $macchina])];
    };

    $this->easylab = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);
    [$this->enteEasylab, , $this->demo] = $sede($this->easylab, 'EasyLab', 'Demo');

    $this->gestito = Account::factory()->create(['ragione_sociale' => 'Gestito srl']);
    $this->gestito->affidaManutenzione();
    [$this->sedeUno, $this->repartoUno, $this->autoclave] = $sede($this->gestito, 'Gestito Uno', 'Autoclave');
    [$this->sedeDue, , $this->cappa] = $sede($this->gestito, 'Gestito Due', 'Cappa');

    $this->autonomo = Account::factory()->create(['ragione_sociale' => 'Autonomo srl']);
    [$this->sedeAutonoma, $this->repartoAutonomo, $this->centrifuga] = $sede($this->autonomo, 'Autonomo', 'Centrifuga');

    $this->utente = function (string $ruolo, ?UnitaOrganizzativa $ente = null): User {
        $u = User::factory()->create(['tenant_id' => $ente?->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        return $u;
    };

    $this->superadmin = ($this->utente)('Superadmin', $this->enteEasylab);
});

// --- La regola ---

it('shows the superadmin every sede of a managed client, next to the own ente', function () {
    $this->actingAs($this->superadmin);

    expect(Strumento::pluck('nome')->all())->toEqualCanonicalizing(['Demo', 'Autoclave', 'Cappa']);
});

it('reaches nodes, interventi, garanzie, ricambi and documenti of a managed client', function () {
    Intervento::factory()->forStrumento($this->autoclave)->create();
    $utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->autoclave)
        ->forRicambio(Ricambio::factory()->forTenant($this->sedeUno)->create(['nome' => 'Guarnizione']))
        ->create();
    Garanzia::factory()->forStrumento($this->autoclave)->create();
    Garanzia::factory()->forRicambio($utilizzo)->create();
    Documento::factory()->perStrumento($this->autoclave)->create();

    $this->actingAs($this->superadmin);

    expect(Intervento::count())->toBe(1)
        ->and(RicambioUtilizzo::count())->toBe(1)
        ->and(Ricambio::count())->toBe(1)
        ->and(Documento::count())->toBe(1)
        ->and(Garanzia::count())->toBe(2)
        ->and(UnitaOrganizzativa::pluck('nome')->all())->toContain('Gestito Uno', 'Reparto Gestito Uno', 'Gestito Due');
});

// --- I negativi: è qui che il blocco si difende ---

it('never shows the superadmin a client that manages itself', function () {
    Intervento::factory()->forStrumento($this->centrifuga)->create();
    Documento::factory()->perStrumento($this->centrifuga)->create();

    $this->actingAs($this->superadmin);

    expect(Strumento::pluck('nome')->all())->not->toContain('Centrifuga')
        ->and(Strumento::find($this->centrifuga->id))->toBeNull()
        ->and(Intervento::where('strumento_id', $this->centrifuga->id)->count())->toBe(0)
        ->and(Documento::count())->toBe(0)
        ->and(UnitaOrganizzativa::pluck('nome')->all())->not->toContain('Autonomo');
});

it('leaves the superadmin exactly where they were when no client is managed', function () {
    // Il confine di prima, riga per riga: senza clienti gestiti questa regola
    // non deve aver cambiato nulla.
    $this->gestito->ritiraManutenzione();

    $this->actingAs($this->superadmin);

    expect(Strumento::pluck('nome')->all())->toBe(['Demo']);
});

it('shows nothing to a superadmin without an ente when no client is managed', function () {
    // Il fail-closed di ADR-018 sopravvive per costruzione: una sottoquery
    // vuota dà falso, senza un ramo dedicato.
    $this->gestito->ritiraManutenzione();

    $this->actingAs(($this->utente)('Superadmin'));

    expect(Strumento::count())->toBe(0)
        ->and(UnitaOrganizzativa::count())->toBe(0);
});

it('gives a superadmin without an ente the managed clients and nothing else', function () {
    $this->actingAs(($this->utente)('Superadmin'));

    expect(Strumento::pluck('nome')->all())->toEqualCanonicalizing(['Autoclave', 'Cappa']);
});

it('takes the access away as soon as the client is no longer managed', function () {
    $this->actingAs($this->superadmin);
    expect(Strumento::count())->toBe(3);

    // Nessuna cache da invalidare: è una sottoquery.
    $this->gestito->ritiraManutenzione();

    expect(Strumento::pluck('nome')->all())->toBe(['Demo']);
});

it('stops at an archived client and at an archived sede', function () {
    // La riga È il permesso: un rapporto chiuso non resta aperto in lettura.
    $this->sedeDue->delete();

    $this->actingAs($this->superadmin);
    expect(Strumento::pluck('nome')->all())->toEqualCanonicalizing(['Demo', 'Autoclave']);

    $this->gestito->delete();

    expect(Strumento::pluck('nome')->all())->toBe(['Demo']);
});

it('keeps working on a managed client in lockout', function () {
    // Il lockout chiude la porta agli utenti del cliente, non il lavoro di
    // EasyLab sulle sue macchine.
    $this->gestito->blocca('insoluto');

    $this->actingAs($this->superadmin);

    expect(Strumento::pluck('nome')->all())->toContain('Autoclave', 'Cappa');
});

it('opens nothing to any other role, managed client or not', function (string $ruolo, bool $conEnte) {
    // 🔴 Il segno apre i dati al Superadmin e a nessun altro. Il Developer è il
    // caso che conta: ha ogni permesso, e con un criterio per permesso li
    // erediterebbe.
    $this->actingAs(($this->utente)($ruolo, $conEnte ? $this->sedeAutonoma : null));

    expect(Strumento::pluck('nome')->all())->toBe($conEnte ? ['Centrifuga'] : []);
})->with([
    'Developer senza Ente' => ['Developer', false],
    'Developer su un altro cliente' => ['Developer', true],
    'Admin di un altro cliente' => ['Admin', true],
    'Tenant di un altro cliente' => ['Tenant', true],
    'Gestore senza portafoglio' => ['Gestore', false],
    'Tecnico senza portafoglio' => ['Tecnico', false],
]);

it('does not open the client users of a managed client to each other\'s sedi', function () {
    // Il segno non tocca gli utenti del cliente: l'Admin della sede Uno resta
    // nella sede Uno.
    $this->actingAs(($this->utente)('Admin', $this->sedeUno));

    expect(Strumento::pluck('nome')->all())->toBe(['Autoclave']);
});

// --- La scrittura ---

it('lets the superadmin create a row for a managed sede, with the tenant of that sede', function () {
    // 🔴 Il Superadmin ha un Ente proprio: senza l'eccezione di `BelongsToTenant`
    // la macchina nascerebbe col `tenant_id` di EasyLab — visibile a EasyLab,
    // invisibile al cliente per cui è stata registrata.
    $this->actingAs($this->superadmin);

    $nuova = Strumento::create([
        'tenant_id' => $this->sedeUno->id,
        'unita_organizzativa_id' => $this->repartoUno->id,
        'nome' => 'Bilancia',
    ]);

    expect($nuova->fresh()->tenant_id)->toBe($this->sedeUno->id);

    $this->actingAs(($this->utente)('Admin', $this->sedeUno));
    expect(Strumento::pluck('nome')->all())->toContain('Bilancia');
});

it('brings back to the own ente a row the superadmin declares for a client that manages itself', function () {
    $this->actingAs($this->superadmin);

    $nuova = Strumento::create([
        'tenant_id' => $this->sedeAutonoma->id,
        'unita_organizzativa_id' => $this->repartoAutonomo->id,
        'nome' => 'Intrusa',
    ]);

    expect($nuova->fresh()->tenant_id)->toBe($this->enteEasylab->id);
});

it('keeps forcing the own ente for a client admin, whatever tenant they declare', function () {
    // La controprova: l'eccezione è del solo Superadmin. Un Admin che dichiara
    // la sede di un cliente gestito non ci scrive dentro.
    $this->actingAs(($this->utente)('Admin', $this->sedeAutonoma));

    $nuova = Strumento::create([
        'tenant_id' => $this->sedeUno->id,
        'unita_organizzativa_id' => $this->repartoAutonomo->id,
        'nome' => 'Forgiata',
    ]);

    expect($nuova->fresh()->tenant_id)->toBe($this->sedeAutonoma->id);
});

// --- Il segno ---

it('writes who entrusted the maintenance, and who took it back', function () {
    $this->actingAs($this->superadmin);
    $prima = Activity::where('log_name', AuditLog::NAME)->count();

    $this->autonomo->affidaManutenzione();

    $riga = Activity::where('log_name', AuditLog::NAME)->latest('id')->first();

    expect(Activity::where('log_name', AuditLog::NAME)->count())->toBe($prima + 1)
        ->and($riga->causer_id)->toBe($this->superadmin->id)
        ->and($riga->attribute_changes['attributes']['manutenzione_gestita'])->toBeTrue();

    // Ripetere il gesto non lascia una seconda riga.
    $this->autonomo->affidaManutenzione();
    expect(Activity::where('log_name', AuditLog::NAME)->count())->toBe($prima + 1);

    $this->autonomo->ritiraManutenzione();
    expect($this->autonomo->fresh()->manutenzione_gestita)->toBeFalse()
        ->and(Activity::where('log_name', AuditLog::NAME)->count())->toBe($prima + 2);
});

it('refuses to entrust the platform account to itself', function () {
    expect(fn () => $this->easylab->affidaManutenzione())->toThrow(RuntimeException::class);

    expect($this->easylab->fresh()->manutenzione_gestita)->toBeFalse();
});

it('is born off, and no form can forge it', function () {
    $nuovo = Account::create(['ragione_sociale' => 'Nuovo srl', 'manutenzione_gestita' => true]);

    expect($nuovo->fresh()->manutenzione_gestita)->toBeFalse()
        ->and((new Account)->manutenzione_gestita)->toBeFalse();
});

// --- I titoli che nominano una sede ---

it('names a single sede only when the rows are of that sede alone', function () {
    // Il PDF dell'archivio documentale si intitola con la sede: col Superadmin
    // sui clienti gestiti direbbe «EasyLab» sopra i documenti di altri.
    $this->actingAs($this->superadmin);
    expect(SediSeguite::sedeUnica())->toBeNull();

    $this->gestito->ritiraManutenzione();
    expect(SediSeguite::sedeUnica())->toBe('EasyLab');

    $this->actingAs(($this->utente)('Admin', $this->sedeAutonoma));
    expect(SediSeguite::sedeUnica())->toBe('Autonomo');

    // Chi non ha un Ente non ha una sede da nominare.
    $this->actingAs(($this->utente)('Gestore'));
    expect(SediSeguite::sedeUnica())->toBeNull();
});
