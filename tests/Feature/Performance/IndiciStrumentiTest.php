<?php

use App\Livewire\Strumenti\ElencoStrumenti;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * Gli indici di S7/T4 su `strumenti` (migration 2026_09_21_110000 e _110100):
 * esistono, si tolgono e si rimettono, e l'indice per l'ordinamento serve
 * davvero la query che la pagina esegue — tie-break sull'id compreso.
 */
function indiciDiStrumenti(): array
{
    return collect(Schema::getIndexes('strumenti'))->pluck('name')->all();
}

function migrazioneIndici(string $file): object
{
    return require database_path("migrations/{$file}.php");
}

it('has the indexes the list sorts by, on every driver', function () {
    expect(indiciDiStrumenti())
        ->toContain('strumenti_tenant_nome_id_index')
        ->toContain('strumenti_tenant_data_installazione_index');

    $colonne = collect(Schema::getIndexes('strumenti'))->firstWhere('name', 'strumenti_tenant_nome_id_index')['columns'];
    expect($colonne)->toBe(['tenant_id', 'nome', 'id']);
});

it('removes and restores the list indexes', function () {
    $m = migrazioneIndici('2026_09_21_110000_add_indici_elenco_to_strumenti_table');

    $m->down();
    expect(indiciDiStrumenti())
        ->not->toContain('strumenti_tenant_nome_id_index')
        ->not->toContain('strumenti_tenant_data_installazione_index');

    $m->up();
    expect(indiciDiStrumenti())->toContain('strumenti_tenant_nome_id_index');
});

it('has the partial forced_state index on Postgres, restricted to forced rows', function () {
    $definizione = DB::scalar("select indexdef from pg_indexes where indexname = 'strumenti_forced_state_parziale_index'");

    expect($definizione)->toContain('WHERE (forced_state IS NOT NULL)');

    $m = migrazioneIndici('2026_09_21_110100_add_indice_parziale_forced_state_to_strumenti_table');
    $m->down();
    expect(indiciDiStrumenti())->not->toContain('strumenti_forced_state_parziale_index');
    $m->up();
    expect(indiciDiStrumenti())->toContain('strumenti_forced_state_parziale_index');
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'Indice parziale solo su Postgres (dichiarato nella migration).');

it('does not create the partial index on SQLite, as the migration declares', function () {
    expect(indiciDiStrumenti())->not->toContain('strumenti_forced_state_parziale_index');
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'Metà SQLite del confine.');

it('sorts the list page by the indexed columns and ends with the id tie-break', function (string $colonna, string $verso, string $atteso) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Sede']);
    Strumento::factory()->count(3)->forNode($ente)->create();
    $admin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');
    $this->actingAs($admin);

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::withQueryParams(['sortBy' => $colonna, 'sortDir' => $verso])->test(ElencoStrumenti::class);
    $pagina = collect(DB::getQueryLog())->pluck('query')
        ->first(fn (string $q) => str_starts_with($q, 'select "strumenti".* from "strumenti"') && str_contains($q, ' limit '));
    DB::disableQueryLog();

    // Il TenantScope filtra per tenant_id, l'ORDER BY è la colonna poi l'id:
    // la forma degli indici (tenant_id, nome, id) e (tenant_id, data_installazione).
    expect($pagina)->toContain('"strumenti"."tenant_id" = ?')
        ->and($pagina)->toMatch($atteso);
})->with([
    'nome asc (default)' => ['nome', 'asc', '/order by "nome" asc, "strumenti"."id" asc limit/'],
    // ⚠️ Discendente il tie-break resta `id asc`: Postgres scorre l'indice
    // all'indietro su `nome` e chiude i pari con un Incremental Sort sull'id
    // (0,03 ms su 8.000 righe, misurato su easylab_test). Nessun indice in più.
    'nome desc' => ['nome', 'desc', '/order by "nome" desc, "strumenti"."id" asc limit/'],
    'data_installazione desc' => ['data_installazione', 'desc', '/order by "data_installazione" desc, "strumenti"."id" asc limit/'],
]);
