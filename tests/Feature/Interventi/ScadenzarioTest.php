<?php

use App\Enums\TipoIntervento;
use App\Livewire\Interventi\Scadenzario;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Semaforo;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Comportamento dello Scadenzario aggregato (🔗 ADR-005/007/011/018/021 —
 * Wireframe §5).
 *
 * L'isolamento — tenancy, sotto-albero, fail-closed, portafoglio del Tecnico —
 * vive in `ScadenzarioIsolationTest`, che è l'area rossa: qui si prova cosa la
 * pagina mostra a chi ha già il diritto di vederlo.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Microbiologia']);
    $this->autoclave = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);
    $this->cappa = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Cappa chimica']);

    $this->admin = User::factory()->create([
        'tenant_id' => $this->ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('Admin');
});

it('redirects guests to login', function () {
    $this->get(route('scadenzario.index'))->assertRedirect(route('login'));
});

it('forbids anyone without interventi.view', function () {
    // Nessuno dei sei ruoli del seeder è privo di `interventi.view`: per provare
    // il 403 serve un ruolo ad hoc, come già fa `HomeCampoTest`.
    Role::create(['name' => 'Magazziniere'])->givePermissionTo('strumenti.view');
    $u = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $u->assignRole('Magazziniere');

    $this->actingAs($u)->get(route('scadenzario.index'))->assertForbidden();
});

it('lets in a role that has interventi.view', function () {
    // Il positivo accanto al 403: senza, «vieta a tutti» sarebbe verde.
    $this->actingAs($this->admin)->get(route('scadenzario.index'))->assertOk();
});

it('carries the permission gate on the route itself', function () {
    // 🔴 La guardia della pagina è il `can:` di ROTTA: il componente è di sola
    // lettura e non ha azioni con `skipRender()` che potrebbero scavalcarla.
    // Il 403 qui sopra la prova sul comportamento; questa riga la prova sulla
    // **definizione**, cioè rende rosso il momento in cui qualcuno toglie il
    // middleware — che è il gesto, non il sintomo.
    //
    // ⚠️ Si legge la rotta vera invece di riscriverne l'elenco: due liste
    // divergono, e la copia che diverge smette di controllare proprio ciò che
    // è appena cambiato.
    $rotta = Route::getRoutes()->getByName('scadenzario.index');

    expect($rotta)->not->toBeNull()
        ->and($rotta->gatherMiddleware())->toContain('can:interventi.view')
        // L'altra metà del cancello: la pagina sta dentro il gruppo autenticato.
        ->and($rotta->gatherMiddleware())->toContain('auth');
});

it('lists only the open interventi, never a completed one', function () {
    Intervento::factory()->forStrumento($this->autoclave)
        ->create(['data_scadenza' => today()->addDay()->toDateString(), 'descrizione' => 'Ancora da fare']);

    // Scadenza vicinissima, ma già eseguito: uno scadenzario che lo mostrasse
    // direbbe che c'è da fare una cosa fatta.
    Intervento::factory()->forStrumento($this->cappa)->fatto()
        ->create(['data_scadenza' => today()->toDateString(), 'descrizione' => 'Gia chiuso ieri']);

    Livewire::actingAs($this->admin)->test(Scadenzario::class)
        ->assertSee('Ancora da fare')
        ->assertDontSee('Gia chiuso ieri')
        ->tap(fn ($c) => expect($c->viewData('interventi')->total())->toBe(1));
});

it('orders by scadenza ascending, with the overdue on top', function () {
    $futuro = Intervento::factory()->forStrumento($this->autoclave)
        ->create(['data_scadenza' => today()->addDays(40)->toDateString(), 'descrizione' => 'Controllo pressione']);
    $scaduto = Intervento::factory()->forStrumento($this->cappa)->scaduto()
        ->create(['descrizione' => 'Sostituzione filtro']);

    Livewire::actingAs($this->admin)->test(Scadenzario::class)
        ->assertSeeInOrder(['Sostituzione filtro', 'Controllo pressione'])
        ->tap(fn ($c) => expect($c->viewData('interventi')->pluck('id')->all())
            ->toBe([$scaduto->id, $futuro->id]));
});

it('inverts the order, and goes back to the first page when it does', function () {
    // ⚠️ Servono abbastanza righe perché la **pagina 2 esista davvero**: da
    // quando una pagina oltre l'ultima viene ricondotta all'ultima esistente, un
    // `setPage(2)` su due sole righe non resterebbe sulla 2, e il test
    // proverebbe quel rimbalzo invece dell'inversione che vuole provare.
    foreach (range(1, 25) as $n) {
        Intervento::factory()->forStrumento($this->autoclave)
            ->create(['data_scadenza' => today()->addDays($n)->toDateString()]);
    }

    Livewire::actingAs($this->admin)->test(Scadenzario::class)
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->call('invertiOrdine')
        ->assertSet('sortDir', 'desc')
        ->assertSet('paginators.page', 1)
        // E l'ordine è davvero invertito: in cima c'è la scadenza più lontana.
        ->tap(fn ($c) => expect($c->viewData('interventi')->first()->data_scadenza->toDateString())
            ->toBe(today()->addDays(25)->toDateString()));
});

