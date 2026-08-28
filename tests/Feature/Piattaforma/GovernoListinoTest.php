<?php

use App\Models\Piano;
use App\Support\AuditLog;
use App\Support\Listino\CatalogoPiani;
use App\Support\Listino\GovernoListino;
use App\Support\Listino\Stripe\PortaListinoStripe;
use App\Support\Piani;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Fakes\PortaListinoStripeFinta;

/**
 * 🔴 Le regole del listino, provate sul motore invece che sulla schermata
 * (🔗 ADR-035).
 *
 * ## Perché un file separato da `ListinoTest`
 *
 * Sul modello di `MatriceRuoliTest` rispetto a `ToggleRuoliTest`: **le guardie
 * sono la feature**, e la schermata è solo il primo dei loro chiamanti. Due
 * delle regole più costose — il `codice` e la `gratuità` immutabili — la pagina
 * non le offre nemmeno: i campi non ci sono. Provarle attraverso la UI
 * significherebbe non provarle affatto, mentre chi le romperà davvero è un
 * comando di console, un webhook, o la schermata di domani.
 *
 * ⚠️ **Dopo ogni scrittura fatta a mano serve `CatalogoPiani::dimentica()`.** Il
 * memo è per-richiesta e in un test la richiesta non finisce mai: senza, si
 * proverebbe il memo invece della regola. `GovernoListino` lo fa da sé dopo ogni
 * gesto; i fixture scritti a mano no.
 */
beforeEach(function () {
    $this->porta = new PortaListinoStripeFinta;
    app()->instance(PortaListinoStripe::class, $this->porta);
});

// ─── Creare ──────────────────────────────────────────────────────────────────

it('writes the row and leaves a line in the audit register', function () {
    // ⚠️ L'audit lo produce il trait `AuditsDomainWrites` su `Piano`, quindi in
    // `GovernoListino` non c'è **nessuna** `activity()`: la regola «o il trait o
    // le esplicite, mai entrambi» (ADR-027) è resa meccanica da
    // `AuditCoverageGuardrailTest`, che legge il sorgente del model. Qui si
    // congela l'effetto — che una riga ci sia, e sul canale giusto.
    GovernoListino::crea([
        'codice' => 'enterprise',
        'etichetta' => 'Enterprise',
        'max_enti' => null,
        'prezzo_mensile_cent' => 19900,
    ]);

    $riga = Activity::query()
        ->where('log_name', AuditLog::NAME)
        ->where('description', 'Creazione piano')
        ->latest('id')
        ->first();

    expect($riga)->not->toBeNull()
        ->and(Piano::query()->where('codice', 'enterprise')->exists())->toBeTrue();
});

it('refuses a code that could be mistaken for the out-of-catalogue sentinel', function () {
    // ⚠️ Il leading underscore è escluso apposta: la sentinella del filtro della
    // cabina (`ElencaClienti::FUORI_CATALOGO`) vale `__fuori_catalogo`, e un
    // piano che si chiamasse così renderebbe indistinguibili «il piano X» e
    // «nessun piano riconosciuto».
    expect(fn () => GovernoListino::crea([
        'codice' => '__fuori_catalogo',
        'etichetta' => 'Finto',
    ]))->toThrow(ValidationException::class);

    expect(Piano::query()->where('codice', '__fuori_catalogo')->exists())->toBeFalse();
});

