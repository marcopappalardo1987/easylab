<?php

use App\Http\Controllers\SceltaPianoAbbonamento;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Billing\AbbonamentoGiaSuStripe;
use App\Support\Billing\AbbonamentoStripe;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Payment;
use Spatie\Activitylog\Models\Activity;
use Stripe\PaymentIntent;
use Tests\Support\BancoAbbonamento;

/**
 * Il cliente attiva un piano a pagamento, o passa a uno più grande, dalla
 * pagina «Abbonamento» (🔗 ADR-045; ADR-032 — l'intestatario è l'Account;
 * ADR-013 — vi si arriva anche da bloccati).
 *
 * ⚠️ **Il percorso felice verso Stripe non è provato qui, ed è dichiarato**,
 * come per `easylab:abbona` e per il Billing Portal: mockare `StripeClient`
 * produrrebbe un test che verifica il proprio mock. La cucitura è
 * `App\Support\Billing\AbbonamentoStripe`, e la suite prova tutto ciò che sta
 * **prima** della rete — che è dove vive la regola di prodotto. La verifica
 * vera è su staging con le chiavi di test.
 *
 * 🔴 **La rotta vive nel gruppo `auth` NUDO**: nessun `can:` la protegge, e la
 * guardia è `Gate::authorize('manage', $account)` scritta nel controller. I
 * negativi di questo file sono la sola rete su quella scelta.
 *
 * Il listino di prova: Free, SaaS 49 €, Pro 99 €, Enterprise 250 €. Le tre
 * cifre non sono una dentro l'altra («199,00» contiene «99,00»): i test della
 * pagina cercano gli importi nel testo, e una cifra contenuta in un'altra
 * renderebbe un `assertDontSee` rosso — o verde — per caso.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Le chiavi Stripe sono azzerate in phpunit.xml: si dà per configurato
    // l'ambiente, e il test che vuole il contrario se lo azzera da sé.
    config(['cashier.secret' => 'sk_test_finta']);

    BancoAbbonamento::piano('saas', 4900);
    BancoAbbonamento::piano('pro', 9900);
    BancoAbbonamento::piano('enterprise', 25000);

    $this->account = Account::factory()->create(['ragione_sociale' => 'Laboratorio Aurora']);
    $this->ente = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Aurora']);

    $this->membro = User::factory()->create(['tenant_id' => $this->ente->id]);
    $this->membro->assignRole('Admin');
    $this->account->aggiungiMembro($this->membro);

    $this->stripe = BancoAbbonamento::stripeFinto();
});

/** Il POST del form, come lo manda la pagina. */
function sceglie(string|array|null $piano, array $altro = [])
{
    return test()->post(route('abbonamento.piano'), array_merge(['piano' => $piano], $altro));
}

function operatoreDiUnAltroAccount(): User
{
    $staff = Account::factory()->create(['ragione_sociale' => 'EasyLab']);
    $sede = UnitaOrganizzativa::factory()->ente()->perAccount($staff)->create(['nome' => 'Sede Staff']);

    $super = User::factory()->create(['tenant_id' => $sede->id, 'two_factor_confirmed_at' => now()]);
    $super->assignRole('Superadmin');
    $staff->aggiungiMembro($super);

    return $super;
}

function righeAuditPiano(string $descrizione): int
{
    return Activity::inLog(AuditLog::NAME)->where('description', $descrizione)->count();
}

/*
 |--------------------------------------------------------------------------
 | Negativi — chi non può
 |--------------------------------------------------------------------------
 */

it('refuses a POST from a guest', function () {
    sceglie('pro')->assertRedirect(route('login'));

    expect($this->stripe->checkout)->toBe([]);
});

