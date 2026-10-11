<?php

use App\Models\Account;
use App\Models\Piano;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Billing\PortaleStripe;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

/**
 * La pagina «Abbonamento» e il bottone che apre il Billing Portal ospitato di
 * Stripe (🔗 ADR-032 — l'intestatario è l'Account; ADR-013 — vi si arriva anche
 * da bloccati; ADR-002 — il piano Free non ha customer Stripe).
 *
 * ⚠️ **Il percorso felice verso Stripe non è provato qui, ed è dichiarato.**
 * Come per `easylab:abbona`, mockare `StripeClient` produrrebbe un test che
 * verifica il proprio mock. Si introduce invece una cucitura minima —
 * `App\Support\Billing\PortaleStripe` — e la suite prova tutto ciò che sta
 * **prima** della rete: autorizzazione, guardie, `returnUrl`, redirect. La
 * verifica vera è su staging con le chiavi test.
 *
 * 🔴 **Le due rotte vivono nel gruppo `auth` NUDO**, quindi non esiste nessun
 * `can:` a proteggerle: la guardia è `Gate::authorize('manage', $account)`
 * scritta dentro il componente e — riscritta, non ereditata — dentro il
 * controller. I negativi di questo file sono la sola rete su quella scelta, e
 * quello sul POST conta doppio: un POST arriva senza passare dalla pagina che
 * offre il bottone.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Le chiavi Stripe sono azzerate in phpunit.xml: si dà per configurato
    // l'ambiente, e il test che vuole il contrario se lo azzera da sé.
    config(['cashier.secret' => 'sk_test_finta']);

    $this->account = Account::factory()->saas()->conStripe()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->ente = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Centrale']);

    $this->membro = User::factory()->create(['tenant_id' => $this->ente->id]);
    $this->membro->assignRole('Admin');
    $this->account->aggiungiMembro($this->membro);
});

/**
 * Il finto della cucitura: registra i `returnUrl` ricevuti e non parla con
 * nessuno. `invocazioni` vuoto è l'asserzione che regge le guardie «prima della
 * rete» — senza, un test potrebbe passare per il motivo sbagliato.
 */
function portaleFinto(): object
{
    $fake = new class extends PortaleStripe
    {
        /** @var list<string> */
        public array $invocazioni = [];

        public function url(Account $account, string $returnUrl): string
        {
            $this->invocazioni[] = $returnUrl;

            return 'https://billing.stripe.test/sessione-finta';
        }
    };

    app()->instance(PortaleStripe::class, $fake);

    return $fake;
}

/**
 * Una riga in `subscriptions` scritta a mano, come in `AbbonaCommandTest`: la
 * suite non parla con Stripe, e `Account::subscription('default')` legge da
 * questa tabella e basta.
 *
 * ⚠️ `DB::table()` e non un factory: `Laravel\Cashier\Subscription` vive in
 * `vendor/`, dove il progetto non ha (né vuole) fixture proprie.
 */