it('allows a paid plan at zero, which is a promotion, and refuses a free plan with a price', function () {
    // 🔴 L'invariante è a **senso unico**: «gratuito ⇒ prezzo 0», mai «prezzo 0 ⇒
    // gratuito». Un piano a pagamento a 0 € ha una subscription vera e deve
    // restare `gratuito = false`; l'invariante inversa vietava quel caso, e
    // `PianiTest` la rifiuta per nome dal blocco Cashier. Le due metà stanno
    // nello stesso corpo perché sono la stessa decisione guardata dai due lati.
    $promozione = GovernoListino::crea([
        'codice' => 'promo',
        'etichetta' => 'Promo',
        'gratuito' => false,
        'prezzo_mensile_cent' => 0,
    ]);

    expect($promozione->gratuito)->toBeFalse()
        ->and($promozione->prezzo_mensile_cent)->toBe(0);

    expect(fn () => GovernoListino::crea([
        'codice' => 'omaggio_caro',
        'etichetta' => 'Incoerente',
        'gratuito' => true,
        'prezzo_mensile_cent' => 100,
    ]))->toThrow(ValidationException::class);

    expect(Piano::query()->where('codice', 'omaggio_caro')->exists())->toBeFalse();
});

it('refuses a cap of zero, which would forbid even the first ente', function () {
    expect(fn () => GovernoListino::crea([
        'codice' => 'nessuna_sede',
        'etichetta' => 'Assurdo',
        'max_enti' => 0,
    ]))->toThrow(ValidationException::class);

    // Vuoto invece è **illimitato**, e non «non lo so»: è la forma che un piano
    // Enterprise ha davvero.
    expect(GovernoListino::crea([
        'codice' => 'illimitato',
        'etichetta' => 'Illimitato',
        'max_enti' => '',
    ])->max_enti)->toBeNull();
});

// ─── Le due immutabilità ─────────────────────────────────────────────────────

it('never lets the code change, because accounts.piano keeps it as a string', function () {
    // 🔴 Il negativo che protegge l'MRR. `accounts.piano` è una stringa **senza
    // FK e senza CHECK**: rinominare il codice non aggiornerebbe nessun account,
    // e in un colpo solo tutti i clienti su quel piano diventerebbero «fuori
    // catalogo» — cioè varrebbero **0 €** in `MetrichePiattaforma`, che itera su
    // `Piani::codici()` e butta il resto in `pianiSconosciuti`.
    $saas = Piani::modello('saas');

    expect(fn () => GovernoListino::aggiornaAnagrafica($saas, [
        'codice' => 'saas_v2',
        'etichetta' => 'SaaS',
    ]))->toThrow(ValidationException::class);

    app(CatalogoPiani::class)->dimentica();

    // La riga resta **com'era**: il rifiuto arriva prima di toccare il database,
    // quindi non lascia dietro di sé mezza modifica.
    expect($saas->fresh()->codice)->toBe('saas')
        ->and(Piani::esiste('saas'))->toBeTrue()
        ->and(Piano::query()->where('codice', 'saas_v2')->exists())->toBeFalse();
});

it('lets the same code through, because a no-op is not a change', function () {
    // A essere rifiutato è il **cambiamento**, non la presenza del campo: così un
    // form che rimanda tutti i suoi campi non è costretto a sapere quali
    // omettere. Senza questa riga, la guardia sopra si potrebbe «riparare»
    // rendendo il campo obbligatoriamente assente, che è una regola diversa.
    $saas = Piani::modello('saas');

    GovernoListino::aggiornaAnagrafica($saas, ['codice' => 'saas', 'etichetta' => 'SaaS Pro']);

    app(CatalogoPiani::class)->dimentica();

    expect($saas->fresh()->etichetta)->toBe('SaaS Pro');
});

it('never lets the free flag flip after the plan exists', function () {
    // Ribaltarla su un piano già venduto marcherebbe come **paganti** dei
    // clienti che non hanno alcuna subscription — o il contrario, cioè
    // smetterebbe di fatturare chi paga. Chi vuole l'altro comportamento crea un
    // piano nuovo e migra a mano.
    $free = Piani::modello('free');

    expect(fn () => GovernoListino::aggiornaAnagrafica($free, ['gratuito' => false]))
        ->toThrow(ValidationException::class);

    app(CatalogoPiani::class)->dimentica();

    expect($free->fresh()->gratuito)->toBeTrue();
});

