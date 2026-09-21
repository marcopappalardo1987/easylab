<?php

use App\Models\Account;
use App\Models\Errore;
use App\Models\PrezzoPiano;
use App\Models\Registrazione;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\BenvenutoRegistrazione;
use App\Notifications\InvitoUtente;
use App\Support\Piani;
use App\Support\Registrazione\CompletaRegistrazione;
use App\Support\Registrazione\EsitoCheckout;
use App\Support\Registrazione\RegistrazioneDaPaymentLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BancoRegistrazione;

/**
 * 🔴 Il pagamento arrivato da un **Payment Link**: dalla sessione all'account
 * (🔗 ADR-039, ADR-012 il provisioning, ADR-002 il rapporto commerciale).
 *
 * **Area rossa doppia** della Policy di Code Review: è denaro *e* è l'unica
 * superficie non autenticata che fa nascere un tenant. I negativi sono quindi la
 * maggioranza del file, e il più importante non è di validazione ma di
 * **sopravvivenza**: questo endpoint non deve lanciare mai. Un 500 qui non è un
 * test rosso — Stripe ritenta per giorni e poi **disabilita l'endpoint**,
 * portandosi via anche `customer.subscription.updated`, cioè i lockout per
 * insoluto.
 *
 * Il percorso del **modulo** (con `registrazione_id` nei metadata) vive in
 * `CheckoutWebhookRegistrazioneTest`, e i due non si sovrappongono: là la riga
 * esiste già, qui va sintetizzata.
 */
const SEGRETO_PLINK = 'whsec_test_plink';

const PLINK = 'plink_saas_finto';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    config(['cashier.webhook.secret' => SEGRETO_PLINK]);

    BancoRegistrazione::listinoVendibile();

    PrezzoPiano::query()->where('corrente', true)->update([
        'stripe_payment_link_id' => PLINK,
        'stripe_payment_link_url' => 'https://buy.stripe.test/saas',
    ]);

    BancoRegistrazione::dimentica();
});

/**
 * Una sessione pagata su un Payment Link, nella forma in cui Stripe la manda.
 *
 * ⚠️ **Nessun `metadata.registrazione_id`**, ed è il punto: la riga non esiste
 * ancora quando qualcuno apre il link.
 */
function sessioneDaLink(array $sovrascritture = [], array $dettagli = [], array $raccolti = []): array
{
    return [
        'id' => 'evt_'.fake()->numerify('##########'),
        'type' => 'checkout.session.completed',
        'data' => ['object' => array_merge([
            'id' => 'cs_test_plink',
            'status' => 'complete',
            'payment_status' => 'paid',
            'customer' => 'cus_plink',
            'subscription' => 'sub_plink',
            'payment_link' => PLINK,
            'metadata' => [],
            // ⚠️ **La forma vera del payload**, non una comoda: Stripe manda i
            // due nomi in `collected_information` **e** in `customer_details`, e
            // valorizza `customer_details.name` con la **ragione sociale**
            // quando la raccolta business è attiva. Una fixture che mettesse il
            // referente in `name` proverebbe un payload che Stripe non manda —
            // ed è esattamente l'errore che ha fatto nascere il difetto.
            'collected_information' => array_merge([
                'business_name' => 'Laboratorio Aurora',
                'individual_name' => 'Marta Bianchi',
            ], $raccolti),
            'customer_details' => array_merge([
                'business_name' => 'Laboratorio Aurora',
                'individual_name' => 'Marta Bianchi',
                'name' => 'Laboratorio Aurora',
                'email' => 'marta@laboratorio-aurora.it',
                // ⚠️ Una **lista**, che è la forma vera: `tax_ids` è plurale
                // anche quando ne arriva una sola.
                'tax_ids' => [['type' => 'eu_vat', 'value' => 'IT01234567890']],
            ], $dettagli),
        ], $sovrascritture)],
    ];
}

/** Consegna un payload firmato come lo firmerebbe Stripe. */
function consegnaDaLink(array $payload): TestResponse
{
    $corpo = json_encode($payload);
    $t = time();
    $firma = hash_hmac('sha256', "{$t}.{$corpo}", SEGRETO_PLINK);

    return test()->call('POST', '/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$firma}",
    ], $corpo);
}

// ─── Il percorso felice ──────────────────────────────────────────────────────

