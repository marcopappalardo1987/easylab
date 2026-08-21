<?php

use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Piani;
use App\Support\Piattaforma\MetrichePiattaforma;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * I quattro numeri della cabina di regia (S6 — Wireframe §4).
 *
 * Sono i primi numeri del progetto che qualcuno potrebbe **riportare a
 * qualcun altro**: «quanti clienti abbiamo», «quanto fatturiamo al mese». Il
 * rischio qui non è il 500, è la cifra plausibile e sbagliata — che nessuno
 * verifica proprio perché è plausibile.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $this->actingAs($this->superadmin->fresh());

    // Un cliente con una sede: la base su cui i test aggiungono il proprio caso.
    $this->cliente = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->sede = UnitaOrganizzativa::factory()->ente()->perAccount($this->cliente)->create();
});

it('prices the platform at list, one plan at a time', function () {
    Account::factory()->saas()->create();
    Account::factory()->create(); // Free: vale 0

    $r = MetrichePiattaforma::riepilogo();

    expect($r->clienti)->toBe(3)
        ->and($r->mrrCent)->toBe(2 * Piani::prezzoMensileCent('saas'))
        // `toBe` è sensibile all'ordine, ed è voluto: l'ordine è quello del
        // catalogo, non quello che il GROUP BY restituisce — così la riga
        // «1 Free · 2 SaaS» in pagina è la stessa su SQLite e su Postgres.
        ->and($r->perPiano)->toBe(['free' => 1, 'saas' => 2]);
});

it('shows a plan with no customers instead of hiding it', function () {
    // Una riga che sparisce si legge come «quel piano non esiste», e il Free è
    // esattamente quello che può restare vuoto per settimane.
    expect(MetrichePiattaforma::riepilogo()->perPiano)->toHaveKeys(Piani::codici());
});

it('leaves the trash out of every number', function () {
    $cestinato = Account::factory()->saas()->create();
    UnitaOrganizzativa::factory()->ente()->perAccount($cestinato)->create()->delete();
    $cestinato->delete();

    Strumento::factory()->forNode($this->sede)->create()->delete();

    $r = MetrichePiattaforma::riepilogo();

    expect($r->clienti)->toBe(1)
        ->and($r->sedi)->toBe(1)
        ->and($r->strumenti)->toBe(0)
        ->and($r->mrrCent)->toBe(Piani::prezzoMensileCent('saas'));
});

it('never counts EasyLab among its own customers, in any of the four numbers', function () {
    // ⚠️ La prima stesura creava l'account di piattaforma **senza Ente**, quindi
    // `sedi` e `strumenti` non erano dimostrabili — e infatti li contavano. Sul
    // database di sviluppo erano una sede di nessuno e **1.217 macchine su
    // 5.105**, il 24% di un numero etichettato «macchine di tutti i clienti».
    // Senza queste due righe di fixture, nessuna correzione è provabile.
    $easylab = Account::factory()->diPiattaforma()->saas()->create(['ragione_sociale' => 'EasyLab']);
    $sedeSua = UnitaOrganizzativa::factory()->ente()->perAccount($easylab)->create();
    Strumento::factory()->forNode($sedeSua)->create();

    Strumento::factory()->forNode($this->sede)->create();

    $r = MetrichePiattaforma::riepilogo();

    expect($r->clienti)->toBe(1)
        ->and($r->mrrCent)->toBe(Piani::prezzoMensileCent('saas'))
        ->and($r->sedi)->toBe(1)
        ->and($r->strumenti)->toBe(1);
});

it('drops a deleted customer out of all four numbers, sedi and strumenti included', function () {
    // `Account` non propaga il soft delete ai figli: cestinare un cliente lo
    // toglieva da `clienti` e dall'MRR ma lasciava le sue sedi e le sue macchine
    // nei totali **per sempre** — due tile della stessa riga che si
    // contraddicono. Qui si cestina SOLO l'account, che è il gesto vero.
    $andato = Account::factory()->saas()->create();
    $suaSede = UnitaOrganizzativa::factory()->ente()->perAccount($andato)->create();
    Strumento::factory()->forNode($suaSede)->create();

    $andato->delete();

    $r = MetrichePiattaforma::riepilogo();

    expect($r->clienti)->toBe(1)
        ->and($r->sedi)->toBe(1)
        ->and($r->strumenti)->toBe(0)
        ->and($r->mrrCent)->toBe(Piani::prezzoMensileCent('saas'));
});

it('says how much money is frozen, not how many heads', function () {
    // Il DTO prometteva «quanta parte di quel numero non si sta incassando» e la
    // tile mostrava un conteggio di teste. Un Free bloccato produceva
    // «588 € · 1 cliente bloccato» dove i 588 € sono interi e incassabili.
    Account::factory()->create()->blocca('Insoluto');   // Free bloccato: 0 € fermi
    $this->cliente->blocca('Insoluto fattura 42');      // SaaS bloccato: il suo listino

    $r = MetrichePiattaforma::riepilogo();

    expect($r->clientiBloccati)->toBe(2)
        ->and($r->mrrBloccatoCent)->toBe(Piani::prezzoMensileCent('saas'))
        ->and($r->mrrCent)->toBe(Piani::prezzoMensileCent('saas'));
});

