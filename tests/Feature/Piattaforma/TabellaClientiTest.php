<?php

use App\Livewire\Piattaforma\Cabina;
use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * L'elenco dei clienti della cabina di regia (S6 — Wireframe §4).
 *
 * La riga è l'**Account** e si espande nelle sue sedi: è la prima tabella del
 * progetto che mostra dati di tenant diversi sulla stessa pagina, quindi il
 * rischio non è la colonna storta — è la riga del cliente sbagliato accanto a
 * quella giusta, che nessuno nota perché la pagina *deve* mostrare più clienti.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $this->actingAs($this->superadmin->fresh());

    $this->rossi = Account::factory()->saas()->create([
        'ragione_sociale' => 'Gruppo Rossi',
        'partita_iva' => '01234567890',
    ]);
    $this->sedeRossi = UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)
        ->create(['nome' => 'Laboratorio San Raffaele']);

    $this->bianchi = Account::factory()->create(['ragione_sociale' => 'Bianchi SRL']);
    $this->sedeBianchi = UnitaOrganizzativa::factory()->ente()->perAccount($this->bianchi)
        ->create(['nome' => 'Sede di Bergamo']);
});

/** Le ragioni sociali in pagina, nell'ordine in cui ci stanno. */
function inPagina(Testable $t): array
{
    return $t->viewData('clienti')->pluck('ragione_sociale')->all();
}

// --- Negativi: chi NON deve comparire, e cosa NON deve raggiungere ---

it('never lists EasyLab among the customers', function () {
    // Stesso confine dei KPI: un cliente in più nell'elenco è un cliente in più
    // in ogni conteggio che qualcuno fa guardando la pagina.
    $piattaforma = Account::factory()->create([
        'ragione_sociale' => 'EasyLab',
        'di_piattaforma' => true,
    ]);
    UnitaOrganizzativa::factory()->ente()->perAccount($piattaforma)->create();

    expect(inPagina(Livewire::test(Cabina::class)))->not->toContain('EasyLab');
});

it('never lists a trashed customer', function () {
    Account::factory()->saas()->create(['ragione_sociale' => 'Verdi Cessata'])->delete();

    expect(inPagina(Livewire::test(Cabina::class)))->not->toContain('Verdi Cessata');
});

it('does not surface a customer through the name of a trashed sede', function () {
    // 🔴 Il difetto che il guardrail sui bypass nudi ha fermato mentre questo
    // blocco veniva scritto: la sottoquery di ricerca toglieva TUTTI i global
    // scope, soft delete compreso. Il cliente compariva cercando una sede che
    // non esiste più, e nella riga si mostrava con zero sedi — cioè con il
    // nome cercato invisibile. Una ricerca che trova e non spiega.
    $this->sedeRossi->delete();

    expect(inPagina(Livewire::test(Cabina::class)->set('search', 'San Raffaele')))
        ->toBe([]);
});

it('expands one customer without showing another customer sedi', function () {
    // 🔴 **Asserito sul render, non su `viewData`.** La prima stesura guardava
    // `sediPerAccount[$rossi->id]`, che contiene solo le sedi di Rossi *per
    // costruzione del `groupBy`*: era vera qualunque cosa facesse il template.
    // Il confronto lo ha dimostrato sostituendo `$sue` con
    // `$sediPerAccount->flatten()` nella vista — cioè «aprire un cliente mostra
    // le sedi di tutti i clienti della pagina» — e il test restava verde.
    // Questa è l'unica pagina del progetto che tiene dati di tenant diversi
    // nella stessa risposta: qui la prova deve guardare la risposta.
    Livewire::test(Cabina::class)
        ->call('espandi', $this->rossi->id)
        ->assertSet('espanso', $this->rossi->id)
        ->assertSee('Laboratorio San Raffaele')
        ->assertDontSee('Sede di Bergamo');
});

it('counts sedi the same way the KPI tile does, even on a row the model would refuse', function () {
    // La coerenza fra la tile «Sedi» in alto e la colonna «Sedi» in basso
    // poggia sul filtro `tipo = Ente`, che **sembra ridondante**: `account_id`
    // vive solo sui nodi ente, quindi il `whereIn('account_id', …)` da solo
    // basterebbe. Sembra — e per questo il filtro va provato per la strada da
    // cui il difetto arriverebbe davvero.
    //
    // ⚠️ Quell'invariante è una **guardia sul modello, non un CHECK**:
    // `UnitaOrganizzativa` la impone su `creating`/`updating`, e il suo stesso
    // docblock scrive che un vincolo di schema sarebbe stato la sede giusta. Una
    // migration di correzione, un `DB::table()->update()` o un import
    // scriverebbero quella riga senza incontrare nessun ostacolo. Quindi la si
    // scrive **così**, scavalcando gli eventi come li scavalcherebbe un fix a
    // mano, e si verifica che né la colonna né la tile ci cadano.
    $dipartimento = UnitaOrganizzativa::factory()->dipartimento()->under($this->sedeRossi)->create();
    DB::table('unita_organizzativa')->where('id', $dipartimento->id)
        ->update(['account_id' => $this->rossi->id]);

    $t = Livewire::test(Cabina::class);

    expect($t->viewData('sediPerAccount')->get($this->rossi->id)->count())->toBe(1)
        ->and($t->viewData('riepilogo')->sedi)->toBe(2); // Rossi + Bianchi, nessun dipartimento
});

