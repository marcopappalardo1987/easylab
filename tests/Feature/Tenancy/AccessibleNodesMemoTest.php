<?php

use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Tenancy\AccessibleNodes;
use App\Support\Tenancy\AccessibleNodesMemo;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;

/**
 * La memo per richiesta di `AccessibleNodes::forCurrentUser()` (S7/T4, ADR-006,
 * ADR-018). È un risolutore di autorizzazioni: il caso felice (meno query) vale
 * poco senza i negativi, cioè che nessun cambio di identità, di tenant o di
 * assegnazione dentro la STESSA richiesta possa pescare nodi vecchi.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept1 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Chimica']);
    $this->sub1 = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->dept1)->create(['nome' => 'Lab 1']);
    $this->dept2 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Fisica']);

    $this->altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
});

function responsabileMemo(int $tenantId, array $nodi): User
{
    $user = User::factory()->create(['tenant_id' => $tenantId]);
    $user->assignRole('Responsabile Reparto');
    $user->unitaResponsabili()->attach($nodi);

    return $user->fresh();
}

/** Quante volte il risolutore è davvero andato al DB durante `$fai`. */
function riletture(Closure $fai): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fai();
    $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'responsabile_unita'))->count();
    DB::disableQueryLog();

    return $n;
}

it('is wired to its invalidation by the application provider', function () {
    // R-T4-1: senza `Event::subscribe(AccessibleNodesMemo::class)` nel
    // provider la memo resta inerte (fail-safe), e tutto il resto qui è rosso.
    expect(app()->bound(AccessibleNodesMemo::class.'.invalidamento'))->toBeTrue();
});

it('reads the tree once per request, not once per scoped query', function () {
    $this->actingAs(responsabileMemo($this->ente->id, [$this->dept1->id]));

    AccessibleNodes::forCurrentUser();

    expect(riletture(function () {
        foreach (range(1, 5) as $_) {
            expect(AccessibleNodes::forCurrentUser())->toEqualCanonicalizing([$this->dept1->id, $this->sub1->id]);
        }
    }))->toBe(0);
});

it('never serves one user the nodes of another in the same request or job', function () {
    $a = responsabileMemo($this->ente->id, [$this->dept1->id]);
    $b = responsabileMemo($this->ente->id, [$this->dept2->id]);

    // Auth::setUser non passa da Login: la chiave per utente è la sola difesa qui.
    Auth::setUser($a);
    expect(AccessibleNodes::forCurrentUser())->toEqualCanonicalizing([$this->dept1->id, $this->sub1->id]);

    Auth::setUser($b);
    expect(AccessibleNodes::forCurrentUser())->toEqual([$this->dept2->id]);

    Auth::setUser($a);
    expect(AccessibleNodes::forCurrentUser())->not->toContain($this->dept2->id);
});

it('hides a node in the same request once its assignment is detached', function () {
    $resp = responsabileMemo($this->ente->id, [$this->dept1->id, $this->dept2->id]);
    $this->actingAs($resp);

    expect(AccessibleNodes::forCurrentUser())->toContain($this->dept2->id);

    $resp->unitaResponsabili()->detach($this->dept2->id);

    expect(AccessibleNodes::forCurrentUser())->not->toContain($this->dept2->id)
        ->and(AccessibleNodes::forCurrentUser())->toContain($this->dept1->id);
});

it('hides a node in the same request when the assignment row is deleted outside Eloquent', function () {
    $resp = responsabileMemo($this->ente->id, [$this->dept1->id]);
    $this->actingAs($resp);

    expect(AccessibleNodes::forCurrentUser())->toContain($this->dept1->id);

    // La forma di EliminaCliente: DB::table, nessun evento di modello.
    DB::table('responsabile_unita')->where('user_id', $resp->id)->delete();

    expect(AccessibleNodes::forCurrentUser())->toBe([]);
});

it('shows a newly assigned node in the same request', function () {
    $resp = responsabileMemo($this->ente->id, [$this->dept1->id]);
    $this->actingAs($resp);

    expect(AccessibleNodes::forCurrentUser())->not->toContain($this->dept2->id);

    $resp->unitaResponsabili()->attach($this->dept2->id);

    expect(AccessibleNodes::forCurrentUser())->toContain($this->dept2->id);
});

it('drops a sub-tree moved out of the assigned node in the same request', function () {
    $this->actingAs(responsabileMemo($this->ente->id, [$this->dept1->id]));

    expect(AccessibleNodes::forCurrentUser())->toContain($this->sub1->id);

    $this->sub1->update(['parent_id' => $this->dept2->id]);

    expect(AccessibleNodes::forCurrentUser())->toEqual([$this->dept1->id]);
});

