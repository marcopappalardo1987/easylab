<?php

use App\Models\Account;
use App\Models\Documento;
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
 * Quante query costano le pagine principali, con un parco di volume realistico
 * (S7/T4). La proprietà da difendere è «costante rispetto alle righe»: le
 * stesse pagine con 5 e con 50 macchine (ciascuna con interventi, garanzia e
 * un ricambio montato con la sua garanzia) devono costare lo stesso numero di
 * statement. Le soglie assolute sono misurate e fissate qui sotto, perché una
 * regressione si veda anche quando resta costante.
 *
 * Si misura la pagina INTERA via HTTP (layout, menù, switcher compresi),
 * dopo un giro a vuoto che scalda i permessi di spatie, e azzerando le istanze
 * scoped prima della misura: è ciò che vede una richiesta nuova, memo dei nodi
 * compresa.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = Account::factory()->saas()->create(['ragione_sociale' => 'Lab Rossi']);
    $this->ente = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Rossi']);
    $this->chimica = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Chimica']);
    $this->fisica = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Fisica']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
    $this->account->aggiungiMembro($this->admin);

    $this->responsabile = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->responsabile->assignRole('Responsabile Reparto');
    $this->responsabile->unitaResponsabili()->attach($this->chimica->id);
    $this->account->aggiungiMembro($this->responsabile);

    $this->tenant = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->tenant->assignRole('Tenant');
    $this->account->aggiungiMembro($this->tenant);

    // Tecnico interno (ADR-030): vede per assegnazione, quindi metà degli
    // interventi aperti del parco gli viene assegnata in `corredo()`.
    $this->tecnico = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->tecnico->assignRole('Tecnico');

    $piattaforma = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);
    $sedeEasylab = UnitaOrganizzativa::factory()->ente()->perAccount($piattaforma)->create(['nome' => 'Sede EasyLab']);
    $this->superadmin = User::factory()->create(['tenant_id' => $sedeEasylab->id, 'two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $piattaforma->aggiungiMembro($this->superadmin);

    $this->ricambio = Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Guarnizione']);
});

function corredo(object $t, Strumento $s, int $interventi = 2): void
{
    foreach (range(1, $interventi) as $i) {
        $intervento = $i % 2 === 0
            ? Intervento::factory()->forStrumento($s)->fatto()->create()
            : Intervento::factory()->forStrumento($s)->scaduto()->assegnatoA($t->tecnico)->create();
    }
    Garanzia::factory()->forStrumento($s)->attiva()->create();
    $utilizzo = RicambioUtilizzo::factory()->forStrumento($s)->forRicambio($t->ricambio)->forIntervento($intervento)->create();
    Garanzia::factory()->forRicambio($utilizzo)->imminente()->create();
    Documento::factory()->perStrumento($s)->create();
    SpostamentoStrumento::factory()->forStrumento($s)->create();
}

function querySullaPagina(User $utente, string $url): int
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

/**
 * La stessa pagina, prima con 5 macchine e poi con 50: [costo con 5, costo con 50].
 *
 * @return array{int, int}
 */
function costoConCinqueEConCinquanta(object $t, string $ruolo, string $url): array
{
    $crea = function (int $da, int $a) use ($t): void {
        auth()->logout();
        foreach (range($da, $a) as $n) {
            corredo($t, Strumento::factory()->forNode($n % 2 === 0 ? $t->chimica : $t->fisica)->create(['nome' => "Macchina {$n}"]));
        }
    };

    $crea(1, 5);
    $cinque = querySullaPagina($t->{$ruolo}, $url);
    $crea(6, 50);

    return [$cinque, querySullaPagina($t->{$ruolo}, $url)];
}

// Misurate il 21 Set 2026 (SQLite, memo dei nodi attiva). Fra parentesi il
// Responsabile PRIMA della memo: dashboard 32, elenco 27, scadenzario 47.
// L'elenco costa una query in meno da quando non carica più `unita`, che la
// vista non legge (usa i percorsi già calcolati).
dataset('pagine', [
    'Admin dashboard' => ['admin', '/dashboard', 11, 'tutte le 50 macchine che vedi'],
    'Responsabile dashboard' => ['responsabile', '/dashboard', 12, 'tutte le 25 macchine che vedi'],
    'Tenant dashboard' => ['tenant', '/dashboard', 10, 'tutte le 50 macchine che vedi'],
    'Tecnico dashboard' => ['tecnico', '/dashboard', 10, 'tutte le 50 macchine che vedi'],
    'Tecnico campo' => ['tecnico', '/campo', 6, 'I miei interventi (50)'],
    'Admin elenco strumenti' => ['admin', '/strumenti', 11, 'Macchina 2'],
    'Responsabile elenco strumenti' => ['responsabile', '/strumenti', 12, 'Macchina 2'],
    'Tenant elenco strumenti' => ['tenant', '/strumenti', 10, 'Macchina 2'],
    'Admin scadenzario' => ['admin', '/scadenzario', 12, 'Macchina 2'],
    'Responsabile scadenzario' => ['responsabile', '/scadenzario', 13, 'Macchina 2'],
    'Tecnico scadenzario' => ['tecnico', '/scadenzario', 11, 'Macchina 2'],
    'Superadmin cabina' => ['superadmin', '/piattaforma', 19, 'Strumenti 50 Macchine di tutti i clienti'],
    'Superadmin parco' => ['superadmin', '/piattaforma/parco', 11, 'Macchina 2'],
]);

it('costs the same with 5 machines and with 50, and no more than measured', function (string $ruolo, string $url, int $soglia, string $vede) {
    [$cinque, $cinquanta] = costoConCinqueEConCinquanta($this, $ruolo, $url);

    // Una pagina vuota costerebbe poco anche con un N+1: prima si prova che
    // la pagina mostra i dati veri (sulle dashboard: il totale delle 50, o 25
    // per il Responsabile che ne vede metà).
    $testo = preg_replace('/\s+/', ' ', strip_tags($this->get($url)->getContent()));
    expect($testo)->toContain($vede);

    expect($cinquanta)->toBe($cinque)
        ->and($cinquanta)->toBeLessThanOrEqual($soglia);
})->with('pagine');

it('costs a Responsabile at most one statement more than an Admin on the same page', function (string $url) {
    // Il debito dichiarato in S6: ogni query scopata rileggeva pivot e albero.
    // Con la memo per richiesta il Responsabile paga UNA lettura in più.
    [, $admin] = costoConCinqueEConCinquanta($this, 'admin', $url);

    expect(querySullaPagina($this->responsabile, $url))->toBeLessThanOrEqual($admin + 1);
})->with(['/dashboard', '/strumenti', '/scadenzario']);

it('costs the same on a machine card with two interventi and with forty-eight', function (string $ruolo, int $soglia) {
    auth()->logout();
    $poca = Strumento::factory()->forNode($this->chimica)->create(['nome' => 'Poca']);
    corredo($this, $poca);
    $tanta = Strumento::factory()->forNode($this->chimica)->create(['nome' => 'Tanta']);
    corredo($this, $tanta, 30);
    foreach (range(1, 9) as $_) {
        corredo($this, $tanta);
    }

    $costoPoca = querySullaPagina($this->{$ruolo}, "/strumenti/{$poca->id}");

    expect(querySullaPagina($this->{$ruolo}, "/strumenti/{$tanta->id}"))->toBe($costoPoca)
        ->and($costoPoca)->toBeLessThanOrEqual($soglia);
})->with([
    // Prima della memo il Responsabile ne pagava 64.
    'Admin' => ['admin', 27],
    'Responsabile' => ['responsabile', 28],
]);
