<?php

use App\Http\Middleware\VerificaFirmaWebhookStripe;
use App\Models\Account;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;

/**
 * Il guardrail di schema di ADR-032: **il Billable è l'Account**.
 *
 * Nessuno di questi test verifica una feature — verificano che una decisione
 * architetturale non venga disfatta per distrazione. Il modo più facile di
 * disfarla è pubblicare le migration di Cashier, che scrivono su `users` e
 * usano `user_id`: dopo, tutto continuerebbe a funzionare, e l'abbonamento
 * sarebbe legato a una credenziale invece che a un cliente.
 */
it('makes the Account the Stripe customer', function () {
    expect(Cashier::$customerModel)->toBe(Account::class)
        ->and(class_uses_recursive(Account::class))->toContain(Billable::class);
});

it('never puts billing columns on users, because a user is a credential', function () {
    // ADR-032, alternative scartate: «l'utente è una credenziale, non un
    // cliente — ha soft delete, e legare l'abbonamento a chi ha fatto il primo
    // login significa perderlo quando quella persona se ne va».
    expect(Schema::hasColumn('users', 'stripe_id'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'pm_type'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'pm_last_four'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'trial_ends_at'))->toBeFalse();
});

it('keys subscriptions on the account, not on the user', function () {
    expect(Schema::hasColumn('subscriptions', 'account_id'))->toBeTrue()
        ->and(Schema::hasColumn('subscriptions', 'user_id'))->toBeFalse()
        // La FK non è una scelta di stile: è `getForeignKey()` del Billable a
        // deciderla, ed è il motivo per cui le migration del pacchetto sono
        // inservibili e non solo scomode.
        ->and((new Account)->subscriptions()->getForeignKeyName())->toBe('account_id');
});

it('keeps the package tables out of the tenancy', function () {
    // ERD §9: `subscriptions`/`subscription_items` sono tabelle di pacchetto,
    // non di business. L'intestatario è `account_id`, che sta sopra i tenant.
    expect(Schema::hasColumn('subscriptions', 'tenant_id'))->toBeFalse()
        ->and(Schema::hasColumn('subscription_items', 'tenant_id'))->toBeFalse();
});

it('carries the metered-billing columns that Cashier writes', function () {
    // Il pacchetto le aggiunge con due migration del 2025; creando la tabella
    // da zero nascono qui. Senza, il primo `updateOrCreate` del webhook
    // fallirebbe su una colonna inesistente.
    expect(Schema::hasColumn('subscription_items', 'meter_id'))->toBeTrue()
        ->and(Schema::hasColumn('subscription_items', 'meter_event_name'))->toBeTrue();
});

it('starts every account on the free plan, in memory and not only in the column', function () {
    // Il default di colonna vale all'INSERT: senza `$attributes`, un'istanza
    // appena creata leggerebbe null e `Piani::maxEnti(null)` lancerebbe.
    expect((new Account)->piano)->toBe('free')
        ->and(Account::factory()->create()->piano)->toBe('free');
});

it('reads trial_ends_at as a date, or Cashier dies on it', function () {
    // `ManagesSubscriptions::onGenericTrial()` fa `->isFuture()`: senza cast è
    // una stringa e va in fatal. Si prova chiamando il metodo, non asserendo la
    // presenza del cast — quello sarebbe una tautologia.
    //
    // ⚠️ E si rilegge dal DB, che è la metà che conta: sull'istanza appena
    // creata il valore è ancora l'oggetto Carbon passato alla factory, quindi
    // `onTrial()` funzionerebbe **anche senza cast**. La prima stesura faceva
    // così e la prova di mutazione l'ha trovata inutile: togliendo il cast
    // restava verde. Chi legge davvero questa colonna è il webhook, che
    // l'account lo carica dal database.
    Account::factory()->create(['trial_ends_at' => now()->addDays(3), 'ragione_sociale' => 'In Prova']);
    Account::factory()->create(['ragione_sociale' => 'Senza Prova']);

    $inProva = Account::where('ragione_sociale', 'In Prova')->first();
    $senzaProva = Account::where('ragione_sociale', 'Senza Prova')->first();

    expect($inProva->trial_ends_at)->toBeInstanceOf(Carbon::class)
        ->and($inProva->onTrial())->toBeTrue()
        ->and($senzaProva->onTrial())->toBeFalse();
});

it('publishes exactly one Stripe surface, and it is ours', function () {
    // `Cashier::ignoreRoutes()`: la rotta `payment/{id}` del pacchetto è
    // pubblica e non autenticata, e in V1 non serve — l'unico percorso di
    // sottoscrizione è `easylab:abbona`, in console.
    expect(Route::has('cashier.webhook'))->toBeTrue()
        ->and(Route::has('cashier.payment'))->toBeFalse();
});

it('verifies the Stripe signature unconditionally, and stays out of the web group', function () {
    $rotta = Route::getRoutes()->getByName('cashier.webhook');
    $middleware = $rotta->gatherMiddleware();

    // Il NOSTRO middleware, non quello del pacchetto: quello, con un segreto
    // vuoto, verifica un HMAC a chiave vuota e lascia passare chiunque.
    expect($middleware)->toContain(VerificaFirmaWebhookStripe::class)
        ->and(is_subclass_of(VerificaFirmaWebhookStripe::class, VerifyWebhookSignature::class))->toBeTrue()
        // Fuori dal gruppo `web`: una POST che arriva da Stripe non porta
        // alcun token CSRF, e `ValidateCsrfToken` la respingerebbe con un 419
        // — cioè un lockout che non scatta mai, in silenzio.
        ->and($middleware)->not->toContain('web')
        ->and($middleware)->not->toContain(ValidateCsrfToken::class);
});

it('leaves the RBAC catalogue untouched', function () {
    // I cinque permessi billing erano già a catalogo da S1: questo blocco li
    // consuma e non li aggiunge. Se questo test diventa rosso vuol dire che
    // `config/rbac.php` è stato toccato, e allora servono un riseeding e
    // l'aggiornamento dei numeri di `RbacSeederTest` — non un fix qui.
    expect(Rbac::permissions())->toContain('billing.manage_own', 'billing.manage_global', 'billing.lockout')
        ->and(Rbac::locked())->toHaveCount(7);
});

it('never lets a user be mistaken for a billable', function () {
    expect(class_uses_recursive(User::class))->not->toContain(Billable::class);
});