it('never lets a free plan grow a price through the back door', function () {
    // La stessa invariante di `crea()`, sull'altra porta: senza, si otterrebbe
    // per due passi ciò che in un passo è vietato.
    $free = Piani::modello('free');

    expect(fn () => GovernoListino::cambiaPrezzo($free, 900))->toThrow(ValidationException::class);

    app(CatalogoPiani::class)->dimentica();

    expect($free->fresh()->prezzo_mensile_cent)->toBe(0);
});

// ─── Lo storico dei prezzi ───────────────────────────────────────────────────

it('keeps the historical price resolvable, which is why prezzi_piano is a table', function () {
    // 🔴 Il negativo che rende attuabile «chi è già abbonato resta al suo». Se
    // `Piani::perPrice()` guardasse il solo price corrente, al primo cambio di
    // listino il webhook `customer.subscription.updated` di ogni cliente vecchio
    // tornerebbe `null` e `accounts.piano` non si riallineerebbe **mai più** —
    // in silenzio, perché quel `null` è un esito legittimo che
    // `StripeWebhookController::applicaStato()` tratta come tale.
    $saas = Piani::modello('saas');

    GovernoListino::sincronizza($saas);
    $vecchio = $saas->fresh()->prezzi()->where('corrente', true)->value('stripe_price_id');

    GovernoListino::cambiaPrezzo($saas, 5900);
    GovernoListino::sincronizza($saas->fresh());

    $nuovo = $saas->fresh()->prezzi()->where('corrente', true)->value('stripe_price_id');

    app(CatalogoPiani::class)->dimentica();

    expect($nuovo)->not->toBe($vecchio)
        ->and($saas->fresh()->prezzi()->count())->toBe(2)
        ->and(Piani::perPrice($vecchio))->toBe('saas')
        ->and(Piani::perPrice($nuovo))->toBe('saas')
        // Il price vecchio è stato archiviato su Stripe — le subscription in
        // essere continuano a fatturarci sopra, ma nessuna nuova lo aggancia.
        ->and($this->porta->prezzi[$vecchio]->attivo)->toBeFalse();
});

it('creates no twins when the same sync runs twice a day apart', function () {
    // 🔴 **Il secondo livello di idempotenza.** La `idempotency_key` di Stripe
    // scade dopo 24 ore, quindi non protegge il retry di domani: prima di creare
    // un Product si guarda `piani.stripe_product_id`, e prima di creare un Price
    // si confrontano importo e valuta con la riga corrente. Se non è cambiato
    // nulla **non si chiama Stripe affatto**.
    $saas = Piani::modello('saas');

    GovernoListino::sincronizza($saas);
    GovernoListino::sincronizza($saas->fresh());
    GovernoListino::sincronizza($saas->fresh());

    expect($this->porta->quante('creaProdotto'))->toBe(1)
        ->and($this->porta->quante('creaPrezzo'))->toBe(1)
        ->and($saas->fresh()->prezzi()->count())->toBe(1);
});

// ─── Quando Stripe rifiuta ───────────────────────────────────────────────────

it('keeps the local row and writes the failure down, instead of failing silently', function () {
    // 🔴 Il dominio — tetto di Enti, etichetta, ordine — deve valere **anche se
    // Stripe è irraggiungibile**: quei numeri governano il provisioning, non la
    // fatturazione. Ciò che non si può accettare è che il fallimento sia
    // invisibile: un piano che risulta sincronizzato e non lo è manda a cercare
    // il guasto ovunque tranne dove sta.
    $this->porta->lancia = 'chiave API non valida';

    $piano = GovernoListino::crea([
        'codice' => 'enterprise',
        'etichetta' => 'Enterprise',
        'prezzo_mensile_cent' => 19900,
    ]);

    GovernoListino::sincronizza($piano);

    $fresco = $piano->fresh();

    expect($fresco)->not->toBeNull()
        ->and($fresco->prezzo_mensile_cent)->toBe(19900)
        ->and($fresco->stripe_sincronizzato_at)->toBeNull()
        // Prima che esista, poi cosa dice: senza la prima riga un
        // `toContain()` su `null` fallisce con «Expected [iterable]», cioè con
        // un messaggio che manda a cercare il difetto nel test.
        ->and($fresco->stripe_ultimo_errore)->not->toBeNull()
        ->and($fresco->stripe_ultimo_errore)->toContain('chiave API non valida')
        // 🔴 E **nessuna riga di prezzo**: un price locale senza il gemello su
        // Stripe farebbe risolvere `Piani::perPrice()` su un id che non esiste,
        // cioè trasformerebbe un guasto rumoroso in uno silenzioso.
        ->and($fresco->prezzi()->count())->toBe(0);
});