it('turns a payment link session into a real tenant', function () {
    Notification::fake();

    consegnaDaLink(sessioneDaLink())->assertOk();

    $account = Account::query()->where('ragione_sociale', 'Laboratorio Aurora')->firstOrFail();

    expect($account->piano)->toBe('saas')
        ->and($account->stripe_id)->toBe('cus_plink')
        // L'Ente è la radice del tenant: senza, non ci sarebbe un posto dove
        // mettere la prima macchina.
        ->and($account->enti()->count())->toBe(1);

    $admin = User::query()->where('email', 'marta@laboratorio-aurora.it')->firstOrFail();

    expect($admin->hasRole('Admin'))->toBeTrue()
        // ⛔ **Non verificato, e non deve esserlo**: la casella la conferma
        // l'accettazione dell'invito, che è l'unica via dentro questo account.
        ->and($admin->email_verified_at)->toBeNull();
});

it('sends the invite and never the welcome that names a password nobody chose', function () {
    Notification::fake();

    consegnaDaLink(sessioneDaLink())->assertOk();

    $admin = User::query()->where('email', 'marta@laboratorio-aurora.it')->firstOrFail();

    Notification::assertSentTo($admin, InvitoUtente::class);

    // 🔴 Il negativo che conta più del positivo: il benvenuto dice «accedi con
    // la password che hai scelto durante la registrazione», che qui è falso.
    // Arriverebbe **insieme** all'invito, contraddicendolo — e sarebbe la più
    // ufficiale delle due email, e quella che non funziona.
    Notification::assertNothingSentTo(Registrazione::query()->firstOrFail());
    Notification::assertNotSentTo($admin, BenvenutoRegistrazione::class);
});

// ─── L'endpoint non lancia mai ───────────────────────────────────────────────

it('never fails the endpoint when the collected fields are unusable', function () {
    // ⛔ Il test di **sopravvivenza**. Ogni riga qui sotto è un pagamento
    // incassato che non può diventare un account, e per ognuna la risposta deve
    // essere 200: un 500 farebbe ritentare Stripe per giorni e poi disabilitare
    // l'endpoint, perdendo anche i lockout per insoluto.
    // ⚠️ Ogni caso svuota il campo in **entrambi** i posti in cui Stripe lo
    // manda: lasciarne uno pieno non proverebbe niente, perché il codice legge
    // l'uno in mancanza dell'altro apposta.
    $casi = [
        // ⚠️ Azzerati **tutti e tre** i posti da cui la ragione sociale può
        // arrivare, `name` compreso: lasciarne uno pieno proverebbe il fallback
        // invece dell'assenza.
        'ragione sociale assente' => [['business_name' => null, 'name' => null], ['business_name' => null]],
        'ragione sociale vuota' => [['business_name' => '   ', 'name' => '  '], ['business_name' => '   ']],
        'email assente' => [['email' => null], []],
        // ⚠️ **La divergenza fra i due motori**: `registrazioni.email` è
        // `varchar(255)` e Stripe accetta indirizzi fino a 512. Senza il tetto
        // nostro, SQLite tacerebbe e Postgres esploderebbe — cioè verde in
        // locale e 500 in produzione.
        'email più lunga della colonna' => [['email' => str_repeat('a', 250).'@esempio.it'], []],
        'campi di tipo sbagliato' => [
            ['business_name' => ['non', 'una', 'stringa'], 'name' => ['nemmeno', 'questa']],
            ['business_name' => ['non', 'una', 'stringa']],
        ],
    ];

    foreach ($casi as $caso => [$dettagli, $raccolti]) {
        consegnaDaLink(sessioneDaLink(['id' => 'cs_'.md5($caso)], $dettagli, $raccolti))
            ->assertOk();
    }

    expect(Account::query()->count())->toBe(0);
});

// ─── I dati fiscali ──────────────────────────────────────────────────────────

it('writes the VAT number declared at checkout onto the account', function () {
    // Il percorso da console (`easylab:provision-tenant`) i dati fiscali li fa
    // inserire a chi crea il tenant. Qui non c'è nessuno che li inserisca: se
    // non si prendono da Stripe, un cliente self-service nasce senza — e nessuno
    // glieli chiederà mai più.
    consegnaDaLink(sessioneDaLink())->assertOk();

    expect(Account::query()->firstOrFail()->partita_iva)->toBe('IT01234567890');
});

