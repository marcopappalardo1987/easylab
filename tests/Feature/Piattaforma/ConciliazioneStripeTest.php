<?php

use App\Livewire\Piattaforma\Listino;
use App\Support\Listino\Stripe\PortaListinoStripe;
use App\Support\Piani;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Tests\Fakes\PortaListinoStripeFinta;

/**
 * 🔴 La divergenza fra il listino e Stripe **si vede**, e non si ripara da sola
 * (🔗 ADR-035).
 *
 * ## Perché questo file esiste
 *
 * Dal momento in cui il listino vive a database, `piani.prezzo_mensile_cent` e
 * l&rsquo;`unit_amount` del Price su Stripe sono **due numeri**, e possono
 * separarsi: una modifica fatta in dashboard, un price archiviato a mano, un
 * prodotto cancellato, una sincronizzazione fallita e mai ripetuta. È
 * esattamente la forma del problema che `/piattaforma/ruoli` ha già incontrato
 * fra il database e `config/rbac.php` — con una differenza che alza la posta:
 * là a divergere erano dei permessi, qui è **quanto un cliente paga**.
 *
 * La risposta è la stessa, e questo file la congela: la divergenza si **mostra
 * coi due valori affiancati** (dire soltanto «diverge» manda ad aprire la
 * dashboard di Stripe per sapere di quanto, cioè a rifare a mano metà del
 * confronto che la pagina esiste per togliere), e si risolve con un gesto
 * esplicito — mai con una riparazione automatica, in nessuna delle due
 * direzioni.
 *
 * ## Il negativo che regge tutto il resto
 *
 * 🔴 **Il `render()` non chiama Stripe.** È la prima trappola che ADR-035
 * nomina, e senza il test che conta le chiamate della porta finta sarebbe una
 * frase in un docblock: una conciliazione dentro `render()` è una chiamata di
 * rete per piano a ogni apertura, e una pagina di piattaforma che **muore
 * perché un fornitore esterno è giù**. La cabina non cade quando un piano è
 * fuori catalogo; il listino non deve cadere quando Stripe non risponde.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->porta = new PortaListinoStripeFinta;
    app()->instance(PortaListinoStripe::class, $this->porta);

    $this->pagina = fn () => Livewire::actingAs(utenteConRuolo('Superadmin'))->test(Listino::class);

    // Un `saas` già allineato: è lo stato da cui ogni divergenza di questo file
    // parte, e averlo nel beforeEach evita che ogni test rifaccia il preludio.
    $this->allinea = function () {
        $saas = Piani::modello('saas');

        ($this->pagina)()->call('sincronizza', $saas->id);

        return $saas->fresh();
    };
});

// ─── Il negativo: la pagina non tocca Stripe da sola ────────────────────────

it('never calls Stripe while rendering the page', function () {
    // 🔴 La riga che tiene in piedi la pagina quando Stripe è giù o le chiavi
    // non sono configurate. Si conta **zero**, e si contano le chiamate di
    // *ogni* metodo della porta — non solo quelle che scrivono: anche una
    // lettura è un round-trip, ed è il round-trip che fa cadere la pagina.
    //
    // ⚠️ **Il catalogo va prima allineato, o il test non misura niente.**
    // Misurato: su un `saas` senza product id la conciliazione si ferma prima di
    // chiamare la porta (l'assenza del product è già una divergenza, e si dice
    // senza chiedere a Stripe), quindi anche una conciliazione messa dentro
    // `render()` lascerebbe il contatore a zero e questo test resterebbe
    // **verde** proprio sul difetto che esiste per cogliere. Il preludio serve
    // a rendere il round-trip possibile; il contatore si azzera dopo.
    ($this->allinea)();
    $this->porta->chiamate = [];

    ($this->pagina)()->assertOk();

    expect($this->porta->quante())->toBe(0);
});

it('says out loud that nobody has compared anything yet', function () {
    // ⚠️ Tre stati e tre frasi, perché mandano a fare tre cose diverse. Il
    // guasto da evitare è il messaggio unico che copre «non confrontato» e
    // «coincidono»: direbbe «tutto a posto» anche quando nessuno ha guardato,
    // ed è la bugia piccola — e per questo credibile — già colta su
    // `RegistroAudit::direzione()`.
    $html = ($this->pagina)()->html();

    expect($html)->toContain('data-confronto="assente"')
        ->and($html)->toContain('non è stato confrontato');
});

// ─── I positivi: la divergenza si vede, coi due valori ──────────────────────

it('shows both numbers when Stripe bills a different amount', function () {
    $saas = ($this->allinea)();
    $priceId = $saas->prezzi()->where('corrente', true)->value('stripe_price_id');

    // Qualcuno ha cambiato la cifra in dashboard: è il caso reale, e non c'è
    // nessun webhook che ce lo racconti.
    $this->porta->conPrezzo($priceId, 9900, 'eur');

    $html = ($this->pagina)()->call('confrontaConStripe')->html();

    expect($html)->toContain('data-divergente="importo"')
        // 🔴 **I due valori affiancati**, non il solo fatto che divergano: è la
        // stessa forma del marcatore «personalizzato» di /piattaforma/ruoli, e
        // la ragione è identica — un marcatore che non dice *di quanto*
        // obbligherebbe ad andare a vedere altrove.
        ->and($html)->toContain('listino 49,00 EUR')
        ->and($html)->toContain('Stripe 99,00 EUR')
        ->and($html)->toContain('data-riconciliazione-stripe="1"');
});

it('compares Stripe against the list price, not against the last price we wrote', function () {
    // 🔴 **La domanda a cui questa pagina risponde è «quanto fattura Stripe
    // rispetto a quanto il listino dichiara»**, non «rispetto a quanto avevamo
    // scritto l&rsquo;ultima volta». Le due si separano esattamente nel momento
    // che conta: fra il cambio di prezzo e la sincronizzazione,
    // `piani.prezzo_mensile_cent` dice 59,00 e la riga corrente di
    // `prezzi_piano` dice ancora 49,00 — che è anche ciò che Stripe fattura.
    //
    // Un confronto fatto contro la riga di `prezzi_piano` direbbe «coincidono» e
    // sarebbe **verde su un listino non ancora applicato**: il prezzo nuovo
    // esiste in pagina, nessun cliente lo paga, e niente lo segnala. Misurato:
    // senza questo test quella mutazione resta verde.
    $saas = ($this->allinea)();

    ($this->pagina)()
        ->call('apriModifica', $saas->id)
        ->set('modifica.prezzo_mensile_cent', '5900')
        ->call('salva')
        ->assertHasNoErrors();

    $html = ($this->pagina)()->call('confrontaConStripe')->html();

    expect($html)->toContain('data-divergente="importo"')
        ->and($html)->toContain('listino 59,00 EUR')
        ->and($html)->toContain('Stripe 49,00 EUR');
});

it('shows both currencies when Stripe bills in another one', function () {
    // ⚠️ La valuta fino ad ADR-035 viveva **solo** in `cashier.currency`, e il
    // commento di `config/easylab.php` la dichiarava come divergenza non
    // presidiata. Registrarla sulla riga di `prezzi_piano` è ciò che rende
    // questo confronto possibile senza indovinare.
    $saas = ($this->allinea)();
    $priceId = $saas->prezzi()->where('corrente', true)->value('stripe_price_id');

    $this->porta->conPrezzo($priceId, 4900, 'usd');

    $html = ($this->pagina)()->call('confrontaConStripe')->html();

    expect($html)->toContain('data-divergente="valuta"')
        ->and($html)->toContain('listino EUR')
        ->and($html)->toContain('Stripe USD');
});

it('reports a product archived on Stripe while the plan is still offered here', function () {
    // La direzione che fa danno: un piano che questa schermata offre e il cui
    // Product su Stripe non è più attivo fa fallire la **prossima**
    // sottoscrizione, in un punto lontanissimo da qui.
    $saas = ($this->allinea)();

    $this->porta->conProdotto($saas->stripe_product_id, 'SaaS', false);

    $html = ($this->pagina)()->call('confrontaConStripe')->html();

    expect($html)->toContain('data-divergente="prodotto"')
        ->and($html)->toContain('archiviato su Stripe');
});

it('reports a plan that has never reached Stripe at all', function () {
    // È lo stato in cui la migration di backfill può aver lasciato `saas` su
    // Cloud, dove la config è cachata in build e `STRIPE_PRICE_SAAS` poteva
    // mancare. Non è un guasto irreversibile — ma resterebbe invisibile, ed è
    // precisamente il tipo di silenzio che questa pagina esiste per rompere.
    $html = ($this->pagina)()->call('confrontaConStripe')->html();

    expect($html)->toContain('data-divergente="prodotto"')
        ->and($html)->toContain('mai sincronizzato');
});

it('calls it a match when the two agree, and does not call that silence', function () {
    ($this->allinea)();

    $html = ($this->pagina)()->call('confrontaConStripe')->html();

    expect($html)->toContain('data-riconciliazione-stripe="0"')
        ->and($html)->toContain('data-confronto="fatto"')
        ->and($html)->toContain('coincide')
        ->and($html)->not->toContain('data-divergente');
});

it('never compares the free plan, which has nothing on Stripe by definition', function () {
    // Un piano omaggiato non ha né customer né subscription (ADR-002): cercarlo
    // su Stripe produrrebbe una riga rossa su uno stato perfettamente corretto,
    // e una pagina che segnala guasti inventati insegna a ignorare i marcatori.
    ($this->allinea)();

    $html = ($this->pagina)()->call('confrontaConStripe')->html();

    expect($this->porta->quante('leggiProdotto'))->toBe(1)
        ->and($html)->toContain('niente, per definizione')
        ->and($html)->toContain('data-riconciliazione-stripe="0"');
});

// ─── Stripe giù ─────────────────────────────────────────────────────────────

it('turns an unreachable Stripe into a row, not into a white page', function () {
    // Se il primo piano facesse esplodere il confronto, i sani non si
    // vedrebbero: chi preme il bottone sta cercando **dove** non torna, e
    // «Stripe non risponde» su una riga è un'informazione, mentre una pagina
    // bianca non lo è.
    ($this->allinea)();

    $this->porta->lancia = 'connessione rifiutata';

    $html = ($this->pagina)()->call('confrontaConStripe')->assertOk()->html();

    expect($html)->toContain('data-divergente="prodotto"')
        ->and($html)->toContain('Stripe non risponde');
});

it('keeps the page readable when Stripe is down and nobody pressed the button', function () {
    // Il complemento del negativo in testa al file: con un catalogo allineato —
    // cioè con qualcosa da chiedere a Stripe — e la porta che lancia a ogni
    // chiamata, la pagina risponde **comunque**, perché non ne fa nessuna.
    ($this->allinea)();

    $this->porta->lancia = 'connessione rifiutata';

    ($this->pagina)()
        ->assertOk()
        ->assertSee('Da qui si crea un piano, se ne cambia il prezzo, e lo stesso gesto arriva su Stripe.', false);
});

// ─── Il confronto invecchia, e non deve restare in pagina ───────────────────

it('throws the comparison away after a write, instead of showing a stale one', function () {
    // 🔴 I marcatori sono stati calcolati contro un valore locale che la
    // scrittura ha appena cambiato: lasciarli in pagina mostrerebbe una
    // divergenza **inventata**, che è peggio di non mostrarne nessuna — chi
    // legge crederebbe a una cifra che nessuno ha più, e il gesto successivo
    // sarebbe una risincronizzazione fatta per niente.
    $saas = ($this->allinea)();
    $priceId = $saas->prezzi()->where('corrente', true)->value('stripe_price_id');

    $this->porta->conPrezzo($priceId, 9900, 'eur');

    $componente = ($this->pagina)()->call('confrontaConStripe');

    expect($componente->html())->toContain('data-riconciliazione-stripe="1"');

    $componente
        ->call('apriModifica', $saas->id)
        ->set('modifica.prezzo_mensile_cent', '9900')
        ->call('salva')
        ->assertHasNoErrors();

    expect($componente->html())->toContain('data-confronto="assente"')
        ->and($componente->html())->not->toContain('data-divergente');
});