it('drops a sub-tree moved by a mass update, which fires no model event', function () {
    $this->actingAs(responsabileMemo($this->ente->id, [$this->dept1->id]));

    expect(AccessibleNodes::forCurrentUser())->toContain($this->sub1->id);

    UnitaOrganizzativa::withoutGlobalScopes()->whereKey($this->sub1->id)->update(['parent_id' => $this->dept2->id]);

    expect(AccessibleNodes::forCurrentUser())->toEqual([$this->dept1->id]);
});

it('re-reads the tree after a node is trashed', function () {
    $this->actingAs(responsabileMemo($this->ente->id, [$this->dept1->id]));
    AccessibleNodes::forCurrentUser();

    $this->sub1->delete();

    expect(riletture(fn () => AccessibleNodes::forCurrentUser()))->toBe(1);
});

it('follows a change of tenant in the same request', function () {
    // SwitcherEnte::passa() riscrive users.tenant_id sull'utente autenticato:
    // le radici dell'Ente A non valgono nell'Ente B.
    $resp = responsabileMemo($this->ente->id, [$this->dept1->id]);
    $this->actingAs($resp);

    expect(AccessibleNodes::forCurrentUser())->toContain($this->dept1->id);

    $resp->forceFill(['tenant_id' => $this->altroEnte->id]);

    expect(AccessibleNodes::forCurrentUser())->toBe([]);
});

it('restricts at once a user promoted to Responsabile mid-request', function () {
    $user = User::factory()->create(['tenant_id' => $this->ente->id]);
    $user->assignRole('Admin');
    $user->unitaResponsabili()->attach($this->dept2->id);
    $this->actingAs($user);

    expect(AccessibleNodes::forCurrentUser())->toBeNull();

    $user->syncRoles(['Responsabile Reparto']);

    expect(AccessibleNodes::forCurrentUser())->toEqual([$this->dept2->id]);
});

it('forgets everything on login and logout', function () {
    $resp = responsabileMemo($this->ente->id, [$this->dept1->id]);
    $this->actingAs($resp);
    AccessibleNodes::forCurrentUser();

    Auth::login($resp);
    expect(riletture(fn () => AccessibleNodes::forCurrentUser()))->toBe(1);

    Auth::logout();
    Auth::setUser($resp);
    expect(riletture(fn () => AccessibleNodes::forCurrentUser()))->toBe(1);
});

it('forgets everything when an impersonation starts or ends', function () {
    $resp = responsabileMemo($this->ente->id, [$this->dept1->id]);
    $admin = User::factory()->create(['tenant_id' => $this->ente->id]);
    $this->actingAs($resp);
    AccessibleNodes::forCurrentUser();

    event(new TakeImpersonation($admin, $resp));
    expect(riletture(fn () => AccessibleNodes::forCurrentUser()))->toBe(1);

    event(new LeaveImpersonation($admin, $resp));
    expect(riletture(fn () => AccessibleNodes::forCurrentUser()))->toBe(1);
});

it('starts empty at every job, because the container scopes it', function () {
    $this->actingAs(responsabileMemo($this->ente->id, [$this->dept1->id]));
    AccessibleNodes::forCurrentUser();

    $prima = app(AccessibleNodesMemo::class);
    // È ciò che il worker fa fra un job e l'altro (QueueServiceProvider).
    app()->forgetScopedInstances();

    expect(app(AccessibleNodesMemo::class))->not->toBe($prima)
        ->and(riletture(fn () => AccessibleNodes::forCurrentUser()))->toBe(1);
});

it('stops memoizing when its invalidation is not wired', function () {
    $this->actingAs(responsabileMemo($this->ente->id, [$this->dept1->id]));

    app()->forgetInstance(AccessibleNodesMemo::class.'.invalidamento');
    AccessibleNodes::forCurrentUser();

    expect(riletture(fn () => AccessibleNodes::forCurrentUser()))->toBe(1);
});