function abbonamentoFinto(Account $account, string $stato, ?string $endsAt = null): void
{
    DB::table('subscriptions')->insert([
        'account_id' => $account->id,
        'type' => 'default',
        'stripe_id' => 'sub_'.$stato.'_'.$account->id,
        'stripe_status' => $stato,
        'stripe_price' => 'price_saas_test',
        'quantity' => 1,
        'ends_at' => $endsAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('shows the page to a member, with the plan and the form towards the portal', function () {
    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee('Abbonamento')
        ->assertSee('Gruppo Rossi')
        ->assertSee('SaaS')
        ->assertSee(route('abbonamento.portale'));
});

it('never shows a price on a page addressed to the paying customer', function () {
    // Il listino non è l'incassato, e nulla nel repository si accorgerebbe se
    // divergesse dal Price su Stripe: stamparlo qui significherebbe affermare
    // quanto paga il cliente senza saperlo. Le cifre vere stanno nel portale.
    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertDontSee('49,00')
        ->assertDontSee('4900');
});

it('never lets a user of the same Ente who is not a member open the page', function () {
    // 🔴 Il negativo che regge tutto: è la Policy a restringere, non il
    // permesso — che con `teams = false` è globale.
    $responsabile = User::factory()->create(['tenant_id' => $this->ente->id]);
    $responsabile->assignRole('Responsabile Reparto');

    $this->actingAs($responsabile)
        ->get(route('abbonamento.index'))
        ->assertForbidden();
});

it('never lets an Admin who is not a member of the account open the page', function () {
    // L'Admin il permesso ce l'ha (è globale): ciò che gli impedisce di aprire
    // il contratto di un altro cliente è solo l'appartenenza ad `account_user`.
    $adminEstraneo = User::factory()->create(['tenant_id' => $this->ente->id]);
    $adminEstraneo->assignRole('Admin');

    expect($adminEstraneo->can('billing.manage_own'))->toBeTrue();

    $this->actingAs($adminEstraneo)
        ->get(route('abbonamento.index'))
        ->assertForbidden();
});

it('never lets an Admin who is not a member open the portal with a POST', function () {
    // 🔴 Il gemello del test qui sopra, e NON è ridondante: un POST arriva
    // senza passare dalla pagina che offre il bottone. Il controller riscrive
    // la propria guardia, e questa è la sola rete su quella riscrittura.
    $fake = portaleFinto();

    $adminEstraneo = User::factory()->create(['tenant_id' => $this->ente->id]);
    $adminEstraneo->assignRole('Admin');

    $this->actingAs($adminEstraneo)
        ->post(route('abbonamento.portale'))
        ->assertForbidden();

    expect($fake->invocazioni)->toBeEmpty();
});

it('sends a guest to the login instead of the page', function () {
    $this->get(route('abbonamento.index'))->assertRedirect(route('login'));
});

it('refuses a POST from a guest', function () {
    $fake = portaleFinto();

    $this->post(route('abbonamento.portale'))->assertRedirect(route('login'));

    expect($fake->invocazioni)->toBeEmpty();
});

it('gives a 404 to a user without an Ente', function () {
    // Il tecnico esterno (ADR-030) non ha `tenant_id`: non c'è nessun contratto
    // da amministrare, e un 403 dichiarerebbe l'esistenza di qualcosa.
    $esterno = User::factory()->create(['tenant_id' => null]);
    $esterno->assignRole('Admin');

    $this->actingAs($esterno)
        ->get(route('abbonamento.index'))
        ->assertNotFound();
});

it('gives a 404 when the Ente has no account at all', function () {
    $orfano = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Sede Orfana']);
    $utente = User::factory()->create(['tenant_id' => $orfano->id]);
    $utente->assignRole('Admin');

    $this->actingAs($utente)
        ->get(route('abbonamento.index'))
        ->assertNotFound();
});

it('gives a 404 on the POST too when the Ente has no account', function () {
    $fake = portaleFinto();

    $orfano = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Sede Orfana']);
    $utente = User::factory()->create(['tenant_id' => $orfano->id]);
    $utente->assignRole('Admin');

    $this->actingAs($utente)
        ->post(route('abbonamento.portale'))
        ->assertNotFound();

    expect($fake->invocazioni)->toBeEmpty();
});

it('redirects to the Stripe session and always passes the return url explicitly', function () {
    // ⛔ Il cuore: il default di Cashier è `route('home')`, rotta che questa
    // applicazione NON ha (`config/fortify.php` dichiara un PERCORSO). Ometterlo
    // darebbe RouteNotFoundException, cioè un 500 sul bottone principale.
    $fake = portaleFinto();

    $this->actingAs($this->membro)
        ->post(route('abbonamento.portale'))
        ->assertRedirect('https://billing.stripe.test/sessione-finta');

    expect($fake->invocazioni)->toBe([route('abbonamento.index')]);
});

it('never touches Stripe for an account without a customer', function () {
    // Un account Free non ha customer **per definizione** (ADR-002): è un caso
    // normalissimo, e `billingPortalUrl()` ci lancerebbe sopra
    // (`assertCustomerExists()`). La guardia sta prima di ogni round-trip.
    $fake = portaleFinto();

    $this->account->forceFill(['stripe_id' => null])->save();

    $this->actingAs($this->membro)
        ->from(route('abbonamento.index'))
        ->post(route('abbonamento.portale'))
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento');

    expect($fake->invocazioni)->toBeEmpty();
});

it('hides the button and explains itself on a free plan without a customer', function () {
    $this->account->forceFill(['stripe_id' => null, 'piano' => 'free'])->save();

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertDontSee(route('abbonamento.portale'))
        ->assertSee("Un piano in comodato d'uso non ha un portale di fatturazione", false);
});

it('refuses before the network when the environment has no Stripe secret', function () {
    // La forma in cui questo si presenta su Laravel Cloud: `optimize` gira in
    // BUILD, quindi una variabile aggiunta dopo resta invisibile.
    $fake = portaleFinto();
    config(['cashier.secret' => null]);

    $this->actingAs($this->membro)
        ->from(route('abbonamento.index'))
        ->post(route('abbonamento.portale'))
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento');

    expect($fake->invocazioni)->toBeEmpty();
});

it('reports the failure instead of showing a blank 500 when Stripe refuses', function () {
    // `report()` e non un catch muto: il guasto finisce nell'error tracker
    // interno invece di sparire, e chi ha cliccato riceve una frase.
    app()->instance(PortaleStripe::class, new class extends PortaleStripe
    {
        public function url(Account $account, string $returnUrl): string
        {
            throw new RuntimeException('Stripe non risponde');
        }
    });

    $this->actingAs($this->membro)
        ->from(route('abbonamento.index'))
        ->post(route('abbonamento.portale'))
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento');
});

it('never shows the lock reason on a page a locked customer can reach', function () {
    // 🔴 PRIVACY. `locked_reason` e `stripe_lock_reason` sono annotazioni
    // operative interne (solleciti, riferimenti di pratica, id di evento
    // Stripe): ADR-013 li tiene fuori da /bloccato apposta, e questa è la
    // superficie NUOVA da cui potrebbero trapelare.
    //
    // ⚠️ Due `assertDontSee` separate e mai `toContain()` con due argomenti:
    // quel metodo è variadico e il secondo testo diventerebbe un secondo ago,
    // rendendo l'asserzione negativa soddisfatta sempre.
    $this->account->blocca('Sollecito n.3 pratica 42/2026');
    $this->account->bloccaPerStripe('Evento Stripe evt_riservato_9');

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertDontSee('Sollecito')
        ->assertDontSee('42/2026')
        ->assertDontSee('evt_riservato_9');
});

it('offers the menu entry to the member who administers the contract', function () {
    // ⚠️ 2FA confermato solo qui: la dashboard sta nel gruppo protetto, e un
    // Admin senza secondo fattore verrebbe spinto al setup prima di vedere il
    // menù. /abbonamento invece sta fuori da `two-factor.enforce` apposta —
    // vedi PortaleStripeInLockoutTest, dove quella differenza È il test.
    $this->membro->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->actingAs($this->membro->fresh())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('abbonamento.index'));
});

it('never offers the menu entry to a user of the same Ente who does not administer it', function () {
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');
    $this->account->aggiungiMembro($tenant);

    // Membro dell'account ma senza il permesso: la voce non deve comparire —
    // vederla e poterla usare devono essere la stessa domanda.
    $this->actingAs($tenant)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('abbonamento.index'));
});

/*
 |--------------------------------------------------------------------------
 | Lo STATO dell'abbonamento, che è il cuore prodotto della pagina
 |--------------------------------------------------------------------------
 |
 | 🔴 Prima del 28 Ago 2026 `statoAbbonamento()` non aveva **nessun** test:
 | ogni fixture creava un Account `conStripe()` senza mai una riga in
 | `subscriptions`, quindi tutti i test rendevano sempre e solo il ramo
 | `null => 'Nessun abbonamento'`. Conseguenza misurata: sostituire l'intero
 | `match` con `$account->subscribed('default') ? 'Attivo' : 'Nessun
 | abbonamento'` non rompeva una sola asserzione — e coi default di Cashier
 | (`$deactivatePastDue`) la pagina avrebbe detto «Nessun abbonamento» proprio
 | al cliente in dunning che sta cercando di pagare, cioè all'unica persona per
 | cui questa pagina esiste.
 */

it('reads the subscription state from stripe_status, never from subscribed()', function (string $stato, string $atteso) {
    abbonamentoFinto($this->account, $stato);

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee($atteso);
})->with([
    'attivo' => ['active', 'Attivo'],
    'in prova' => ['trialing', 'In prova'],
    // ⛔ Il caso che regge tutto: `valid()` lo direbbe NON abbonato.
    'insoluto in corso' => ['past_due', 'Stripe sta ritentando'],
    'non pagato' => ['unpaid', 'Chiuso'],
    'disdetto e chiuso' => ['canceled', 'Chiuso'],
    'mai partito' => ['incomplete', 'Mai partito'],
]);

it('never says «no subscription» to the customer Stripe is still retrying', function () {
    // Il negativo del caso `past_due`, scritto a parte perché è la riga che il
    // difetto attraverserebbe in silenzio: `subscribed()` passa da `valid()`,
    // che con `$deactivatePastDue` considera NON valido proprio questo stato.
    abbonamentoFinto($this->account, 'past_due');

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertDontSee('Nessun abbonamento');
});

it('says «no subscription» when there is no subscription row at all', function () {
    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee('Nessun abbonamento');
});

it('prints an unknown Stripe status verbatim instead of a blank page', function () {
    // Uno stato che Stripe introducesse domani non deve diventare una pagina
    // vuota: si stampa com'è, e chi legge ha di che chiamare.
    abbonamentoFinto($this->account, 'paused');

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee('paused');
});

it('tells the self-cancelled customer until when the subscription stays active', function () {
    // 🔴 È la riga che dà il titolo alla feature — «disdetta autonoma». Chi
    // disdice nel portale resta `active` con un `ends_at` futuro, e senza
    // questa frase leggerebbe soltanto «Attivo», cioè crederebbe di non aver
    // disdetto nulla.
    abbonamentoFinto($this->account, 'active', now()->addDays(12)->toDateTimeString());

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee('disdetto, attivo fino al '.now()->addDays(12)->format('d/m/Y'));
});

it('never claims a cancellation that has already expired', function () {
    // `ends_at` passato = la disdetta è già stata eseguita, e la subscription
    // è `canceled`: «attivo fino al» sarebbe falso al passato.
    abbonamentoFinto($this->account, 'canceled', now()->subDay()->toDateTimeString());

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee('Chiuso')
        ->assertDontSee('attivo fino al');
});

/*
 |--------------------------------------------------------------------------
 | Il limite di sedi: «illimitato» e «non lo so» non sono la stessa cosa
 |--------------------------------------------------------------------------
 */

it('never claims unlimited sedi on a plan that is not in the catalogue', function () {
    // `accounts.piano` è una stringa senza CHECK a DB e i piani fuori catalogo
    // sono uno stato governato (ADR-035). `Piani::maxEnti()` LANCIA su quel
    // codice — quindi `Account::slotEntiResidui()` esplode e la quarta sede non
    // viene concessa affatto: stampare «∞» affermerebbe al cliente il contrario
    // esatto di ciò che succede.
    $this->account->forceFill(['piano' => 'saas_legacy'])->save();

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertDontSee('∞')
        // Il ripiego dell'etichetta resta il codice grezzo, come già era.
        ->assertSee('saas_legacy');
});

it('still shows the infinity sign for a plan that declares no limit', function () {
    // La controprova: «∞» non è stato spento in generale — significa ancora
    // «illimitato», e solo quello.
    Piano::query()->where('codice', 'saas')->update(['max_enti' => null]);

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee('∞');
});

/*
 |--------------------------------------------------------------------------
 | L'errore che TORNA: il flash deve essere reso da chi lo riceve
 |--------------------------------------------------------------------------
 */

it('shows the failure to the customer who lands back on the subscription page', function () {
    // Non basta che il flash sia in sessione: prima del 28 Ago 2026 le tre
    // asserzioni si fermavano a `assertSessionHas`, e nessun test seguiva il
    // redirect — cancellando il blocco @if dalla vista la suite restava verde.
    app()->instance(PortaleStripe::class, new class extends PortaleStripe
    {
        public function url(Account $account, string $returnUrl): string
        {
            throw new RuntimeException('Stripe non risponde');
        }
    });

    $this->actingAs($this->membro)
        ->from(route('abbonamento.index'))
        ->post(route('abbonamento.portale'))
        ->assertRedirect(route('abbonamento.index'));

    // ⚠️ Ago senza apostrofi: `assertSee` escapa, e «non è stato possibile»
    // non ne contiene.
    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee('Non è stato possibile aprire il portale di fatturazione');
});

it('reports the Stripe failure to the internal error tracker', function () {
    // 🔴 La METÀ del nome che prima non poteva diventare rossa: senza questa
    // asserzione, sostituire `report($e)` con un catch muto lasciava il test
    // verde — il cliente leggeva la frase gentile e l'assistenza non vedeva
    // nulla in /piattaforma/errori, che è esattamente lo scenario che il
    // docblock del controller dice di voler evitare.
    Exceptions::fake();

    app()->instance(PortaleStripe::class, new class extends PortaleStripe
    {
        public function url(Account $account, string $returnUrl): string
        {
            throw new RuntimeException('Stripe non risponde');
        }
    });

    $this->actingAs($this->membro)
        ->from(route('abbonamento.index'))
        ->post(route('abbonamento.portale'))
        ->assertRedirect(route('abbonamento.index'));

    Exceptions::assertReported(RuntimeException::class);
});

/*
 |--------------------------------------------------------------------------
 | Il registro: aprire il portale è un ATTO, e lascia una riga
 |--------------------------------------------------------------------------
 */

it('writes an audit row when the portal is actually opened', function () {
    // Nel progetto anche il semplice download di un documento è tracciato
    // (`ScaricaDocumento`): aprire una sessione da cui si può disdire un
    // abbonamento non può essere l'unico gesto muto.
    portaleFinto();

    $this->actingAs($this->membro)
        ->post(route('abbonamento.portale'))
        ->assertRedirect('https://billing.stripe.test/sessione-finta');

    $riga = Activity::inLog(AuditLog::NAME)->where('description', 'Portale di fatturazione aperto')->sole();

    expect($riga->causer_id)->toBe($this->membro->id)
        ->and($riga->subject_id)->toBe($this->account->id);
});

it('never writes an audit row when the portal was not opened', function () {
    // La controprova: la riga descrive un ATTO compiuto, non un tentativo.
    portaleFinto();
    config(['cashier.secret' => null]);

    $this->actingAs($this->membro)
        ->from(route('abbonamento.index'))
        ->post(route('abbonamento.portale'));

    expect(Activity::inLog(AuditLog::NAME)->where('description', 'Portale di fatturazione aperto')->count())->toBe(0);
});

/*
 |--------------------------------------------------------------------------
 | 🔴 IMPERSONAZIONE — il portale di un ALTRO Account non si apre
 |--------------------------------------------------------------------------
 |
 | `Gate::authorize('manage', …)` risponde sull'utente della guard, e lab404
 | SOSTITUISCE quell'utente: dentro un'impersonazione la Policy risponde
 | sull'impersonato e dice di sì. Riprodotto il 28 Ago 2026: un Superadmin di
 | un altro Account otteneva un 302 verso una sessione di portale intestata al
 | customer del cliente — e da lì, con la disdetta abilitata (27 Ago 2026), se
 | ne poteva disdire l'abbonamento.
 */

/** Il Superadmin di un Account DIVERSO, che impersona l'Admin del cliente. */
function superadminDiUnAltroAccount(): User
{
    $staff = Account::factory()->create(['ragione_sociale' => 'EasyLab']);
    $sede = UnitaOrganizzativa::factory()->ente()->perAccount($staff)->create(['nome' => 'Sede Staff']);

    $super = User::factory()->create(['tenant_id' => $sede->id, 'two_factor_confirmed_at' => now()]);
    $super->assignRole('Superadmin');
    $staff->aggiungiMembro($super);

    return $super;
}

it('never opens the billing portal of another account while impersonating', function () {
    $fake = portaleFinto();
    $super = superadminDiUnAltroAccount();

    $this->actingAs($super)->get(route('impersonate', $this->membro->id));

    $this->from(route('abbonamento.index'))
        ->post(route('abbonamento.portale'))
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento');

    expect($fake->invocazioni)->toBeEmpty()
        ->and(Activity::inLog(AuditLog::NAME)->where('description', 'Portale di fatturazione aperto')->count())->toBe(0);
});

it('says why instead of offering a button it would refuse, while impersonating', function () {
    $super = superadminDiUnAltroAccount();

    $this->actingAs($super)->get(route('impersonate', $this->membro->id));

    $this->get(route('abbonamento.index'))
        ->assertOk()
        // Ago senza apostrofi: `assertSee` escapa l'ago, e la copy ne contiene.
        ->assertSee('non si apre durante')
        ->assertDontSee(route('abbonamento.portale'));
});

it('still opens the portal for the member who is really signed in', function () {
    // La controprova che la guardia dell'impersonazione è chirurgica e non ha
    // spento la feature.
    $fake = portaleFinto();

    $this->actingAs($this->membro)
        ->post(route('abbonamento.portale'))
        ->assertRedirect('https://billing.stripe.test/sessione-finta');

    expect($fake->invocazioni)->toBe([route('abbonamento.index')]);
});

it('logs the anomaly of a paying plan without a Stripe customer, and stays quiet on the free one', function () {
    // Le due situazioni dietro lo stesso `stripe_id` mancante non sono la
    // stessa cosa, e la differenza è ciò che il log esiste per registrare: sul
    // Free non c'è **niente da riparare** (ADR-002), su un piano a pagamento
    // c'è un account rotto che qualcuno deve sistemare. Senza questa
    // asserzione, fondere i due rami in un messaggio solo non rompeva nulla e
    // l'anomalia smetteva di essere segnalata a chi può rimediare.
    Log::spy();
    portaleFinto();

    $this->account->forceFill(['stripe_id' => null])->save();

    $this->actingAs($this->membro)
        ->from(route('abbonamento.index'))
        ->post(route('abbonamento.portale'));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $messaggio) => str_contains($messaggio, 'senza customer Stripe'))
        ->once();
});

it('never logs an anomaly for a free plan, which has no customer by definition', function () {
    Log::spy();
    portaleFinto();

    $this->account->forceFill(['stripe_id' => null, 'piano' => 'free'])->save();

    $this->actingAs($this->membro)
        ->from(route('abbonamento.index'))
        ->post(route('abbonamento.portale'))
        ->assertSessionHas('erroreAbbonamento');

    Log::shouldNotHaveReceived('warning');
});