it('never lets an Admin who is not a member of the account buy for it', function () {
    // 🔴 Il negativo che conta di più: il POST arriva senza passare dalla
    // pagina, e l'Admin il permesso ce l'ha (è globale). A fermarlo è la sola
    // appartenenza ad `account_user`.
    $estraneo = User::factory()->create(['tenant_id' => $this->ente->id]);
    $estraneo->assignRole('Admin');

    $this->actingAs($estraneo);

    sceglie('pro')->assertForbidden();

    expect($this->stripe->checkout)->toBe([])
        ->and($this->account->fresh()->piano)->toBe('free');
});

it('never lets a user of the same Ente who does not administer the contract buy for it', function () {
    $responsabile = User::factory()->create(['tenant_id' => $this->ente->id]);
    $responsabile->assignRole('Responsabile Reparto');

    $this->actingAs($responsabile);

    sceglie('pro')->assertForbidden();

    expect($this->stripe->checkout)->toBe([]);
});

it('gives a 404 when there is no account to buy for', function () {
    $orfano = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Sede Orfana']);
    $utente = User::factory()->create(['tenant_id' => $orfano->id]);
    $utente->assignRole('Admin');

    $this->actingAs($utente);

    sceglie('pro')->assertNotFound();
});

it('never buys on behalf of the customer while impersonating', function () {
    // La Policy risponde sull'utente della guard, che impersonando è il
    // cliente: senza la guardia un operatore aprirebbe un pagamento a nome suo.
    $super = operatoreDiUnAltroAccount();

    $this->actingAs($super)->get(route('impersonate', $this->membro->id));

    sceglie('pro')
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento');

    expect($this->stripe->checkout)->toBe([])
        ->and(righeAuditPiano(SceltaPianoAbbonamento::AUDIT_CHECKOUT))->toBe(0);
});

it('never changes the plan of the customer while impersonating', function () {
    BancoAbbonamento::abbona($this->account, 'saas');
    $super = operatoreDiUnAltroAccount();

    $this->actingAs($super)->get(route('impersonate', $this->membro->id));

    sceglie('pro', ['conferma' => '1'])->assertSessionHas('erroreAbbonamento');

    expect($this->stripe->cambi)->toBe([])
        ->and($this->account->fresh()->piano)->toBe('saas');
});

it('refuses before the network when the environment has no Stripe secret', function () {
    config(['cashier.secret' => null]);

    $this->actingAs($this->membro);

    sceglie('pro')
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento');

    expect($this->stripe->checkout)->toBe([]);
});

/*
 |--------------------------------------------------------------------------
 | 🔴 La regola di prodotto, rifatta sul POST
 |--------------------------------------------------------------------------
 |
 | La pagina non mostra questi piani; ma un `piano=` scritto a mano nel corpo
 | della richiesta non è passato da nessun bottone. Ognuno di questi test
 | resta verde solo finché il controller rifà la domanda.
 */

it('never lets a paying customer go back to a free plan', function () {
    BancoAbbonamento::abbona($this->account, 'pro');

    $this->actingAs($this->membro);

    sceglie('free', ['conferma' => '1'])
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento', 'Il piano scelto non è disponibile per questo account.');

    expect($this->stripe->cambi)->toBe([])
        ->and($this->stripe->checkout)->toBe([])
        ->and($this->account->fresh()->piano)->toBe('pro');
});

it('never lets a paying customer go back to a plan that costs less', function () {
    BancoAbbonamento::abbona($this->account, 'pro');

    $this->actingAs($this->membro);

    sceglie('saas', ['conferma' => '1'])
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento', 'Il piano scelto non è disponibile per questo account.');

    expect($this->stripe->cambi)->toBe([])
        ->and($this->stripe->checkout)->toBe([])
        ->and($this->account->fresh()->piano)->toBe('pro');
});

it('never lets a free account take a free plan through the checkout', function () {
    BancoAbbonamento::piano('omaggio', 2900, ['gratuito' => true]);

    $this->actingAs($this->membro);

    foreach (['free', 'omaggio'] as $gratuito) {
        sceglie($gratuito)->assertSessionHas('erroreAbbonamento');
    }

    expect($this->stripe->checkout)->toBe([])
        ->and($this->account->fresh()->piano)->toBe('free');
});

