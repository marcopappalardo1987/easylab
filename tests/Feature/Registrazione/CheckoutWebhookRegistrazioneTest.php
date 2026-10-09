<?php

use App\Models\Account;
use App\Models\Errore;
use App\Models\Registrazione;
use App\Models\User;
use App\Notifications\PianoCambiato;
use App\Support\AuditLog;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use App\Support\Provisioning\ProvisionaEnte;
use App\Support\Registrazione\CompletaRegistrazione;
use App\Support\Registrazione\RegistrazioneRifiutata;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BancoRegistrazione;

/**
 * 🔴 `checkout.session.completed`: la rete che chiude il caso «ha pagato e ha
 * chiuso la scheda» (🔗 ADR-012, ADR-032).
 *
 * Il ritorno del browser da Stripe **non è garantito** — una connessione che
 * cade, una scheda chiusa, un telefono che si spegne — e senza questo evento
 * quel cliente avrebbe **pagato per niente**: nessun account, nessun utente,
 * nessuna email, e una riga `registrazioni` che si pota da sola a trenta giorni.
 *
 * ⚠️ **Cashier non ha un `handleCheckoutSessionCompleted`**: il dispatch del
 * parent finirebbe in `missingMethod()` con un 200 muto, cioè con la rete che
 * *sembra* esserci. L'handler è nostro, e l'evento va **registrato**: da qui il
 * guardrail sull'elenco di `config/cashier.php`, che è l'unico posto in cui
 * `cashier:webhook` legge cosa chiedere a Stripe.
 *
 * ⚠️ I 200 su un rifiuto non sono una resa: un 404 o un 500 farebbe ritentare
 * Stripe per giorni e poi **disabilitare l'endpoint**, facendoci perdere anche
 * gli eventi buoni — compresi i lockout per insoluto.
 */
const SEGRETO_REGISTRAZIONE = 'whsec_test_registrazione';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    config(['cashier.webhook.secret' => SEGRETO_REGISTRAZIONE]);

    BancoRegistrazione::listinoVendibile();

    $this->riga = Registrazione::factory()->conSessione('cs_test_aurora')->create([
        'nome_ente' => 'Laboratorio Aurora',
        'nome_referente' => 'Marta Bianchi',
        'email' => 'marta@laboratorio-aurora.it',
        'piano' => 'saas',
    ]);
});

/** Il payload di una sessione di checkout, nella forma in cui Stripe la manda. */
function sessionePagata(array $metadata, array $sovrascritture = []): array
{
    return [
        'id' => 'evt_'.fake()->numerify('##########'),
        'type' => 'checkout.session.completed',
        'data' => ['object' => array_merge([
            'id' => 'cs_test_aurora',
            'status' => 'complete',
            'payment_status' => 'paid',
            'customer' => 'cus_aurora',
            'subscription' => 'sub_aurora',
            'metadata' => $metadata,
        ], $sovrascritture)],
    ];
}

/** Consegna un payload firmato come lo firmerebbe Stripe. */
function consegnaRegistrazione(array $payload): TestResponse
{
    $corpo = json_encode($payload);
    $t = time();
    $firma = hash_hmac('sha256', "{$t}.{$corpo}", SEGRETO_REGISTRAZIONE);

    return test()->call('POST', '/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$firma}",
    ], $corpo);
}

// ─── 🔴 Il guardrail sull'elenco degli eventi ────────────────────────────────

it('asks Stripe for the one event without which a paid customer gets nothing', function () {
    // 🔴 `cashier:webhook` registra su Stripe **solo** ciò che legge da questa
    // chiave: toglierla non rompe nessun handler — semplicemente l'evento non
    // arriva più, e il caso «scheda chiusa dopo il pagamento» torna a finire nel
    // nulla senza che una riga rossa lo dica.
    expect(config('cashier.webhook.events'))->toContain('checkout.session.completed');
});

// ─── Il percorso felice ──────────────────────────────────────────────────────

it('makes the account of somebody who paid and never came back', function () {
    consegnaRegistrazione(sessionePagata(['registrazione_id' => (string) $this->riga->getKey(), 'piano' => 'saas']))
        ->assertOk();

    $account = Account::query()->sole();

    expect($account->piano)->toBe('saas')
        ->and($account->stripe_id)->toBe('cus_aurora')
        ->and(User::query()->where('email', 'marta@laboratorio-aurora.it')->exists())->toBeTrue()
        ->and($this->riga->fresh()->completata())->toBeTrue();
});

it('does not announce a plan change to an account that is being born on its plan', function () {
    // 🔗 ADR-047: l'account nasce già sul piano pagato. Non gli è «cambiato»
    // nulla, e a dirlo è il benvenuto — una seconda email «il piano è passato
    // da Free a SaaS» racconterebbe un fatto che il cliente non ha vissuto.
    InterruttoriEmail::imposta(CatalogoEmail::PIANO_CAMBIATO, true);
    Notification::fake();

    consegnaRegistrazione(sessionePagata(['registrazione_id' => (string) $this->riga->id]))->assertOk();

    expect(Account::query()->sole()->piano)->toBe('saas');

    Notification::assertNotSentTo(
        User::where('email', 'marta@laboratorio-aurora.it')->sole(),
        PianoCambiato::class,
    );
});

