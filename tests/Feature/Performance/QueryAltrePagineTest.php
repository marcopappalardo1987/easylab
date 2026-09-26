<?php

use App\Models\Account;
use App\Models\Documento;
use App\Models\Fornitore;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Seguito di QueryPagineTest (S7/T4, portate qui dalla caccia T4B): le altre
 * pagine elenco, misurate con 5 e con 50 macchine (ognuna con fornitore,
 * ricambio, documento, interventi, garanzie e un utente in più). Costo diverso =
 * query che crescono con le righe; le soglie sono misurate e fissate.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = Account::factory()->saas()->create(['ragione_sociale' => 'Lab Rossi']);
    $this->ente = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Rossi']);
    $this->chimica = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Chimica']);
    $this->fisica = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Fisica']);

    foreach (['admin' => 'Admin', 'responsabile' => 'Responsabile Reparto', 'tenant' => 'Tenant', 'tecnico' => 'Tecnico'] as $k => $ruolo) {
        $this->{$k} = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
        $this->{$k}->assignRole($ruolo);
        if ($k !== 'tecnico') {
            $this->account->aggiungiMembro($this->{$k});
        }
    }
    $this->responsabile->unitaResponsabili()->attach($this->chimica->id);

    $piattaforma = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);
    $sede = UnitaOrganizzativa::factory()->ente()->perAccount($piattaforma)->create(['nome' => 'Sede EasyLab']);
    $this->superadmin = User::factory()->create(['tenant_id' => $sede->id, 'two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $piattaforma->aggiungiMembro($this->superadmin);
});

function corredoAltrePagine(object $t, int $n): Strumento
{
    auth()->logout();
    $fornitore = Fornitore::factory()->forTenant($t->ente)->create(['ragione_sociale' => "Fornitore {$n}"]);
    $s = Strumento::factory()->forNode($n % 2 === 0 ? $t->chimica : $t->fisica)->create(['nome' => "Macchina {$n}", 'fornitore_id' => $fornitore->id]);
    $ricambio = Ricambio::factory()->forTenant($t->ente)->create(['nome' => "Ricambio {$n}"]);
    Intervento::factory()->forStrumento($s)->fatto()->create();
    $intervento = Intervento::factory()->forStrumento($s)->scaduto()->assegnatoA($t->tecnico)->create();
    Garanzia::factory()->forStrumento($s)->attiva()->create();
    $u = RicambioUtilizzo::factory()->forStrumento($s)->forRicambio($ricambio)->forIntervento($intervento)->create();
    Garanzia::factory()->forRicambio($u)->imminente()->create();
    Documento::factory()->perStrumento($s)->create();
    SpostamentoStrumento::factory()->forStrumento($s)->create();
    $utente = User::factory()->create(['tenant_id' => $t->ente->id, 'name' => "Utente {$n}"]);
    $utente->assignRole('Tecnico');
    // Un tecnico esterno per macchina, con la sede in portafoglio: è la riga che
    // cresce su /piattaforma/tecnici (lista i soli tecnici senza tenant).
    $esterno = User::factory()->create(['tenant_id' => null, 'name' => "Esterno {$n}"]);
    $esterno->assignRole('Tecnico');
    $esterno->portafoglioClienti()->attach($t->ente->id);

    return $s;
}

