<?php

use App\Models\Account;
use App\Models\Errore;
use App\Models\Registrazione;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\BenvenutoRegistrazione;
use App\Support\AuditLog;
use App\Support\Provisioning\ProvisionaEnte;
use App\Support\Registrazione\CompletaRegistrazione;
use App\Support\Registrazione\EsitoCheckout;
use App\Support\Registrazione\RegistrazioneRifiutata;
use Database\Factories\RegistrazioneFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BancoRegistrazione;

/**
 * 🔴 Il cuore del self-signup: **l'account nasce solo se il pagamento è andato a
 * buon fine** (🔗 ADR-012, ADR-032, ADR-002).
 *
 * L'azione ha **due** chiamanti che arrivano da due mondi — il ritorno del
 * browser da Stripe e il webhook `checkout.session.completed` — e questo file
 * prova l'azione, non i chiamanti: le loro due traduzioni dell'esito stanno in
 * `CheckoutWebhookRegistrazioneTest` e nei test del controller.
 *
 * ⚠️ **`EsitoCheckout` si costruisce a mano**, ed è la ragione per cui questa
 * feature ha test veri invece di un mock che verifica sé stesso: gli esiti che
 * contano — sessione aperta, pagamento non incassato, customer mancante — Stripe
 * non ce li darebbe mai su richiesta.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    BancoRegistrazione::listinoVendibile();

    $this->azione = app(CompletaRegistrazione::class);

    $this->riga = Registrazione::factory()->conSessione('cs_test_aurora')->create([
        'nome_ente' => 'Laboratorio Aurora',
        'nome_referente' => 'Marta Bianchi',
        'email' => 'marta@laboratorio-aurora.it',
        'piano' => 'saas',
    ]);
});

/** L'esito di un pagamento riuscito, nella forma in cui arriva da Stripe. */
function esitoPagato(array $sovrascritture = []): EsitoCheckout
{
    return new EsitoCheckout(...array_merge([
        'pagato' => true,
        'sessionId' => 'cs_test_aurora',
        'customerId' => 'cus_aurora',
        'subscriptionId' => 'sub_aurora',
        'piano' => 'saas',
    ], $sovrascritture));
}

/** Le righe di audit scritte da questa azione, che non sono tutte quelle dell'account. */
function righeDiRegistrazione(): int
{
    return Activity::query()
        ->where('log_name', AuditLog::NAME)
        ->where('description', CompletaRegistrazione::DESCRIZIONE_AUDIT)
        ->count();
}

// ─── 🔴 Il pagamento che non c'è ─────────────────────────────────────────────