it('never lets a cancelled customer come back on a smaller plan', function () {
    BancoAbbonamento::abbona($this->account, 'pro', 'canceled');
    $this->account->forceFill(['piano' => 'free'])->save();

    $this->actingAs($this->membro);

    sceglie('saas')->assertSessionHas('erroreAbbonamento');

    expect($this->stripe->checkout)->toBe([]);
});

it('refuses a plan that is archived, unknown or not a string', function (mixed $piano) {
    BancoAbbonamento::piano('ritirato', 14900, ['attivo' => false]);

    $this->actingAs($this->membro);

    sceglie($piano)
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento');

    expect($this->stripe->checkout)->toBe([]);
})->with([
    'archiviato' => 'ritirato',
    'inesistente' => 'platino',
    'assente' => null,
    'vuoto' => '',
    'un array' => [['pro']],
]);

it('refuses with the reason when the account cannot buy right now', function () {
    // Insoluto in corso: prima si salda, poi si cambia. E nessun checkout —
    // aprirne uno creerebbe un secondo abbonamento accanto a quello in dunning.
    BancoAbbonamento::abbona($this->account, 'saas', 'past_due');

    $this->actingAs($this->membro);

    sceglie('pro', ['conferma' => '1'])
        ->assertSessionHas('erroreAbbonamento', fn (string $m) => str_contains($m, 'pagamento in sospeso'));

    expect($this->stripe->cambi)->toBe([])
        ->and($this->stripe->checkout)->toBe([])
        ->and($this->account->fresh()->piano)->toBe('saas');
});

it('never sells to an account suspended by hand', function () {
    $this->account->blocca('Contenzioso: pratica 4471.');

    $this->actingAs($this->membro);

    sceglie('pro')
        ->assertSessionHas('erroreAbbonamento', fn (string $m) => ! str_contains($m, 'Contenzioso'));

    expect($this->stripe->checkout)->toBe([]);
});

/*
 |--------------------------------------------------------------------------
 | Attivazione — chi non ha un abbonamento in corso
 |--------------------------------------------------------------------------
 */

it('sends a free account to the Stripe checkout of the chosen plan', function () {
    $this->actingAs($this->membro);

    sceglie('pro')->assertRedirect($this->stripe->url);

    expect($this->stripe->checkout)->toHaveCount(1)
        ->and($this->stripe->checkout[0]['account_id'])->toBe($this->account->id)
        ->and($this->stripe->checkout[0]['piano'])->toBe('pro')
        // Il price del NOSTRO listino, risolto dal codice: dal form arriva solo
        // una stringa.
        ->and($this->stripe->checkout[0]['price'])->toBe('price_pro')
        ->and($this->stripe->cambi)->toBe([]);
});

it('always passes both return urls explicitly', function () {
    // ⛔ Il default di Cashier è `route('home')`, che in questa applicazione
    // non esiste: ometterli sarebbe un 500 sul bottone che porta denaro.
    $this->actingAs($this->membro);

    sceglie('saas');

    expect($this->stripe->checkout[0]['success_url'])->toBe(route('abbonamento.index', ['checkout' => 'ok']))
        ->and($this->stripe->checkout[0]['cancel_url'])->toBe(route('abbonamento.index', ['checkout' => 'annullato']));
});

it('never writes the plan when the checkout is only opened', function () {
    // 🔴 Aprire un checkout non è pagare. Un account marcato «pro» qui sarebbe
    // un cliente che risulta pagante e non paga: il piano lo scrive il webhook.
    $this->actingAs($this->membro);

    sceglie('pro');

    expect($this->account->fresh()->piano)->toBe('free');
});

