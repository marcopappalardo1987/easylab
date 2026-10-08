<?php

use App\Models\Account;
use App\Models\Piano;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Listino\CatalogoPiani;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

/**
 * 🔴 Il webhook Stripe: l'innesco automatico del lockout (ADR-013, ADR-032).
 *
 * Area rossa della Policy di Code Review — webhook, stato abbonamento e
 * lockout — quindi i negativi contano più dei positivi e sono la maggioranza
 * di questo file: firma assente o falsa, segreto mancante, customer
 * sconosciuto, account cestinato, account estraneo che non deve muoversi,
 * stati che NON devono bloccare, e il blocco manuale che nessun pagamento
 * riuscito può riaprire.
 *
 * L'endpoint non ha utente, non ha sessione e non ha global scope (ADR-011
 * §292): l'unico filtro fra un payload e un `is_locked` è l'uguaglianza su
 * `stripe_id`. Da qui il test di non-trapelamento.
 */
const SEGRETO = 'whsec_test_easylab';

beforeEach(function () {
    // Il segreto NON viene dall'ambiente (phpunit.xml lo azzera apposta): lo
    // impone il test, così il file dice la stessa cosa in locale e in CI.
    config(['cashier.webhook.secret' => SEGRETO]);

    $this->account = Account::factory()->conStripe('cus_easylab')->saas()->create([
        'ragione_sociale' => 'Gruppo Rossi',
    ]);
    $this->ente = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create([
        'nome' => 'Sede Unica',
    ]);
});

/** Il payload di un evento subscription, nella forma che Stripe manda davvero. */
function evento(string $tipo, string $stato = 'active', string $customer = 'cus_easylab', ?string $price = null): array
{
    return [
        'id' => 'evt_'.fake()->numerify('##########'),
        'type' => $tipo,
        'data' => ['object' => [
            'id' => 'sub_easylab',
            'customer' => $customer,
            'status' => $stato,
            'cancel_at_period_end' => false,
            'items' => ['data' => [[
                'id' => 'si_easylab',
                'quantity' => 1,
                'price' => ['id' => $price ?? 'price_saas_test', 'product' => 'prod_easylab'],
            ]]],
        ]],
    ];
}

/** Consegna un payload firmato come lo firmerebbe Stripe. */
function consegna(array $payload, ?string $segreto = SEGRETO, ?int $timestamp = null): TestResponse
{
    $corpo = json_encode($payload);
    $t = $timestamp ?? time();

    $headers = [];

    if ($segreto !== null) {
        $firma = hash_hmac('sha256', "{$t}.{$corpo}", $segreto);
        $headers['Stripe-Signature'] = "t={$t},v1={$firma}";
    }

    return test()->call('POST', '/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $headers['Stripe-Signature'] ?? '',
    ], $corpo);
}

// ─── Negativi sulla firma ────────────────────────────────────────────────────

it('rejects a request without a signature', function () {
    $risposta = test()->call('POST', '/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(evento('customer.subscription.updated', 'unpaid')));

    $risposta->assertForbidden();

    expect($this->account->fresh()->is_locked)->toBeFalse();
});

it('rejects a forged signature', function () {
    consegna(evento('customer.subscription.updated', 'unpaid'), 'whsec_sbagliato')
        ->assertForbidden();

    expect($this->account->fresh()->is_locked)->toBeFalse();
});

it('rejects a signature outside the tolerance window', function () {
    // Un replay di un'ora fa, firmato bene ma vecchio.
    consegna(evento('customer.subscription.updated', 'unpaid'), SEGRETO, time() - 3600)
        ->assertForbidden();

    expect($this->account->fresh()->is_locked)->toBeFalse();
});

it('rejects everything when no webhook secret is configured', function (?string $segreto) {
    // Il difetto che il costruttore di Cashier porta con sé: aggancia
    // `VerifyWebhookSignature` solo `if (config('cashier.webhook.secret'))`.
    // Senza il middleware dichiarato sulla rotta, qui l'endpoint accetterebbe
    // un payload arbitrario e bloccherebbe un account a comando di chiunque.
    // Fail-CLOSED: senza segreto il webhook è inerte, non ostile.
    config(['cashier.webhook.secret' => $segreto]);

    // ⚠️ **Il caso che conta è il secondo**, e la prima stesura di questo test
    // lo mancava: mandava un header di firma VUOTO, che `verifyHeader()` scarta
    // nel parser prima di arrivare al confronto HMAC — quindi il test passava
    // per la ragione sbagliata e non provava nulla.
    //
    // L'attacco vero è firmare **con la chiave vuota**: `computeSignature()` è
    // `hash_hmac('sha256', $payload, $secret)`, quindi con un segreto assente
    // la firma «valida» la può calcolare chiunque. Trovato da /security-review.
    $corpo = json_encode(evento('customer.subscription.updated', 'unpaid'));
    $t = time();

    test()->call('POST', '/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$corpo}", (string) $segreto),
    ], $corpo)->assertForbidden();

    expect($this->account->fresh()->is_locked)->toBeFalse();
})->with([null, '']);