it('loads only the sedi of the customers on the page', function () {
    // L'altro filtro scoperto: senza `whereIn('account_id', $ids)` la pagina
    // caricherebbe in memoria le sedi di **tutta la piattaforma** per servirne
    // venti, e il `groupBy` maschererebbe l'errore in vista — nessuna
    // asserzione sul contenuto lo vedrebbe mai.
    Account::factory()->count(25)->create()->each(
        fn ($a) => UnitaOrganizzativa::factory()->ente()->perAccount($a)->create()
    );

    $t = Livewire::test(Cabina::class);

    expect($t->viewData('sediPerAccount')->flatten()->count())
        ->toBe($t->viewData('clienti')->count());
});

it('goes back to the first page when the order changes', function () {
    // Cambiare ordinamento rimescola tutte le righe: restare a pagina 3 fa
    // atterrare a metà di un elenco che non si è mai visto dall'inizio. È la
    // riga che `ElencoStrumenti::sort()` ha e che questo trait, dichiarando di
    // imitarlo, aveva perso per strada.
    Account::factory()->count(60)->create();

    $t = Livewire::test(Cabina::class)->call('gotoPage', 3)->call('ordina', 'piano');

    expect($t->viewData('clienti')->currentPage())->toBe(1);
});

it('lets the orphaned plans be reached, not just announced', function () {
    // L'avviso sopra la tabella dice che quei clienti si riparano da qui: senza
    // un filtro che li isoli, su trecento righe non c'è modo di trovarli.
    Account::factory()->create(['ragione_sociale' => 'Piano Dismesso SPA', 'piano' => 'gold']);

    expect(inPagina(Livewire::test(Cabina::class)->set('piano', Cabina::FUORI_CATALOGO)))
        ->toBe(['Piano Dismesso SPA']);
});

it('does not treat a broken plan as an unlimited one', function () {
    // `maxEnti()` restituisce `null` per «illimitato»; un piano fuori catalogo
    // produce `null` per «non lo so». Fonderli fa leggere il caso corrotto come
    // il più permissivo — proprio sulla riga che la pagina invita a riparare.
    $rotto = Account::factory()->create(['ragione_sociale' => 'Piano Dismesso SPA', 'piano' => 'gold']);
    UnitaOrganizzativa::factory()->ente()->perAccount($rotto)->create();

    Livewire::test(Cabina::class)
        ->set('piano', Cabina::FUORI_CATALOGO)
        // Il conteggio e il limite sono due nodi distinti nel markup, quindi si
        // asserisce sul limite: è quello la cosa che può mentire.
        ->assertSee('/ ?')
        ->assertDontSee('/ ∞');
});

it('closes the other way into the expanded row too', function () {
    // `espanso` è una property pubblica: Livewire ne accetta l'update diretto
    // senza passare da `espandi()`. Oggi non è un trapelamento — la vista la usa
    // solo per indicizzare un gruppo già ristretto alla pagina — ma un'azione
    // guardata accanto a una property libera è un'invariante che *sembra*
    // chiusa. Le due strade devono dire la stessa cosa.
    $cestinato = Account::factory()->create();
    $cestinato->delete();

    expect(fn () => Livewire::test(Cabina::class)->set('espanso', $cestinato->id))
        ->toThrow(ModelNotFoundException::class);

    // E chiudere la riga resta possibile: `null` non è un id da verificare.
    Livewire::test(Cabina::class)->set('espanso', null)->assertSet('espanso', null);
});

it('refuses to expand an account that is not a customer', function () {
    // L'id arriva dal browser e `Account` non ha global scope: senza la
    // rilettura da `VistaPiattaforma::accounts()` questa azione aprirebbe
    // qualunque riga della tabella, EasyLab e cestinati compresi.
    $cestinato = Account::factory()->create();
    $cestinato->delete();

    expect(fn () => Livewire::test(Cabina::class)->call('espandi', $cestinato->id))
        ->toThrow(ModelNotFoundException::class);

    $piattaforma = Account::factory()->create(['di_piattaforma' => true]);

    expect(fn () => Livewire::test(Cabina::class)->call('espandi', $piattaforma->id))
        ->toThrow(ModelNotFoundException::class);
});