it('writes an audit row when the checkout is opened, naming who and which plan', function () {
    $this->actingAs($this->membro);

    sceglie('pro');

    $riga = Activity::inLog(AuditLog::NAME)->where('description', SceltaPianoAbbonamento::AUDIT_CHECKOUT)->sole();

    expect($riga->causer_id)->toBe($this->membro->id)
        ->and($riga->subject_id)->toBe($this->account->id)
        ->and($riga->properties['piano'])->toBe('pro');
});

it('lets a customer closed out after a cancellation pay again from the locked account', function () {
    // È la ragione per cui la rotta sta fuori da `account.lockout`: chi è
    // chiuso per disdetta deve poter riattivare da solo.
    BancoAbbonamento::abbona($this->account, 'saas', 'canceled');
    $this->account->forceFill(['piano' => 'free'])->save();
    $this->account->bloccaPerStripe('Stripe: abbonamento cancellato.');

    $this->actingAs($this->membro);

    sceglie('saas')->assertRedirect($this->stripe->url);

    expect($this->stripe->checkout)->toHaveCount(1);
});

it('reports the failure instead of showing a blank 500 when Stripe refuses the checkout', function () {
    Exceptions::fake();
    $this->stripe->guasto = new RuntimeException('Stripe non risponde');

    $this->actingAs($this->membro);

    sceglie('pro')
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento');

    Exceptions::assertReported(RuntimeException::class);

    expect(righeAuditPiano(SceltaPianoAbbonamento::AUDIT_CHECKOUT))->toBe(0);
});

it('opens no checkout and calls for a human when Stripe already has a subscription', function () {
    // Lo specchio locale è indietro (webhook in ritardo o non consegnati):
    // senza questo ramo ogni clic aprirebbe un abbonamento in più.
    Exceptions::fake();
    $this->stripe->guasto = AbbonamentoGiaSuStripe::per($this->account->id, 'sub_viva', 'active');

    $this->actingAs($this->membro);

    sceglie('pro')
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento', fn (string $m) => str_contains($m, 'già un abbonamento in corso'));

    Exceptions::assertReported(AbbonamentoGiaSuStripe::class);
});

/*
 |--------------------------------------------------------------------------
 | 🔴 La partita IVA si inserisce al checkout, ed è obbligatoria
 |--------------------------------------------------------------------------
 |
 | La chiamata a Stripe non si prova in suite; ciò che le si CHIEDE sì, perché
 | `AbbonamentoStripe::opzioniCheckout()` lo costruisce senza toccare la rete.
 | È lì che vive la decisione di prodotto, ed è una riga sola: toglierla
 | lascerebbe verde tutto il resto.
 */

it('requires the VAT number at the activation checkout', function () {
    $opzioni = (new AbbonamentoStripe)->opzioniCheckout($this->account, 'pro', 'https://easylab.test/ok', 'https://easylab.test/ko');

    expect($opzioni['tax_id_collection'])->toBe(['enabled' => true, 'required' => 'if_supported']);
});

it('asks Stripe for what the VAT collection needs on an existing customer', function () {
    // Su un customer che esiste già Stripe ESIGE `customer_update.name = auto`
    // quando la raccolta è accesa: senza, la sessione non si crea affatto.
    // L'indirizzo serve a sapere in che paese sta il cliente — cioè se la
    // partita IVA va chiesta — e salvarlo lo mette in fattura.
    $opzioni = (new AbbonamentoStripe)->opzioniCheckout($this->account, 'pro', 'https://easylab.test/ok', 'https://easylab.test/ko');

    expect($opzioni['customer_update'])->toBe(['name' => 'auto', 'address' => 'auto'])
        ->and($opzioni['billing_address_collection'])->toBe('required');
});

it('carries the return urls and the references of the account into the session', function () {
    $opzioni = (new AbbonamentoStripe)->opzioniCheckout($this->account, 'pro', 'https://easylab.test/ok', 'https://easylab.test/ko');

    expect($opzioni['success_url'])->toBe('https://easylab.test/ok')
        ->and($opzioni['cancel_url'])->toBe('https://easylab.test/ko')
        ->and($opzioni['metadata'])->toBe(['account_id' => (string) $this->account->id, 'piano' => 'pro']);
});