it('keeps the «di cui» adding up to the number above it', function () {
    // Due numeri adiacenti che si contraddicono sono l'errore che questa pagina
    // non si può permettere: chi legge un «di cui» la somma la fa. Con un piano
    // dismesso, il dettaglio mostrava «1 SaaS» sotto un totale di 2.
    $orfano = Account::factory()->create();
    $orfano->forceFill(['piano' => 'dismesso'])->save();

    $r = MetrichePiattaforma::riepilogo();

    expect($r->clienti)->toBe(2)
        ->and($r->dettaglioClienti())->toBe('1 SaaS · 1 piano sconosciuto');
});

it('skips the zeros in the «di cui», which are noise in a sentence', function () {
    // I piani a zero restano in `perPiano` (un elenco che li nasconde si legge
    // come «quel piano non esiste»), ma in coda a un numero un «0 Free ·» è
    // rumore che comparirebbe a ogni apertura.
    expect(MetrichePiattaforma::riepilogo())
        ->perPiano->toHaveKey('free')
        ->and(MetrichePiattaforma::riepilogo()->dettaglioClienti())->toBe('1 SaaS');
});

it('keeps every list price a whole number of euro', function () {
    // ⚠️ `mrrEuro()` usa `intdiv`, quindi tronca. Con 4900 non si vede; con 4950
    // e sette clienti mostrerebbe 346 € invece di 346,50 — per difetto, in modo
    // NON proporzionale, quindi irriconoscibile a una rilettura. L'assunzione si
    // difende dove viene fatta, e il listino di oggi è dichiarato un segnaposto.
    foreach (Piani::codici() as $codice) {
        expect(Piani::prezzoMensileCent($codice) % 100)->toBe(
            0,
            "Il piano «{$codice}» ha un listino con i centesimi: mrrEuro() lo troncherebbe in silenzio."
        );
    }
});

it('counts a trialing customer at list price, on purpose', function () {
    // Decisione, non effetto: la subscription è aperta e il contratto firmato.
    // Il caso è raggiungibile (`easylab:abbona --trial-giorni`, e `trialing` è
    // fra gli stati sani del webhook), quindi senza questo test sarebbe un
    // comportamento che nessuno ha scelto.
    $this->cliente->forceFill(['trial_ends_at' => now()->addDays(14)])->save();

    expect(MetrichePiattaforma::riepilogo()->mrrCent)->toBe(Piani::prezzoMensileCent('saas'));
});

it('keeps a locked customer in the revenue, and says so beside it', function () {
    // ADR-013: il lockout è una porta chiusa, non una disdetta — il contratto
    // resta in essere. Toglierlo dall'MRR racconterebbe una disdetta che non
    // c'è stata; per questo il contatore 🔒 sta **accanto** al numero e non al
    // posto suo.
    $this->cliente->blocca('Insoluto fattura 42');

    $r = MetrichePiattaforma::riepilogo();

    expect($r->mrrCent)->toBe(Piani::prezzoMensileCent('saas'))
        ->and($r->clientiBloccati)->toBe(1)
        ->and($r->clienti)->toBe(1);
});

it('counts a customer locked by Stripe too, not only one locked by hand', function () {
    // `is_locked` significa «almeno una delle due sorgenti è accesa» (ADR-013):
    // un conteggio che guardasse solo `locked_at` mancherebbe tutti gli insoluti
    // automatici, che sono la maggioranza.
    $this->cliente->bloccaPerStripe('Stripe: abbonamento in stato «unpaid».');

    expect(MetrichePiattaforma::riepilogo()->clientiBloccati)->toBe(1);
});