it('ignores a sort column that did not come from the whitelist', function () {
    // `sortBy` finisce dentro `orderBy()` e arriva dalla query string senza
    // passare da `updatingSortBy()`: la ri-validazione sta a ogni render, e
    // questo test la esercita per la strada che la aggira.
    $t = Livewire::test(Cabina::class)->set('sortBy', 'password')->set('sortDir', 'drop');

    expect(inPagina($t))->toBe(['Bianchi SRL', 'Gruppo Rossi']);

    // 🔴 E la **freccia** dice la stessa cosa della query. È l'invariante che ha
    // reso necessario estrarre `filtriNormalizzati()`: finché la whitelist stava
    // in due posti, la pagina poteva ordinare per una colonna e indicarne
    // un'altra — una bugia piccola, e proprio per questo credibile. Da S6 il
    // terzo consumatore della stessa definizione è il foglio esportato, dove la
    // bugia esce dall'applicazione su carta e non si corregge ricaricando.
    expect($t->instance()->ordinamentoEffettivo())->toBe(['ragione_sociale', 'asc']);
});

it('ignores a click on a column that is not sortable', function () {
    $t = Livewire::test(Cabina::class)->call('ordina', 'stripe_id');

    expect($t->get('sortBy'))->toBe('ragione_sociale');
});

it('ignores a page size that is not on the menu', function () {
    Account::factory()->count(25)->create();

    $t = Livewire::test(Cabina::class)->set('perPage', 1000);

    expect($t->viewData('clienti')->perPage())->toBe(20);
});

// --- Positivi: la tabella fa il suo mestiere ---

it('searches by company name, VAT number and sede name', function () {
    expect(inPagina(Livewire::test(Cabina::class)->set('search', 'rossi')))->toBe(['Gruppo Rossi'])
        ->and(inPagina(Livewire::test(Cabina::class)->set('search', '0123456')))->toBe(['Gruppo Rossi'])
        // Chi cerca «San Raffaele» pensa al laboratorio, non alla ragione
        // sociale che lo fattura.
        ->and(inPagina(Livewire::test(Cabina::class)->set('search', 'san raffaele')))->toBe(['Gruppo Rossi'])
        ->and(inPagina(Livewire::test(Cabina::class)->set('search', 'bergamo')))->toBe(['Bianchi SRL']);
});

it('filters by plan', function () {
    expect(inPagina(Livewire::test(Cabina::class)->set('piano', 'saas')))->toBe(['Gruppo Rossi'])
        ->and(inPagina(Livewire::test(Cabina::class)->set('piano', 'free')))->toBe(['Bianchi SRL']);
});

it('filters the two lockout sources apart, because they are two', function () {
    // ADR-013: un solo filtro «bloccato» nasconderebbe la distinzione che
    // esiste apposta — chi cerca gli insoluti non vuole trovare chi è chiuso
    // per contenzioso, e viceversa.
    $this->rossi->blocca('Contenzioso aperto');
    $this->bianchi->bloccaPerStripe('Pagamento fallito');

    expect(inPagina(Livewire::test(Cabina::class)->set('stato', 'bloccato_manuale')))->toBe(['Gruppo Rossi'])
        ->and(inPagina(Livewire::test(Cabina::class)->set('stato', 'bloccato_stripe')))->toBe(['Bianchi SRL'])
        ->and(inPagina(Livewire::test(Cabina::class)->set('stato', 'attivo')))->toBe([]);
});

it('counts the instruments of each sede and of each customer', function () {
    Strumento::factory()->count(3)->forNode($this->sedeRossi)->create();
    Strumento::factory()->forNode($this->sedeBianchi)->create();

    $t = Livewire::test(Cabina::class);

    expect($t->viewData('strumentiPerAccount')[$this->rossi->id])->toBe(3)
        ->and($t->viewData('strumentiPerSede')[$this->sedeRossi->id])->toBe(3)
        ->and($t->viewData('strumentiPerAccount')[$this->bianchi->id])->toBe(1);
});

it('keeps the query count flat from one customer to twelve', function () {
    // ⚠️ Scaldare la cache dei permessi prima di misurare: al primo render
    // spatie carica ruoli e permessi, e senza questa riga il confronto
    // misurerebbe l'ordine dei due render invece del numero di clienti.
    Livewire::test(Cabina::class);

    $conta = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(Cabina::class);
        // Filtrato per tabella, come ogni altro conteggio del progetto: un
        // totale assoluto conta anche sessione e permessi, che variano fra
        // driver e fra prima e seconda chiamata.
        $n = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'from "accounts"')
                || str_contains($q['query'], 'from "unita_organizzativa"')
                || str_contains($q['query'], 'from "strumenti"'))
            ->count();
        DB::disableQueryLog();

        return $n;
    };

    $conDue = $conta();

    for ($i = 0; $i < 10; $i++) {
        $account = Account::factory()->create();
        $sede = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create();
        Strumento::factory()->forNode($sede)->create();
    }

    expect($conta())->toBe($conDue);
});