function queryAltrePagine(User $utente, string $url): int
{
    test()->actingAs($utente->fresh());
    test()->get($url)->assertOk();

    app()->forgetScopedInstances();
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get($url)->assertOk();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

// Misurate il 21 Set 2026 (SQLite, memo dei nodi attiva). `$vede` è una riga
// vera della pagina: una pagina vuota sarebbe costante anche con un N+1.
it('costs the same with 5 and 50 machines on the other list pages, and no more than measured', function (string $ruolo, string $url, int $soglia, string $vede) {
    foreach (range(1, 5) as $n) {
        corredoAltrePagine($this, $n);
    }
    $cinque = queryAltrePagine($this->{$ruolo}, $url);
    foreach (range(6, 50) as $n) {
        corredoAltrePagine($this, $n);
    }
    $cinquanta = queryAltrePagine($this->{$ruolo}, $url);

    $this->get($url)->assertSee($vede);
    expect($cinquanta)->toBe($cinque)
        ->and($cinquanta)->toBeLessThanOrEqual($soglia);
})->with([
    'Admin documenti' => ['admin', '/documenti', 9, 'Macchina 2'],
    'Responsabile documenti' => ['responsabile', '/documenti', 10, 'Macchina 2'],
    'Tecnico documenti' => ['tecnico', '/documenti', 9, 'Macchina 2'],
    'Admin fornitori' => ['admin', '/fornitori', 5, 'Fornitore 2'],
    'Admin ricambi' => ['admin', '/ricambi?search=Ricambio', 8, 'Ricambio 2'],
    'Responsabile ricambi' => ['responsabile', '/ricambi?search=Ricambio', 9, 'Ricambio 2'],
    'Admin utenti' => ['admin', '/utenti', 10, 'Utente 1'],
    'Tecnico elenco strumenti' => ['tecnico', '/strumenti', 10, 'Macchina 2'],
    'Superadmin parco scadenzario' => ['superadmin', '/piattaforma/parco/scadenzario', 13, 'Macchina 2'],
    'Superadmin parco ricambi' => ['superadmin', '/piattaforma/parco/ricambi', 10, 'Ricambio 2'],
    'Superadmin tecnici' => ['superadmin', '/piattaforma/tecnici', 8, 'Esterno 1'],
    'Superadmin audit' => ['superadmin', '/piattaforma/audit', 19, 'Macchina 4'],
]);

it('costs the same for a Tecnico on a machine card with 2 and with 48 interventi, and no more than measured', function () {
    $this->tecnico->portafoglioClienti()->attach($this->ente->id);
    $poca = corredoAltrePagine($this, 2);
    $tanta = corredoAltrePagine($this, 4);
    auth()->logout();
    foreach (range(1, 23) as $_) {
        Intervento::factory()->forStrumento($tanta)->fatto()->create();
        Intervento::factory()->forStrumento($tanta)->scaduto()->assegnatoA($this->tecnico)->create();
    }

    $costoPoca = queryAltrePagine($this->tecnico, "/strumenti/{$poca->id}");

    $this->get("/strumenti/{$tanta->id}")->assertSee('Macchina 4');
    expect(queryAltrePagine($this->tecnico, "/strumenti/{$tanta->id}"))->toBe($costoPoca)
        ->and($costoPoca)->toBeLessThanOrEqual(27);
});

// ─── T4B-2: la scheda del Tecnico che vede la macchina solo per assegnazione ──
// ADR-030: l'assegnazione dà accesso a UNA macchina, non al catalogo ricambi
// dell'Ente (che arriva col portafoglio). La relazione `ricambio` è null per lui
// e `_ricambi.blade.php` leggeva `->nome` su null: 500.

it('renders the machine card for a Tecnico who sees the machine only by assignment, with a spare part mounted', function () {
    $s = corredoAltrePagine($this, 2);

    $this->actingAs($this->tecnico->fresh());
    $this->get("/strumenti/{$s->id}")->assertOk()->assertSee('Macchina 2');
});

it('shows that Tecnico no catalogue data about the mounted part, while the Admin sees its name', function () {
    $s = corredoAltrePagine($this, 2);
    $ricambio = Ricambio::withoutGlobalScopes()->where('nome', 'Ricambio 2')->sole();
    $ricambio->forceFill(['codice' => 'COD-SEGRETO-2'])->saveQuietly();

    // Controllo positivo: la riga c'è e il nome si vede a chi ha il catalogo,
    // quindi l'assenza qui sotto non è una pagina vuota.
    $this->actingAs($this->admin->fresh());
    $this->get("/strumenti/{$s->id}")->assertOk()->assertSee('Ricambio 2');

    $this->actingAs($this->tecnico->fresh());
    $this->get("/strumenti/{$s->id}")->assertOk()
        ->assertSee('Pezzo del catalogo dell\'Ente, non consultabile', false)
        ->assertDontSee('Ricambio 2')
        ->assertDontSee('COD-SEGRETO-2');
});
