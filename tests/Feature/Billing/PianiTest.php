<?php

use App\Models\Piano;
use App\Support\Listino\CatalogoPiani;
use App\Support\Piani;

/**
 * Il listino dei piani, letto dalla sua unica porta (`App\Support\Piani`).
 *
 * ⚠️ **Dal 27 Ago 2026 la fonte è il DATABASE** (ADR-035): questi test
 * scrivevano `config(['easylab.piani.catalogo...'])`, e ora scrivono righe.
 * L'intento è rimasto identico riga per riga — sono le viscere della porta ad
 * essere cambiate, non il suo contratto, che è precisamente ciò che questo file
 * esiste per congelare.
 *
 * ⚠️ **Dopo ogni scrittura serve `CatalogoPiani::dimentica()`.** Il memo è
 * per-richiesta e in un test la richiesta non finisce mai: senza, la porta
 * continuerebbe a leggere il listino caricato prima della scrittura, e il test
 * proverebbe il memo invece della regola.
 *
 * I numeri del bootstrap sono ripetuti qui apposta, come in `RbacSeederTest`: se
 * cambiano devono cambiare anche qui, o uno dei due sta mentendo. Un piano è un
 * parametro commerciale, e cambiarlo dev'essere un gesto che si vede.
 */

/** Scrive una riga di listino e invalida il memo, che è l'unica insidia del file. */
function unPiano(array $attributi): Piano
{
    $piano = new Piano;
    $piano->forceFill(array_merge([
        'etichetta' => 'Gold',
        'max_enti' => 9,
        'gratuito' => false,
        'prezzo_mensile_cent' => 9900,
        'valuta' => 'eur',
        'attivo' => true,
        'ordine' => 99,
    ], $attributi))->save();

    app(CatalogoPiani::class)->dimentica();

    return $piano;
}

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
    // pagamento senza price è un errore di configurazione da segnalare per nome,
    // non un piano gratuito. Dedurre la gratuità dall'assenza del prezzo
    // nasconderebbe il piano non sincronizzato dietro un messaggio rassicurante.
    //
    // In suite il caso è già quello di partenza: le chiavi Stripe sono azzerate
    // in `phpunit.xml`, quindi la migration di backfill non ha scritto nessuna
    // riga di `prezzi_piano`.
    expect(Piani::eGratuito('saas'))->toBeFalse()
        ->and(Piani::stripePrice('saas'))->toBeNull();
});

it('resolves a plan from its Stripe price', function () {
    Piani::modello('saas')->prezzi()->create([
        'stripe_price_id' => 'price_xyz',
        'importo_cent' => 4900,
        'valuta' => 'eur',
        'corrente' => true,
    ]);
    app(CatalogoPiani::class)->dimentica();

    expect(Piani::perPrice('price_xyz'))->toBe('saas')
        ->and(Piani::stripePrice('saas'))->toBe('price_xyz');
});

it('still resolves a price that is no longer the current one', function () {
    // 🔴 **La ragione per cui `prezzi_piano` è una tabella e non una colonna.**
    // Su Stripe un Price è immutabile: cambiare cifra ne crea uno nuovo, e chi
    // è già abbonato continua a fatturare sul vecchio. Se `perPrice()` guardasse
    // solo il corrente, al primo cambio di listino il webhook di **ogni cliente
    // vecchio** tornerebbe `null` e `accounts.piano` non si riallineerebbe più —
    // in silenzio, perché quel `null` è già oggi un esito legittimo che
    // `StripeWebhookController::applicaStato()` tratta come tale.
    $saas = Piani::modello('saas');
    $saas->prezzi()->create(['stripe_price_id' => 'price_vecchio', 'importo_cent' => 3900, 'valuta' => 'eur', 'corrente' => false]);
    $saas->prezzi()->create(['stripe_price_id' => 'price_nuovo', 'importo_cent' => 4900, 'valuta' => 'eur', 'corrente' => true]);
    app(CatalogoPiani::class)->dimentica();

    expect(Piani::perPrice('price_vecchio'))->toBe('saas')
        ->and(Piani::perPrice('price_nuovo'))->toBe('saas')
        // Il **corrente** però è uno solo: è quello su cui si aprono le
        // subscription nuove.
        ->and(Piani::stripePrice('saas'))->toBe('price_nuovo');
});

it('returns no plan for a price outside the catalogo', function () {
    // Esito legittimo, non errore: un price creato a mano in dashboard o di un
    // piano dismesso non deve far esplodere un handler di webhook.
    expect(Piani::perPrice('price_mai_visto'))->toBeNull()
        ->and(Piani::perPrice(null))->toBeNull()
        ->and(Piani::perPrice(''))->toBeNull();
});

