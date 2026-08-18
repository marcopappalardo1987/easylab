<?php

use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Il backfill 1:1 di ADR-032, testato su righe PREESISTENTI.
 *
 * Il difetto strutturale di ogni backfill in questo progetto è che la suite
 * ricrea SQLite da zero: al momento della migration non esiste alcuna riga, e
 * un backfill sbagliato passerebbe verde (docblock di `add_qr_token`). Qui si
 * chiama direttamente `backfill()` della migration — lo stesso codice che gira
 * in produzione — su Enti creati con `account_id` ancora nullo: il rollback
 * vero delle tre migration non è praticabile in test (SQLite dentro la
 * transazione di RefreshDatabase non regge la ricostruzione di una tabella
 * molto referenziata), e questa è la via che il docblock della migration
 * dichiara.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->migrazione = include database_path('migrations/2026_08_18_090500_add_account_id_to_unita_organizzativa_table.php');
});

it('backfills one account per existing ente, with the right membri', function () {
    // Tre Enti nei tre scenari della regola membri; account_id nasce nullo.
    $conAdmin = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente con Admin']);
    $admin = User::factory()->create(['tenant_id' => $conAdmin->id]);
    $admin->assignRole('Admin');
    $secondoAdmin = User::factory()->create(['tenant_id' => $conAdmin->id]);
    $secondoAdmin->assignRole('Admin');

    $soloSuperadmin = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente piattaforma']);
    $superadmin = User::factory()->create(['tenant_id' => $soloSuperadmin->id]);
    $superadmin->assignRole('Superadmin');

    $orfano = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente senza nessuno']);

    // Un dipartimento NON deve ricevere account, e un utente di un ALTRO
    // tenant col ruolo Admin non deve finire fra i membri.
    $dipartimento = UnitaOrganizzativa::factory()->dipartimento()->under($conAdmin)->create();
    $adminAltrove = User::factory()->create(['tenant_id' => $soloSuperadmin->id]);
    $adminAltrove->assignRole('Admin');

    $this->migrazione->backfill();

    expect(DB::table('accounts')->count())->toBe(3);

    $accountDi = fn (int $enteId) => DB::table('unita_organizzativa')->where('id', $enteId)->value('account_id');
    $membriDi = fn (?int $accountId) => DB::table('account_user')->where('account_id', $accountId)->pluck('user_id')->all();

    // 1:1 con la ragione sociale dell'Ente.
    expect($accountDi($conAdmin->id))->not->toBeNull()
        ->and(DB::table('accounts')->where('id', $accountDi($conAdmin->id))->value('ragione_sociale'))->toBe('Ente con Admin');

    // Membri = TUTTI gli Admin del PROPRIO tenant (adminAltrove escluso).
    expect($membriDi($accountDi($conAdmin->id)))->toEqualCanonicalizing([$admin->id, $secondoAdmin->id]);

    // Con un Admin nel tenant, il fallback Superadmin NON scatta.
    expect($membriDi($accountDi($soloSuperadmin->id)))->toBe([$adminAltrove->id]);

    // Nessun Admin né Superadmin → account senza membri (invariante non retroattivo).
    expect($accountDi($orfano->id))->not->toBeNull()
        ->and($membriDi($accountDi($orfano->id)))->toBe([]);

    // Account distinti (1:1, non uno condiviso); il dipartimento resta fuori.
    expect(collect([$conAdmin->id, $soloSuperadmin->id, $orfano->id])->map($accountDi)->unique())->toHaveCount(3)
        ->and(DB::table('unita_organizzativa')->where('id', $dipartimento->id)->value('account_id'))->toBeNull();
});

it('falls back to the Superadmin when the tenant has no Admin', function () {
    $ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'EasyLab (piattaforma)']);
    $superadmin = User::factory()->create(['tenant_id' => $ente->id]);
    $superadmin->assignRole('Superadmin');

    $this->migrazione->backfill();

    $accountId = DB::table('unita_organizzativa')->where('id', $ente->id)->value('account_id');
    expect(DB::table('account_user')->where('account_id', $accountId)->pluck('user_id')->all())
        ->toBe([$superadmin->id]);
});

it('is idempotent: a second run creates nothing new', function () {
    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $admin = User::factory()->create(['tenant_id' => $ente->id]);
    $admin->assignRole('Admin');

    $this->migrazione->backfill();
    $this->migrazione->backfill();

    // Il whereNull('account_id') è la guardia: niente doppioni al secondo giro.
    expect(DB::table('accounts')->count())->toBe(1)
        ->and(DB::table('account_user')->count())->toBe(1);
});

it('skips enti that already have an account', function () {
    $gia = UnitaOrganizzativa::factory()->ente()
        ->perAccount(Account::factory()->create(['ragione_sociale' => 'Preesistente']))
        ->create();

    $this->migrazione->backfill();

    expect(DB::table('accounts')->count())->toBe(1)
        ->and(DB::table('unita_organizzativa')->where('id', $gia->id)->value('account_id'))->not->toBeNull();
});
