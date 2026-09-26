<?php

use App\Models\Account;
use App\Models\Piano;
use App\Support\Listino\CatalogoPiani;
use Illuminate\Support\Facades\DB;

/**
 * Le guardie di `easylab:abbona` (ADR-002, ADR-032).
 *
 * ⚠️ **Il percorso felice non si prova qui, ed è una scelta dichiarata.** Il
 * comando parla con Stripe; mockare `StripeClient` produrrebbe un test che
 * verifica il proprio mock e non l'integrazione. Si verifica su staging con le
 * chiavi test — la procedura è in `Setup Repository e Ambienti.md`, e questo
 * file copre tutto ciò che deve fermarsi **prima** della rete.
 *
 * Che sia prima della rete non è un dettaglio: un comando che crea oggetti di
 * fatturazione non deve lasciare dietro di sé un customer a metà che poi
 * qualcuno dovrà ripulire a mano dalla dashboard.
 */
beforeEach(function () {
    $this->account = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);

    // Le chiavi sono azzerate in phpunit.xml: qui si dà per configurato tutto
    // tranne ciò che il singolo test vuole mancante.
    config(['cashier.secret' => 'sk_test_finta']);

    // ⚠️ Il price id **non** viene più da `config('easylab.piani...')`: dal 27
    // Ago 2026 (ADR-035) il listino vive a database, e il price corrente è una
    // riga di `prezzi_piano`. La riga si scrive qui perché la migration di
    // backfill ne crea una solo se `STRIPE_PRICE_SAAS` è valorizzata, e in
    // suite le chiavi Stripe sono azzerate da `phpunit.xml`.
    Piano::query()->where('codice', 'saas')->sole()->prezzi()->create([
        'stripe_price_id' => 'price_saas_test',
        'importo_cent' => 4900,
        'valuta' => 'eur',
        'corrente' => true,
    ]);

    app(CatalogoPiani::class)->dimentica();
});

it('fails on an account that does not exist', function () {
    $this->artisan('easylab:abbona', ['account' => '999'])
        ->expectsOutputToContain('Nessun account con id 999')
        ->assertFailed();
});

it('fails on a plan that is not in the catalogo', function () {
    $this->artisan('easylab:abbona', ['account' => (string) $this->account->id, '--piano' => 'gold'])
        ->expectsOutputToContain('non a catalogo')
        ->assertFailed();

    expect($this->account->fresh()->piano)->toBe('free');
});

it('refuses to subscribe a free plan, because that is what free means', function () {
    // Non è una casella vuota da riempire: il Free è omaggiato a fronte di un
    // contratto fisico (ADR-002), quindi non ha customer né subscription.
    $this->artisan('easylab:abbona', ['account' => (string) $this->account->id, '--piano' => 'free'])
        ->expectsOutputToContain('non ha un abbonamento Stripe')
        ->assertFailed();

    expect($this->account->fresh()->stripe_id)->toBeNull();
});

it('sends the operator to the listino when the plan has no price yet', function () {
    // *Questo test si chiamava «names the missing variable» e nominava
    // `STRIPE_PRICE_SAAS`.* Ha cambiato nome col listino a database (ADR-035):
    // la variabile d'ambiente è rimasta solo come **bootstrap** letto dalla
    // migration di backfill, quindi mandare l'operatore a valorizzarla
    // significherebbe mandarlo a modificare un file che non produce più alcun
    // effetto sul price di un piano già creato. Il gesto che ripara è
    // «Sincronizza» su /piattaforma/piani.
    //
    // La distinzione che il comando difende resta identica per intento: un
    // piano **a pagamento senza price** è un errore di configurazione da
    // segnalare per nome, non un piano gratuito.
    Piano::query()->where('codice', 'saas')->sole()->prezzi()->delete();
    app(CatalogoPiani::class)->dimentica();

    $this->artisan('easylab:abbona', ['account' => (string) $this->account->id])
        ->expectsOutputToContain('/piattaforma/piani')
        ->assertFailed();
});

it('says where to look when the Stripe secret is missing', function () {
    // La forma in cui questo si presenta su Laravel Cloud: `optimize` gira in
    // build, quindi una variabile aggiunta dopo resta invisibile. Il messaggio
    // rimanda lì invece di lasciare un errore SDK illeggibile.
    config(['cashier.secret' => null]);

    $this->artisan('easylab:abbona', ['account' => (string) $this->account->id])
        ->expectsOutputToContain('config:show cashier')
        ->assertFailed();
});

it('never opens a second subscription on an account that already has one', function (string $stato) {
    // ⚠️ Il negativo che conta davvero, e `past_due` è il motivo per cui la
    // guardia NON usa `subscribed()`: quel metodo passa da `valid()`, che con i
    // default di Cashier considera non valide `past_due` e `incomplete` —
    // lasciando passare proprio l'account con un insoluto in corso, cioè
    // quando qualcuno mette le mani su questo comando. Il risultato sarebbe
    // una seconda subscription **fatturata**.
    $this->account->update(['stripe_id' => 'cus_esistente']);

    DB::table('subscriptions')->insert([
        'account_id' => $this->account->id,
        'type' => 'default',
        'stripe_id' => 'sub_esistente',
        'stripe_status' => $stato,
        'stripe_price' => 'price_saas_test',
        'quantity' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('easylab:abbona', ['account' => (string) $this->account->id])
        ->expectsOutputToContain('sub_esistente')
        ->assertFailed();

    expect(DB::table('subscriptions')->count())->toBe(1)
        ->and($this->account->fresh()->piano)->toBe('free');
})->with(['active', 'past_due', 'incomplete', 'unpaid']);

it('lets a returning customer subscribe again after cancelling', function () {
    // ⚠️ Trovato su staging: la prima stesura della guardia usava
    // `subscriptions()->exists()` e rifiutava anche una subscription
    // `canceled` — che non fattura nulla. Un cliente che disdice e torna è un
    // caso normale, non un errore da bloccare.
    $this->account->update(['stripe_id' => 'cus_esistente']);

    DB::table('subscriptions')->insert([
        'account_id' => $this->account->id,
        'type' => 'default',
        'stripe_id' => 'sub_chiusa',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_saas_test',
        'quantity' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Passa la guardia e arriva alla rete, che in test non c'è: il comando
    // fallisce PIÙ AVANTI, e il messaggio lo dimostra.
    $this->artisan('easylab:abbona', ['account' => (string) $this->account->id, '--force' => true])
        ->doesntExpectOutputToContain('ha già una subscription attiva')
        ->assertFailed();
});