it('declares a monthly price for every plan in the catalogue', function () {
    // I numeri sono ripetuti qui apposta, come i conteggi RBAC: se cambiano
    // devono cambiare anche qui, o uno dei due sta mentendo.
    expect(Piani::prezzoMensileCent('free'))->toBe(0)
        ->and(Piani::prezzoMensileCent('saas'))->toBe(4900);
});

it('never lets an omaggiato plan carry a price', function () {
    // ⚠️ **Una direzione sola, e non l'equivalenza.** «Gratuito» in ADR-002
    // significa «nessun customer, nessuna subscription» — è una dichiarazione
    // del listino, non una deduzione dal prezzo. L'invariante inversa («chi
    // costa 0 è gratuito») era la prima stesura e vietava un caso legittimo e
    // vicino: un SaaS in promozione a 0 €, che ha una subscription vera e deve
    // restare `gratuito => false`. Quando fosse caduta, la reazione naturale
    // sarebbe stata cancellarla — e una guardia che si cancella invece di
    // correggerla non ha guardato niente.
    //
    // Questa direzione invece protegge l'MRR da denaro mai fatturato. È fatta
    // valere da `GovernoListino::crea()`, e il suo negativo vive lì.
    foreach (Piani::codici() as $codice) {
        if (Piani::eGratuito($codice)) {
            expect(Piani::prezzoMensileCent($codice))->toBe(0);
        }
    }
});

it('no longer lets a plan be declared by halves at all', function () {
    // *Questo test si chiamava «refuses a plan declared by halves» e scriveva un
    // piano senza `prezzo_mensile_cent` in config, verificando che il getter
    // lanciasse.* Col listino a database quel piano non è più **esprimibile**:
    // la colonna è NOT NULL, e ciò che era una guardia in PHP è diventato un
    // vincolo di schema — la forma migliore, perché non si può dimenticare.
    // La prova sta in `SchemaListinoTest`; qui resta il fatto che ne discende.
    $gold = unPiano(['codice' => 'gold']);

    expect(Piani::prezzoMensileCent('gold'))->toBe(9900)
        ->and($gold->prezzo_mensile_cent)->not->toBeNull();
});

it('refuses an unknown plan instead of guessing a limit', function () {
    // Il negativo che conta: un `?? 1` silenzioso trasformerebbe un dato
    // corrotto in un limite sbagliato applicato a un cliente vero.
    expect(fn () => Piani::maxEnti('gold'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Piani::maxStrumenti('gold'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Piani::conteggioStrumenti('gold'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Piani::prezzoMensileCent('gold'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Piani::etichetta('gold'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Piani::modello('gold'))->toThrow(InvalidArgumentException::class)
        ->and(Piani::esiste('gold'))->toBeFalse()
        ->and(Piani::esiste(''))->toBeFalse();
});

it('keeps an archived plan in the catalogo, and only out of the offer', function () {
    // 🔴 Archiviare ≠ togliere dal catalogo, ed è la riga più facile da
    // sbagliare di tutta la feature. Se `codici()` filtrasse su `attivo`, ogni
    // account rimasto sul piano ritirato diventerebbe «fuori catalogo» in
    // `MetrichePiattaforma` — cioè varrebbe **0 €** nell'MRR, in silenzio e
    // tutti insieme.
    unPiano(['codice' => 'gold', 'attivo' => false]);

    expect(Piani::esiste('gold'))->toBeTrue()
        ->and(Piani::codici())->toContain('gold')
        ->and(Piani::prezzoMensileCent('gold'))->toBe(9900)
        ->and(Piani::offribili())->not->toContain('gold')
        ->and(Piani::offribili())->toContain('free');
});

it('orders the catalogo by ordine and then by id', function () {
    // ⚠️ Il tie-break sull'`id` non è pignoleria: a parità di chiave di
    // ordinamento l'ordine fra due righe è una **proprietà del motore**, e
    // SQLite e Postgres non concordano (CLAUDE.md, 25 Ago 2026). Senza,
    // l'ordine delle colonne dell'MRR cambierebbe fra locale e CI.
    unPiano(['codice' => 'terzo', 'ordine' => 1]);
    unPiano(['codice' => 'secondo', 'ordine' => 1]);

    // `free` e `saas` nascono dal backfill con ordine 1 e 2; i due qui sopra
    // hanno ordine 1 come `free`, quindi a decidere fra loro è solo l'id.
    expect(Piani::codici())->toBe(['free', 'terzo', 'secondo', 'saas']);
});