it('tells the customer, before the checkout, that the VAT number will be asked', function () {
    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee('ti vengono chiesti la partita IVA');
});

it('does not announce the VAT number where there is no checkout to go through', function () {
    // Sul cambio non c'è nessun checkout: il prezzo cambia sull'abbonamento
    // che c'è, e la partita IVA quel cliente l'ha già data pagando la prima
    // volta.
    BancoAbbonamento::abbona($this->account, 'saas');

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertDontSee('ti vengono chiesti la partita IVA');
});

/*
 |--------------------------------------------------------------------------
 | Cambio — chi sta già pagando
 |--------------------------------------------------------------------------
 */

it('moves a paying customer to a bigger plan by changing the price of its subscription', function () {
    $sub = BancoAbbonamento::abbona($this->account, 'saas');

    $this->actingAs($this->membro);

    sceglie('pro', ['conferma' => '1'])
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('esitoAbbonamento');

    expect($this->stripe->cambi)->toBe([['subscription' => $sub, 'price' => 'price_pro']])
        // 🔴 Mai un checkout per chi ha già un abbonamento: ne nascerebbe un
        // secondo, fatturato accanto al primo.
        ->and($this->stripe->checkout)->toBe([])
        ->and($this->account->fresh()->piano)->toBe('pro');
});

it('asks for an explicit confirmation before committing the customer to pay more', function () {
    BancoAbbonamento::abbona($this->account, 'saas');

    $this->actingAs($this->membro);

    sceglie('pro')
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento', fn (string $m) => str_contains($m, 'conferma'));

    expect($this->stripe->cambi)->toBe([])
        ->and($this->account->fresh()->piano)->toBe('saas');
});

it('writes an audit row for the change, with the plan it left and the one it took', function () {
    BancoAbbonamento::abbona($this->account, 'saas');

    $this->actingAs($this->membro);

    sceglie('enterprise', ['conferma' => '1']);

    $riga = Activity::inLog(AuditLog::NAME)->where('description', SceltaPianoAbbonamento::AUDIT_CAMBIO)->sole();

    expect($riga->causer_id)->toBe($this->membro->id)
        ->and($riga->subject_id)->toBe($this->account->id)
        ->and($riga->properties['da'])->toBe('saas')
        ->and($riga->properties['a'])->toBe('enterprise');
});

it('leaves the plan alone when Stripe has not confirmed the change yet', function (string $stato) {
    // Come nel webhook: il piano si scrive solo su un abbonamento sano. Uno
    // stato diverso significa che Stripe aspetta ancora qualcosa.
    BancoAbbonamento::abbona($this->account, 'saas');
    $this->stripe->statoDopoIlCambio = $stato;

    $this->actingAs($this->membro);

    sceglie('pro', ['conferma' => '1'])->assertRedirect(route('abbonamento.index'));

    expect($this->stripe->cambi)->toHaveCount(1)
        ->and($this->account->fresh()->piano)->toBe('saas');
})->with(['past_due', 'incomplete']);

it('leaves the plan alone and reports when Stripe refuses the change', function () {
    Exceptions::fake();
    BancoAbbonamento::abbona($this->account, 'saas');
    $this->stripe->guasto = new RuntimeException('Stripe non risponde');

    $this->actingAs($this->membro);

    sceglie('pro', ['conferma' => '1'])
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento');

    Exceptions::assertReported(RuntimeException::class);

    expect($this->account->fresh()->piano)->toBe('saas')
        ->and(righeAuditPiano(SceltaPianoAbbonamento::AUDIT_CAMBIO))->toBe(0);
});