// ─── Il gesto ────────────────────────────────────────────────────────────────

it('locks the account when Stripe gives up on the payment', function (string $stato) {
    consegna(evento('customer.subscription.updated', $stato))->assertOk();

    $account = $this->account->fresh();

    expect($account->is_locked)->toBeTrue()
        ->and($account->stripe_locked_at)->not->toBeNull()
        ->and($account->stripe_lock_reason)->toContain($stato)
        ->and($account->stripe_lock_reason)->toContain('sub_easylab')
        // La sorgente manuale resta spenta: è ciò che rende le due ortogonali.
        ->and($account->locked_at)->toBeNull();
})->with(['unpaid', 'canceled']);

it('locks the account when the subscription is deleted, and the piano decays', function () {
    consegna(evento('customer.subscription.deleted', 'canceled'))->assertOk();

    $account = $this->account->fresh();

    expect($account->is_locked)->toBeTrue()
        // Il piano decade alla cancellazione, che nel flusso ordinario è la
        // fine del periodo già pagato: chi disdice resta `active` fino a
        // scadenza, e solo allora Stripe manda questo evento.
        ->and($account->piano)->toBe('free');
});

/**
 * Il price corrente del piano `saas`, come riga di `prezzi_piano`.
 *
 * ⚠️ Era `config(['easylab.piani.catalogo.saas.stripe_price' => ...])`: dal 27
 * Ago 2026 il listino vive a database (ADR-035), e la migration di backfill non
 * scrive alcuna riga perché in suite le chiavi Stripe sono azzerate da
 * `phpunit.xml`. Il memo del catalogo è per-richiesta e in un test la richiesta
 * non finisce mai, quindi va dimenticato a mano dopo la scrittura.
 */
function prezzoSaas(string $priceId, bool $corrente = true): void
{
    Piano::query()->where('codice', 'saas')->sole()->prezzi()->create([
        'stripe_price_id' => $priceId,
        'importo_cent' => 4900,
        'valuta' => 'eur',
        'corrente' => $corrente,
    ]);

    app(CatalogoPiani::class)->dimentica();
}

it('unlocks the account when the subscription is healthy again', function (string $stato) {
    $this->account->bloccaPerStripe('Stripe: insoluto.');

    consegna(evento('customer.subscription.updated', $stato))->assertOk();

    $account = $this->account->fresh();

    expect($account->is_locked)->toBeFalse()
        ->and($account->stripe_locked_at)->toBeNull()
        ->and($account->stripe_lock_reason)->toBeNull();
})->with(['active', 'trialing']);

it('realigns the piano from the Stripe price, but only on a healthy subscription', function () {
    prezzoSaas('price_saas_test');
    $this->account->cambiaPiano('free');

    consegna(evento('customer.subscription.updated', 'active'))->assertOk();

    expect($this->account->fresh()->piano)->toBe('saas');
});

it('realigns the piano from a HISTORIC price, which is what every old customer has', function () {
    // 🔴 Il caso che nasce alla **prima modifica di listino**, e che senza lo
    // storico non si vedrebbe mai in test: su Stripe un Price è immutabile,
    // quindi cambiare cifra ne crea uno nuovo e archivia il vecchio — mentre le
    // subscription in essere continuano a fatturare sul vecchio. Se
    // `Piani::perPrice()` guardasse solo il price corrente, da quel momento in
    // poi il piano di **ogni cliente già abbonato** smetterebbe di riallinearsi,
    // in silenzio: `null` è un esito legittimo, e `applicaStato()` lo tratta
    // come tale lasciando il piano dov'è.
    prezzoSaas('price_storico', corrente: false);
    prezzoSaas('price_corrente');

    $this->account->cambiaPiano('free');

    consegna(evento('customer.subscription.updated', 'active', price: 'price_storico'))->assertOk();

    expect($this->account->fresh()->piano)->toBe('saas');
});