it('creates nothing at all when the checkout was never paid', function (EsitoCheckout $esito) {
    // 🔴 LA riga della decisione di prodotto. Una sessione `open` è un checkout
    // aperto e mai concluso; una `complete` con `payment_status` diverso da
    // `paid` è un pagamento non incassato (bonifico in attesa, autorizzazione
    // non catturata). Nessuna delle due fa nascere niente.
    expect($this->azione->esegui($this->riga, $esito))->toBeNull()
        ->and(Account::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(UnitaOrganizzativa::query()->count())->toBe(0)
        ->and($this->riga->fresh()->completata())->toBeFalse()
        // Il segreto resta dov'era: la riga è ancora completabile, e senza hash
        // il cliente che paga davvero non potrebbe più entrare.
        ->and($this->riga->fresh()->password_hash)->not->toBeNull();
})->with([
    'sessione ancora aperta' => fn () => new EsitoCheckout(pagato: false, sessionId: 'cs_test_aurora'),
    'pagamento non incassato' => fn () => new EsitoCheckout(pagato: false, sessionId: 'cs_test_aurora', customerId: 'cus_aurora'),
]);

it('reads the two conditions of «paid» from the Stripe session, not one of them', function () {
    // La stessa regola, letta dal payload come arriva davvero: guardare il solo
    // `status` farebbe nascere un account su un pagamento non incassato.
    expect(EsitoCheckout::daSessioneStripe(['status' => 'complete', 'payment_status' => 'unpaid'])->pagato)->toBeFalse()
        ->and(EsitoCheckout::daSessioneStripe(['status' => 'open', 'payment_status' => 'paid'])->pagato)->toBeFalse()
        ->and(EsitoCheckout::daSessioneStripe(['status' => 'complete', 'payment_status' => 'paid'])->pagato)->toBeTrue();
});

// ─── Il percorso felice ──────────────────────────────────────────────────────

it('makes the account, the Ente and the Admin born from one paid checkout', function () {
    $account = $this->azione->esegui($this->riga, esitoPagato());

    $admin = User::query()->where('email', 'marta@laboratorio-aurora.it')->sole();
    $ente = UnitaOrganizzativa::withoutGlobalScopes()->sole();

    expect($account)->not->toBeNull()
        ->and($account->piano)->toBe('saas')
        ->and($account->stripe_id)->toBe('cus_aurora')
        ->and($ente->nome)->toBe('Laboratorio Aurora')
        ->and($admin->name)->toBe('Marta Bianchi')
        ->and($admin->tenant_id)->toBe($ente->getKey())
        ->and($admin->hasRole('Admin'))->toBeTrue()
        // Chi ha appena confermato la casella non deve confermarla una seconda
        // volta subito dopo aver pagato.
        ->and($admin->email_verified_at)->not->toBeNull();
});

it('lets the customer in with the password chosen at the form, not with a new one', function () {
    // 🔴 L'hash viaggia già hashato da `registrazioni.password_hash`:
    // rihasharlo produrrebbe una credenziale che nessuno conosce — cioè un
    // cliente pagante chiuso fuori dal proprio account.
    $this->azione->esegui($this->riga, esitoPagato());

    $admin = User::query()->where('email', 'marta@laboratorio-aurora.it')->sole();

    expect(Hash::check(RegistrazioneFactory::PASSWORD, $admin->password))->toBeTrue();
});

it('kills the secret in the same transaction in which users.password is born', function () {
    // Conservarlo sarebbe una seconda copia di una credenziale in una tabella
    // che nessuna Policy protegge.
    $this->azione->esegui($this->riga, esitoPagato());

    $riga = $this->riga->fresh();

    expect($riga->password_hash)->toBeNull()
        ->and($riga->completata())->toBeTrue()
        ->and($riga->account_id)->toBe(Account::query()->sole()->getKey());
});

it('mirrors the subscription with the price of our own listino', function () {
    // Stripe non garantisce l'ordine dei webhook: `customer.subscription.created`
    // può arrivare **prima** che l'Account esista, e allora la riga locale non
    // verrebbe scritta mai più — `/abbonamento` mostrerebbe un cliente pagante
    // senza abbonamento, in silenzio.
    $account = $this->azione->esegui($this->riga, esitoPagato());

    $sub = $account->subscriptions()->sole();

    expect($sub->stripe_id)->toBe('sub_aurora')
        ->and($sub->stripe_status)->toBe('active')
        ->and($sub->stripe_price)->toBe(BancoRegistrazione::PRICE_SAAS);
});

it('takes the plan from our row and never from the Stripe payload', function () {
    // 🔴 `metadata` viaggia su un dominio di terzi. Qui il payload dice `free` —
    // che è gratuito e il modulo non vende — e l'account deve nascere lo stesso
    // sul piano che il cliente ha davvero pagato.
    $account = $this->azione->esegui($this->riga, esitoPagato(['piano' => 'free']));

    expect($account->piano)->toBe('saas');
});

it('welcomes the new customer exactly once', function () {
    Notification::fake();

    $this->azione->esegui($this->riga, esitoPagato());
    $this->azione->esegui($this->riga->fresh(), esitoPagato());

    Notification::assertCount(1);
    Notification::assertSentTo($this->riga, BenvenutoRegistrazione::class);
});

it('writes one audit row on the account, without inventing a causer', function () {
    // Il soggetto è l'**Account** e non la registrazione: senza `causedBy()`,
    // perché non c'è nessuna persona identificata dietro questo gesto e
    // attribuirlo a chi passava di lì sarebbe un dato falso in un registro di
    // sicurezza.
    $account = $this->azione->esegui($this->riga, esitoPagato());

    $riga = Activity::query()
        ->where('description', CompletaRegistrazione::DESCRIZIONE_AUDIT)
        ->sole();

    expect($riga->causer_id)->toBeNull()
        ->and($riga->subject_id)->toBe($account->getKey())
        ->and($riga->properties['piano'])->toBe('saas')
        ->and($riga->properties['stripe_customer_id'])->toBe('cus_aurora');
});

// ─── L'idempotenza ───────────────────────────────────────────────────────────

it('makes one account out of two deliveries of the same payment', function () {
    // 🔴 Il ritorno del browser e il webhook possono arrivare entrambi: due
    // account per un solo pagamento sarebbero due contratti per un cliente.
    $primo = $this->azione->esegui($this->riga, esitoPagato());
    $secondo = $this->azione->esegui($this->riga->fresh(), esitoPagato());

    expect($secondo->getKey())->toBe($primo->getKey())
        ->and(Account::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1)
        ->and(UnitaOrganizzativa::withoutGlobalScopes()->count())->toBe(1)
        ->and(righeDiRegistrazione())->toBe(1);
});

it('answers a delivery for a row that no longer exists, instead of dying', function () {
    // La potatura a 30 giorni può aver portato via una riga pendente mentre un
    // evento in ritardo la nomina ancora.
    $fantasma = $this->riga->replicate();
    $fantasma->id = 9999;

    expect($this->azione->esegui($fantasma, esitoPagato()))->toBeNull()
        ->and(Account::query()->count())->toBe(0);
});

// ─── 🔴 I rifiuti, e la traccia che lasciano ─────────────────────────────────

it('refuses to make an account for a mailbox nobody ever confirmed', function () {
    // 🔴 La verifica della casella è **obbligatoria**, non soltanto
    // cronologicamente prima. Il ramo browser la impone in `versoStripe`, ma il
    // webhook chiama quest'azione **direttamente**: senza questa guardia, la
    // sola strada che non passa dal browser non guarderebbe nessuno.
    $nonVerificata = Registrazione::factory()->create([
        'email' => 'mai-confermata@pec.it',
        'stripe_session_id' => 'cs_test_mai_confermata',
    ]);

    expect(fn () => $this->azione->esegui($nonVerificata, esitoPagato(['sessionId' => 'cs_test_mai_confermata'])))
        ->toThrow(RegistrazioneRifiutata::class);

    expect(Account::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and($nonVerificata->fresh()->completata())->toBeFalse();
});

it('names the reason of the refusal on the code, never on the message', function () {
    $nonVerificata = Registrazione::factory()->create(['stripe_session_id' => 'cs_test_x']);

    try {
        $this->azione->esegui($nonVerificata, esitoPagato(['sessionId' => 'cs_test_x']));
    } catch (RegistrazioneRifiutata $e) {
        expect($e->codice)->toBe(RegistrazioneRifiutata::NON_VERIFICATA);

        return;
    }

    $this->fail('Il completamento di una registrazione non verificata doveva essere rifiutato.');
});

it('refuses an address that already administers somebody else account', function () {
    // 🔴 La guardia `esigiAccountNuovo`: senza, un'email che appartiene già a un
    // amministratore — **compreso l'account di piattaforma** — farebbe atterrare
    // l'Ente appena pagato sul contratto di un terzo.
    $altro = (new ProvisionaEnte(
        nome: 'Studio Terzo',
        adminEmail: 'marta@laboratorio-aurora.it',
        adminName: 'Marta Bianchi',
        passwordEsplicita: 'ParolaSegreta!2026',
    ))->esegui();

    // ⚠️ **L'account del terzo ha posto**, e la riga non è pignoleria: sul piano
    // predefinito (`free`, un solo Ente) l'aggancio sarebbe rifiutato dal
    // **limite di piano**, cioè da un'altra guardia — e questo test resterebbe
    // verde anche senza `esigiAccountNuovo`, provando qualcos'altro. Verificato
    // mutando: senza il tetto alzato la mutazione non diventa rossa.
    $altro->account->cambiaPiano('saas');

    expect(fn () => $this->azione->esegui($this->riga, esitoPagato()))
        ->toThrow(RegistrazioneRifiutata::class);

    expect(Account::query()->count())->toBe(1)
        ->and(Account::query()->sole()->getKey())->toBe($altro->account->getKey())
        // 🔴 L'asserzione che coglie il difetto: senza il flag l'Ente appena
        // pagato **si aggancerebbe** al contratto del terzo, e la registrazione
        // risulterebbe completata su un account che non è di chi ha pagato.
        ->and(UnitaOrganizzativa::withoutGlobalScopes()->count())->toBe(1)
        ->and($this->riga->fresh()->completata())->toBeFalse();
});

it('refuses a plan that has left the catalogue altogether', function () {
    BancoRegistrazione::cancellaDalCatalogo('saas');

    expect(fn () => $this->azione->esegui($this->riga, esitoPagato()))
        ->toThrow(RegistrazioneRifiutata::class);

    expect(Account::query()->count())->toBe(0);
});

it('still delivers the account when the plan was only archived while paying', function () {
    // 🔴 La differenza deliberata fra i due lati del pagamento. **Prima**
    // dell'incasso il ricontrollo è `PianiRegistrabili` e chiude la porta, che
    // non costa niente a nessuno; **dopo**, la domanda è soltanto «il piano
    // esiste ancora?» — rifiutare qui significherebbe aver incassato senza
    // consegnare, per un gesto (l'archiviazione) compiuto da noi mentre il
    // cliente era sulla pagina di Stripe.
    BancoRegistrazione::archivia('saas');

    $account = $this->azione->esegui($this->riga, esitoPagato());

    expect($account)->not->toBeNull()
        ->and($account->piano)->toBe('saas');
});

it('leaves a durable trace of a payment that could not become an account', function () {
    // 🔴 Il difetto che questo test chiude: il rifiuto arrivava **dopo**
    // l'incasso e l'unica traccia era una riga in `laravel.log` — disco
    // effimero e per-replica, azzerato a ogni deploy. A trenta giorni la
    // potatura si porta via anche la riga pendente, e non resta niente da cui
    // accorgersene: incasso avvenuto, nessun account, nessuna email, nessuno
    // che lo sappia.
    BancoRegistrazione::cancellaDalCatalogo('saas');

    try {
        $this->azione->esegui($this->riga, esitoPagato());
    } catch (RegistrazioneRifiutata) {
        // Il rifiuto lo raccontano i due chiamanti; qui si guarda la traccia.
    }

    $issue = Errore::query()->sole();

    expect($issue->classe)->toBe(RegistrazioneRifiutata::class);

    $audit = Activity::query()
        ->where('description', CompletaRegistrazione::DESCRIZIONE_RIFIUTO)
        ->sole();

    expect($audit->subject_id)->toBe($this->riga->getKey())
        ->and($audit->subject_type)->toBe(Registrazione::class)
        ->and($audit->properties['codice'])->toBe(RegistrazioneRifiutata::PIANO_FUORI_CATALOGO)
        // ⛔ **Mai l'email in chiaro nel registro**: si legge con
        // `tenants.view_all`, e chi è stato rifiutato non è un cliente. Il nome
        // dell'organizzazione dichiarata dice di quale tentativo si tratta.
        ->and(json_encode($audit->properties))->not->toContain('marta@laboratorio-aurora.it');
});