it('still births the account for a customer who declares no VAT number', function () {
    // 🔴 **Il negativo che conta**, ed è il motivo per cui la raccolta è
    // facoltativa: un privato o un ente senza partita IVA deve poter comprare.
    // Imporla bloccherebbe una vendita già decisa per un dato che si può
    // chiedere dopo — e questo modulo incassa **prima** di consegnare, quindi un
    // rifiuto qui è denaro non preso, non un errore da correggere.
    consegnaDaLink(sessioneDaLink(dettagli: ['tax_ids' => []]))->assertOk();

    $account = Account::query()->firstOrFail();

    expect($account->ragione_sociale)->toBe('Laboratorio Aurora')
        // `null`, non stringa vuota: «non dichiarata» e «dichiarata vuota» sono
        // la stessa cosa, e a database si scrive in un modo solo.
        ->and($account->partita_iva)->toBeNull();
});

// ─── Da dove si leggono i nomi ───────────────────────────────────────────────

it('never mistakes the business name for the referente, which is what Stripe puts in name', function () {
    // 🔴 **Il difetto del 5 Set 2026, colto sul fatto.** Con la raccolta
    // business attiva Stripe valorizza `customer_details.name` con la **ragione
    // sociale** («set to the `business_name` or `individual_name`, in that
    // order»), non col nome della persona. Leggendo `name` come referente
    // sarebbe nato un tenant col referente chiamato come l'ente — sbagliato in
    // silenzio, cioè nel modo peggiore. Il referente sta in `individual_name`.
    consegnaDaLink(sessioneDaLink())->assertOk();

    $riga = Registrazione::query()->firstOrFail();

    expect($riga->nome_ente)->toBe('Laboratorio Aurora')
        ->and($riga->nome_referente)->toBe('Marta Bianchi');
});

it('births the account even without a referente, which is a label and not an identity', function () {
    // 🔴 **Il referente non è indispensabile, e pretenderlo sarebbe rifiutare
    // dopo aver incassato.** Per creare un account servono un intestatario e una
    // casella a cui mandare l'invito; il nome della persona è un'etichetta, che
    // l'Admin corregge dall'anagrafica al primo accesso. Su un payload di
    // versione API vecchia `individual_name` non esiste nemmeno, quindi questo
    // sarebbe stato il caso **normale**, non un'eccezione.
    consegnaDaLink(sessioneDaLink(
        dettagli: ['individual_name' => null],
        raccolti: ['individual_name' => null],
    ))->assertOk();

    $riga = Registrazione::query()->firstOrFail();

    expect(Account::query()->count())->toBe(1)
        // Ripiega sulla ragione sociale: un'etichetta onesta, non una stringa
        // vuota né un «—» che finirebbe stampato in ogni email.
        ->and($riga->nome_referente)->toBe('Laboratorio Aurora');
});

it('still works when the webhook endpoint is pinned to an older Stripe API version', function () {
    // ⚠️ **Ogni endpoint webhook porta la propria versione API**, fissata quando
    // lo si è creato e indipendente da quella che il nostro SDK usa in uscita.
    // `business_name` e `individual_name` esistono solo da `2025-09-30.clover`:
    // un endpoint più vecchio consegna un payload che ha il solo `name`. Rifiutare
    // lì significherebbe **rifiutare dopo aver incassato**, per una ragione che
    // il cliente non può né vedere né correggere.
    consegnaDaLink(sessioneDaLink(
        dettagli: ['business_name' => null, 'individual_name' => null, 'name' => 'Laboratorio Aurora'],
        raccolti: ['business_name' => null, 'individual_name' => null],
    ))->assertOk();

    $account = Account::query()->firstOrFail();

    // L'account nasce, e il referente vale quanto il payload sa dire: un nome
    // approssimativo è meno grave di un pagamento senza consegna, e si corregge
    // dall'anagrafica.
    expect($account->ragione_sociale)->toBe('Laboratorio Aurora');
});

it('leaves a trace in the tracker when money came in and no account could be born', function () {
    // ⛔ Un 200 muto su un incasso che non è diventato un account sarebbe un
    // cliente perduto in silenzio: serve una persona, quindi serve una traccia
    // **durevole** — non `laravel.log`, che è disco effimero e per-replica.
    consegnaDaLink(sessioneDaLink(
        dettagli: ['business_name' => null, 'name' => null],
        raccolti: ['business_name' => null],
    ))->assertOk();

    expect(Errore::query()->count())->toBeGreaterThan(0);
});