it('never promotes the piano on a subscription that never started', function () {
    prezzoSaas('price_saas_test');
    $this->account->cambiaPiano('free');

    // `incomplete`: il primo pagamento non è mai andato a buon fine. Se il
    // piano si allineasse su ogni evento, questo account salirebbe a `saas`
    // senza aver pagato un centesimo.
    consegna(evento('customer.subscription.updated', 'incomplete'))->assertOk();

    expect($this->account->fresh()->piano)->toBe('free');
});

it('leaves the piano alone when the price is not in the catalogo', function () {
    // Price creato a mano in dashboard, o di un piano dismesso: un webhook non
    // deve poter scrivere in `accounts.piano` un valore che il catalogo non
    // conosce — `cambiaPiano()` lancerebbe, e l'endpoint darebbe 500.
    consegna(evento('customer.subscription.updated', 'active', price: 'price_mai_visto'))->assertOk();

    expect($this->account->fresh()->piano)->toBe('saas');
});

it('unlocks a returning customer who subscribes again', function () {
    // ⚠️ Trovato su staging, non qui: chi disdice e torna produce un
    // `customer.subscription.created`, non un `updated`. Senza questo handler
    // l'account restava BLOCCATO e sul piano `free` mentre pagava — il peggior
    // esito possibile per questo blocco. I test non lo vedevano perché erano
    // scritti sul flusso che avevo immaginato, dove si disdice e basta.
    prezzoSaas('price_saas_test');
    $this->account->bloccaPerStripe('Stripe: abbonamento cancellato.');
    $this->account->cambiaPiano('free');

    consegna(evento('customer.subscription.created', 'active'))->assertOk();

    $account = $this->account->fresh();

    expect($account->is_locked)->toBeFalse()
        ->and($account->stripe_locked_at)->toBeNull()
        ->and($account->piano)->toBe('saas');
});

it('puts an existing free account on the plan it paid for, and closes the proposal', function () {
    // 🔗 ADR-045 — l'attivazione dalla pagina «Abbonamento». Il checkout nasce
    // intestato al customer dell'Account, quindi la subscription arriva qui con
    // uno `stripe_id` che il database conosce: nessun handler nuovo, è la stessa
    // strada di chi disdice e torna. Il piano lo scrive QUESTO evento — aprire
    // il checkout non lo aveva toccato.
    prezzoSaas('price_saas_test');
    $this->account->cambiaPiano('free');
    $this->account->proponiPiano('saas');

    consegna(evento('customer.subscription.created', 'active'))->assertOk();

    $account = $this->account->fresh();

    expect($account->piano)->toBe('saas')
        ->and($account->piano_proposto)->toBeNull()
        ->and(DB::table('subscriptions')->where('account_id', $account->id)->where('stripe_id', 'sub_easylab')->count())->toBe(1);
});

it('keeps the proposal while the subscription has not started', function () {
    // `incomplete`: il checkout è aperto, l'incasso no. Il piano non si tocca
    // e la proposta resta — è ancora vero che il cliente deve pagare.
    prezzoSaas('price_saas_test');
    $this->account->cambiaPiano('free');
    $this->account->proponiPiano('saas');

    consegna(evento('customer.subscription.created', 'incomplete'))->assertOk();

    $account = $this->account->fresh();

    expect($account->piano)->toBe('free')
        ->and($account->piano_proposto)->toBe('saas');
});

it('ignores the completed session of an activation checkout, which is not a signup', function () {
    // 🔗 ADR-045 — la sessione aperta da `/abbonamento` non porta né un
    // `registrazione_id` né un Payment Link: per `checkout.session.completed`
    // è un checkout che non lo riguarda. Nessun account nasce, e nessun
    // allarme finisce nel tracker — l'account lo aggiorna l'evento della
    // subscription.
    Exceptions::fake();
    $prima = Account::count();

    consegna([
        'id' => 'evt_attivazione',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_test_attivazione',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'customer' => 'cus_easylab',
            'subscription' => 'sub_easylab',
            'payment_link' => null,
            'metadata' => ['account_id' => (string) $this->account->id, 'piano' => 'saas'],
        ]],
    ])->assertOk();

    Exceptions::assertNothingReported();

    expect(Account::count())->toBe($prima);
});

