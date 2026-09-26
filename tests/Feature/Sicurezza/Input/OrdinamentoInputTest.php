<?php

use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 L'ordinamento arriva dalla query string e finisce nell'SQL (S7, T1c · OWASP A03).
 *
 * `ElencoStrumenti` è il solo punto in cui la direzione scelta dall'utente
 * viene **concatenata** in un `orderByRaw` (la colonna calcolata della
 * prossima scadenza): lì il builder non valida più `asc`/`desc` per noi.
 * La prova è sull'SQL eseguito, non sui dati: un payload che non rompe la
 * query darebbe comunque una pagina verde.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create();
    Strumento::factory()->forNode($this->ente)->create(['nome' => 'Centrifuga']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

it('never lets a forged sort direction reach the SQL', function (string $sortBy) {
    $payload = 'asc; delete from strumenti --';

    DB::enableQueryLog();

    $this->actingAs($this->admin)
        ->get(route('strumenti.index', ['sortBy' => $sortBy, 'sortDir' => $payload]))
        ->assertOk()
        ->assertSee('Centrifuga');

    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");

    expect($sql)->toContain('order by')
        ->and($sql)->not->toContain('delete from')
        ->and(Strumento::withoutGlobalScopes()->count())->toBe(1);
})->with(['prossima_scadenza', 'stato', 'ubicazione', 'nome']);

it('never lets a forged sort column reach the SQL', function () {
    DB::enableQueryLog();

    $this->actingAs($this->admin)
        ->get(route('strumenti.index', ['sortBy' => 'tenant_id) or 1=1 --', 'sortDir' => 'desc']))
        ->assertOk();

    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");

    expect($sql)->toContain('order by')
        ->and($sql)->not->toContain('1=1');
});

it('does not answer 500 to a query string of the wrong type', function (string $rotta, array $parametri) {
    // Da un link incollato: `#[Url]` su una proprietà tipizzata riceve un array o un testo.
    $stato = $this->actingAs($this->admin)->get(route($rotta).'?'.http_build_query($parametri))->status();

    expect($stato)->toBeLessThan(500);
})->with([
    'strumenti sortBy array' => ['strumenti.index', ['sortBy' => ['x']]],
    'strumenti search array' => ['strumenti.index', ['search' => ['x']]],
    'strumenti enteId text' => ['strumenti.index', ['enteId' => 'abc']],
    'strumenti stato array' => ['strumenti.index', ['stato' => ['rosso']]],
    'scadenzario perPage text' => ['scadenzario.index', ['perPage' => 'abc']],
    'scadenzario soloMiei array' => ['scadenzario.index', ['soloMiei' => ['1']]],
]);