it('survives an unexpected failure anywhere in the provisioning', function () {
    // ⛔ **Il test della dottrina, e il solo che tocca il `catch (Throwable)`.**
    // Gli altri negativi sollevano `RegistrazioneRifiutata`, che estende
    // `RuntimeException` come pure `QueryException`: restringere la cattura li
    // lascerebbe tutti verdi. Qui invece si rompe qualcosa di **imprevisto** —
    // un bug nostro, un driver che cambia, un `Error` di PHP — e la promessa
    // resta la stessa: 200 a Stripe, traccia nel tracker, endpoint vivo. Un 500
    // costerebbe l'endpoint, e con esso i lockout per insoluto.
    // ⚠️ Il guasto si inietta dove il codice **non** si aspetta un fallimento:
    // la notifica di benvenuto e l'invito sono già dentro `rescue()`, quindi
    // rompere lì non proverebbe niente. `CompletaRegistrazione` è `final` e non
    // si può sostituire con un doppio: si rompe allora ciò che risolve il piano,
    // cioè un `Error` sollevato da un binding che il container risolve.
    app()->bind(RegistrazioneDaPaymentLink::class, function () {
        throw new Error('Un guasto che nessuno aveva previsto.');
    });

    consegnaDaLink(sessioneDaLink())->assertOk();

    expect(Account::query()->count())->toBe(0)
        ->and(Errore::query()->count())->toBeGreaterThan(0);
});

it('says nothing about a payment link it does not know', function () {
    // Un plink creato a mano dalla dashboard di Stripe è un caso **legittimo**,
    // non un guasto: non ci riguarda, e la risposta è la stessa del
    // `registrazione_id` assente — 200 e nessuna scrittura.
    consegnaDaLink(sessioneDaLink(['payment_link' => 'plink_mai_visto']))->assertOk();

    expect(Registrazione::query()->count())->toBe(0)
        ->and(Account::query()->count())->toBe(0)
        ->and(Errore::query()->count())->toBe(0);
});

// ─── Il piano viene dal NOSTRO database ──────────────────────────────────────

it('resolves the plan from our own database and never from the payload', function () {
    // 🔴 **L'invariante che regge il prezzo di tutto.** I metadata viaggiano su
    // un dominio di terzi e si riscrivono dalla dashboard di Stripe: leggere di
    // là il piano significherebbe un Enterprise comprabile a 49 €. Qui il
    // payload mente apposta, e deve perdere.
    BancoRegistrazione::pianoDiProva('enterprise', 19900);

    consegnaDaLink(sessioneDaLink(['metadata' => [
        'easylab_piano' => 'enterprise',
        'piano' => 'enterprise',
    ]]))->assertOk();

    $account = Account::query()->firstOrFail();

    // Il plink della sessione è quello del `saas`: vince lui.
    expect($account->piano)->toBe('saas');
});

it('still resolves the plan from a payment link whose price is no longer current', function () {
    // Un plink si spegne quando il prezzo cambia, ma una sessione aperta un
    // minuto prima si completa **dopo**. Guardando la sola riga corrente quel
    // pagamento risulterebbe di nessun piano, e un incasso vero diventerebbe un
    // 200 muto — la stessa ragione per cui `prezzi_piano` conserva lo storico.
    PrezzoPiano::query()->where('stripe_payment_link_id', PLINK)->update(['corrente' => false]);
    BancoRegistrazione::dimentica();

    consegnaDaLink(sessioneDaLink())->assertOk();

    expect(Account::query()->where('piano', 'saas')->count())->toBe(1);
});

// ─── Idempotenza ─────────────────────────────────────────────────────────────

it('creates one account for one payment, however many times Stripe delivers it', function () {
    // Stripe ritenta: due consegne dello stesso evento sono lo scenario
    // **normale**, non l'eccezione. Due account per un pagamento sarebbero due
    // tenant, due Admin e una fattura sola.
    consegnaDaLink(sessioneDaLink())->assertOk();
    consegnaDaLink(sessioneDaLink())->assertOk();

    expect(Account::query()->count())->toBe(1)
        ->and(Registrazione::query()->count())->toBe(1)
        ->and(User::query()->where('email', 'marta@laboratorio-aurora.it')->count())->toBe(1);
});