/*
 |--------------------------------------------------------------------------
 | 🔗 ADR-045 — la partita IVA scritta al checkout di attivazione
 |--------------------------------------------------------------------------
 |
 | Al checkout è obbligatoria, e Stripe la salva sul customer. Senza questo
 | ramo resterebbe là: la cabina direbbe «P.IVA non dichiarata» di un cliente
 | che l'ha appena scritta. L'account è quello col `stripe_id` della sessione,
 | e il dato riempie un vuoto — non corregge ciò che la cabina ha già scritto.
 */

/** La sessione completata di un checkout di attivazione, come la manda Stripe. */
function sessioneDiAttivazione(?string $partitaIva, string $customer = 'cus_easylab'): array
{
    return [
        'id' => 'evt_'.fake()->numerify('##########'),
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_test_attivazione',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'customer' => $customer,
            'subscription' => 'sub_easylab',
            'payment_link' => null,
            'metadata' => ['account_id' => '1', 'piano' => 'saas'],
            'customer_details' => [
                'email' => 'marta@rossi.test',
                'tax_ids' => $partitaIva === null ? [] : [['type' => 'eu_vat', 'value' => $partitaIva]],
            ],
        ]],
    ];
}

it('writes on the account the VAT number entered at the activation checkout', function () {
    consegna(sessioneDiAttivazione('IT01234567890'))->assertOk();

    expect($this->account->fresh()->partita_iva)->toBe('IT01234567890');
});

it('never overwrites a VAT number the cabina has already written', function () {
    // In cabina il dato l'ha messo una persona che risponde di quel contratto;
    // in checkout l'ha digitato chi stava pagando. Il secondo riempie un vuoto.
    $this->account->update(['partita_iva' => 'IT99999999999']);

    consegna(sessioneDiAttivazione('IT01234567890'))->assertOk();

    expect($this->account->fresh()->partita_iva)->toBe('IT99999999999');
});

it('writes nothing when the session carries no VAT number', function () {
    // ⚠️ Il campo parte da stringa VUOTA e non da `null`, apposta: «niente
    // partita IVA» scritto sopra un `null` non si distingue dal non aver
    // scritto. Sopra una stringa vuota sì — diventerebbe `null`, cioè una
    // modifica all'account e una riga di audit per un dato che non è arrivato.
    $this->account->forceFill(['partita_iva' => ''])->saveQuietly();

    consegna(sessioneDiAttivazione(null))->assertOk();

    expect($this->account->fresh()->partita_iva)->toBe('')
        ->and(Activity::where('subject_type', Account::class)
            ->where('subject_id', $this->account->id)
            ->where('description', 'Modifica account')->count())->toBe(0);
});

it('never writes the VAT number on an account other than the one that paid', function () {
    // 🔴 Nessuno scope protegge questo percorso: l'unico filtro è lo
    // `stripe_id`. La sessione è di «cus_easylab», e l'altro account non deve
    // muoversi di un bit — nemmeno se i metadata nominassero il suo id.
    $altro = Account::factory()->conStripe('cus_altro')->create(['ragione_sociale' => 'Altro Cliente']);

    $payload = sessioneDiAttivazione('IT01234567890');
    $payload['data']['object']['metadata']['account_id'] = (string) $altro->id;

    consegna($payload)->assertOk();

    expect($altro->fresh()->partita_iva)->toBeNull()
        ->and($this->account->fresh()->partita_iva)->toBe('IT01234567890');
});

it('ignores the VAT number of a customer that is nobody account, or of a trashed one', function () {
    consegna(sessioneDiAttivazione('IT01234567890', 'cus_sconosciuto'))->assertOk();

    expect(Account::query()->whereNotNull('partita_iva')->count())->toBe(0);

    $this->account->delete();

    consegna(sessioneDiAttivazione('IT01234567890'))->assertOk();

    expect(Account::withTrashed()->find($this->account->id)->partita_iva)->toBeNull();
});

it('is idempotent on the VAT number when Stripe delivers the session twice', function () {
    consegna(sessioneDiAttivazione('IT01234567890'))->assertOk();
    consegna(sessioneDiAttivazione('IT01234567890'))->assertOk();

    // Una riga di audit sola: la seconda consegna trova il campo pieno.
    expect(Activity::where('subject_type', Account::class)
        ->where('subject_id', $this->account->id)
        ->where('description', 'Modifica account')->count())->toBe(1);
});

