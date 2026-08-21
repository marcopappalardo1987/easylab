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

it('declares a monthly price for every plan in the catalogue', function () {
    // Si guarda la **config grezza**, non il getter: un `expect(...)->toBeInt()`
    // sarebbe garantito dal tipo di ritorno e non potrebbe fallire mai — era la
    // prima stesura, ed è la stessa forma di test-che-non-prova-nulla che questo
    // progetto ha già incontrato due volte.
    foreach (Piani::codici() as $codice) {
        expect(config("easylab.piani.catalogo.{$codice}"))->toHaveKey('prezzo_mensile_cent');
    }

    // I numeri sono ripetuti qui apposta, come i conteggi RBAC: se cambiano in
    // config devono cambiare anche qui, o uno dei due sta mentendo. Un listino è
    // un parametro commerciale, e cambiarlo dev'essere un gesto che si vede.
    expect(Piani::prezzoMensileCent('free'))->toBe(0)
        ->and(Piani::prezzoMensileCent('saas'))->toBe(4900);
});

it('never lets an omaggiato plan carry a price', function () {
    // ⚠️ **Una direzione sola, e non l'equivalenza.** «Gratuito» in ADR-002
    // significa «nessun customer, nessuna subscription» — è una dichiarazione
    // del catalogo, non una deduzione dal prezzo, e il docblock di `eGratuito()`
    // lo dice per esteso. L'invariante inversa («chi costa 0 è gratuito») era la
    // prima stesura e vietava un caso legittimo e vicino: un SaaS in promozione
    // a 0 €, che ha una subscription vera e deve restare `gratuito => false`.
    // Quando fosse caduta, la reazione naturale sarebbe stata cancellarla — e
    // una guardia che si cancella invece di correggerla non ha guardato niente.
    //
    // Questa direzione invece protegge l'MRR da denaro mai fatturato.
    foreach (Piani::codici() as $codice) {
        if (Piani::eGratuito($codice)) {
            expect(Piani::prezzoMensileCent($codice))->toBe(0);
        }
    }
});

it('refuses a plan declared by halves', function () {
    // Chiave **assente** ≠ valore `null` dichiarato: `stripe_price => null` sul
    // Free è legittimo, un piano senza `prezzo_mensile_cent` è scritto a metà —
    // e valeva zero euro in silenzio dentro una somma di denaro, finché il
    // confronto sul blocco A non l'ha trovato.
    config(['easylab.piani.catalogo.gold' => ['etichetta' => 'Gold', 'max_enti' => 9, 'gratuito' => false]]);

    expect(fn () => Piani::prezzoMensileCent('gold'))->toThrow(InvalidArgumentException::class)
        ->and(Piani::stripePrice('free'))->toBeNull();
});

it('refuses an unknown plan instead of guessing a limit', function () {
    // Il negativo che conta: un `?? 1` silenzioso trasformerebbe un dato
    // corrotto in un limite sbagliato applicato a un cliente vero.
    expect(fn () => Piani::maxEnti('gold'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Piani::prezzoMensileCent('gold'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Piani::etichetta('gold'))->toThrow(InvalidArgumentException::class)
        ->and(Piani::esiste('gold'))->toBeFalse()
        ->and(Piani::esiste(''))->toBeFalse();
});
