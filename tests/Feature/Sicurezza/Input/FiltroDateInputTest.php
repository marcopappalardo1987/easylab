<?php

use App\Models\Documento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Documenti\FiltroDocumenti;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 I filtri `dal`/`al` arrivano da un link, e l'SQL li riceve (S7, T1c · caccia T1cB-7).
 *
 * Carbon legge «0000-01-01» e «+100000000 years»; Postgres no: la pagina
 * andava in 500 da un link incollato. Un anno fuori da 1..9999 vale come un
 * valore illeggibile: si ignora il filtro. La prova è sui BINDING (che cosa
 * arriverebbe a Postgres), non sullo stato HTTP, che su SQLite è 200 comunque.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create();
    Strumento::factory()->forNode($this->ente)->create();

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

function anniDeiBinding(array $binding): array
{
    return collect($binding)
        ->map(fn ($b) => $b instanceof DateTimeInterface ? (int) $b->format('Y') : (is_string($b) && preg_match('/^(-?\d{1,9})-\d{2}-\d{2}/', $b, $m) ? (int) $m[1] : null))
        ->filter(fn ($anno) => $anno !== null)
        ->values()
        ->all();
}

it('drops a document date filter with a year Postgres cannot store', function (string $valore) {
    $query = (new FiltroDocumenti(dal: $valore, al: $valore))->applica(Documento::query());

    expect(anniDeiBinding($query->getBindings()))->toBe([]);
})->with(['0000-01-01', '+100000000 years']);

it('keeps a sensible document date filter', function () {
    $query = (new FiltroDocumenti(dal: '2026-01-01', al: '2026-12-31'))->applica(Documento::query());

    expect(anniDeiBinding($query->getBindings()))->toBe([2026, 2027]); // `al` è inclusivo: il confine è il giorno dopo, escluso.
});

it('answers the documents page, not a 500, for a strange date in the link', function (string $valore) {
    $this->actingAs($this->admin)
        ->get(route('documenti.index', ['dal' => $valore, 'al' => $valore]))
        ->assertOk();
})->with(['0000-01-01', '+100000000 years', 'abc', '2026-02-30']);

it('drops an audit date filter with a year Postgres cannot store', function (string $valore) {
    $superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');

    DB::enableQueryLog();

    $this->actingAs($superadmin->fresh())
        ->get(route('piattaforma.audit', ['dal' => $valore, 'al' => $valore]))
        ->assertOk();

    $anni = collect(DB::getQueryLog())
        ->filter(fn ($q) => str_contains($q['query'], 'activity_log') && str_contains($q['query'], 'created_at'))
        ->flatMap(fn ($q) => anniDeiBinding($q['bindings']))
        ->all();

    expect($anni)->toBe([]);
})->with(['0000-01-01', '+100000000 years']);

it('still filters the audit log by a sensible date', function () {
    $superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');

    DB::enableQueryLog();

    $this->actingAs($superadmin->fresh())
        ->get(route('piattaforma.audit', ['dal' => '2026-01-01']))
        ->assertOk();

    $anni = collect(DB::getQueryLog())
        ->filter(fn ($q) => str_contains($q['query'], 'activity_log') && str_contains($q['query'], 'created_at'))
        ->flatMap(fn ($q) => anniDeiBinding($q['bindings']))
        ->all();

    // Positivo: senza, il test negativo sopra passerebbe anche se il filtro non usasse mai i binding.
    expect($anni)->toContain(2026);
});