it('locks on a subscription that is born already unpaid', function () {
    // `created` e `updated` rispondono alla stessa domanda e devono dare la
    // stessa risposta, o le due strade divergono al primo cambiamento.
    consegna(evento('customer.subscription.created', 'unpaid'))->assertOk();

    expect($this->account->fresh()->is_locked)->toBeTrue();
});

// ─── Stati che NON devono bloccare ───────────────────────────────────────────

it('does not lock while Stripe is still retrying', function () {
    // `past_due` è il primo tentativo fallito: il dunning è in corso e il
    // cliente ha giorni per rimediare. Bloccare qui vuol dire chiudere fuori
    // chi ha solo la carta scaduta.
    consegna(evento('customer.subscription.updated', 'past_due'))->assertOk();

    expect($this->account->fresh()->is_locked)->toBeFalse();
});

it('answers 200 without locking when an incomplete subscription expires', function () {
    // Ramo insidioso: il parent cancella la riga `subscriptions` e ritorna
    // `null`. Se l'override propagasse quel ritorno, Stripe riceverebbe una
    // risposta vuota invece di un 200 esplicito.
    consegna(evento('customer.subscription.updated', 'incomplete_expired'))->assertOk();

    expect($this->account->fresh()->is_locked)->toBeFalse();
});

// ─── Il filtro sugli eventi ammessi ──────────────────────────────────────────

it('ignores an event type that is not in the configured list', function () {
    // ⚠️ Non è ridondante con `config/cashier.php`: quella chiave dice a
    // `cashier:webhook` cosa REGISTRARE su Stripe, e non filtra niente in
    // ingresso. Su staging l'endpoint era stato creato a mano in ascolto su
    // **241 tipi**, quindi la restrizione era carta straccia — e gli handler
    // ereditati da Cashier avrebbero lavorato per conto loro.
    //
    // `customer.deleted` è il caso peggiore: il parent azzera `stripe_id`, e da
    // quel momento l'account è staccato da ogni futuro controllo sull'insoluto.
    consegna([
        'id' => 'evt_estraneo',
        'type' => 'customer.deleted',
        'data' => ['object' => ['id' => 'cus_easylab', 'customer' => 'cus_easylab']],
    ])->assertOk();

    expect($this->account->fresh()->stripe_id)->toBe('cus_easylab');
});

it('never lets an unlisted event reach an inherited handler', function () {
    // `customer.updated` nel parent chiama `updateDefaultPaymentMethodFromStripe()`,
    // cioè una chiamata di rete verso Stripe DENTRO la richiesta del webhook.
    // Senza chiavi valide qui esploderebbe: che risponda 200 è la prova che
    // l'handler non è stato nemmeno raggiunto.
    consegna([
        'id' => 'evt_estraneo',
        'type' => 'customer.updated',
        'data' => ['object' => ['id' => 'cus_easylab', 'customer' => 'cus_easylab']],
    ])->assertOk();

    expect($this->account->fresh()->pm_type)->toBeNull();
});

it('still handles everything when the configured list is empty', function () {
    // Un elenco vuoto NON filtra: un webhook che ignora tutto in silenzio
    // sarebbe di nuovo un lockout che non scatta mai.
    config(['cashier.webhook.events' => []]);

    consegna(evento('customer.subscription.updated', 'unpaid'))->assertOk();

    expect($this->account->fresh()->is_locked)->toBeTrue();
});

// ─── Confini: chi non deve essere toccato ────────────────────────────────────

it('ignores an event for an unknown customer, without writing anything', function () {
    // 200 e non 404: un errore farebbe ritentare Stripe per giorni e poi
    // disabilitare l'endpoint, facendoci perdere anche gli eventi buoni.
    consegna(evento('customer.subscription.updated', 'unpaid', customer: 'cus_mai_visto'))->assertOk();

    expect($this->account->fresh()->is_locked)->toBeFalse()
        ->and(DB::table('subscriptions')->count())->toBe(0);
});

it('ignores an event for a trashed account', function () {
    $this->account->delete();

    consegna(evento('customer.subscription.updated', 'unpaid'))->assertOk();

    $account = Account::withTrashed()->find($this->account->id);

    expect($account->is_locked)->toBeFalse()
        ->and($account->stripe_locked_at)->toBeNull();
});