it('ends every ordering with a tie-break, so pages cannot lose rows', function () {
    // 🔴 **Si asserisce sull'SQL, non sulle righe raccolte**, ed è la lezione
    // già pagata da `ElencoStrumentiTest`: togliendo il tie-break la suite resta
    // verde su entrambi i driver a seconda del piano scelto. Un test che coglie
    // un difetto una volta su tre non è una rete, è un aneddoto.
    //
    // Qui pesa più che altrove: `data_scadenza` ha pochissimi valori distinti su
    // un parco vero, quindi i pari sono quasi tutte le righe.
    Intervento::factory()->count(3)->forStrumento($this->autoclave)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);

    foreach (['asc', 'desc'] as $direzione) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withQueryParams(['sortDir' => $direzione])
            ->actingAs($this->admin)->test(Scadenzario::class);
        $query = collect(DB::getQueryLog())
            ->pluck('query')
            ->first(fn (string $q) => str_contains($q, 'from "interventi"') && str_contains($q, 'order by'));
        DB::disableQueryLog();

        expect($query)->not->toBeNull("Nessuna query ordinata per «{$direzione}»");

        // ⚠️ Il `limit … offset …` si toglie con un'ancora di FINE stringa: un
        // `strpos(' limit ')` prenderebbe un eventuale limit di sottoquery.
        //
        // ⚠️ Nessun messaggio dentro `toEndWith`/`toContain`: sono variadici, e
        // il testo diventerebbe un secondo ago — cioè un'asserzione che non può
        // fallire. La spiegazione sta qui, in un commento.
        $ordinamento = preg_replace(
            '/\s+limit\s+\d+(\s+offset\s+\d+)?$/',
            '',
            substr($query, strpos($query, 'order by'))
        );

        expect($ordinamento)->toEndWith('"interventi"."id" asc');
    }
});

it('splits the open interventi in three partitions that are exactly complementary', function () {
    // 🔴 Il caso che conta è **oggi**: `isScaduto()` esclude la giornata in
    // corso, quindi un intervento che scade oggi sta fra i NON scaduti. Se il
    // taglio del ramo «in scadenza» diventasse `> oggi`, quella riga
    // sparirebbe da tutte e tre le partizioni senza che nessun conteggio
    // sembrasse sbagliato.
    $soglia = Semaforo::giorniImminente();

    $ieri = Intervento::factory()->forStrumento($this->autoclave)
        ->create(['data_scadenza' => today()->subDay()->toDateString()]);
    $oggi = Intervento::factory()->forStrumento($this->autoclave)
        ->create(['data_scadenza' => today()->toDateString()]);
    $domani = Intervento::factory()->forStrumento($this->cappa)
        ->create(['data_scadenza' => today()->addDay()->toDateString()]);
    $alConfine = Intervento::factory()->forStrumento($this->cappa)
        ->create(['data_scadenza' => today()->addDays($soglia)->toDateString()]);
    $oltre = Intervento::factory()->forStrumento($this->cappa)
        ->create(['data_scadenza' => today()->addDays($soglia + 1)->toDateString()]);

    $idsDi = function (?string $stato): array {
        return Livewire::withQueryParams(['stato' => $stato])
            ->actingAs($this->admin)->test(Scadenzario::class)
            ->viewData('interventi')->pluck('id')->sort()->values()->all();
    };

    $tutti = $idsDi(null);
    $scaduti = $idsDi('scaduti');
    $inScadenza = $idsDi('in_scadenza');
    $oltreSoglia = $idsDi('oltre');

    expect($tutti)->toHaveCount(5)
        ->and($scaduti)->toBe([$ieri->id])
        ->and($inScadenza)->toBe([$oggi->id, $domani->id, $alConfine->id])
        ->and($oltreSoglia)->toBe([$oltre->id]);

    // Le tre partizioni coprono TUTTO e non si sovrappongono.
    $unione = collect([$scaduti, $inScadenza, $oltreSoglia])->flatten()->sort()->values()->all();

    expect($unione)->toBe($tutti)
        ->and(array_intersect($scaduti, $inScadenza))->toBe([])
        ->and(array_intersect($inScadenza, $oltreSoglia))->toBe([])
        ->and(array_intersect($scaduti, $oltreSoglia))->toBe([]);
});