it('keeps a past_due customer paying, because Stripe is still trying', function () {
    // ADR-013 non blocca su `past_due`: il dunning è in corso e il cliente ha
    // giorni per rimediare. Toglierlo dall'MRR sarebbe contarlo perso mentre sta
    // ancora pagando. È il caso che qualcuno «correggerà» fra sei mesi.
    DB::table('subscriptions')->insert([
        'account_id' => $this->cliente->id,
        'type' => 'default',
        'stripe_id' => 'sub_past_due',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_x',
        'quantity' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $r = MetrichePiattaforma::riepilogo();

    expect($r->mrrCent)->toBe(Piani::prezzoMensileCent('saas'))
        ->and($r->clientiBloccati)->toBe(0);
});

it('survives a plan that left the catalogue, instead of dying on the page that repairs it', function () {
    // ⚠️ `Piani::prezzoMensileCent()` lancia su un piano sconosciuto, ed è
    // giusto in console. Qui no: questa è l'unica schermata da cui quel dato si
    // ripara, e morire proprio lì sarebbe il modo peggiore di segnalarlo. Il
    // caso è documentato come legittimo da `Piani::perPrice()` — basta
    // dismettere un codice perché ogni riga rimasta diventi orfana.
    $orfano = Account::factory()->create();
    $orfano->forceFill(['piano' => 'dismesso'])->save();

    $r = MetrichePiattaforma::riepilogo();

    expect($r->pianiSconosciuti)->toBe(1)
        ->and($r->clienti)->toBe(2)                                     // conta comunque come cliente
        ->and($r->mrrCent)->toBe(Piani::prezzoMensileCent('saas'))      // ma vale 0 €
        ->and($r->perPiano)->not->toHaveKey('dismesso');
});

it('shows the orphaned plan on the page, so it can be repaired', function () {
    $orfano = Account::factory()->create();
    $orfano->forceFill(['piano' => 'dismesso'])->save();

    $this->get(route('piattaforma.index'))
        ->assertOk()
        ->assertSee('non è più a catalogo');
});

it('counts every tenant, which is the whole point of the door', function () {
    $altro = Account::factory()->create();
    $sedeAltrui = UnitaOrganizzativa::factory()->ente()->perAccount($altro)->create();
    Strumento::factory()->forNode($sedeAltrui)->create();
    Strumento::factory()->forNode($this->sede)->create();

    $r = MetrichePiattaforma::riepilogo();

    expect($r->sedi)->toBe(2)
        ->and($r->strumenti)->toBe(2);
});

it('counts only Enti as sedi, not every node of the tree', function () {
    // Un dipartimento non è una sede: contarlo gonfierebbe il numero che il
    // wireframe chiama «Sedi» con la profondità dell'alberatura di ciascun
    // cliente, cioè con un dato che non riguarda la piattaforma.
    UnitaOrganizzativa::factory()->dipartimento()->under($this->sede)->create();

    expect(MetrichePiattaforma::riepilogo()->sedi)->toBe(1);
});

it('stays at three statements no matter how many customers there are', function () {
    // ⚠️ **Si contano gli STATEMENT, non le citazioni di una tabella.** La
    // convenzione del progetto è filtrare per tabella (`from "strumenti"`), e
    // qui non basta più: le sottoquery che legano sedi e macchine alla nozione
    // di cliente **nominano** `accounts` e `unita_organizzativa` dentro lo
    // stesso SQL, quindi un conteggio per tabella diceva 3 e 2 dove i
    // round-trip erano uno solo. Misurava la cosa sbagliata.
    //
    // Il filtro sulle tre tabelle resta, e serve a restare ciechi — giustamente
    // — alle query di sessione e a quelle dei permessi di spatie, che `porta()`
    // provoca tre volte.
    $conta = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        MetrichePiattaforma::riepilogo();
        $n = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], '"accounts"')
                || str_contains($q['query'], '"unita_organizzativa"')
                || str_contains($q['query'], '"strumenti"'))
            ->count();
        DB::disableQueryLog();

        return $n;
    };

    expect($conta())->toBe(3);

    collect(range(1, 11))->each(function () {
        $a = Account::factory()->saas()->create();
        $e = UnitaOrganizzativa::factory()->ente()->perAccount($a)->create();
        Strumento::factory()->forNode($e)->create();
    });

    // La costanza è ciò che conta: dodici clienti non devono costare più di uno.
    expect($conta())->toBe(3);
});

it('puts each number under its own label', function () {
    // ⚠️ Fra il DTO — testato a fondo — e la pagina non c'era **nessuna**
    // verifica: si potevano scambiare i valori di «Sedi» e «Strumenti» fra le
    // due tile e la suite restava verde. Numeri volutamente distinti e
    // `assertSeeInOrder`, così la coppia label→valore è congelata per tutte le
    // tile, anche quelle future.
    UnitaOrganizzativa::factory()->ente()->perAccount($this->cliente)->create();  // 2 sedi
    Strumento::factory()->count(3)->forNode($this->sede)->create();               // 3 strumenti

    // ⚠️ Si asserisce sul **testo senza markup**, non sull'HTML: `assertSeeInOrder`
    // con cifre nude cerca sottostringhe in un documento pieno di `max-w-7xl` e
    // `grid-cols-2`, quindi passava per una classe CSS — la prova di mutazione
    // l'ha mostrato scambiando due valori senza far cadere niente.
    $testo = preg_replace('/\s+/', ' ', strip_tags(
        $this->get(route('piattaforma.index'))->assertOk()->getContent()
    ));

    expect($testo)->toMatch('/Ricavo mensile 49 €/')
        ->and($testo)->toMatch('/Clienti 1 /')
        ->and($testo)->toMatch('/Sedi 2 /')
        ->and($testo)->toMatch('/Strumenti 3/');
});

it('refuses to count for someone without the platform permission', function () {
    // Le metriche passano dalla porta, quindi ereditano il suo gate: non esiste
    // una seconda strada che aggreghi senza chiedere il permesso.
    $tenant = User::factory()->create(['tenant_id' => $this->sede->id]);
    $tenant->assignRole('Tenant');
    $this->actingAs($tenant->fresh());

    expect(fn () => MetrichePiattaforma::riepilogo())
        ->toThrow(AuthorizationException::class);
});
