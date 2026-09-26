<?php

use App\Models\Account;
use App\Models\Piano;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Listino\CatalogoPiani;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Il limite di Enti per piano (ADR-032), fatto rispettare dove un Ente nasce.
 *
 * La regola vive su `Account::puoAggiungereEnte()` e il comando la consuma: in
 * S6 il provisioning diventa una UI Livewire, e una guardia scritta dentro
 * `handle()` non sarebbe lì.
 *
 * ⚠️ Limite dichiarato di questo file: se qualcuno spostasse la guardia
 * **dentro** `DB::transaction`, nessuno di questi test diventerebbe rosso — il
 * rollback salverebbe le apparenze e i conteggi tornerebbero comunque a zero.
 * Quella posizione va difesa dalla lettura in review, non da un assert; è
 * scritto qui perché non si scambi la copertura per una garanzia.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

it('lets a brand-new account open its first ente', function () {
    // Nessun `--account`, email mai vista: zero Enti, quindi il controllo è
    // banalmente verde. Si esegue lo stesso, senza ramo condizionale: un
    // `max_enti` a 0, se un giorno esistesse, andrebbe rispettato anche qui.
    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Prima Sede',
        '--admin-email' => 'nuovo@demo.test',
        '--admin-password' => 'secret-password',
    ])->assertSuccessful();

    expect(Account::first()->enti()->count())->toBe(1);
});

it('refuses the ente that hits the cap exactly, and writes nothing', function () {
    $account = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);
    UnitaOrganizzativa::factory()->ente()->perAccount($account)->create();

    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Seconda Sede',
        '--account' => (string) $account->id,
        '--admin-email' => 'seconda@demo.test',
    ])
        ->expectsOutputToContain('limite raggiunto')
        ->assertFailed();

    // La prova della validazione-prima-di-scrivere, sul modello del test
    // gemello `fails on a missing --account without writing anything`: chi
    // viene rifiutato non lascia dietro di sé né un Ente né un utente.
    expect(UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Seconda Sede')->exists())->toBeFalse()
        ->and(User::where('email', 'seconda@demo.test')->exists())->toBeFalse()
        ->and($account->enti()->count())->toBe(1);
});

it('lets a paying account open a second sede', function () {
    $account = Account::factory()->saas()->create();
    UnitaOrganizzativa::factory()->ente()->perAccount($account)->create();

    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Seconda Sede',
        '--account' => (string) $account->id,
        '--admin-email' => 'seconda@demo.test',
    ])->assertSuccessful();

    expect($account->enti()->count())->toBe(2);
});

it('never lets a trashed sede consume a slot forever', function () {
    // Il difetto che questo blocco ha corretto in `Account::enti()`: con
    // `withoutGlobalScopes()` nudo anche `SoftDeletingScope` spariva, quindi
    // una sede chiusa mesi fa avrebbe occupato uno slot per sempre — il limite
    // punirebbe la pulizia dei dati.
    $account = Account::factory()->create();
    $chiusa = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => 'Sede Chiusa']);
    $chiusa->delete();

    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Sede Nuova',
        '--account' => (string) $account->id,
        '--admin-email' => 'nuova@demo.test',
    ])->assertSuccessful();

    expect($account->enti()->count())->toBe(1);
});

it('grandfathers the sedi of an account that downgraded', function () {
    // Il downgrade non espelle nessuno: il limite è una condizione d'ingresso,
    // non un'espulsione. Cestinare sedi in uso per effetto di un webhook
    // sarebbe una perdita di dati decisa da una macchina.
    $account = Account::factory()->saas()->create();
    $sedi = collect(range(1, 3))->map(
        fn (int $n) => UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => "Sede {$n}"])
    );

    $account->cambiaPiano('free');

    expect($account->enti()->count())->toBe(3)
        ->and($account->fresh()->is_locked)->toBeFalse()
        ->and($account->slotEntiResidui())->toBe(-2)
        ->and($account->puoAggiungereEnte())->toBeFalse()
        // Le sedi restano raggiungibili: nessuna è stata chiusa.
        ->and($sedi->every(fn (UnitaOrganizzativa $sede) => $sede->fresh() !== null))->toBeTrue();

    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Quarta Sede',
        '--account' => (string) $account->id,
        '--admin-email' => 'quarta@demo.test',
    ])->assertFailed();
});

it('never takes a sede away when the cap drops below what a client already has', function () {
    // 🔴 Il negativo di ADR-035, ed è il gemello di «grandfathers the sedi of an
    // account that downgraded» con la causa rovesciata: là scendeva il
    // **cliente** di piano, qui scende il **piano** sotto il cliente — è ciò che
    // succede la prima volta che qualcuno abbassa `max_enti` da
    // /piattaforma/piani.
    //
    // L'esito dev'essere lo stesso: nessuna sede chiusa, nessun account
    // bloccato, e la sesta rifiutata. Cestinare sedi in uso per effetto di un
    // click su un listino sarebbe una perdita di dati decisa da una macchina —
    // qui, per giunta, su clienti che non hanno fatto niente.
    $account = Account::factory()->saas()->create();
    $sedi = collect(range(1, 5))->map(
        fn (int $n) => UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => "Sede {$n}"])
    );

    tettoEnti('saas', 2);

    expect($account->enti()->count())->toBe(5)
        ->and($account->fresh()->is_locked)->toBeFalse()
        ->and($account->slotEntiResidui())->toBe(-3)
        ->and($account->puoAggiungereEnte())->toBeFalse()
        ->and($sedi->every(fn (UnitaOrganizzativa $sede) => $sede->fresh() !== null))->toBeTrue();

    $this->artisan('easylab:provision-tenant', [
        'nome' => 'Sesta Sede',
        '--account' => (string) $account->id,
        '--admin-email' => 'sesta@demo.test',
    ])
        ->expectsOutputToContain('limite raggiunto')
        ->assertFailed();

    expect(UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Sesta Sede')->exists())->toBeFalse();
});

it('counts the enti of this account only', function () {
    // In console non c'è tenant e non c'è scope: il confine è `account_id`,
    // e un Ente di un altro account non deve consumare slot qui.
    $mio = Account::factory()->create();
    $altrui = Account::factory()->create();
    UnitaOrganizzativa::factory()->ente()->perAccount($altrui)->create();

    expect($mio->slotEntiResidui())->toBe(1)
        ->and($mio->puoAggiungereEnte())->toBeTrue();
});

/** Il tetto di Enti di un piano, che dal 27 Ago 2026 vive a database (ADR-035). */
function tettoEnti(string $codice, ?int $max): void
{
    Piano::query()->where('codice', $codice)->sole()->forceFill(['max_enti' => $max])->save();

    // Il memo è per-richiesta e in un test la richiesta non finisce mai: senza
    // questa riga `Piani::maxEnti()` continuerebbe a leggere il valore caricato
    // prima della scrittura.
    app(CatalogoPiani::class)->dimentica();
}

it('has no limit at all when the plan declares none', function () {
    tettoEnti('saas', null);

    $account = Account::factory()->saas()->create();
    UnitaOrganizzativa::factory()->ente()->perAccount($account)->create();

    expect($account->slotEntiResidui())->toBeNull()
        ->and($account->puoAggiungereEnte())->toBeTrue();
});