it('never lets the public form steal a row that has already been to Stripe', function () {
    // ⛔ Una riga sintetizzata e **rifiutata** resta pendente col suo session id.
    // Se il modulo pubblico la riusasse, la riscriverebbe azzerando
    // `stripe_session_id`: si cancellerebbe l'unico legame fra un pagamento
    // incassato e la sua traccia, e l'audit di quel rifiuto punterebbe a un id
    // che non esiste più.
    // ⚠️ Solo l'interruttore del modulo, **non** `apri()`: il `beforeEach` ha
    // già dato al `saas` il suo price, e riseminarlo violerebbe l'unique.
    config(['easylab.registrazione.aperta' => true]);

    // Un utente esiste già con quell'email: il provisioning rifiuta *dopo*
    // l'incasso, ed è il caso che lascia la riga pendente.
    User::factory()->create(['email' => 'marta@laboratorio-aurora.it']);

    consegnaDaLink(sessioneDaLink())->assertOk();

    $sintetizzata = Registrazione::query()->where('stripe_session_id', 'cs_test_plink')->firstOrFail();

    $this->post(route('registrazione.avvia'), [
        'nome_ente' => 'Tentativo Altrui',
        'nome_referente' => 'Chi Passava',
        'email' => 'marta@laboratorio-aurora.it',
        'piano' => 'saas',
        'password' => 'ParolaSegreta!2026',
        'password_confirmation' => 'ParolaSegreta!2026',
    ]);

    expect($sintetizzata->fresh()->stripe_session_id)->toBe('cs_test_plink')
        ->and($sintetizzata->fresh()->nome_ente)->toBe('Laboratorio Aurora');
});

// ─── Il pagamento non riuscito ───────────────────────────────────────────────

it('never births an account from a session that was not actually paid', function () {
    // `status: complete` con `payment_status` diverso da `paid` è un pagamento
    // **non incassato** — vedi `EsitoCheckout`, dove le due condizioni vivono
    // insieme. La riga si scrive comunque (è la traccia del tentativo), ma
    // l'account no.
    consegnaDaLink(sessioneDaLink(['payment_status' => 'unpaid']))->assertOk();

    expect(Account::query()->count())->toBe(0);
});

// ─── Nessun piano gratuito da questa porta ───────────────────────────────────

it('never sells the free plan through a payment link', function () {
    // ⛔ Il Free non ha checkout da superare (ADR-002): un plink che lo vendesse
    // sarebbe l'unica porta del progetto da cui chiunque, senza pagare e senza
    // che nessuno lo autorizzi, si crea un Ente e un ruolo Admin. La difesa vera
    // sta in `GovernoListino`, che un plink al Free non lo crea mai; qui si
    // congela l'effetto sul listino appena seminato.
    $gratuiti = PrezzoPiano::query()
        ->whereHas('piano', fn ($q) => $q->where('gratuito', true))
        ->whereNotNull('stripe_payment_link_id')
        ->count();

    expect($gratuiti)->toBe(0)
        ->and(Piani::eGratuito('free'))->toBeTrue();
});

// ─── 🔴 Un indirizzo già in uso non si promuove (caccia T3, A2) ──────────────

it('never promotes an existing plain user to Admin of their own tenant by paying a link', function () {
    // Un Tecnico del laboratorio Beta, che non è membro di nessun account: il
    // ramo «amministra già» non lo vedeva, e `assignRole('Admin')` lo faceva
    // Admin del tenant in cui vive. Bastava pagare.
    $accountBeta = Account::factory()->saas()->create(['ragione_sociale' => 'Laboratorio Beta']);
    $enteBeta = UnitaOrganizzativa::factory()->ente()->perAccount($accountBeta)->create(['nome' => 'Beta']);
    $tecnico = User::factory()->create(['email' => 'luca@laboratorio-beta.it']);
    $tecnico->forceFill(['tenant_id' => $enteBeta->id])->save();
    $tecnico->assignRole('Tecnico');

    $entiPrima = UnitaOrganizzativa::query()->count();
    $erroriPrima = Errore::query()->count();

    consegnaDaLink(sessioneDaLink(dettagli: ['email' => 'luca@laboratorio-beta.it']))->assertOk();

    $tecnico->refresh();

    expect($tecnico->tenant_id)->toBe($enteBeta->id)
        ->and($tecnico->hasRole('Admin'))->toBeFalse()
        ->and($tecnico->accounts()->count())->toBe(0)
        // Nessun Account e nessun Ente orfano: il rifiuto viene prima di scrivere.
        ->and(Account::query()->count())->toBe(1)
        ->and(UnitaOrganizzativa::query()->count())->toBe($entiPrima)
        // La traccia del rifiuto dopo l'incasso: tracker e audit.
        ->and(Errore::query()->count())->toBeGreaterThan($erroriPrima)
        ->and(Activity::query()->where('description', CompletaRegistrazione::DESCRIZIONE_RIFIUTO)->count())->toBe(1);
});

