<?php

use App\Livewire\Piattaforma\Listino;
use App\Models\Account;
use App\Models\Piano;
use App\Models\UnitaOrganizzativa;
use App\Support\Listino\CatalogoPiani;
use App\Support\Listino\Stripe\PortaListinoStripe;
use App\Support\Piani;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fakes\PortaListinoStripeFinta;

/**
 * 🔴 La schermata del listino: cosa mostra, e cosa scrive quando la si preme
 * (🔗 ADR-035, ADR-002 sul Free, ADR-032 sul tetto di Enti).
 *
 * Il gate lo prova `AccessoListinoTest`; qui si prova ciò che succede **dopo**
 * che qualcuno è entrato — e le due domande sono separate apposta, perché una
 * pagina può essere gatata benissimo e scrivere la cosa sbagliata.
 *
 * ⚠️ **La porta verso Stripe è finta e conta le chiamate.** Non si verificano
 * le risposte di Stripe (quella metà è dichiarata senza test, come per
 * `easylab:abbona`): si verificano le **nostre** transizioni di stato, e in due
 * casi il conteggio delle chiamate È l'asserzione — il doppio invio che non
 * deve lasciare gemelli, e il `render()` che non deve toccare Stripe affatto.
 *
 * ⚠️ **Dopo ogni scrittura fatta a mano serve `CatalogoPiani::dimentica()`**: il
 * memo è per-richiesta e in un test la richiesta non finisce mai, quindi senza
 * si proverebbe il memo invece della regola. Le azioni della pagina lo fanno da
 * sé (lo fa `GovernoListino`), il fixture no.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->porta = new PortaListinoStripeFinta;
    app()->instance(PortaListinoStripe::class, $this->porta);

    $this->pagina = fn () => Livewire::actingAs(utenteConRuolo('Superadmin'))->test(Listino::class);
});

// ─── Ciò che la pagina mostra ────────────────────────────────────────────────

it('shows the two bootstrap plans with the string that lives in accounts.piano', function () {
    // Il **codice** in pagina, non solo l'etichetta: è la stringa che
    // `accounts.piano` conserva, ed è quella che si cerca quando qualcosa non
    // torna. Un listino che mostrasse i soli nomi commerciali obbligherebbe ad
    // aprire il database per collegare «SaaS» a `saas`.
    ($this->pagina)()
        ->assertSee('Free')
        ->assertSee('SaaS')
        ->assertSee('free')
        ->assertSee('saas')
        // 4900 centesimi resi in cifre leggibili, non in centesimi grezzi: chi
        // legge un listino legge un prezzo.
        ->assertSee('49,00');
});

it('says which plan is the default, before anyone tries to archive it', function () {
    // Il piano predefinito è quello con cui nasce ogni account e quello a cui
    // torna chi disdice: archiviarlo è rifiutato da `GovernoListino`. Dirlo in
    // pagina è ciò che evita di far scoprire il rifiuto con un click — e la
    // marcatura è sul piano che la config dichiara, non su `free` scritto a
    // mano, o il test resterebbe verde il giorno in cui il predefinito cambia.
    expect(Piani::predefinito())->toBe('free');

    ($this->pagina)()->assertSee('predefinito');
});

it('counts the customers sitting on each plan, archived ones included', function () {
    // Il numero conta soprattutto sui piani **ritirati**: un piano archiviato
    // con clienti sopra non è un piano morto, è un piano che vale ancora il suo
    // MRR — ed è la ragione per cui archiviare non è cancellare.
    Account::factory()->count(3)->create(['piano' => 'free']);
    Account::factory()->saas()->create();

    $html = ($this->pagina)()->html();

    expect($html)->toContain('data-clienti="3"')
        ->and($html)->toContain('data-clienti="1"');
});

// ─── Creare un piano ─────────────────────────────────────────────────────────

it('creates a plan and the Stripe product in the same gesture', function () {
    // La decisione di prodotto di ADR-035 («opzione A»): il gesto è **uno**.
    // L'alternativa — riga adesso, sincronizzazione a un secondo click —
    // produce come stato normale un piano offribile che su Stripe non esiste, e
    // il difetto si scoprirebbe dal lato del cliente, alla prima sottoscrizione.
    ($this->pagina)()
        ->call('apriCreazione')
        ->set('nuovo.codice', 'enterprise')
        ->set('nuovo.etichetta', 'Enterprise')
        ->set('nuovo.max_enti', '')
        ->set('nuovo.prezzo_mensile_cent', '19900')
        ->call('crea')
        ->assertHasNoErrors();

    $piano = Piano::query()->where('codice', 'enterprise')->firstOrFail();

    expect($piano->etichetta)->toBe('Enterprise')
        // Vuoto = **illimitato**, non zero: è la forma che un piano Enterprise
        // ha davvero, e uno zero significherebbe «nemmeno la prima sede».
        ->and($piano->max_enti)->toBeNull()
        ->and($piano->prezzo_mensile_cent)->toBe(19900)
        ->and($piano->gratuito)->toBeFalse()
        // Stripe è stato toccato, e nell'ordine giusto: prima il prodotto, poi
        // il price. Il contrario non esiste — un Price su Stripe appartiene a un
        // Product.
        ->and($this->porta->chiamate)->toBe(['creaProdotto', 'creaPrezzo'])
        ->and($piano->stripe_product_id)->not->toBeNull()
        ->and($piano->stripe_sincronizzato_at)->not->toBeNull()
        ->and($piano->prezzi()->where('corrente', true)->count())->toBe(1);
});

it('never touches Stripe for a free plan, because a free plan has nothing there', function () {
    // 🔴 Non è un no-op di comodo: è la **definizione** del Free (ADR-002 —
    // nessun customer, nessuna subscription, perché è omaggiato a fronte di un
    // contratto di manutenzione fisico). Creargli un Product produrrebbe un
    // oggetto di fatturazione che nessuno fatturerà mai, e un price attivo su
    // Stripe che nessuna schermata mostra.
    ($this->pagina)()
        ->call('apriCreazione')
        ->set('nuovo.codice', 'omaggio')
        ->set('nuovo.etichetta', 'Omaggio')
        ->set('nuovo.gratuito', true)
        ->set('nuovo.prezzo_mensile_cent', '0')
        ->call('crea')
        ->assertHasNoErrors();

    expect(Piano::query()->where('codice', 'omaggio')->exists())->toBeTrue()
        ->and($this->porta->quante())->toBe(0);
});

it('refuses a free plan with a price, and says the promotional case is the other one', function () {
    // ⚠️ L'invariante è a **senso unico**: «gratuito ⇒ prezzo 0», mai «prezzo 0
    // ⇒ gratuito». Un piano a pagamento a 0 € è una promozione legittima — ha
    // una subscription vera — e vietarlo sarebbe l'errore che `PianiTest`
    // rifiuta per nome dal blocco Cashier.
    ($this->pagina)()
        ->call('apriCreazione')
        ->set('nuovo.codice', 'gratis_ma_caro')
        ->set('nuovo.etichetta', 'Incoerente')
        ->set('nuovo.gratuito', true)
        ->set('nuovo.prezzo_mensile_cent', '900')
        ->call('crea')
        ->assertHasErrors('prezzo_mensile_cent');

    expect(Piano::query()->where('codice', 'gratis_ma_caro')->exists())->toBeFalse()
        // 🔴 Il negativo che conta: il rifiuto arriva **prima** di qualunque
        // chiamata di rete, quindi non lascia dietro di sé un Product orfano su
        // Stripe per un piano che a database non esiste.
        ->and($this->porta->quante())->toBe(0);
});

it('renders the refusal in the page, not only inside the modal', function () {
    // Se l'errore vivesse solo nella modale, una richiesta forgiata a mano
    // tornerebbe **muta** e il rifiuto si leggerebbe come «non è successo
    // niente». È la disciplina di `EditorRuoli`.
    $html = ($this->pagina)()
        ->call('apriCreazione')
        ->set('nuovo.codice', 'NON valido')
        ->set('nuovo.etichetta', 'X')
        ->call('crea')
        ->assertHasErrors('codice')
        ->html();

    expect($html)->toContain('data-errore')
        ->and($html)->toContain('Gesto rifiutato');
});

// ─── Cambiare prezzo ─────────────────────────────────────────────────────────

it('keeps the old price alive when the list price changes', function () {
    // 🔴 **La decisione di prodotto, resa meccanica**: chi è già abbonato resta
    // al suo. Su Stripe un Price è immutabile, quindi cambiare cifra ne crea uno
    // nuovo; la riga vecchia resta in `prezzi_piano` e continua a risolversi al
    // proprio piano. Senza, al primo cambio di listino il webhook
    // `customer.subscription.updated` di ogni cliente vecchio tornerebbe `null`
    // e `accounts.piano` non si riallineerebbe più — **in silenzio**, perché
    // `null` è un esito legittimo che il controller tratta come tale.
    $saas = Piani::modello('saas');

    ($this->pagina)()->call('sincronizza', $saas->id)->assertHasNoErrors();

    $vecchio = $saas->fresh()->prezzi()->where('corrente', true)->firstOrFail()->stripe_price_id;

    ($this->pagina)()
        ->call('apriModifica', $saas->id)
        ->set('modifica.prezzo_mensile_cent', '5900')
        ->call('salva')
        ->assertHasNoErrors();

    ($this->pagina)()->call('sincronizza', $saas->id)->assertHasNoErrors();

    $piano = $saas->fresh();
    $nuovo = $piano->prezzi()->where('corrente', true)->firstOrFail();

    expect($piano->prezzo_mensile_cent)->toBe(5900)
        ->and($nuovo->stripe_price_id)->not->toBe($vecchio)
        ->and($nuovo->importo_cent)->toBe(5900)
        // La riga vecchia **c'è ancora**, marcata non corrente.
        ->and($piano->prezzi()->where('stripe_price_id', $vecchio)->value('corrente'))->toBeFalsy()
        // E resta risolvibile al proprio piano: è l'intera ragione della tabella.
        ->and(Piani::perPrice($vecchio))->toBe('saas')
        ->and(Piani::perPrice($nuovo->stripe_price_id))->toBe('saas');
});

it('does not mark a plan out of sync when only its label changed', function () {
    // ⚠️ `cambiaPrezzo()` azzera `stripe_sincronizzato_at`, cioè marca il piano
    // «da risincronizzare». Chiamarlo a ogni salvataggio dell'etichetta
    // metterebbe in stato di divergenza dei piani perfettamente allineati — e
    // una pagina che segnala divergenze inventate insegna a ignorarle.
    $saas = Piani::modello('saas');

    ($this->pagina)()->call('sincronizza', $saas->id);

    $quandoSincronizzato = $saas->fresh()->stripe_sincronizzato_at;
    $chiamatePrima = $this->porta->quante();

    ($this->pagina)()
        ->call('apriModifica', $saas->id)
        ->set('modifica.etichetta', 'SaaS Pro')
        ->call('salva')
        ->assertHasNoErrors();

    expect($saas->fresh()->etichetta)->toBe('SaaS Pro')
        ->and($saas->fresh()->stripe_sincronizzato_at?->timestamp)->toBe($quandoSincronizzato?->timestamp)
        ->and($this->porta->quante())->toBe($chiamatePrima);
});

it('creates no twin price when the same sync is asked twice', function () {
    // 🔴 **Il secondo livello di idempotenza**, quello che sopravvive alle 24
    // ore della `idempotency_key` di Stripe: se importo e valuta non sono
    // cambiati non si chiama Stripe affatto. Senza, il doppio click di oggi
    // sarebbe protetto dalla chiave e il retry di domani no.
    $saas = Piani::modello('saas');

    ($this->pagina)()->call('sincronizza', $saas->id);
    ($this->pagina)()->call('sincronizza', $saas->id);

    expect($this->porta->quante('creaProdotto'))->toBe(1)
        ->and($this->porta->quante('creaPrezzo'))->toBe(1)
        // Il secondo giro aggiorna il nome del Product e si ferma lì.
        ->and($this->porta->quante('aggiornaProdotto'))->toBe(1)
        ->and($saas->fresh()->prezzi()->count())->toBe(1);
});

// ─── Il tetto di Enti ────────────────────────────────────────────────────────

it('asks before lowering the cap under someone, and writes nothing until it is confirmed', function () {
    // 🔴 **Non è una guardia, ed è il punto.** Abbassare il tetto è permesso e
    // non cestina niente: chi ci finisce sotto tiene tutte le sue sedi e
    // semplicemente non ne apre altre (grandfathering, ADR-032 —
    // `slotEntiResidui()` diventa negativo, ed è uno stato legittimo). Ciò che
    // manca a chi clicca è **saperlo prima**, col numero davanti.
    $account = Account::factory()->saas()->create();
    UnitaOrganizzativa::factory()->count(3)->ente()->perAccount($account)->create();

    $saas = Piani::modello('saas');

    $componente = ($this->pagina)()
        ->call('apriModifica', $saas->id)
        ->set('modifica.max_enti', '2')
        ->call('salva');

    // La modale è aperta, col conteggio dentro.
    expect($componente->html())->toContain('data-conferma-tetto="1"');

    // 🔴 E **niente è stato scritto**: è la metà del test che conta. Una modale
    // che comparisse dopo la scrittura sarebbe una notifica, non una conferma.
    app(CatalogoPiani::class)->dimentica();
    expect($saas->fresh()->max_enti)->toBe(5);

    $componente->call('procedi')->assertHasNoErrors();

    app(CatalogoPiani::class)->dimentica();

    expect($saas->fresh()->max_enti)->toBe(2)
        // Nessuna sede chiusa, nessun account bloccato: il grandfathering è
        // esattamente questo, e `slotEntiResidui()` negativo è lo stato atteso.
        ->and($account->fresh()->enti()->count())->toBe(3)
        ->and($account->fresh()->slotEntiResidui())->toBe(-1)
        ->and($account->fresh()->puoAggiungereEnte())->toBeFalse()
        ->and($account->fresh()->bloccato_at)->toBeNull();
});

it('does not stop to ask when the lower cap hurts nobody', function () {
    // Una modale che comparisse comunque sarebbe un ostacolo, non una decisione
    // — e il prezzo di un ostacolo è che lo si preme senza leggerlo.
    $account = Account::factory()->saas()->create();
    UnitaOrganizzativa::factory()->ente()->perAccount($account)->create();

    $saas = Piani::modello('saas');

    $componente = ($this->pagina)()
        ->call('apriModifica', $saas->id)
        ->set('modifica.max_enti', '2')
        ->call('salva')
        ->assertHasNoErrors();

    expect($componente->html())->not->toContain('data-conferma-tetto');

    app(CatalogoPiani::class)->dimentica();

    expect($saas->fresh()->max_enti)->toBe(2);
});

it('never counts a trashed sede against the cap', function () {
    // ⚠️ `Account::enti()` toglie gli scope **per nome** (`TenantScope`,
    // `DepartmentScope`) e non con un `withoutGlobalScopes()` nudo, che
    // porterebbe via anche `SoftDeletingScope`: è un difetto già pagato una
    // volta dal progetto, quando una sede chiusa mesi fa occupava uno slot del
    // piano per sempre. Qui riemergerebbe come una modale che avvisa di clienti
    // che non hanno alcun problema.
    $account = Account::factory()->saas()->create();
    $enti = UnitaOrganizzativa::factory()->count(3)->ente()->perAccount($account)->create();
    $enti->last()->delete();
    $enti->first()->delete();

    $saas = Piani::modello('saas');

    $componente = ($this->pagina)()
        ->call('apriModifica', $saas->id)
        ->set('modifica.max_enti', '2')
        ->call('salva')
        ->assertHasNoErrors();

    expect($componente->html())->not->toContain('data-conferma-tetto');
});

it('writes nothing when procedi arrives with nothing pending', function () {
    // Il pulsante di conferma non è una seconda strada per scrivere: senza una
    // conferma in attesa non fa niente. Senza questa riga, una richiesta
    // forgiata potrebbe saltare la modale — che è precisamente ciò che la
    // modale esiste per impedire.
    $saas = Piani::modello('saas');

    ($this->pagina)()
        ->call('apriModifica', $saas->id)
        ->set('modifica.max_enti', '1')
        ->call('procedi')
        ->assertHasNoErrors();

    app(CatalogoPiani::class)->dimentica();

    expect($saas->fresh()->max_enti)->toBe(5);
});

// ─── Archiviare ──────────────────────────────────────────────────────────────

it('archives a plan without taking it out of the catalogue', function () {
    // ⚠️ Archiviare ≠ togliere dal catalogo. `Piani::esiste()` e `::codici()`
    // devono continuare a includerlo, o ogni account rimasto sopra diventerebbe
    // «fuori catalogo» e varrebbe **0 €** nell'MRR di `MetrichePiattaforma`.
    $saas = Piani::modello('saas');

    ($this->pagina)()->call('archivia', $saas->id)->assertHasNoErrors();

    app(CatalogoPiani::class)->dimentica();

    expect($saas->fresh()->attivo)->toBeFalse()
        ->and(Piani::esiste('saas'))->toBeTrue()
        ->and(Piani::codici())->toContain('saas')
        ->and(Piani::prezzoMensileCent('saas'))->toBe(4900)
        // `attivo` governa **solo** l'offribilità.
        ->and(Piani::offribili())->not->toContain('saas');
});

it('refuses to archive the default plan', function () {
    // Archiviarlo lascerebbe il webhook di disdetta a scrivere un piano non
    // offribile — e quel webhook non può permettersi un'eccezione, o Stripe
    // ritenterebbe per giorni.
    $free = Piani::modello(Piani::predefinito());

    ($this->pagina)()
        ->call('archivia', $free->id)
        ->assertHasErrors('attivo');

    app(CatalogoPiani::class)->dimentica();

    expect($free->fresh()->attivo)->toBeTrue();
});

// ─── Agganciare un price esistente ───────────────────────────────────────────

it('hooks an existing Stripe price instead of creating a twin', function () {
    // La via d'uscita per il caso che la migration di backfill mette in conto:
    // su Cloud la config è cachata in build e la migration gira dopo, quindi con
    // `STRIPE_PRICE_SAAS` assente il piano `saas` nasce **senza** price id.
    // Ricrearne uno duplicherebbe il prodotto su Stripe.
    $this->porta->conPrezzo('price_gia_esistente', 4900, 'eur');

    $saas = Piani::modello('saas');

    ($this->pagina)()
        ->call('apriAggancio', $saas->id)
        ->set('priceId', 'price_gia_esistente')
        ->call('aggancia')
        ->assertHasNoErrors();

    app(CatalogoPiani::class)->dimentica();

    expect(Piani::stripePrice('saas'))->toBe('price_gia_esistente')
        // Nessun price creato: si è **letto**, non scritto, su Stripe.
        ->and($this->porta->quante('creaPrezzo'))->toBe(0);
});

it('refuses to hook a price that bills a different amount', function () {
    // Agganciare un price da 99 € a un piano che il listino dichiara da 49 €
    // fatturerebbe una cifra che nessuna schermata mostra.
    $this->porta->conPrezzo('price_troppo_caro', 9900, 'eur');

    $saas = Piani::modello('saas');

    ($this->pagina)()
        ->call('apriAggancio', $saas->id)
        ->set('priceId', 'price_troppo_caro')
        ->call('aggancia')
        ->assertHasErrors('price_id');

    app(CatalogoPiani::class)->dimentica();

    expect(Piani::stripePrice('saas'))->toBeNull();
});

// ─── Il costo della pagina ───────────────────────────────────────────────────

it('keeps the page cost flat as the catalogue grows', function () {
    // ⚠️ **Il conteggio va congelato a mano, e va detto perché.** I test di
    // costo esistenti (`TabellaClientiTest::keeps the query count flat`,
    // `KpiPiattaformaTest::stays at three statements`) filtrano per nome di
    // tabella e **non vedrebbero** una query su `piani`: non sono una rete per
    // questa pagina. E il difetto da temere è reale — la colonna «Stripe» legge
    // i price di ogni riga, che senza l'eager load di `CatalogoPiani` sarebbe
    // una query per piano, e la colonna «Clienti» sarebbe un'altra.
    $conta = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->pagina)();
        $n = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], '"piani"')
                || str_contains($q['query'], '"prezzi_piano"')
                || str_contains($q['query'], '"accounts"'))
            ->count();
        DB::disableQueryLog();

        return $n;
    };

    $costoIniziale = $conta();

    // Sei piani in più, ciascuno col proprio price: se il costo dipendesse dal
    // numero di righe, qui si vedrebbe.
    collect(range(1, 6))->each(function (int $i) {
        $piano = new Piano;
        $piano->forceFill([
            'codice' => 'piano_'.$i,
            'etichetta' => 'Piano '.$i,
            'max_enti' => $i,
            'gratuito' => false,
            'prezzo_mensile_cent' => 1000 * $i,
            'valuta' => 'eur',
            'attivo' => true,
            'ordine' => $i,
        ])->save();

        $piano->prezzi()->create([
            'stripe_price_id' => 'price_finto_'.$i,
            'importo_cent' => 1000 * $i,
            'valuta' => 'eur',
            'corrente' => true,
        ]);
    });

    app(CatalogoPiani::class)->dimentica();

    expect($conta())->toBe($costoIniziale)
        // E il numero è **scritto**, non solo «uguale a prima»: un costo che
        // fosse già lineare all'inizio passerebbe il confronto con sé stesso.
        ->and($costoIniziale)->toBe(3);
});