it('sends the customer to the portal when Stripe asks to confirm the payment', function () {
    // Il caso che `swap()` non dovrebbe incontrare (nessun addebito immediato),
    // ma che Stripe può sollevare: la frase deve dire DOVE si conferma, perché
    // la pagina di conferma di Cashier in questa applicazione non è registrata.
    Exceptions::fake();
    BancoAbbonamento::abbona($this->account, 'saas');
    $this->stripe->guasto = new IncompletePayment(new Payment(new PaymentIntent('pi_finto')));

    $this->actingAs($this->membro);

    sceglie('pro', ['conferma' => '1'])
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento', fn (string $m) => str_contains($m, 'portale di fatturazione'));

    Exceptions::assertReported(IncompletePayment::class);

    expect($this->account->fresh()->piano)->toBe('saas');
});

it('answers a double click on the change without contradicting the first one', function () {
    // La prima richiesta ha già fatto tutto: la seconda trova il piano fuori
    // dall'offerta, e dirle «non disponibile» smentirebbe un gesto riuscito.
    BancoAbbonamento::abbona($this->account, 'saas');

    $this->actingAs($this->membro);

    sceglie('pro', ['conferma' => '1']);
    sceglie('pro', ['conferma' => '1'])
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('esitoAbbonamento')
        ->assertSessionMissing('erroreAbbonamento');

    expect($this->stripe->cambi)->toHaveCount(1);
});

/*
 |--------------------------------------------------------------------------
 | /bloccato — dove chi ha disdetto trova la strada per riattivare
 |--------------------------------------------------------------------------
 |
 | Il portale salda un insoluto, ma dopo una disdetta non c'è niente da
 | saldare: c'è un piano da riattivare, e si fa da /abbonamento. Chi è chiuso
 | fuori vede solo /bloccato, quindi il link deve stare lì.
 */

it('points the locked member to the subscription page, where a plan is reactivated', function () {
    BancoAbbonamento::abbona($this->account, 'saas', 'canceled');
    $this->account->forceFill(['piano' => 'free'])->save();
    $this->account->bloccaPerStripe('Stripe: abbonamento cancellato.');

    $this->actingAs($this->membro)
        ->get(route('bloccato'))
        ->assertOk()
        ->assertSee(route('abbonamento.index'))
        ->assertSee('Da lì si riattiva un piano dopo una disdetta.');
});

it('never points to the subscription page someone who does not administer the contract', function () {
    $this->account->bloccaPerStripe('Stripe: abbonamento cancellato.');

    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');

    $this->actingAs($tenant)
        ->get(route('bloccato'))
        ->assertOk()
        ->assertDontSee(route('abbonamento.index'));
});

it('never points an impersonating operator to a page where it could not buy anyway', function () {
    $this->account->bloccaPerStripe('Stripe: abbonamento cancellato.');
    $super = operatoreDiUnAltroAccount();

    $this->actingAs($super)->get(route('impersonate', $this->membro->id));

    $this->get(route('bloccato'))
        ->assertOk()
        ->assertDontSee(route('abbonamento.index'));
});

/*
 |--------------------------------------------------------------------------
 | La rotta
 |--------------------------------------------------------------------------
 */

it('keeps the route outside the lockout and the two factor gate, and behind a throttle', function () {
    // Posizionale, come il portale (`PortaleStripeInLockoutTest`): la rotta sta
    // accanto a /bloccato. Dentro `account.lockout` chi è chiuso per disdetta
    // non potrebbe riattivare; dentro `two-factor.enforce` finirebbe in un
    // vicolo cieco verso /bloccato.
    $middleware = collect(Route::getRoutes()->getByName('abbonamento.piano')->gatherMiddleware());

    expect($middleware)->toContain('auth')
        ->and($middleware)->not->toContain('account.lockout')
        ->and($middleware)->not->toContain('two-factor.enforce')
        // ⛔ Ogni POST può aprire una sessione di Checkout su Stripe.
        ->and($middleware)->toContain('throttle:10,1');
});