it('cuts both boundaries with >= in the SQL, and not only in the data', function () {
    // 🔴 **La prova va fatta sull'SQL, non sui dati — e qui è stato MISURATO.**
    // Mutando `>=` in `>` nel ramo «in scadenza» la suite restava **verde**: su
    // SQLite le colonne `date` sono stringhe `'Y-m-d 00:00:00'` e i confronti
    // sono lessicografici, quindi `'2026-08-27 00:00:00' > '2026-08-27'` è VERO
    // e i due operatori danno lo stesso risultato. Su Postgres — cioè in CI e in
    // produzione — `>` farebbe sparire da TUTTE E TRE le partizioni l'intervento
    // che scade oggi, senza che nessun conteggio sembri sbagliato.
    //
    // Il test di complementarità qui sotto resta utile (dice cosa significano le
    // tre partizioni), ma su SQLite non è la rete di questo confine: la rete è
    // questa.
    // ⚠️ Servono righe in entrambe le partizioni: senza, il paginatore conta
    // zero e **non emette affatto** la query di pagina — il test cercherebbe una
    // query che non c'è e sarebbe rosso per il motivo sbagliato.
    Intervento::factory()->forStrumento($this->autoclave)
        ->create(['data_scadenza' => today()->addDays(2)->toDateString()]);
    Intervento::factory()->forStrumento($this->cappa)
        ->create(['data_scadenza' => today()->addDays(Semaforo::giorniImminente() + 5)->toDateString()]);

    $sqlDellaPagina = function (string $stato): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withQueryParams(['stato' => $stato])->actingAs($this->admin)->test(Scadenzario::class);
        $riga = collect(DB::getQueryLog())
            ->first(fn (array $q) => str_contains($q['query'], 'from "interventi"') && str_contains($q['query'], 'order by'));
        DB::disableQueryLog();

        expect($riga)->not->toBeNull("Nessuna query di pagina per la partizione «{$stato}»");

        return $riga;
    };

    // ⚠️ Nessun messaggio dentro `toContain`: è variadico, e il testo diventerebbe
    // un secondo ago. La spiegazione sta in questo commento.
    $inScadenza = $sqlDellaPagina('in_scadenza');
    expect($inScadenza['query'])->toContain('"interventi"."data_scadenza" >= ?')
        ->and($inScadenza['bindings'])->toContain(today()->toDateString());

    // Il confine superiore si esprime come `>= oggi+soglia+1` e mai come
    // `> oggi+soglia`, per la stessa ragione e nella stessa forma di
    // `Intervento::scopeApertiEntroSoglia()`.
    $oltre = $sqlDellaPagina('oltre');
    expect($oltre['query'])->toContain('"interventi"."data_scadenza" >= ?')
        ->and($oltre['bindings'])->toContain(today()->addDays(Semaforo::giorniImminente() + 1)->toDateString());
});

it('reads the imminente boundary from the config, never from a hand-written 30', function () {
    // La soglia è quella di `Semaforo::giorniImminente()` (ADR-005): spostandola
    // nel config, la riga passa da una partizione all'altra. Un 30 scritto a
    // mano in un ramo lo lascerebbe dov'era.
    config(['easylab.semaforo.giorni_imminente' => 7]);

    $dentro = Intervento::factory()->forStrumento($this->autoclave)
        ->create(['data_scadenza' => today()->addDays(7)->toDateString(), 'descrizione' => 'Appena dentro']);
    $fuori = Intervento::factory()->forStrumento($this->cappa)
        ->create(['data_scadenza' => today()->addDays(8)->toDateString(), 'descrizione' => 'Appena fuori']);

    $componente = Livewire::actingAs($this->admin)->test(Scadenzario::class);

    expect($componente->viewData('contatori'))->toBe([
        'scaduti' => 0,
        'in_scadenza' => 1,
        'oltre' => 1,
    ]);

    // E la frase sotto la tile dice SETTE, non trenta.
    $componente->assertSee('entro 7 giorni');

    expect(Livewire::withQueryParams(['stato' => 'in_scadenza'])
        ->actingAs($this->admin)->test(Scadenzario::class)
        ->viewData('interventi')->pluck('id')->all())->toBe([$dentro->id]);

    expect(Livewire::withQueryParams(['stato' => 'oltre'])
        ->actingAs($this->admin)->test(Scadenzario::class)
        ->viewData('interventi')->pluck('id')->all())->toBe([$fuori->id]);
});