it('recognises an existing user whatever the case Stripe collected the address in', function () {
    $esistente = User::factory()->create(['email' => 'luca@laboratorio-beta.it']);

    consegnaDaLink(sessioneDaLink(dettagli: ['email' => 'Luca@Laboratorio-Beta.IT']))->assertOk();

    // Riconosciuto e rifiutato: niente secondo utente con la stessa casella in
    // maiuscolo, e nessun ruolo nuovo a quello che c'era.
    expect(User::query()->count())->toBe(1)
        ->and($esistente->fresh()->hasRole('Admin'))->toBeFalse()
        ->and(Account::query()->count())->toBe(0)
        ->and(Activity::query()->where('description', CompletaRegistrazione::DESCRIZIONE_RIFIUTO)->count())->toBe(1);
});

it('never reuses the address of a trashed user, whatever its case', function () {
    User::factory()->create(['email' => 'luca@laboratorio-beta.it'])->delete();

    consegnaDaLink(sessioneDaLink(dettagli: ['email' => 'LUCA@laboratorio-beta.it']))->assertOk();

    expect(User::withTrashed()->count())->toBe(1)
        ->and(Account::query()->count())->toBe(0)
        ->and(Activity::query()->where('description', CompletaRegistrazione::DESCRIZIONE_RIFIUTO)->count())->toBe(1);
});

// ─── L'indirizzo come lo cerca Fortify (caccia T3, A9/B2) ────────────────────

it('lets the admin born from a payment link log in with the address typed at checkout', function () {
    Notification::fake();

    consegnaDaLink(sessioneDaLink(dettagli: ['email' => 'Marta.Bianchi@Laboratorio-Aurora.it']))->assertOk();

    $admin = User::query()->sole();

    expect($admin->email)->toBe('marta.bianchi@laboratorio-aurora.it')
        ->and(Registrazione::query()->sole()->email)->toBe('marta.bianchi@laboratorio-aurora.it');

    // L'invito imposta la password (il flusso d'invito va per id): qui a mano.
    $admin->forceFill(['password' => 'ParolaSegreta!2026'])->save();

    $this->post('/login', ['email' => 'Marta.Bianchi@Laboratorio-Aurora.it', 'password' => 'ParolaSegreta!2026']);

    $this->assertAuthenticatedAs($admin);
});

// ─── Il pagamento differito (caccia T3, A8/B6) ───────────────────────────────

it('births the account when an asynchronous payment settles after the checkout', function () {
    Notification::fake();

    // ⚠️ Dipende da `config/cashier.php`: senza l'evento in `webhook.events`
    // `handleWebhook()` lo scarta prima dell'handler. Lo si aggiunge qui per
    // provare l'handler; la presenza nel config ha il suo guardrail altrove.
    config(['cashier.webhook.events' => array_values(array_unique([
        ...config('cashier.webhook.events'), 'checkout.session.async_payment_succeeded',
    ]))]);

    // SEPA: `completed` arriva non incassato, e non nasce niente...
    consegnaDaLink(sessioneDaLink(['payment_status' => 'unpaid']))->assertOk();
    expect(Account::query()->count())->toBe(0);

    // ...poi l'incasso, con questo evento e la stessa sessione ora «paid».
    $incasso = sessioneDaLink();
    $incasso['type'] = 'checkout.session.async_payment_succeeded';
    consegnaDaLink($incasso)->assertOk();

    expect(Account::query()->count())->toBe(1)
        ->and(User::query()->where('email', 'marta@laboratorio-aurora.it')->exists())->toBeTrue();
});