it('counts as tree writes only the statements that can change a user nodes', function (string $sql, bool $atteso) {
    expect(AccessibleNodesMemo::toccaLAlbero($sql))->toBe($atteso);
})->with([
    ['insert into "responsabile_unita" ("user_id") values (?)', true],
    ['delete from "responsabile_unita" where "user_id" = ?', true],
    ['update "unita_organizzativa" set "parent_id" = ? where "id" = ?', true],
    ['select * from "responsabile_unita" where "user_id" = ?', false],
    ['update "strumenti" set "nome" = ? where "id" = ?', false],
    // T4A-2: forme che l'ancora `^` in minuscolo non riconosceva.
    ['DELETE FROM RESPONSABILE_UNITA WHERE USER_ID = ?', true],
    ['/* pulizia */ delete from responsabile_unita where user_id = ?', true],
    ['with t as (select 1) delete from responsabile_unita where user_id = ?', true],
    ['replace into responsabile_unita (user_id, unita_organizzativa_id) values (?, ?)', true],
    ['merge into unita_organizzativa u using x on u.id = x.id when matched then update set parent_id = x.p', true],
    // `\b` tiene fuori le colonne che contengono il verbo.
    ['select "deleted_at", "updated_at" from "unita_organizzativa" where "id" = ?', false],
]);

// ─── T4A-1 / T4B-1: rollback ─────────────────────────────────────────────────
// La scrittura svuota la memo, ma una lettura fra la scrittura e l'annullamento
// memorizzava nodi mai concessi. Stessi test su SQLite e su easylab_test.

it('does not serve a node whose assignment was rolled back', function () {
    $resp = responsabileMemo($this->ente->id, [$this->dept1->id]);
    $this->actingAs($resp);

    DB::beginTransaction();
    $resp->unitaResponsabili()->attach($this->dept2->id);
    expect(AccessibleNodes::forCurrentUser())->toContain($this->dept2->id);
    DB::rollBack();

    expect(DB::table('responsabile_unita')->where('user_id', $resp->id)->pluck('unita_organizzativa_id')->all())
        ->toEqual([$this->dept1->id])
        ->and(AccessibleNodes::forCurrentUser())->not->toContain($this->dept2->id);
});

it('does not serve a node whose assignment was rolled back by a throwing DB::transaction', function () {
    $resp = responsabileMemo($this->ente->id, [$this->dept1->id]);
    $this->actingAs($resp);
    AccessibleNodes::forCurrentUser();

    try {
        DB::transaction(function () use ($resp) {
            $resp->unitaResponsabili()->attach($this->dept2->id);
            AccessibleNodes::forCurrentUser();
            throw new RuntimeException('validazione fallita dopo l\'assegnazione');
        });
    } catch (RuntimeException) {
    }

    expect(AccessibleNodes::forCurrentUser())->toEqualCanonicalizing([$this->dept1->id, $this->sub1->id]);
});

it('does not keep a node moved into the assigned sub-tree by a rolled-back savepoint', function () {
    $lab = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->dept2)->create(['nome' => 'Lab Fisica']);
    $this->actingAs(responsabileMemo($this->ente->id, [$this->dept1->id]));
    expect(AccessibleNodes::forCurrentUser())->not->toContain($lab->id);

    DB::transaction(function () use ($lab) {
        try {
            DB::transaction(function () use ($lab) {
                UnitaOrganizzativa::withoutGlobalScopes()->whereKey($lab->id)->update(['parent_id' => $this->dept1->id]);
                AccessibleNodes::forCurrentUser();
                throw new RuntimeException('annullato');
            });
        } catch (RuntimeException) {
        }

        // Dentro la transazione esterna ancora aperta: il savepoint è annullato.
        expect(AccessibleNodes::forCurrentUser())->not->toContain($lab->id);
    });

    expect(AccessibleNodes::forCurrentUser())->not->toContain($lab->id);
});

it('forgets revoked nodes after a raw write in a shape the old matcher missed', function (string $sql) {
    $resp = responsabileMemo($this->ente->id, [$this->dept1->id]);
    $this->actingAs($resp);
    expect(AccessibleNodes::forCurrentUser())->toContain($this->dept1->id);

    DB::statement($sql, [$resp->id]);

    expect(AccessibleNodes::forCurrentUser())->toBe([]);
})->with([
    'upper case' => ['DELETE FROM RESPONSABILE_UNITA WHERE USER_ID = ?'],
    'leading comment' => ['/* pulizia */ delete from responsabile_unita where user_id = ?'],
    'cte' => ['with t as (select 1) delete from responsabile_unita where user_id = ?'],
]);

it('shows a node granted by a raw REPLACE', function () {
    $resp = responsabileMemo($this->ente->id, [$this->dept1->id]);
    $this->actingAs($resp);
    expect(AccessibleNodes::forCurrentUser())->not->toContain($this->dept2->id);

    DB::statement('replace into responsabile_unita (user_id, unita_organizzativa_id) values (?, ?)', [$resp->id, $this->dept2->id]);

    expect(AccessibleNodes::forCurrentUser())->toContain($this->dept2->id);
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'REPLACE è sintassi SQLite/MySQL.');