it('costs the same number of statements with five rows and with thirty', function () {
    // ⚠️ Con un **Admin**: per il Responsabile Reparto ogni query scopata
    // rilegge l'albero (`AccessibleNodes` non è memoizzata) e il totale è un
    // multiplo — è un costo dichiarato nel docblock del componente, non una
    // proprietà di questa pagina.
    $misura = function (int $perPage): int {
        // Un giro a vuoto prima: la prima richiesta di un utente risolve ruoli e
        // permessi, e quelle letture non si ripetono.
        Livewire::withQueryParams(['perPage' => $perPage])->actingAs($this->admin)->test(Scadenzario::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withQueryParams(['perPage' => $perPage])->actingAs($this->admin)->test(Scadenzario::class);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $tecnico = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tecnico->assignRole('Tecnico');

    foreach (range(1, 5) as $n) {
        Intervento::factory()->forStrumento($this->autoclave)->assegnatoA($tecnico)
            ->create(['data_scadenza' => today()->addDays($n)->toDateString()]);
    }

    $conCinque = $misura(20);

    foreach (range(6, 30) as $n) {
        Intervento::factory()->forStrumento($this->cappa)->assegnatoA($tecnico)
            ->create(['data_scadenza' => today()->addDays($n)->toDateString()]);
    }

    // La proprietà che conta: trenta righe in pagina non costano più di cinque.
    expect($misura(50))->toBe($conCinque)
        // Otto, e sapere quali rende utile il numero: count del paginatore,
        // pagina, due eager load (strumento, tecnico), la mappa delle
        // ubicazioni, i tre contatori.
        ->and($conCinque)->toBe(8);
});

it('makes the paginator total agree with the number on the tile', function () {
    // I contatori sono la superficie che si dimentica: una tile che dicesse 4 e
    // un elenco che ne mostra 3 manderebbe a cercare una riga che non esiste.
    Intervento::factory()->count(3)->forStrumento($this->autoclave)->scaduto()->create();
    Intervento::factory()->count(2)->forStrumento($this->cappa)
        ->create(['data_scadenza' => today()->addDays(2)->toDateString()]);

    $componente = Livewire::actingAs($this->admin)->test(Scadenzario::class);
    $contatori = $componente->viewData('contatori');

    expect($contatori['scaduti'])->toBe(3)
        ->and($contatori['in_scadenza'])->toBe(2)
        ->and($contatori['oltre'])->toBe(0);

    $componente->call('filtra', 'scaduti')
        ->assertSet('stato', 'scaduti')
        ->tap(fn ($c) => expect($c->viewData('interventi')->total())->toBe($contatori['scaduti']))
        // ⚠️ Le tile NON portano il filtro attivo: applicandolo anche a loro,
        // due su tre direbbero zero appena se ne clicca una e non si potrebbe
        // più passare dall'una all'altra.
        ->tap(fn ($c) => expect($c->viewData('contatori'))->toBe($contatori));
});

it('shows everything when the partition in the query string is not in the whitelist', function () {
    Intervento::factory()->count(2)->forStrumento($this->autoclave)->scaduto()->create();

    Livewire::withQueryParams(['stato' => 'inventato'])
        ->actingAs($this->admin)->test(Scadenzario::class)
        // Il filtro non è stato applicato, quindi non c'è nessun vuoto da
        // spiegare e le righe ci sono tutte.
        ->tap(fn ($c) => expect($c->viewData('interventi')->total())->toBe(2))
        ->tap(fn ($c) => expect($c->instance()->haFiltriAttivi())->toBeFalse());

    // E `filtra()` non accetta niente fuori dalla whitelist.
    Livewire::actingAs($this->admin)->test(Scadenzario::class)
        ->call('filtra', 'inventato')
        ->assertSet('stato', null);
});

it('never exceeds 100 rows per page, whatever the query string says', function () {
    foreach (range(1, 30) as $n) {
        Intervento::factory()->forStrumento($this->autoclave)
            ->create(['data_scadenza' => today()->addDays($n)->toDateString()]);
    }

    foreach ([999999, 101, 0, -5, 37] as $malevolo) {
        $righe = Livewire::withQueryParams(['perPage' => $malevolo])
            ->actingAs($this->admin)->test(Scadenzario::class)
            ->viewData('interventi');

        expect($righe->perPage())->toBe(20, "perPage={$malevolo} non è stato ricondotto al default");
    }
});

it('says the list is empty full stop when there is nothing and no filter', function () {
    Livewire::actingAs($this->admin)->test(Scadenzario::class)
        ->assertSee('Nessun intervento aperto.')
        ->assertDontSee('Nessun risultato per i filtri applicati.');
});

it('names the filters when the empty list is a filtered one', function () {
    Intervento::factory()->forStrumento($this->autoclave)
        ->create(['descrizione' => 'Taratura annuale', 'data_scadenza' => today()->addDays(3)->toDateString()]);

    Livewire::withQueryParams(['search' => 'niente-che-esista'])
        ->actingAs($this->admin)->test(Scadenzario::class)
        ->assertSee('Nessun risultato per i filtri applicati.')
        ->assertDontSee('Nessun intervento aperto.');
});

it('filters by tipo, using the enum label and never ucfirst', function () {
    Intervento::factory()->forStrumento($this->autoclave)
        ->create(['tipo' => TipoIntervento::ManutenzioneFullRisk, 'descrizione' => 'Contratto full risk']);
    Intervento::factory()->forStrumento($this->cappa)
        ->create(['tipo' => TipoIntervento::ManutenzioneOrdinaria, 'descrizione' => 'Giro ordinario']);

    Livewire::withQueryParams(['tipo' => TipoIntervento::ManutenzioneFullRisk->value])
        ->actingAs($this->admin)->test(Scadenzario::class)
        ->assertSee('Contratto full risk')
        ->assertDontSee('Giro ordinario')
        // ADR-021: l'etichetta viene da `label()`, non da `ucfirst($value)`.
        ->assertSee('Manutenzione full risk')
        ->assertDontSee('Manutenzione_full_risk');
});

it('searches by machine name as well as by descrizione', function () {
    Intervento::factory()->forStrumento($this->autoclave)->create(['descrizione' => 'Giro trimestrale']);
    Intervento::factory()->forStrumento($this->cappa)->create(['descrizione' => 'Sostituzione filtro']);

    Livewire::withQueryParams(['search' => 'autoclave'])
        ->actingAs($this->admin)->test(Scadenzario::class)
        ->assertSee('Giro trimestrale')
        ->assertDontSee('Sostituzione filtro');

    Livewire::withQueryParams(['search' => 'filtro'])
        ->actingAs($this->admin)->test(Scadenzario::class)
        ->assertSee('Sostituzione filtro')
        ->assertDontSee('Giro trimestrale');
});

it('shows only the interventi assigned to whoever ticks solo i miei', function () {
    $altro = User::factory()->create(['tenant_id' => $this->ente->id]);
    $altro->assignRole('Tecnico');

    Intervento::factory()->forStrumento($this->autoclave)->assegnatoA($this->admin)
        ->create(['descrizione' => 'Roba mia']);
    Intervento::factory()->forStrumento($this->cappa)->assegnatoA($altro)
        ->create(['descrizione' => 'Roba di un collega']);

    Livewire::actingAs($this->admin)->test(Scadenzario::class)
        ->assertSee('Roba mia')
        ->assertSee('Roba di un collega')
        ->set('soloMiei', true)
        ->assertSee('Roba mia')
        ->assertDontSee('Roba di un collega')
        ->tap(fn ($c) => expect($c->viewData('interventi')->total())->toBe(1));
});

it('never shows the name of an assegnatario who belongs to another Ente', function () {
    // ⛔ `users` non ha il TenantScope: `$intervento->tecnico->name` mostrerebbe
    // il nome di una persona di un altro cliente. `tecnicoLabel()` rende `—`.
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $estraneo = User::factory()->create(['tenant_id' => $altroEnte->id, 'name' => 'Giulia Estranea']);

    // Il tecnico ESTERNO (`tenant_id` null, ADR-007) resta invece visibile.
    $esterno = User::factory()->create(['tenant_id' => null, 'name' => 'Marco Esterno']);
    $esterno->assignRole('Tecnico');
    $esterno->portafoglioClienti()->attach($this->ente->id);

    Intervento::factory()->forStrumento($this->autoclave)->assegnatoA($estraneo)
        ->create(['descrizione' => 'Assegnato fuori Ente']);
    Intervento::factory()->forStrumento($this->cappa)->assegnatoA($esterno)
        ->create(['descrizione' => 'Assegnato a un esterno']);

    Livewire::actingAs($this->admin)->test(Scadenzario::class)
        ->assertSee('Assegnato fuori Ente')
        ->assertDontSee('Giulia Estranea')
        ->assertSee('Marco Esterno');
});

it('keeps naming an external Tecnico after his portafoglio is revoked (reading is wider than writing, ADR-038)', function () {
    // 🔴 Riscritto il 29 Ago 2026. Il test qui sopra congelava «il tecnico
    // esterno resta visibile» quando esterno voleva dire soltanto «senza
    // tenant»; da ADR-038 la SCRITTURA vuole in più il portafoglio, e la
    // domanda diventa: e la lettura?
    //
    // La risposta, decisa e motivata in `Intervento::tecnicoLabel()`, è NO —
    // la lettura resta più larga della scrittura, e la disuguaglianza è nella
    // direzione sicura (scrivibile ⊂ mostrabile). Se `tecnicoLabel()` seguisse
    // il portafoglio, revocarlo riscriverebbe lo storico: interventi chiusi
    // mesi fa direbbero «—», e il PDF dello storico stamperebbe un documento
    // diverso da quello di ieri. È la stessa ragione per cui `tecnico()` è
    // `withTrashed()`.
    $esterno = User::factory()->create(['tenant_id' => null, 'name' => 'Marco Esterno']);
    $esterno->assignRole('Tecnico');
    $esterno->portafoglioClienti()->attach($this->ente->id);

    Intervento::factory()->forStrumento($this->cappa)->assegnatoA($esterno)
        ->create(['descrizione' => 'Taratura di marzo']);

    $esterno->portafoglioClienti()->detach($this->ente->id);

    Livewire::actingAs($this->admin)->test(Scadenzario::class)
        ->assertSee('Taratura di marzo')
        ->assertSee('Marco Esterno');
});

it('keeps naming a trashed assegnatario in the scadenzario (ADR-038)', function () {
    // Il cestino toglie la persona dalle tendine e dalle email, non dallo
    // storico: `Intervento::tecnico()` è `withTrashed()` apposta. Senza questo
    // test la relazione potrebbe tornare stretta e la colonna direbbe «—» per
    // ogni ex dipendente, in silenzio.
    $uscito = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Ex Dipendente']);

    Intervento::factory()->forStrumento($this->cappa)->assegnatoA($uscito)
        ->create(['descrizione' => 'Controllo di aprile']);

    $uscito->delete();

    Livewire::actingAs($this->admin)->test(Scadenzario::class)
        ->assertSee('Controllo di aprile')
        ->assertSee('Ex Dipendente');
});

it('groups the rows by month without dropping any of them', function () {
    // ⚠️ Il raggruppamento è **sulla pagina già ordinata**, non un filtro: filtrare
    // in PHP dopo `paginate()` darebbe pagine incomplete e conteggi falsi.
    Intervento::factory()->forStrumento($this->autoclave)
        ->create(['data_scadenza' => today()->startOfMonth()->addDays(2)->toDateString(), 'descrizione' => 'Questo mese']);
    Intervento::factory()->forStrumento($this->cappa)
        ->create(['data_scadenza' => today()->addMonth()->startOfMonth()->addDays(2)->toDateString(), 'descrizione' => 'Il mese dopo']);

    $componente = Livewire::actingAs($this->admin)->test(Scadenzario::class);

    $componente->assertSee(today()->translatedFormat('F Y'))
        ->assertSee(today()->addMonth()->translatedFormat('F Y'))
        ->assertSee('Questo mese')
        ->assertSee('Il mese dopo');

    expect($componente->viewData('interventi')->total())->toBe(2);
});

it('labels every cell with the heading of its own column', function () {
    // Sotto 48rem la tabella diventa una lista di card (`tabella-a-card`) e ogni
    // cella espone la propria intestazione da `data-etichetta`. L'etichetta è
    // l'unica cosa scritta due volte, quindi può divergere — e su desktop non si
    // vedrebbe nulla, perché lì gli attributi non sono usati. Stessa disciplina
    // di `TabellaCardMobileTest`.
    Intervento::factory()->forStrumento($this->autoclave)->create(['descrizione' => 'Una riga qualsiasi']);

    $html = Livewire::actingAs($this->admin)->test(Scadenzario::class)->html();

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
    $xpath = new DOMXPath($dom);

    $tabelle = $xpath->query("//table[contains(@class, 'tabella-a-card')]");
    expect($tabelle->length)->toBe(1);

    $intestazioni = [];
    foreach ($xpath->query('.//thead//th', $tabelle->item(0)) as $th) {
        $intestazioni[] = trim($th->textContent);
    }

    $celleControllate = 0;

    foreach ($xpath->query('.//tbody/tr', $tabelle->item(0)) as $riga) {
        $celle = $xpath->query('./td', $riga);

        // Le righe di intestazione del mese e quella di stato vuoto hanno una
        // cella sola in `colspan`, che non corrisponde ad alcuna colonna.
        if ($celle->length !== count($intestazioni)) {
            expect($celle->length)->toBe(1);

            continue;
        }

        foreach ($celle as $i => $cella) {
            expect($cella->getAttribute('data-etichetta'))->toBe(
                $intestazioni[$i],
                'Colonna '.($i + 1).": l'etichetta della cella non è l'intestazione «{$intestazioni[$i]}»"
            );
            $celleControllate++;
        }
    }

    // Senza questo, una pagina che per qualunque motivo non rendesse righe
    // passerebbe il test a vuoto.
    expect($celleControllate)->toBe(count($intestazioni));
});

it('drops the rows of a binned machine, from the list and from the tiles', function () {
    // 🔴 `SchedaStrumento::delete()` cestina lo **strumento** e non tocca gli
    // interventi: le righe restano `non_fatto` e nessuno scope di `Intervento`
    // le nasconde (il `DepartmentThroughStrumentoScope` passa da
    // `AccessibleStrumenti::nei()`, che dichiara di ignorare il soft delete).
    //
    // Su una scheda quel comportamento è voluto — lo storico di una macchina
    // cestinata resta leggibile a chi la riapre da cestinati — ma qui è un
    // elenco di **cose da fare**: la riga non si può chiudere da nessuna parte
    // (la chiusura vive nella scheda, che risponde 404 perché il binding
    // implicito applica il soft delete), non si trova con la ricerca per nome
    // macchina (quella sottoquery passa da `Strumento::query()`, che la vede
    // cestinata) e gonfia per sempre un contatore. È la stessa scelta già fatta
    // dal digest, che salta le righe la cui macchina non è più leggibile.
    $vivo = Intervento::factory()->forStrumento($this->autoclave)->scaduto()
        ->create(['descrizione' => 'Su una macchina viva']);
    $orfano = Intervento::factory()->forStrumento($this->cappa)->scaduto()
        ->create(['descrizione' => 'Su una macchina cestinata']);

    $this->cappa->delete();

    $componente = Livewire::actingAs($this->admin)->test(Scadenzario::class);

    $componente->assertSee('Su una macchina viva')
        ->assertDontSee('Su una macchina cestinata')
        // Nessun link verso una scheda che risponderebbe 404.
        ->assertDontSee(route('strumenti.show', $this->cappa->id))
        ->assertSee(route('strumenti.show', $this->autoclave->id));

    expect($componente->viewData('interventi')->pluck('id')->all())->toBe([$vivo->id])
        ->and($componente->viewData('contatori'))->toBe([
            'scaduti' => 1,
            'in_scadenza' => 0,
            'oltre' => 0,
        ])
        // La riga NON è stata cancellata: è solo uscita da questo elenco. Senza
        // questa asserzione il test sarebbe verde anche se cestinare una
        // macchina cancellasse i suoi interventi.
        ->and(Intervento::withoutGlobalScopes()->whereKey($orfano->id)->exists())->toBeTrue();
});

it('honours 50 and 100 rows per page, and not only the default', function () {
    // ⚠️ La whitelist era provata solo **per esclusione**: tutti i valori
    // asseriti ricadevano sul default, quindi pinnare `$perPage` a 20 lasciava
    // la suite verde e il select «Righe per pagina» diventava un comando morto.
    foreach (range(1, 30) as $n) {
        Intervento::factory()->forStrumento($this->autoclave)
            ->create(['data_scadenza' => today()->addDays($n)->toDateString()]);
    }

    foreach ([50, 100] as $scelta) {
        $righe = Livewire::withQueryParams(['perPage' => $scelta])
            ->actingAs($this->admin)->test(Scadenzario::class)
            ->viewData('interventi');

        expect($righe->perPage())->toBe($scelta, "perPage={$scelta} non è stato onorato")
            // E le trenta righe stanno davvero tutte in una pagina sola.
            ->and($righe->count())->toBe(30);
    }

    // Il 20 di default resta una scelta possibile, non solo un fallback.
    expect(Livewire::withQueryParams(['perPage' => 20])
        ->actingAs($this->admin)->test(Scadenzario::class)
        ->viewData('interventi')->count())->toBe(20);
});

it('inverts the order starting from the direction it actually applied', function () {
    // 🔴 `render()` e `invertiOrdine()` normalizzavano `sortDir` con due copie
    // della stessa whitelist, e per ogni valore fuori da esse divergevano: con
    // `?sortDir=inventato` la pagina rendeva 'asc' e il primo clic scriveva di
    // nuovo 'asc', cioè non faceva nulla. Una definizione sola — `direzione()` —
    // come in `RegistroAudit` e in `ElencaClienti::ordinamentoEffettivo()`.
    $futuro = Intervento::factory()->forStrumento($this->autoclave)
        ->create(['data_scadenza' => today()->addDays(40)->toDateString()]);
    $scaduto = Intervento::factory()->forStrumento($this->cappa)->scaduto()->create();

    Livewire::withQueryParams(['sortDir' => 'inventato'])
        ->actingAs($this->admin)->test(Scadenzario::class)
        // ⛔ La **freccia** dice il vero, ed è la metà che si dimentica: la
        // vista riceve `direzione` e non `sortDir`, perché Livewire condivide le
        // proprietà pubbliche DOPO i dati di `view()` e una chiave omonima
        // verrebbe sovrascritta dal valore grezzo. Senza il rinominare, con
        // `?sortDir=inventato` la pagina ordinava per scadenza crescente e il
        // pulsante annunciava «Prima le più lontane».
        ->tap(fn ($c) => expect($c->viewData('direzione'))->toBe('asc'))
        ->assertSee('Prima le più vicine')
        ->assertDontSee('Prima le più lontane')
        ->tap(fn ($c) => expect($c->viewData('interventi')->pluck('id')->all())
            ->toBe([$scaduto->id, $futuro->id]))
        ->call('invertiOrdine')
        ->assertSet('sortDir', 'desc')
        ->assertSee('Prima le più lontane')
        ->tap(fn ($c) => expect($c->viewData('interventi')->pluck('id')->all())
            ->toBe([$futuro->id, $scaduto->id]));

    // E un `DESC` maiuscolo — che un URL scritto a mano produce facilmente — è
    // la direzione che dice di essere, non un valore scartato.
    Livewire::withQueryParams(['sortDir' => 'DESC'])
        ->actingAs($this->admin)->test(Scadenzario::class)
        ->tap(fn ($c) => expect($c->viewData('direzione'))->toBe('desc'))
        ->assertSee('Prima le più lontane')
        ->tap(fn ($c) => expect($c->viewData('interventi')->pluck('id')->all())
            ->toBe([$futuro->id, $scaduto->id]));
});

it('treats the LIKE wildcards typed in the search box as ordinary characters', function () {
    // Senza `addcslashes` + `ESCAPE`, `%` da solo restituisce OGNI riga mentre
    // `haFiltriAttivi()` è true — un filtro che si aggira digitando un carattere
    // — e `_` diventa «un carattere qualunque». `ESCAPE` va dichiarato perché
    // SQLite, a differenza di Postgres, non ha un carattere di escape di
    // default. Stessa forma di `ElencaClienti` e `RegistroAudit::jolly()`.
    Intervento::factory()->forStrumento($this->autoclave)
        ->create(['descrizione' => 'Ricarica gas 20% residuo']);
    Intervento::factory()->forStrumento($this->cappa)
        ->create(['descrizione' => 'Sostituzione 20 filtri']);

    Livewire::withQueryParams(['search' => '20%'])
        ->actingAs($this->admin)->test(Scadenzario::class)
        ->assertSee('Ricarica gas 20% residuo')
        ->assertDontSee('Sostituzione 20 filtri')
        ->tap(fn ($c) => expect($c->viewData('interventi')->total())->toBe(1));

    // Il caso limite: un `%` da solo non deve restituire tutto.
    Livewire::withQueryParams(['search' => '%'])
        ->actingAs($this->admin)->test(Scadenzario::class)
        ->tap(fn ($c) => expect($c->viewData('interventi')->total())->toBe(1))
        ->tap(fn ($c) => expect($c->instance()->haFiltriAttivi())->toBeTrue());

    // E `_` è un underscore, non «un carattere qualunque» — anche sul nome
    // macchina, che passa dall'altra metà dello stesso predicato.
    Strumento::factory()->forNode($this->dept)->create(['nome' => 'Cappa_1']);
    $sosia = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Cappa X1']);
    Intervento::factory()->forStrumento($sosia)->create(['descrizione' => 'Sul sosia']);

    Livewire::withQueryParams(['search' => 'Cappa_1'])
        ->actingAs($this->admin)->test(Scadenzario::class)
        ->assertDontSee('Sul sosia')
        ->tap(fn ($c) => expect($c->viewData('interventi')->total())->toBe(0));
});

it('lowercases the search term in a UTF-8 aware way, not byte by byte', function () {
    // 🔴 **Si asserisce sul BINDING, non sulle righe**, ed è l'unica rete
    // possibile: con `strtolower()` l'ago di «SANITÀ» resta `sanitÀ`, e su
    // SQLite anche la colonna resta `sanitÀ` (il suo `LOWER()` converte solo
    // A–Z), quindi ago e pagliaio si trovano lo stesso e un test sui dati
    // nascerebbe VERDE in locale. Su Postgres — cioè in CI e in produzione —
    // `LOWER()` è UTF-8-aware e produce `sanità`: la riga non si troverebbe
    // più. È la direzione peggiore della divergenza già documentata in
    // `RegistroAudit::jolly()`.
    Intervento::factory()->forStrumento($this->autoclave)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::withQueryParams(['search' => 'SANITÀ'])->actingAs($this->admin)->test(Scadenzario::class);
    $righe = collect(DB::getQueryLog());
    DB::disableQueryLog();

    $conLike = $righe->first(fn (array $q) => str_contains($q['query'], 'LOWER(interventi.descrizione)'));

    expect($conLike)->not->toBeNull();

    // ⚠️ Nessun messaggio dentro `toContain`: è variadico, e il testo
    // diventerebbe un secondo ago. La spiegazione sta in questo commento.
    expect($conLike['bindings'])->toContain('%'.mb_strtolower('SANITÀ').'%')
        ->and($conLike['bindings'])->not->toContain('%'.strtolower('SANITÀ').'%')
        // E la clausola `ESCAPE` c'è: senza, su SQLite i jolly neutralizzati
        // resterebbero neutralizzati a metà (il backslash non ha significato).
        ->and($conLike['query'])->toContain("ESCAPE '\\'");
});

it('falls back to the last existing page when the URL asks for one past the end', function () {
    // Scenario: si condivide `/scadenzario?page=2`, un collega chiude quasi
    // tutto, e alla riapertura `paginate()` torna zero righe mentre le tile e il
    // totale restano corretti. La tabella direbbe «Nessun intervento aperto.» —
    // il messaggio scritto apposta per NON mandare a cercare righe che non ci
    // sono — davanti a un elenco che ne ha cinque una pagina più indietro.
    Intervento::factory()->count(5)->forStrumento($this->autoclave)->scaduto()
        ->create(['descrizione' => 'Ancora aperto']);

    $componente = Livewire::actingAs($this->admin)->test(Scadenzario::class)
        ->call('setPage', 3);

    $componente->assertSee('Ancora aperto')
        ->assertDontSee('Nessun intervento aperto.')
        ->assertDontSee('Nessun risultato per i filtri applicati.')
        ->assertSet('paginators.page', 1);

    expect($componente->viewData('interventi')->count())->toBe(5)
        ->and($componente->viewData('interventi')->currentPage())->toBe(1);

    // ⚠️ Il rimbalzo vale solo quando c'è qualcosa da mostrare: su un elenco
    // davvero vuoto «pagina 2» è vuota per il motivo giusto, e rimbalzare
    // costerebbe una seconda coppia di query per dire la stessa cosa.
    Intervento::withoutGlobalScopes()->delete();

    Livewire::actingAs($this->admin)->test(Scadenzario::class)
        ->call('setPage', 3)
        ->assertSee('Nessun intervento aperto.')
        ->assertSet('paginators.page', 3);
});

it('puts the Scadenzario in the sidebar only for whoever may see the interventi', function () {
    // La voce di menù sta in `app.blade.php` dietro `@can('interventi.view')`,
    // e `AppShellTest` gira su due ruoli che quel permesso ce l'hanno entrambi:
    // il gate non è mai esercitato in negativo. Senza questa riga, toglierlo
    // (o cancellare la voce) non renderebbe rosso nulla, e un ruolo senza
    // `interventi.view` si troverebbe in sidebar un link verso una 403.
    //
    // ⚠️ Si asserisce sul blocco `<nav>` **estratto**, non sulla pagina: la
    // parola vive anche nel titolo e nel breadcrumb di altre viste, quindi un
    // `assertDontSee` sul documento intero sarebbe rosso per il motivo
    // sbagliato. Stessa forma di `AppShellTest`.
    $nav = function (User $u): string {
        $html = $this->actingAs($u->fresh())->get(route('dashboard'))->assertOk()->getContent();
        preg_match('/<nav class="flex-1 space-y-1[^"]*">.*?<\/nav>/s', $html, $blocco);

        expect($blocco)->not->toBeEmpty('Sidebar non trovata');

        return $blocco[0];
    };

    Role::create(['name' => 'Magazziniere'])->givePermissionTo('strumenti.view');
    $senza = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $senza->assignRole('Magazziniere');

    expect($nav($this->admin))->toContain('Scadenzario')
        ->and($nav($this->admin))->toContain(route('scadenzario.index'))
        ->and($nav($senza))->not->toContain('Scadenzario')
        // Il positivo, senza cui la riga sopra sarebbe verde su una nav vuota.
        ->and($nav($senza))->toContain('Strumenti');
});

it('wires the tiles to the filter, so they cannot go inert with the suite green', function () {
    // 🔴 Lacuna segnalata il 29 Ago 2026 dal correttore del Parco, che la stessa
    // aveva appena chiusa sulla scheda gemella: i test delle tile chiamano
    // `filtra()` come METODO, e nessuna asserzione tocca il `wire:click` del
    // Blade. Cancellandoli dal markup le tre tile diventerebbero decorazioni
    // inerti — si cliccherebbe e non succederebbe niente — con la suite verde.
    //
    // ⚠️ Si asserisce sul markup e non sul comportamento perché è il markup a
    // mancare: il metodo è già provato sopra, ed è proprio quella copertura che
    // rende il buco invisibile. Le due metà insieme sono la rete.
    $html = $this->actingAs($this->admin)->get(route('scadenzario.index'))->assertOk()->getContent();

    expect($html)->toContain('wire:click="filtra(\'scaduti\')"')
        ->and($html)->toContain('wire:click="filtra(\'in_scadenza\')"')
        ->and($html)->toContain('wire:click="filtra(\'oltre\')"');
});
