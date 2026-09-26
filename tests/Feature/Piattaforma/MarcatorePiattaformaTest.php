<?php

use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Piani;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperadminSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * L'account che è EasyLab, distinto dai clienti (S6 — cabina di regia).
 *
 * Il `SuperadminSeeder` crea un Account di piattaforma perché il TenantScope è
 * fail-closed (ADR-018) e un Superadmin senza Ente entrerebbe in un'app vuota.
 * Senza un marcatore, quell'account è indistinguibile da un cliente e falsa
 * tutti e quattro i KPI della cabina.
 */
it('marks the platform account, so the KPIs never count EasyLab as a customer', function () {
    config([
        'easylab.piattaforma.superadmin.email' => 'direzione@easylab.test',
        'easylab.piattaforma.superadmin.password' => 'password-di-prova',
        'easylab.piattaforma.superadmin.ente' => 'EasyLab',
    ]);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SuperadminSeeder::class);

    $piattaforma = Account::where('ragione_sociale', 'EasyLab')->firstOrFail();

    expect($piattaforma->di_piattaforma)->toBeTrue();
});

it('marks it even when the account already existed', function () {
    // Il seeder è rieseguibile, e su un ambiente nato prima di questa colonna
    // l'account di piattaforma esiste già (backfill 1:1 di ADR-032): il flag va
    // messo comunque, non solo alla creazione.
    Account::factory()->create(['ragione_sociale' => 'EasyLab']);

    config([
        'easylab.piattaforma.superadmin.email' => 'direzione@easylab.test',
        'easylab.piattaforma.superadmin.password' => 'password-di-prova',
        'easylab.piattaforma.superadmin.ente' => 'EasyLab',
    ]);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SuperadminSeeder::class);

    expect(Account::where('ragione_sociale', 'EasyLab')->firstOrFail()->di_piattaforma)->toBeTrue()
        ->and(Account::count())->toBe(1);
});

it('backfills the platform account on an environment that already existed', function () {
    // ⚠️ Il caso che la prima stesura mancava, e non in teoria: sul database di
    // sviluppo l'account si chiama «EasyLab (piattaforma)» mentre la config dice
    // «EasyLab», quindi un backfill per nome trovava zero righe **in silenzio**.
    // La migration risale invece dalla struttura — ruolo Superadmin → Ente →
    // account — che è la stessa catena su cui poggia l'accesso.
    $this->seed(RolesAndPermissionsSeeder::class);

    $account = Account::factory()->create(['ragione_sociale' => 'Nome Che Non Combacia']);
    $ente = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create();
    $superadmin = User::factory()->create(['tenant_id' => $ente->id]);
    $superadmin->assignRole('Superadmin');

    // ⚠️ **Limite dichiarato**: questo test **ricopia** la risalita, non esegue
    // la migration (che con `RefreshDatabase` è già girata su un DB vuoto).
    // Quindi rompere la migration NON lo fa diventare rosso — la prova di
    // mutazione lo ha verificato, ed è giusto scriverlo invece di lasciar
    // credere il contrario. Qui si congela la **strategia** di risalita; la
    // prova della migration è che, lanciata sul database di sviluppo, ha
    // risolto l'account giusto dove il confronto per nome aveva fallito.
    // Estrarla in un helper condiviso renderebbe la mutazione efficace, ma
    // legherebbe una migration a codice applicativo che cambia — il rimedio
    // sarebbe peggio del male.
    $risolti = DB::table('unita_organizzativa')
        ->whereIn('id', DB::table('users')
            ->whereIn('id', DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->whereIn('role_id', DB::table('roles')->where('name', 'Superadmin')->select('id'))
                ->select('model_id'))
            ->select('tenant_id'))
        ->whereNotNull('account_id')
        ->pluck('account_id');

    expect($risolti->all())->toBe([$account->id]);
});

it('treats every other account as a customer', function () {
    expect(Account::factory()->create()->di_piattaforma)->toBeFalse()
        ->and((new Account)->di_piattaforma)->toBeFalse()
        ->and(Account::factory()->diPiattaforma()->create()->di_piattaforma)->toBeTrue();
});

it('never lets a form forge the platform flag', function () {
    // Fuori da `$fillable` come `piano` e le colonne di lockout: un account che
    // si dichiarasse «di piattaforma» sparirebbe da ogni conteggio — e da ogni
    // fattura che qualcuno costruisse su quei conteggi.
    $account = Account::factory()->create();

    $account->update(['di_piattaforma' => true, 'ragione_sociale' => 'Gruppo Rossi']);

    expect($account->fresh()->di_piattaforma)->toBeFalse()
        ->and($account->fresh()->ragione_sociale)->toBe('Gruppo Rossi');
});

it('prices an account at its plan, in cents', function () {
    $free = Account::factory()->create();
    $saas = Account::factory()->saas()->create();

    expect($free->valoreMensileCent())->toBe(0)
        ->and($saas->valoreMensileCent())->toBe(Piani::prezzoMensileCent('saas'));
});

it('keeps pricing a locked account, because a lockout is not a cancellation', function () {
    // ADR-013: il blocco è una porta chiusa, non una disdetta. Il contratto
    // resta in essere, e l'MRR a listino lo deve continuare a contare — con il
    // contatore 🔒 accanto a dire quanta parte non si sta incassando.
    $account = Account::factory()->saas()->create();
    $account->blocca('Insoluto fattura 42');

    // La premessa si asserisce: `blocca()` è idempotente come no-op, quindi se
    // domani diventasse un no-op per un difetto questo test resterebbe verde
    // continuando a raccontare di «un account bloccato».
    expect($account->fresh()->is_locked)->toBeTrue()
        ->and($account->fresh()->valoreMensileCent())->toBe(Piani::prezzoMensileCent('saas'));
});

it('leaves a trace when an account is marked as platform', function () {
    // La colonna decide se l'account esiste commercialmente — sparire dai
    // conteggi è un effetto grande quanto un lockout. Senza la voce in
    // `attributiDerivatiTracciati()` il gesto non lascerebbe riga di audit, e
    // sarebbe l'unica del gruppo a non lasciarla.
    $account = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);

    $account->forceFill(['di_piattaforma' => true])->save();

    $riga = Activity::where('subject_id', $account->id)
        ->where('subject_type', Account::class)
        ->where('description', 'Modifica account')->latest('id')->first();

    expect($riga)->not->toBeNull()
        ->and($riga->attribute_changes['attributes']['di_piattaforma'])->toBeTrue();
});

it('refuses to price an account whose piano left the catalogue', function () {
    // Il caso che `Piani::perPrice()` documenta come legittimo — un piano
    // dismesso — e che qui deve essere rumoroso, non silenzioso. Chi scriverà
    // l'aggregato dell'MRR trova il comportamento dichiarato da un test invece
    // che da un docblock, e sa che deve iterare sui codici a catalogo.
    $account = Account::factory()->create();
    $account->forceFill(['piano' => 'dismesso'])->save();

    expect(fn () => $account->fresh()->valoreMensileCent())->toThrow(InvalidArgumentException::class);
});