it('stops the eleventh request in the same minute', function () {
    $this->actingAs($this->membro);

    foreach (range(1, 10) as $giro) {
        sceglie('pro')->assertRedirect($this->stripe->url);
    }

    sceglie('pro')->assertStatus(429);

    expect($this->stripe->checkout)->toHaveCount(10);
});

/*
 |--------------------------------------------------------------------------
 | La pagina
 |--------------------------------------------------------------------------
 */

it('shows a free account the paid plans on sale, with what each one costs', function () {
    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee('Attiva un piano')
        // La scheda del Free non può più dire «nulla da gestire qui» due righe
        // sopra i piani attivabili.
        ->assertSee('scegline uno qui sotto')
        ->assertDontSee('nulla da gestire qui')
        ->assertSee(route('abbonamento.piano'))
        ->assertSeeInOrder(['data-piano="saas"', '49,00', 'data-piano="pro"', '99,00', 'data-piano="enterprise"', '250,00'], false)
        // Nessun piano gratuito fra quelli in vendita.
        ->assertDontSee('data-piano="free"', false);
});

it('never shows a paying customer the free plan or a cheaper one among the upgrades', function () {
    BancoAbbonamento::abbona($this->account, 'pro');

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee('Passa a un piano più grande')
        ->assertSee('data-piano="enterprise"', false)
        ->assertDontSee('data-piano="free"', false)
        ->assertDontSee('data-piano="saas"', false)
        ->assertDontSee('data-piano="pro"', false)
        // E nessuna cifra su ciò che il cliente paga: del suo piano la pagina
        // stampa il nome, non l'importo.
        ->assertDontSee('99,00')
        ->assertDontSee('49,00');
});

it('asks for the confirmation only where the click changes a price', function () {
    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertDontSee('name="conferma"', false);

    BancoAbbonamento::abbona($this->account, 'saas');

    // ⚠️ `fresh()`: l'utente della prima richiesta porta in memoria l'account
    // con gli abbonamenti già letti (nessuno). In produzione ogni richiesta
    // rilegge; qui le due richieste condividono l'istanza.
    $this->actingAs($this->membro->fresh())
        ->get(route('abbonamento.index'))
        ->assertSee('name="conferma"', false);
});

it('puts first, and marks, the plan the platform proposed', function () {
    $this->account->proponiPiano('enterprise');

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSeeInOrder(['data-piano="enterprise"', 'Proposto da EasyLab', 'data-piano="saas"', 'data-piano="pro"'], false);
});

it('shows the plans without any button while impersonating', function () {
    $super = operatoreDiUnAltroAccount();

    $this->actingAs($super)->get(route('impersonate', $this->membro->id));

    $this->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee('data-piano="pro"', false)
        ->assertDontSee(route('abbonamento.piano'));
});

it('offers no button on an environment without the Stripe secret', function () {
    config(['cashier.secret' => null]);

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertDontSee(route('abbonamento.piano'));
});

it('says why instead of offering plans when the account cannot buy right now', function () {
    BancoAbbonamento::abbona($this->account, 'saas', 'past_due');

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee('pagamento in sospeso')
        ->assertDontSee(route('abbonamento.piano'));
});

it('never claims the payment was received just because the url says so', function () {
    // Il parametro lo scrive il ritorno da Stripe, ma chiunque può digitarlo:
    // la pagina parla al condizionale e il piano resta quello che è.
    $this->actingAs($this->membro)
        ->get(route('abbonamento.index', ['checkout' => 'ok']))
        ->assertOk()
        ->assertSee('Se hai completato il pagamento')
        ->assertDontSee('Pagamento ricevuto');

    expect($this->account->fresh()->piano)->toBe('free');
});

it('ignores a return parameter it does not know', function () {
    $this->actingAs($this->membro)
        ->get(route('abbonamento.index', ['checkout' => '<script>']))
        ->assertOk()
        ->assertDontSee('Se hai completato il pagamento')
        ->assertDontSee('Hai lasciato il pagamento');
});