it('makes one account even if Stripe delivers the same event twice', function () {
    $payload = sessionePagata(['registrazione_id' => (string) $this->riga->getKey()]);

    consegnaRegistrazione($payload)->assertOk();
    consegnaRegistrazione($payload)->assertOk();

    // ⚠️ Il conteggio da solo non può fallire: senza la guardia di idempotenza
    // la seconda consegna arriva al provisioning, che rifiuta l'email già in
    // uso, e gli account restano uno. Il gesto ripetuto dev'essere **muto**:
    // nessun rifiuto registrato e nessuna issue nel tracker.
    expect(Account::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1)
        ->and(Activity::query()->where('description', CompletaRegistrazione::DESCRIZIONE_RIFIUTO)->count())->toBe(0)
        ->and(Errore::query()->count())->toBe(0);
});

it('reads the two conditions of «paid» from the payload, and creates nothing without them', function (array $stato) {
    // 🔴 Il payload arriva da un dominio di terzi ma è firmato: ciò che non si
    // dà per buono è il **significato**, non l'origine. Una `complete` non
    // ancora incassata non fa nascere niente.
    consegnaRegistrazione(sessionePagata(
        ['registrazione_id' => (string) $this->riga->getKey()],
        $stato,
    ))->assertOk();

    expect(Account::query()->count())->toBe(0)
        ->and($this->riga->fresh()->completata())->toBeFalse();
})->with([
    'sessione ancora aperta' => [['status' => 'open']],
    'pagamento non incassato' => [['payment_status' => 'unpaid']],
]);

it('takes the plan from our row and never from the metadata', function () {
    consegnaRegistrazione(sessionePagata([
        'registrazione_id' => (string) $this->riga->getKey(),
        // Il payload mente: `free` è gratuito e il modulo non lo vende.
        'piano' => 'free',
    ]))->assertOk();

    expect(Account::query()->sole()->piano)->toBe('saas');
});

// ─── I 200 che non sono una resa ─────────────────────────────────────────────

it('stays quiet on a checkout that has nothing to do with us', function () {
    // Un checkout aperto a mano dalla dashboard di Stripe non ha i nostri
    // metadata: è un caso **normale**, non un guasto.
    consegnaRegistrazione(sessionePagata([]))->assertOk();

    expect(Account::query()->count())->toBe(0)
        ->and(Errore::query()->count())->toBe(0);
});

it('stays quiet on a registration that no longer exists', function () {
    // La potatura a trenta giorni può aver portato via la riga mentre un evento
    // in ritardo la nomina ancora.
    consegnaRegistrazione(sessionePagata(['registrazione_id' => '999999']))->assertOk();

    expect(Account::query()->count())->toBe(0);
});

it('never lets a forged signature make an account', function () {
    $corpo = json_encode(sessionePagata(['registrazione_id' => (string) $this->riga->getKey()]));

    $this->call('POST', '/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=falsa',
    ], $corpo)->assertForbidden();

    expect(Account::query()->count())->toBe(0)
        ->and($this->riga->fresh()->completata())->toBeFalse();
});

// ─── 🔴 Il rifiuto dopo l'incasso ────────────────────────────────────────────

it('answers 200 to a definitive refusal, and leaves a trace that survives the deploy', function () {
    // 🔴 Il rifiuto è **definitivo** (l'email appartiene già a un
    // amministratore): ripetere l'evento darebbe lo stesso esito mille volte, e
    // far ritentare Stripe per giorni costerebbe l'endpoint. Ma il pagamento è
    // già incassato, quindi il 200 muto deve lasciare qualcosa dietro di sé — o
    // di quel cliente non si accorge nessuno.
    $altro = (new ProvisionaEnte(
        nome: 'Studio Terzo',
        adminEmail: 'marta@laboratorio-aurora.it',
        adminName: 'Marta Bianchi',
        passwordEsplicita: 'ParolaSegreta!2026',
    ))->esegui();

    $altro->account->cambiaPiano('saas');

    consegnaRegistrazione(sessionePagata(['registrazione_id' => (string) $this->riga->getKey()]))
        ->assertOk();

    expect($this->riga->fresh()->completata())->toBeFalse()
        ->and(Errore::query()->count())->toBe(1);

    $audit = Activity::query()
        ->where('log_name', AuditLog::NAME)
        ->where('description', CompletaRegistrazione::DESCRIZIONE_RIFIUTO)
        ->sole();

    expect($audit->subject_id)->toBe($this->riga->getKey());
});