it('never touches an account other than the one the event belongs to', function () {
    // Non-trapelamento: in questo percorso non c'è alcuno scope, quindi la
    // prova che il confine tiene è che il vicino resti immobile.
    $estraneo = Account::factory()->conStripe('cus_estraneo')->saas()->create();

    consegna(evento('customer.subscription.updated', 'unpaid'))->assertOk();

    $vicino = $estraneo->fresh();

    expect($vicino->is_locked)->toBeFalse()
        ->and($vicino->stripe_locked_at)->toBeNull()
        ->and($vicino->piano)->toBe('saas')
        // «Creazione account» c'è per forza (l'ha creata la factory): ciò che
        // non deve esistere è una MODIFICA — cioè un gesto sul vicino.
        ->and(Activity::where('subject_id', $estraneo->id)
            ->where('subject_type', Account::class)
            ->where('description', 'Modifica account')->count())->toBe(0);
});

// ─── L'incrocio fra le due sorgenti di lockout ───────────────────────────────

it('never reopens a manual lockout when the payment succeeds', function () {
    // L'ordine che conta: prima Stripe, poi la persona. `blocca()` è
    // idempotente come no-op, quindi con una sola colonna `locked_reason` il
    // gesto manuale sparirebbe e il primo `active` riaprirebbe un account
    // chiuso per contenzioso.
    $this->account->bloccaPerStripe('Stripe: insoluto.');
    $this->account->blocca('Contenzioso legale');

    consegna(evento('customer.subscription.updated', 'active'))->assertOk();

    $account = $this->account->fresh();

    expect($account->is_locked)->toBeTrue()
        ->and($account->locked_reason)->toBe('Contenzioso legale')
        ->and($account->stripe_locked_at)->toBeNull();
});

it('records the insoluto even on an account already locked by hand', function () {
    // L'ordine opposto: prima la persona, poi Stripe. Senza la seconda
    // sorgente, allo sblocco manuale il cliente tornerebbe operativo con la
    // subscription ancora `unpaid`, e nessun evento futuro lo richiuderebbe.
    $this->account->blocca('Contenzioso legale');

    consegna(evento('customer.subscription.updated', 'unpaid'))->assertOk();

    $this->account->fresh()->sblocca();
    $account = $this->account->fresh();

    expect($account->is_locked)->toBeTrue()
        ->and($account->locked_at)->toBeNull()
        ->and($account->stripe_locked_at)->not->toBeNull();
});

// ─── Idempotenza e audit ─────────────────────────────────────────────────────

it('is idempotent when Stripe delivers the same event twice', function () {
    $payload = evento('customer.subscription.updated', 'unpaid');

    consegna($payload)->assertOk();
    $primoBlocco = $this->account->fresh()->stripe_locked_at;

    $this->travel(5)->minutes();
    consegna($payload)->assertOk();

    $account = $this->account->fresh();

    expect(DB::table('subscriptions')->count())->toBe(1)
        // `stripe_locked_at` documenta QUANDO è iniziato l'insoluto: una
        // seconda consegna non deve riscriverlo.
        ->and($account->stripe_locked_at->timestamp)->toBe($primoBlocco->timestamp)
        ->and(Activity::where('subject_id', $account->id)
            ->where('subject_type', Account::class)
            ->where('description', 'Modifica account')->count())->toBe(1);
});

it('attributes the lockout to nobody, because nobody did it', function () {
    consegna(evento('customer.subscription.updated', 'unpaid'))->assertOk();

    $riga = Activity::where('subject_id', $this->account->id)
        ->where('subject_type', Account::class)->latest('id')->first();

    // Non c'è un causer: l'ha fatto Stripe. Il riferimento all'evento sta in
    // `stripe_lock_reason`, che la riga di audit porta con sé.
    expect($riga->causer_id)->toBeNull()
        ->and($riga->attribute_changes['attributes']['stripe_lock_reason'])->toContain('evt_');
});

it('never shows the lockout reason to the person who is locked out', function () {
    // ADR-013: `locked_reason`/`stripe_lock_reason` sono annotazioni operative
    // interne. Il bloccato vede un messaggio generico e la via d'uscita.
    consegna(evento('customer.subscription.updated', 'unpaid'))->assertOk();

    $this->seed(RolesAndPermissionsSeeder::class);
    $membro = User::factory()->create(['tenant_id' => $this->ente->id]);
    $membro->assignRole('Tenant');
    $this->account->aggiungiMembro($membro);

    $this->actingAs($membro)->get(route('bloccato'))
        ->assertOk()
        ->assertDontSee('unpaid')
        ->assertDontSee('sub_easylab');
});
