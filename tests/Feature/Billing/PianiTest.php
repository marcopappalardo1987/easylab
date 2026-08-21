<?php

use App\Support\Piani;

/**
 * Il catalogo dei piani (config/easylab.php ↔ App\Support\Piani).
 *
 * I numeri sono ripetuti qui apposta, come in `RbacSeederTest`: se cambiano in
 * config devono cambiare anche qui, o uno dei due sta mentendo. Un piano è un
 * parametro commerciale, e cambiarlo dev'essere un gesto che si vede.
 */
it('carries exactly the two V1 plans', function () {
    expect(Piani::codici())->toBe(['free', 'saas'])
        ->and(Piani::predefinito())->toBe('free');
});

it('caps the free plan at one ente', function () {
    expect(Piani::maxEnti('free'))->toBe(1)
        ->and(Piani::maxEnti('saas'))->toBe(5);
});

it('knows that the free plan never talks to Stripe', function () {
    // Non è una casella da riempire più avanti: un cliente Free è omaggiato a
    // fronte di un contratto fisico (ADR-002), quindi non ha né customer né
    // subscription. È da qui che passa la guardia di `easylab:abbona`.
    expect(Piani::eGratuito('free'))->toBeTrue()
        ->and(Piani::richiedeStripe('free'))->toBeFalse()
        ->and(Piani::stripePrice('free'))->toBeNull();
});

it('never mistakes a missing price id for a free plan', function () {
    // La distinzione che il comando `easylab:abbona` ha imposto: un piano a
    // pagamento con `STRIPE_PRICE_SAAS` non configurato è un errore di deploy
    // da segnalare per nome, non un piano gratuito. Dedurre la gratuità
    // dall'assenza del prezzo nasconderebbe la variabile dimenticata dietro un
    // messaggio rassicurante.
    config(['easylab.piani.catalogo.saas.stripe_price' => null]);

    expect(Piani::eGratuito('saas'))->toBeFalse()
        ->and(Piani::stripePrice('saas'))->toBeNull();
});

it('resolves a plan from its Stripe price', function () {
    config(['easylab.piani.catalogo.saas.stripe_price' => 'price_xyz']);

    expect(Piani::perPrice('price_xyz'))->toBe('saas');
});

it('returns no plan for a price outside the catalogo', function () {
    // Esito legittimo, non errore: un price creato a mano in dashboard o di un
    // piano dismesso non deve far esplodere un handler di webhook.
    expect(Piani::perPrice('price_mai_visto'))->toBeNull()
        ->and(Piani::perPrice(null))->toBeNull();
});

it('refuses an unknown plan instead of guessing a limit', function () {
    // Il negativo che conta: un `?? 1` silenzioso trasformerebbe un dato
    // corrotto in un limite sbagliato applicato a un cliente vero.
    expect(fn () => Piani::maxEnti('gold'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Piani::etichetta('gold'))->toThrow(InvalidArgumentException::class)
        ->and(Piani::esiste('gold'))->toBeFalse()
        ->and(Piani::esiste(''))->toBeFalse();
});