it('never makes an account from the webhook for a mailbox nobody confirmed', function () {
    // 🔴 Il ramo che **non** passa dal browser: `versoStripe` non è mai stato
    // percorso, quindi la sua guardia non ha guardato niente. Se la verifica
    // vivesse solo là, un evento firmato basterebbe a far nascere un account su
    // un indirizzo che nessuno ha confermato.
    $nonVerificata = Registrazione::factory()->create([
        'email' => 'mai-confermata@pec.it',
        'stripe_session_id' => 'cs_test_mai_confermata',
        'piano' => 'saas',
    ]);

    consegnaRegistrazione(sessionePagata(
        ['registrazione_id' => (string) $nonVerificata->getKey()],
        ['id' => 'cs_test_mai_confermata'],
    ))->assertOk();

    expect(Account::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and($nonVerificata->fresh()->completata())->toBeFalse();
});

// ─── 🔴 Due sessioni pagate per una riga (caccia T3, A3/B3) ──────────────────

it('leaves a trace when a second paid session lands on an already completed registration', function () {
    // Due schede aperte su «vai a Stripe»: la riga porta l'id dell'ultima
    // aperta (cs_test_aurora), e il cliente le paga entrambe.
    consegnaRegistrazione(sessionePagata(
        ['registrazione_id' => (string) $this->riga->getKey()],
        ['id' => 'cs_test_prima', 'customer' => 'cus_prima', 'subscription' => 'sub_prima'],
    ))->assertOk();

    expect(Errore::query()->count())->toBe(0);

    consegnaRegistrazione(sessionePagata(
        ['registrazione_id' => (string) $this->riga->getKey()],
        ['id' => 'cs_test_aurora', 'customer' => 'cus_seconda', 'subscription' => 'sub_seconda'],
    ))->assertOk();

    $rifiuto = Activity::query()->where('description', CompletaRegistrazione::DESCRIZIONE_RIFIUTO)->sole();

    // Un account solo, e il secondo incasso con gli oggetti Stripe che servono
    // a rimborsarlo: nel registro e nel tracker.
    expect(Account::query()->count())->toBe(1)
        ->and($rifiuto->properties['codice'])->toBe(RegistrazioneRifiutata::GIA_COMPLETATA)
        ->and($rifiuto->properties['stripe_session_id'])->toBe('cs_test_aurora')
        ->and($rifiuto->properties['stripe_customer_id'])->toBe('cus_seconda')
        ->and($rifiuto->properties['stripe_subscription_id'])->toBe('sub_seconda')
        ->and(Errore::query()->count())->toBe(1);
});

it('records in the audit the session that was actually paid, not the last one opened', function () {
    consegnaRegistrazione(sessionePagata(
        ['registrazione_id' => (string) $this->riga->getKey()],
        ['id' => 'cs_test_prima'],
    ))->assertOk();

    $audit = Activity::query()->where('description', CompletaRegistrazione::DESCRIZIONE_AUDIT)->sole();

    expect($audit->properties['stripe_session_id'])->toBe('cs_test_prima')
        ->and($this->riga->fresh()->stripe_session_id)->toBe('cs_test_prima');
});

// ─── Il pagamento differito (caccia T3, A8/B6) ───────────────────────────────

it('births the account when an asynchronous payment settles after the checkout', function () {
    // ⚠️ Dipende da `config/cashier.php`: senza l'evento in `webhook.events`
    // `handleWebhook()` lo scarta prima dell'handler. Lo si aggiunge qui per
    // provare l'handler; la presenza nel config la prova il guardrail sotto.
    config(['cashier.webhook.events' => array_values(array_unique([
        ...config('cashier.webhook.events'), 'checkout.session.async_payment_succeeded',
    ]))]);

    $metadata = ['registrazione_id' => (string) $this->riga->getKey()];

    consegnaRegistrazione(sessionePagata($metadata, ['payment_status' => 'unpaid']))->assertOk();
    expect($this->riga->fresh()->completata())->toBeFalse();

    $incasso = sessionePagata($metadata);
    $incasso['type'] = 'checkout.session.async_payment_succeeded';
    consegnaRegistrazione($incasso)->assertOk();

    expect($this->riga->fresh()->completata())->toBeTrue()
        ->and(Account::query()->count())->toBe(1);
});

it('asks Stripe for the event that settles an asynchronous payment', function () {
    // Il diff sul config lo applica l'orchestratore (file conteso): finché
    // manca, questo test è rosso ed è giusto che lo sia.
    expect(config('cashier.webhook.events'))->toContain('checkout.session.async_payment_succeeded');
});

it('leaves a trace when a checkout closes with no payment required, and births nothing', function () {
    consegnaRegistrazione(sessionePagata(
        ['registrazione_id' => (string) $this->riga->getKey()],
        ['payment_status' => 'no_payment_required'],
    ))->assertOk();

    expect(Account::query()->count())->toBe(0)
        ->and($this->riga->fresh()->completata())->toBeFalse()
        ->and(Errore::query()->count())->toBe(1);
});