// ─── Archiviare ──────────────────────────────────────────────────────────────

it('refuses to archive the plan every account falls back to', function () {
    // È quello con cui nasce ogni account nuovo e quello a cui torna chi disdice
    // (`StripeWebhookController` → `Piani::predefinito()`). Archiviarlo
    // lascerebbe il webhook di disdetta a scrivere un piano non offribile — e un
    // webhook non può permettersi un'eccezione, o Stripe ritenta per giorni.
    $free = Piani::modello(Piani::predefinito());

    expect(fn () => GovernoListino::archivia($free))->toThrow(ValidationException::class);

    app(CatalogoPiani::class)->dimentica();

    expect($free->fresh()->attivo)->toBeTrue()
        ->and(Piani::offribili())->toContain('free');
});

it('keeps an archived plan inside the catalogue, worth its price in the MRR', function () {
    // ⚠️ Archiviare ≠ togliere dal catalogo. `Piani::esiste()` e `::codici()`
    // continuano a includerlo, o ogni account rimasto sopra varrebbe **0 €**
    // nell'MRR di `MetrichePiattaforma`. `attivo` governa **solo**
    // l'offribilità.
    $saas = Piani::modello('saas');

    GovernoListino::archivia($saas);

    app(CatalogoPiani::class)->dimentica();

    expect(Piani::esiste('saas'))->toBeTrue()
        ->and(Piani::codici())->toContain('saas')
        ->and(Piani::prezzoMensileCent('saas'))->toBe(4900)
        ->and(Piani::offribili())->not->toContain('saas');

    GovernoListino::riattiva($saas->fresh());

    app(CatalogoPiani::class)->dimentica();

    expect(Piani::offribili())->toContain('saas');
});

// ─── Agganciare un price esistente ───────────────────────────────────────────

it('refuses to hook a price that already belongs to another plan', function () {
    // Due piani sullo stesso price renderebbero **arbitrario** il riallineamento
    // del webhook: `perPrice()` ne restituirebbe uno dei due a seconda
    // dell'ordine di caricamento, e il piano di un cliente cambierebbe da solo.
    $saas = Piani::modello('saas');

    GovernoListino::sincronizza($saas);
    $priceDelSaas = $saas->fresh()->prezzi()->where('corrente', true)->value('stripe_price_id');

    $altro = GovernoListino::crea([
        'codice' => 'enterprise',
        'etichetta' => 'Enterprise',
        'prezzo_mensile_cent' => 4900,
    ]);

    expect(fn () => GovernoListino::agganciaPrezzo($altro, $priceDelSaas))
        ->toThrow(ValidationException::class);

    app(CatalogoPiani::class)->dimentica();

    expect(Piani::perPrice($priceDelSaas))->toBe('saas')
        ->and($altro->fresh()->prezzi()->count())->toBe(0);
});

it('refuses to hook any price onto a free plan', function () {
    // Un piano omaggiato non ha né customer né subscription (ADR-002): un price
    // agganciato lì sarebbe un oggetto di fatturazione che nessuno fatturerà mai.
    $this->porta->conPrezzo('price_qualunque', 0, 'eur');

    expect(fn () => GovernoListino::agganciaPrezzo(Piani::modello('free'), 'price_qualunque'))
        ->toThrow(ValidationException::class);
});
