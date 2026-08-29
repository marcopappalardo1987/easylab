<?php

use App\Enums\TipoIntervento;
use App\Livewire\Piattaforma\ParcoScadenzario;
use App\Models\Account;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Piattaforma\Perimetro;
use App\Support\Piattaforma\ScadenzarioParco;
use App\Support\Semaforo;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * 🔴 Parco clienti — scheda «Scadenzario» (🔗 ADR-037), area rossa.
 *
 * È una vista che **legge oltre il proprio Ente**, quindi l'errore che conta
 * non è la pagina che non si apre: è la riga di un cliente dentro l'elenco di un
 * altro, o un elenco che mostra «tutti» mentre il filtro in cima dice altro. Su
 * questa superficie l'isolamento non è più una proprietà del framework — il
 * global scope è tolto per costruzione — ma una proprietà del **codice**, e si
 * difende con dei negativi invece che con un'architettura.
 *
 * Le specie di rischio, e ognuna ha i suoi test:
 *
 *   1. **Il permesso** — chi non ha `tenants.view_all` non ottiene un elenco
 *      vuoto (che si leggerebbe come «i clienti non hanno scadenze»): ottiene un
 *      403. Due strade separate: la rotta e il montaggio diretto del componente,
 *      che non passa dal middleware.
 *   2. **Il perimetro** — arriva dal browser. Un id forgiato, un piano fuori
 *      catalogo, un modo inventato e la selezione vuota devono cadere tutti su
 *      «nessuno», mai su «tutti».
 *   3. **Di chi sono i dati** — cliente, sede e macchina su ogni riga. Non è
 *      cosmesi: `$intervento->strumento` e la risalita al nodo passano da
 *      modelli **scopati**, quindi la strada comoda darebbe una tabella di
 *      trattini — plausibile e muta. Un test la coglie per nome.
 *   4. **I confini di data** — su SQLite le colonne `date` sono stringhe e il
 *      confronto è lessicografico; su Postgres sono date vere. Un `<` al posto
 *      di un `<=` passa in locale e fallisce in CI, quindi il confine si prova
 *      sui **tre giorni** che lo circondano.
 *   5. **Il tie-break di pagina** — si prova sull'**SQL** e non sui dati: senza,
 *      il difetto si coglie a seconda del piano che il motore sceglie, cioè una
 *      volta su tre, e un test che coglie un difetto una volta su tre non è una
 *      rete, è un aneddoto.
 *   6. **La sola lettura** — da qui si guarda; si scrive impersonando.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Due clienti su due piani diversi, ciascuno con la sua sede e la sua
    // macchina: senza il secondo, «cross-cliente» e «il mio Ente» darebbero le
    // stesse righe e ogni asserzione positiva resterebbe verde per caso.
    $this->rossi = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi', 'piano' => 'free']);
    $this->sedeRossi = UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)->create(['nome' => 'Sede di Milano']);
    $this->autoclaveRossi = Strumento::factory()->forNode($this->sedeRossi)->create(['nome' => 'Autoclave Rossi']);

    $this->bianchi = Account::factory()->saas()->create(['ragione_sociale' => 'Lab Bianchi']);
    $this->sedeBianchi = UnitaOrganizzativa::factory()->ente()->perAccount($this->bianchi)->create(['nome' => 'Sede di Torino']);
    $this->cappaBianchi = Strumento::factory()->forNode($this->sedeBianchi)->create(['nome' => 'Cappa Bianchi']);

    // ⚠️ EasyLab **nella fixture**: senza, «tutti i clienti» e «tutti gli
    // account» darebbero lo stesso elenco e la riga che tiene fuori l'account di
    // piattaforma potrebbe sparire senza che nulla diventi rosso.
    $this->easylab = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);
    $this->sedeEasylab = UnitaOrganizzativa::factory()->ente()->perAccount($this->easylab)->create(['nome' => 'Sede EasyLab']);
    $this->bancoEasylab = Strumento::factory()->forNode($this->sedeEasylab)->create(['nome' => 'Banco EasyLab']);

    // 🔴 Il Superadmin ha un tenant, ed è quello di **Rossi**: è ciò che rende
    // falsificabile ogni asserzione su Bianchi. Se il confine cross-tenant
    // saltasse al contrario — cioè se la vista tornasse scopata — Rossi
    // continuerebbe a comparire e solo Bianchi sparirebbe.
    $this->superadmin = User::factory()->create([
        'name' => 'Direzione EasyLab',
        'tenant_id' => $this->sedeRossi->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->superadmin->assignRole('Superadmin');
    $this->rossi->aggiungiMembro($this->superadmin);

    // Un membro impersonabile per parte, così la leva ha qualcosa da offrire.
    $this->annaRossi = User::factory()->create([
        'name' => 'Anna Rossi',
        'tenant_id' => $this->sedeRossi->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->annaRossi->assignRole('Admin');
    $this->rossi->aggiungiMembro($this->annaRossi);

    $this->actingAs($this->superadmin->fresh());
});

/**
 * 🔴 Crea righe di dominio **senza utente in sessione**, cioè come le crea il
 * cliente a cui appartengono.
 *
 * ⛔ Senza questa precauzione la fixture è **falsa, e falsa nella direzione che
 * conta**: `BelongsToTenant::creating()` forza `tenant_id` al tenant di chi sta
 * creando (`CurrentTenant::shouldScope()` è vero appena c'è un utente, e il
 * Superadmin non fa eccezione — ADR-018: nessun ruolo bypassa). Un intervento
 * creato qui sulla macchina di *Bianchi* mentre si agisce da Superadmin nasce
 * col `tenant_id` di **Rossi**: l'invariante `interventi.tenant_id ==
 * strumenti.tenant_id` salta, e la riga finisce nell'intestazione del cliente
 * sbagliato.
 *
 * ⚠️ E il difetto non si vede: la pagina si disegna, il numero torna, la riga
 * c'è. Trovato il 29 Ago 2026 guardando l'HTML reso — «Cappa Bianchi» sotto
 * «Gruppo Rossi» — non da un test rosso. Ogni asserzione su «di chi è questa
 * riga» sarebbe stata verde per il motivo sbagliato.
 *
 * Il resto delle fixture nasce in `beforeEach` **prima** di `actingAs`, che è la
 * stessa convenzione di `ParcoClientiTest`; questo helper serve alle righe che
 * ogni test si costruisce da sé, con le proprie date.
 */
function comeIlCliente(callable $creazione): mixed
{
    $chiGuarda = Auth::user();
    Auth::forgetUser();

    try {
        return $creazione();
    } finally {
        if ($chiGuarda !== null) {
            Auth::setUser($chiGuarda);
        }
    }
}

/**
 * Un intervento aperto su una macchina, alla data data.
 *
 * Il **tipo** e' un parametro e non un valore fisso: e' l'unico modo di scrivere
 * due righe che differiscono SOLO per quello, che e' cio' che serve per provare
 * il filtro «Tipo di intervento» — con una sola riga in tabella un filtro rotto
 * e uno funzionante mostrano la stessa pagina.
 */
function apertoIl(Strumento $strumento, string $data, string $descrizione, ?TipoIntervento $tipo = null): Intervento
{
    $attributi = ['data_scadenza' => $data, 'descrizione' => $descrizione];

    if ($tipo !== null) {
        $attributi['tipo'] = $tipo;
    }

    return comeIlCliente(fn () => Intervento::factory()->forStrumento($strumento)->create($attributi));
}

// ─── 1. Il permesso: 403, non un elenco vuoto ────────────────────────────────

it('sends a guest to the login instead of the client park schedule', function () {
    auth()->logout();

    $this->get(route('piattaforma.parco.scadenzario'))->assertRedirect(route('login'));
});

it('refuses the schedule to every client role, however senior', function (string $ruolo) {
    // ⚠️ `two_factor_confirmed_at` valorizzato: Admin ha il 2FA obbligatorio e
    // senza finirebbe su /settings/security — cioè il test passerebbe per il
    // motivo sbagliato, e resterebbe verde anche togliendo il `can:` di rotta.
    $utente = User::factory()->create([
        'tenant_id' => $this->sedeRossi->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $utente->assignRole($ruolo);

    $this->actingAs($utente->fresh())
        ->get(route('piattaforma.parco.scadenzario'))
        ->assertForbidden();
})->with(['Admin', 'Tenant', 'Responsabile Reparto', 'Tecnico']);

it('throws instead of handing an empty table to a client role that mounts the component directly', function () {
    // 🔴 La seconda strada, e non la copre la prima: montare il componente non
    // passa dal middleware di rotta. Il rifiuto deve essere un'eccezione — un
    // elenco vuoto si leggerebbe come «i clienti non hanno scadenze», che è la
    // forma peggiore di negare: silenziosa e plausibile.
    $admin = User::factory()->create([
        'tenant_id' => $this->sedeRossi->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('Admin');

    // 🔴 La riga esiste PRIMA del rifiuto, e l'ago è il nome del cliente che la
    // pagina stamperebbe: senza, l'`assertDontSee` qui sotto non potrebbe
    // fallire — «Gruppo Rossi» non sarebbe in pagina in nessun caso, nemmeno
    // togliendo del tutto il gate, e si leggerebbe come una copertura che non
    // c'è. Stessa famiglia della cicatrice del `toContain()` variadico.
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Taratura Rossi');

    $this->actingAs($admin->fresh());

    // ⚠️ Si asserisce sul **403**, non su `->throws()`: il test harness di
    // Livewire cattura l'`AuthorizationException` e la rende come risposta,
    // quindi un `throws()` qui non scatterebbe mai — e un test che non può
    // fallire occuperebbe il posto di quello vero.
    Livewire::test(ParcoScadenzario::class)
        ->assertForbidden()
        ->assertDontSee('Gruppo Rossi');

    // E che l'ago sia davvero raggiungibile lo dice la stessa fixture vista da
    // chi il permesso ce l'ha: se «Gruppo Rossi» non comparisse nemmeno qui,
    // l'asserzione negativa qui sopra sarebbe vera per il motivo sbagliato.
    $this->actingAs($this->superadmin->fresh());

    Livewire::test(ParcoScadenzario::class)->assertSee('Gruppo Rossi');
});

it('opens for the platform roles', function (string $ruolo) {
    $utente = User::factory()->create([
        'tenant_id' => $this->sedeRossi->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $utente->assignRole($ruolo);

    $this->actingAs($utente->fresh())
        ->get(route('piattaforma.parco.scadenzario'))
        ->assertOk();
})->with(['Superadmin', 'Developer']);

// ─── 2. Il perimetro: ogni dubbio cade su «nessuno», mai su «tutti» ──────────

it('shows the rows of every client, not only the ones of the tenant the viewer belongs to', function () {
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Taratura Rossi');
    apertoIl($this->cappaBianchi, today()->addDays(6)->toDateString(), 'Taratura Bianchi');

    Livewire::test(ParcoScadenzario::class)
        ->assertSee('Taratura Rossi')
        ->assertSee('Taratura Bianchi');
});

it('never widens the perimeter with a forged account id', function () {
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Taratura Rossi');
    apertoIl($this->cappaBianchi, today()->addDays(6)->toDateString(), 'Taratura Bianchi');

    Livewire::test(ParcoScadenzario::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('clientiScelti', [$this->rossi->id + $this->bianchi->id + $this->easylab->id + 999])
        ->assertDontSee('Taratura Rossi')
        ->assertDontSee('Taratura Bianchi');
});

it('never widens the perimeter with an id that exists but is not a client', function () {
    // 🔴 **Il caso che falsifica davvero.** Un id INESISTENTE — come la somma
    // del test qui sopra — non prova niente sull'intersezione: un `whereIn`
    // nudo, senza il filtro sull'account di piattaforma e senza il soft delete,
    // tornerebbe comunque zero righe e resterebbe verde. L'id di EasyLab invece
    // c'è: è legittimo come riga della tabella e illegittimo come cliente, e
    // solo la riconvalida contro l'insieme selezionabile lo tiene fuori.
    //
    // ⚠️ Ed è QUI che l'id arriva davvero dal browser: la porta ha il suo
    // negativo (`ParcoClientiTest`), ma la scheda è il punto di ingresso.
    apertoIl($this->bancoEasylab, today()->addDays(3)->toDateString(), 'Taratura EasyLab');
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Taratura Rossi');

    // Il controllo, che è ciò che rende falsificabili le due negazioni qui
    // sotto: con un id **legittimo** la riga si vede, quindi «non si vede» non è
    // una proprietà della pagina — è l'effetto del perimetro.
    Livewire::test(ParcoScadenzario::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('clientiScelti', [$this->rossi->id])
        ->assertSee('Taratura Rossi');

    Livewire::test(ParcoScadenzario::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('clientiScelti', [$this->easylab->id])
        ->assertDontSee('Taratura EasyLab')
        // ⛔ E nemmeno le righe di chi cliente lo è: un id che l'intersezione
        // scarta lascia un perimetro **vuoto**, non un perimetro senza filtro.
        ->assertDontSee('Taratura Rossi');
});

it('reads an empty selection as nobody, never as no filter', function () {
    // ⛔ È la trappola centrale di ADR-037: «nessuno selezionato» è la
    // condizione più facile da leggere come «nessun filtro», e la differenza fra
    // le due letture è fra zero righe e le righe di tutti i clienti.
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Taratura Rossi');
    apertoIl($this->cappaBianchi, today()->addDays(6)->toDateString(), 'Taratura Bianchi');

    Livewire::test(ParcoScadenzario::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('clientiScelti', [])
        ->assertDontSee('Taratura Rossi')
        ->assertDontSee('Taratura Bianchi')
        // E lo si DICE: una tabella vuota si legge come «questi clienti non
        // hanno scadenze», che è un fatto diverso da «non hai scelto nessuno».
        ->assertSee('Nessun cliente selezionato');
});

it('keeps the other client out when only one is picked', function () {
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Taratura Rossi');
    apertoIl($this->cappaBianchi, today()->addDays(6)->toDateString(), 'Taratura Bianchi');

    Livewire::test(ParcoScadenzario::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('clientiScelti', [$this->rossi->id])
        ->assertSee('Taratura Rossi')
        ->assertDontSee('Taratura Bianchi');
});

it('never lets the per-plan perimeter admit another plan', function () {
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Taratura Rossi');
    apertoIl($this->cappaBianchi, today()->addDays(6)->toDateString(), 'Taratura Bianchi');

    Livewire::test(ParcoScadenzario::class)
        ->set('modo', Perimetro::PER_PIANO)
        ->set('piano', 'free')
        ->assertSee('Taratura Rossi')
        ->assertDontSee('Taratura Bianchi');
});

it('falls back to nobody, never to everybody, on input it did not understand', function (string $modo, ?string $piano) {
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Taratura Rossi');
    apertoIl($this->cappaBianchi, today()->addDays(6)->toDateString(), 'Taratura Bianchi');

    Livewire::test(ParcoScadenzario::class)
        ->set('modo', $modo)
        ->set('piano', $piano)
        ->assertDontSee('Taratura Rossi')
        ->assertDontSee('Taratura Bianchi');
})->with([
    'un modo inventato' => ['inventatissimo', null],
    'un piano fuori catalogo' => [Perimetro::PER_PIANO, 'piano_doro'],
    'un piano vuoto' => [Perimetro::PER_PIANO, ''],
]);

it('keeps the platform own machines out of the client park, even asking for everybody', function () {
    // EasyLab non è un cliente: le sue macchine non stanno nel parco clienti, e
    // il modo «tutti» è esattamente quello in cui rientrerebbero dalla finestra.
    apertoIl($this->bancoEasylab, today()->addDays(3)->toDateString(), 'Taratura EasyLab');
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Taratura Rossi');

    Livewire::test(ParcoScadenzario::class)
        ->set('modo', Perimetro::TUTTI)
        ->assertSee('Taratura Rossi')
        ->assertDontSee('Taratura EasyLab');
});

it('keeps the counters inside the perimeter, so they cannot contradict the rows', function () {
    // ⚠️ I contatori sono la superficie che si dimentica: un `count()` fuori dal
    // filtro tornerebbe il numero di un altro cliente **senza mostrarne una
    // riga**, cioè un numero plausibile e sbagliato accanto a un elenco giusto.
    apertoIl($this->autoclaveRossi, today()->subDays(3)->toDateString(), 'Scaduta Rossi');
    apertoIl($this->cappaBianchi, today()->subDays(4)->toDateString(), 'Scaduta Bianchi');

    Livewire::test(ParcoScadenzario::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('clientiScelti', [$this->rossi->id])
        ->assertViewHas('contatori', fn (array $c) => $c['scaduti'] === 1);
});

// ─── 3. Di CHI sono i dati: cliente, sede e macchina su ogni riga ────────────

it('names the client, the site and the machine of a row that belongs to another tenant', function () {
    // 🔴 **Il test che coglie la strada comoda.** `$intervento->strumento` e la
    // risalita al nodo passano da `Strumento`/`UnitaOrganizzativa`, che portano
    // il `TenantScope`: un eager load qui tornerebbe `null` per ogni riga di un
    // cliente che non è il proprio — e la pagina non darebbe errore, mostrerebbe
    // trattini. Bianchi non è il tenant di chi guarda: è la riga giusta su cui
    // chiederlo.
    apertoIl($this->cappaBianchi, today()->addDays(6)->toDateString(), 'Taratura Bianchi');

    Livewire::test(ParcoScadenzario::class)
        ->assertSee('Lab Bianchi')
        ->assertSee('Sede di Torino')
        ->assertSee('Cappa Bianchi');
});

it('never leaves a row without a client, so no intervention can be read as anybody', function () {
    apertoIl($this->cappaBianchi, today()->addDays(6)->toDateString(), 'Taratura Bianchi');

    Livewire::test(ParcoScadenzario::class)->assertDontSee('Cliente non identificato');
});

// ─── 4. I confini di data: si provano sui giorni che li circondano ──────────

it('keeps an intervention due today out of the overdue partition', function () {
    // ⛔ `isScaduto()` dice che ciò che scade OGGI **non è ancora scaduto**, e
    // il confine si esprime come `< oggi`. Un `<=` — o un `whereDate` — lo
    // sposterebbe di un giorno, e su Postgres e SQLite lo sposterebbe in modo
    // diverso.
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

    apertoIl($this->autoclaveRossi, '2026-06-15', 'Scade oggi');
    apertoIl($this->autoclaveRossi, '2026-06-14', 'Scaduta ieri');

    Livewire::test(ParcoScadenzario::class)
        ->set('stato', 'scaduti')
        ->assertSee('Scaduta ieri')
        ->assertDontSee('Scade oggi')
        ->assertViewHas('contatori', fn (array $c) => $c['scaduti'] === 1);
});

it('puts an intervention due today inside the imminent partition, not outside every one', function () {
    // Il rovescio del test qui sopra, e serve: un confine spostato «in avanti»
    // farebbe sparire la riga da entrambe le partizioni invece di spostarla, e
    // una riga che non sta in nessuna tile è invisibile a chi legge i numeri.
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

    apertoIl($this->autoclaveRossi, '2026-06-15', 'Scade oggi');

    Livewire::test(ParcoScadenzario::class)
        ->set('stato', 'in_scadenza')
        ->assertSee('Scade oggi')
        ->assertViewHas('contatori', fn (array $c) => $c['in_scadenza'] === 1 && $c['scaduti'] === 0 && $c['oltre'] === 0);
});

it('holds the imminent threshold on the exact day, and lets go the day after', function () {
    // ⛔ **Il confine che passa in locale e fallisce in CI.** Su SQLite le
    // colonne `date` sono stringhe `'Y-m-d 00:00:00'` e il confronto è
    // lessicografico, quindi `'…-15 00:00:00' <= '…-15'` è FALSO mentre su
    // Postgres è vero. Per questo la soglia si scrive sempre come «< il giorno
    // successivo», e per questo il test guarda **entrambi** i giorni: uno solo
    // dei due resterebbe verde su un confine sbagliato.
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

    $soglia = Semaforo::giorniImminente();

    apertoIl($this->autoclaveRossi, today()->addDays($soglia)->toDateString(), 'Ultimo giorno utile');
    apertoIl($this->autoclaveRossi, today()->addDays($soglia + 1)->toDateString(), 'Primo giorno oltre');

    Livewire::test(ParcoScadenzario::class)
        ->set('stato', 'in_scadenza')
        ->assertSee('Ultimo giorno utile')
        ->assertDontSee('Primo giorno oltre');

    Livewire::test(ParcoScadenzario::class)
        ->set('stato', 'oltre')
        ->assertSee('Primo giorno oltre')
        ->assertDontSee('Ultimo giorno utile')
        ->assertViewHas('contatori', fn (array $c) => $c['in_scadenza'] === 1 && $c['oltre'] === 1);
});

it('reads the threshold from the semaforo instead of writing thirty by hand', function () {
    // Se la soglia fosse ricopiata qui dentro, cambiare la config non
    // sposterebbe la partizione e il parco direbbe una cosa diversa dal
    // semaforo, dal digest e dal scadenzario dell'Ente — sulla stessa riga.
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));
    config()->set('easylab.semaforo.giorni_imminente', 5);

    apertoIl($this->autoclaveRossi, today()->addDays(6)->toDateString(), 'Fuori dalla soglia stretta');

    Livewire::test(ParcoScadenzario::class)
        ->assertViewHas('contatori', fn (array $c) => $c['in_scadenza'] === 0 && $c['oltre'] === 1);
});

it('writes every date boundary as a comparison the two drivers agree on', function () {
    // 🔴 **Il test che i dati non possono dare.** Su SQLite le colonne `date`
    // sono stringhe `'Y-m-d 00:00:00'` e il confronto è lessicografico, quindi
    // `'2026-06-15 00:00:00' > '2026-06-15'` è VERO: spostare il confine di
    // «in scadenza» da `>=` a `>` non cambia una riga in locale e ne sposta una
    // su Postgres, cioè in CI e in produzione. La prova di mutazione lo ha
    // mostrato — quel `>` sopravviveva a tutti i test sui dati.
    //
    // Si asserisce quindi sull**SQL** e sui **binding**, che sono gli stessi su
    // entrambi i driver.
    $soglia = Semaforo::giorniImminente();
    $oggi = today()->toDateString();
    $primoOltre = today()->addDays($soglia + 1)->toDateString();

    $q = fn (string $stato) => ScadenzarioParco::partiziona(ScadenzarioParco::base(Perimetro::tutti()), $stato);

    // Scaduto = STRETTAMENTE prima di oggi: ciò che scade oggi non è scaduto
    // (`Intervento::isScaduto()`). Un `<=` sposterebbe il confine di un giorno.
    expect($q('scaduti')->toSql())->toContain('"data_scadenza" < ?');
    expect($q('scaduti')->getBindings())->toContain($oggi);

    // «In scadenza» riprende da oggi INCLUSO — `>=`, mai `>` — così le due metà
    // non possono divergere di un giorno e nessuna riga resta fuori da entrambe.
    expect($q('in_scadenza')->toSql())->toContain('"interventi"."data_scadenza" >= ?');
    expect($q('in_scadenza')->getBindings())->toContain($oggi);

    // ⛔ E si chiude sul giorno DOPO la soglia con un `<`, mai su `<=` la
    // soglia: è la sola forma su cui SQLite e Postgres sono d'accordo.
    expect($q('in_scadenza')->toSql())->toContain('"data_scadenza" < ?');
    expect($q('in_scadenza')->getBindings())->toContain($primoOltre);

    expect($q('oltre')->toSql())->toContain('"interventi"."data_scadenza" >= ?');
    expect($q('oltre')->getBindings())->toContain($primoOltre);

    // ⛔ Le date si passano come STRINGA e mai come Carbon: un Carbon si
    // serializza con l'orario, e l'orario è esattamente ciò che rende il
    // confronto diverso fra i due driver.
    foreach (ScadenzarioParco::STATI as $stato) {
        $bindings = $q($stato)->getBindings();

        // Il conteggio PRIMA del ciclo: su un array vuoto un `foreach` non
        // asserisce niente e occupa il posto dell'asserzione vera.
        expect($bindings)->not->toBeEmpty();

        foreach ($bindings as $binding) {
            expect($binding)->not->toBeInstanceOf(CarbonInterface::class);
        }
    }
});

// ─── 4-bis. «Quanto manca»: la colonna per cui la scheda esiste ─────────────

it('says how much time is left in words, and says the overdue at the positive', function (int $giorni, string $atteso) {
    // 🔴 È la colonna che risponde alla domanda del committente — «la situazione
    // a colpo d'occhio» — ed è un match a cinque rami su una convenzione di
    // SEGNO: scambiare due rami fa stampare «scade domani» su un intervento
    // scaduto ieri, e la pagina resta plausibile. Si provano tutti e cinque, coi
    // due confini (ieri e domani) che sono i rami che si scambiano fra loro.
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

    expect(ScadenzarioParco::quantoManca(Carbon::parse('2026-06-15')->addDays($giorni)))->toBe($atteso);
})->with([
    'tre giorni fa' => [-3, 'scaduto da 3 giorni'],
    'ieri' => [-1, 'scaduto da ieri'],
    'oggi' => [0, 'scade oggi'],
    'domani' => [1, 'scade domani'],
    'fra tre giorni' => [3, 'fra 3 giorni'],
]);

it('counts the days between two starts of day, so an hour cannot move a deadline', function () {
    // ⛔ `data_scadenza` è castata a `date`, cioè mezzanotte; confrontarla con
    // `now()` renderebbe «scaduto» un intervento di oggi alle 00:01. È la stessa
    // trappola scritta nel docblock di `Intervento::isScaduto()`, e qui la si
    // chiude sull'ora più tarda della giornata.
    Carbon::setTestNow(Carbon::parse('2026-06-15 23:45:00'));

    expect(ScadenzarioParco::giorniAllaScadenza(Carbon::parse('2026-06-15 23:59:00')))->toBe(0)
        ->and(ScadenzarioParco::giorniAllaScadenza(Carbon::parse('2026-06-16 00:01:00')))->toBe(1);
});

it('prints the words of the countdown on the row, not only in the helper', function () {
    // Il gemello sui dati: senza, la colonna potrebbe non essere in pagina
    // affatto e i cinque casi qui sopra resterebbero verdi su un metodo che
    // nessuna vista chiama.
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

    apertoIl($this->autoclaveRossi, '2026-06-12', 'Taratura in ritardo');

    Livewire::test(ParcoScadenzario::class)->assertSee('scaduto da 3 giorni');
});

// ─── 5. L'ordine: il più urgente in cima, e il TIE-BREAK sull'id ────────────

it('breaks the tie on the id, in both directions, so no row can come out of two pages', function (string $direzione) {
    // ⛔ **Si prova sull'SQL e non sui dati.** A parità di `data_scadenza`
    // l'ordine fra due pagine è una proprietà del motore: SQLite scansiona in
    // modo stabile e resterebbe verde, Postgres può riordinare i pari fra la
    // query di pagina 1 e quella di pagina 2 e far uscire una riga da entrambe.
    // È già successo su questo progetto: 46 righe raccolte su 47.
    //
    // ⚠️ E `data_scadenza` è il caso peggiore: su un parco di molti clienti i
    // valori distinti sono pochi, quindi i pari sono quasi tutte le righe.
    $sql = ScadenzarioParco::ordinata(ScadenzarioParco::base(Perimetro::tutti()), $direzione)->toSql();

    expect($sql)->toContain('order by');
    expect(substr($sql, strpos($sql, 'order by')))
        ->toContain('"data_scadenza" '.$direzione.', "interventi"."id" asc');
})->with(['asc', 'desc']);

it('puts the most urgent first', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

    apertoIl($this->cappaBianchi, '2026-07-20', 'La lontana');
    apertoIl($this->autoclaveRossi, '2026-06-16', 'La urgente');

    Livewire::test(ParcoScadenzario::class)
        ->assertViewHas('interventi', fn ($p) => $p->first()->descrizione === 'La urgente');
});

it('shows the direction it actually applied, never the raw one from the query string', function () {
    // ⛔ `sortDir` arriva dalla query string e può valere qualunque cosa.
    // `ordinata()` la normalizza per conto proprio, quindi un valore inventato
    // non sbaglia l'ordine: sbaglia la FRECCIA — la pagina disegnerebbe «↓ Prima
    // le più lontane» sopra un elenco crescente. È la «bugia piccola, e per
    // questo credibile» che il docblock di `render()` dice di voler impedire, e
    // la si coglie solo passando dal componente: i test sull'SQL chiamano
    // `ordinata()` con la direzione già scritta a mano.
    Livewire::test(ParcoScadenzario::class)
        ->set('sortDir', 'inventato')
        ->assertViewHas('direzione', fn ($d) => $d === 'asc')
        // E il primo clic deve MUOVERE l'elenco: con due copie della whitelist
        // — una in `direzione()` e una dentro `invertiOrdine()` — un valore
        // inventato renderebbe 'asc' e il clic riscriverebbe 'asc'.
        ->call('invertiOrdine')
        ->assertViewHas('direzione', fn ($d) => $d === 'desc');
});

it('reads a direction typed in capitals as the same direction, not as one to discard', function () {
    // `?sortDir=DESC` incollato a mano è la stessa direzione. E il clic che
    // segue deve partire da quella APPLICATA: su `Asc` un invertitore scritto
    // sul valore grezzo tornerebbe 'asc' un'altra volta.
    Livewire::test(ParcoScadenzario::class)
        ->set('sortDir', 'DESC')
        ->assertViewHas('direzione', fn ($d) => $d === 'desc');

    Livewire::test(ParcoScadenzario::class)
        ->set('sortDir', 'Asc')
        ->call('invertiOrdine')
        ->assertViewHas('direzione', fn ($d) => $d === 'desc');
});

it('puts the furthest first when the direction is inverted', function () {
    // La freccia e l'elenco devono dire la stessa cosa: qui si lega la
    // direzione mostrata all'ordine davvero applicato dalla query.
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

    apertoIl($this->autoclaveRossi, '2026-06-16', 'La urgente');
    apertoIl($this->cappaBianchi, '2026-07-20', 'La lontana');

    Livewire::test(ParcoScadenzario::class)
        ->call('invertiOrdine')
        ->assertViewHas('direzione', fn ($d) => $d === 'desc')
        ->assertViewHas('interventi', fn ($p) => $p->first()->descrizione === 'La lontana');
});

// ─── Ciò che nello scadenzario non ci sta ───────────────────────────────────

it('leaves out interventions already done, because this is a list of things to do', function () {
    comeIlCliente(fn () => Intervento::factory()->forStrumento($this->cappaBianchi)->fatto()->create(['descrizione' => 'Gia chiusa']));
    apertoIl($this->cappaBianchi, today()->addDays(6)->toDateString(), 'Ancora aperta');

    Livewire::test(ParcoScadenzario::class)
        ->assertSee('Ancora aperta')
        ->assertDontSee('Gia chiusa');
});

it('leaves out the interventions of a trashed machine, which nobody could ever close', function () {
    // Cestinare uno strumento non tocca i suoi interventi — nessun hook, nessun
    // observer — e nessuno scope di `Intervento` li nasconde: senza il filtro
    // esplicito quelle righe gonfierebbero per sempre un contatore, e non
    // sarebbero chiudibili da nessuna parte.
    apertoIl($this->cappaBianchi, today()->addDays(6)->toDateString(), 'Sulla macchina cestinata');
    $this->cappaBianchi->delete();

    Livewire::test(ParcoScadenzario::class)
        ->assertDontSee('Sulla macchina cestinata')
        ->assertViewHas('contatori', fn (array $c) => $c['in_scadenza'] === 0);
});

it('never lets the query string ask for the whole park in a single page', function () {
    Livewire::test(ParcoScadenzario::class)
        ->set('perPage', 999999)
        ->assertViewHas('interventi', fn ($p) => $p->perPage() === 20);
});

it('drops a partition it does not know instead of showing a list no label describes', function () {
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Taratura Rossi');

    Livewire::test(ParcoScadenzario::class)
        ->set('stato', 'inventata')
        ->assertSee('Taratura Rossi')
        // ⛔ La closure e non `null` come secondo argomento: `assertViewHas($chiave,
        // null)` si limita a verificare che la CHIAVE esista — non confronta
        // niente, e l'asserzione non può fallire. Stessa famiglia della
        // cicatrice di `toContain()` variadico. La prova di mutazione lo ha
        // colto: togliendo la whitelist da `render()` il test restava verde.
        ->assertViewHas('statoAttivo', fn ($v) => $v === null);
});

it('neutralises the wildcards of the search, so a single character cannot lift the filter', function () {
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Taratura Rossi');

    Livewire::test(ParcoScadenzario::class)
        ->set('search', '%')
        ->assertDontSee('Taratura Rossi');
});

// ─── I filtri dell'elenco, la pagina e i messaggi di elenco vuoto ───────────

it('narrows the list to the chosen kind of intervention, counters included', function () {
    // 🔴 Il filtro è in pagina («Tutti i tipi»), i tre contatori lo portano e
    // `haFiltriAttivi()` ci branca sopra: senza un positivo si può cancellare
    // per intero, e un Superadmin che sceglie «Manutenzione full risk» su venti
    // clienti continuerebbe a vedere tutti i tipi — coi numeri d'accordo con
    // l'elenco sbagliato.
    //
    // ⚠️ Gli aghi sono le DESCRIZIONI e non le etichette del tipo: queste
    // ultime sono in pagina comunque, come opzioni del select, e un
    // `assertSee('Manutenzione full risk')` sarebbe verde a filtro rotto.
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Verifica del full risk', TipoIntervento::ManutenzioneFullRisk);
    apertoIl($this->autoclaveRossi, today()->addDays(6)->toDateString(), 'Verifica ordinaria', TipoIntervento::ManutenzioneOrdinaria);

    Livewire::test(ParcoScadenzario::class)
        ->set('tipo', TipoIntervento::ManutenzioneFullRisk->value)
        ->assertSee('Verifica del full risk')
        ->assertDontSee('Verifica ordinaria')
        ->assertViewHas('contatori', fn (array $c) => $c['in_scadenza'] === 1 && $c['scaduti'] === 0 && $c['oltre'] === 0);
});

it('drops a kind of intervention it does not know instead of emptying the list', function () {
    // Il negativo: `?tipo=inventato` non è un filtro applicato, quindi non
    // toglie righe — e non va nemmeno annunciato come filtro, o l'elenco vuoto
    // manderebbe a togliere qualcosa che il componente ha già scartato.
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Taratura Rossi');

    $componente = Livewire::test(ParcoScadenzario::class)
        ->set('tipo', 'inventato')
        ->assertSee('Taratura Rossi');

    expect($componente->instance()->haFiltriAttivi())->toBeFalse();
});

it('finds a row by the name of the machine, which is what the placeholder promises', function () {
    // ⛔ La ricerca ha due rami — descrizione e nome della macchina — e finora
    // solo il jolly era provato: quel test resta verde anche cancellando il ramo
    // sulla macchina, perché con `%` neutralizzato nessuno dei due matcha. Il
    // campo dice «Cerca per macchina o descrizione».
    apertoIl($this->cappaBianchi, today()->addDays(6)->toDateString(), 'Verifica annuale');
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Controllo periodico');

    Livewire::test(ParcoScadenzario::class)
        ->set('search', 'Cappa')
        ->assertSee('Verifica annuale')
        ->assertDontSee('Controllo periodico');
});

it('finds a row by its description too', function () {
    apertoIl($this->cappaBianchi, today()->addDays(6)->toDateString(), 'Verifica annuale');
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Controllo periodico');

    Livewire::test(ParcoScadenzario::class)
        ->set('search', 'periodico')
        ->assertSee('Controllo periodico')
        ->assertDontSee('Verifica annuale');
});

it('lowercases the search term the way Postgres does, accents included', function () {
    // 🔴 **Un test sul BINDING, non sulle righe.** `strtolower()` è byte-wise:
    // su «SANITÀ» lascerebbe intatti i due byte della À mentre il `LOWER()` di
    // Postgres è UTF-8-aware, e la macchina non si troverebbe. In locale il
    // difetto è invisibile — il `LOWER()` di SQLite converte solo A–Z — quindi
    // sui dati non si coglie mai, né qui né in CI.
    $bindings = ScadenzarioParco::base(Perimetro::tutti(), 'SANITÀ')->getBindings();

    // ⛔ Un ago solo: `toContain()` è variadico, e un secondo argomento
    // diventerebbe un secondo ago invece di un messaggio.
    expect($bindings)->toContain('%sanità%');
});

it('bounces a page beyond the last one back onto rows, instead of an empty table', function () {
    // Il caso reale è un `?page=5` rimasto in un segnalibro, o il perimetro
    // stretto con la pagina in fondo: senza il rimbalzo la tabella direbbe
    // «Nessun intervento aperto sui clienti selezionati» mentre le tre tile in
    // cima contano ventuno righe.
    for ($i = 1; $i <= 21; $i++) {
        apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Riga numero '.$i);
    }

    Livewire::test(ParcoScadenzario::class)
        ->call('gotoPage', 5)
        ->assertViewHas('interventi', fn ($p) => $p->currentPage() === 2 && $p->count() > 0)
        ->assertDontSee('Nessun intervento aperto sui clienti selezionati');
});

it('sends to remove the filter, not to look elsewhere, when the filters emptied the list', function (string $property, mixed $valore) {
    // 🔴 Dei tre rami del messaggio di elenco vuoto era provato solo il
    // perimetro: `haFiltriAttivi()` poteva tornare `false` sempre, e con
    // duecento interventi in elenco una ricerca senza esiti avrebbe detto
    // «Nessun intervento aperto sui clienti selezionati» — cioè avrebbe mandato
    // a cercare altrove invece che a togliere il filtro. Ogni ramo del predicato
    // ha il suo caso.
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Taratura Rossi', TipoIntervento::ManutenzioneOrdinaria);

    Livewire::test(ParcoScadenzario::class)
        ->set($property, $valore)
        ->assertDontSee('Taratura Rossi')
        ->assertSee('Nessun risultato per i filtri applicati');
})->with([
    'la ricerca' => ['search', 'zzz'],
    'il tipo' => ['tipo', 'taratura_e_certificazione'],
    'la partizione' => ['stato', 'scaduti'],
]);

it('says the park has nothing open, when nothing is filtering the list', function () {
    // Il rovescio: senza filtri il messaggio non deve mandare a toglierne uno.
    Livewire::test(ParcoScadenzario::class)
        ->assertSee('Nessun intervento aperto sui clienti selezionati')
        ->assertDontSee('Nessun risultato per i filtri applicati');
});

it('does not read the whole client list for a dropdown the page is not showing', function () {
    // La tendina dei clienti sta dentro il solo modo «scelti»: negli altri due
    // — compreso «tutti», che è il default — la query sull'intera tabella
    // account si eseguiva lo stesso e la collection veniva scartata, a ogni
    // render, cioè a ogni pausa di digitazione nella ricerca.
    Livewire::test(ParcoScadenzario::class)
        ->assertViewHas('selezionabili', fn ($c) => $c->isEmpty())
        // ⚠️ E l'altra metà, o «non leggerla mai» passerebbe questo test: quando
        // la tendina c'è, dentro ci sono i clienti.
        ->set('modo', Perimetro::SCELTI)
        ->assertViewHas('selezionabili', fn ($c) => $c->pluck('ragione_sociale')->contains('Gruppo Rossi'))
        ->assertSee('Lab Bianchi');
});

// ─── 6. Da qui si GUARDA: la sola leva è «impersona» ────────────────────────

it('offers the impersonate button next to the row of a client that has a member', function () {
    apertoIl($this->autoclaveRossi, today()->addDays(5)->toDateString(), 'Taratura Rossi');

    Livewire::test(ParcoScadenzario::class)->assertSee('Impersona');
});

it('says so, instead of showing nothing, when a client has no impersonable member', function () {
    // ⚠️ Un'assenza muta fa chiedere se sia un difetto — è successo davvero su
    // questa piattaforma, il 28 Ago 2026, con la striscia dell'account di
    // piattaforma. Bianchi non ha membri: la cella lo dice.
    apertoIl($this->cappaBianchi, today()->addDays(6)->toDateString(), 'Taratura Bianchi');

    Livewire::test(ParcoScadenzario::class)
        ->set('modo', Perimetro::SCELTI)
        // ⚠️ `clientiScelti` senza `modo` è INERTE — il default è «tutti» — e
        // l'elenco conterrebbe anche la riga di Rossi, che un membro ce l'ha.
        ->set('modo', Perimetro::SCELTI)
        ->set('clientiScelti', [$this->bianchi->id])
        ->assertSee('Nessun membro impersonabile');
});

it('never offers the Developer, who is the one account that is never impersonable', function () {
    $developer = User::factory()->create(['name' => 'Il Developer', 'tenant_id' => $this->sedeBianchi->id]);
    $developer->assignRole('Developer');
    $this->bianchi->aggiungiMembro($developer);

    apertoIl($this->cappaBianchi, today()->addDays(6)->toDateString(), 'Taratura Bianchi');

    Livewire::test(ParcoScadenzario::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('modo', Perimetro::SCELTI)
        ->set('clientiScelti', [$this->bianchi->id])
        ->assertSee('Nessun membro impersonabile')
        ->assertDontSee('Il Developer');
});

it('refuses to open the member picker to somebody who may look but not impersonate', function () {
    // 🔴 `tenants.view_all` e `utenti.impersonate` sono permessi **diversi**:
    // stare nel parco non è poter entrare in casa di un cliente. Senza la
    // guardia dentro `apriScelta()`, l'azione raggiungerebbe nome ed email dei
    // membri di un altro tenant partendo da un id.
    //
    // Il ruolo su misura non è un artificio: la matrice si modifica **a
    // runtime** dall'editor ruoli, e un ruolo con la vista di piattaforma e
    // senza l'impersonazione è a un click di distanza.
    $ruolo = Role::findOrCreate('Osservatore Parco', 'web');
    $ruolo->givePermissionTo(Permission::findByName('tenants.view_all', 'web'));

    $guardone = User::factory()->create(['tenant_id' => $this->sedeRossi->id]);
    $guardone->assignRole($ruolo);

    $this->actingAs($guardone->fresh());

    // ⚠️ Si asserisce sul 403 e sulla modale che resta chiusa, non su
    // `->throws()`: il test harness di Livewire cattura l'eccezione e la rende
    // come risposta, quindi un `throws()` qui non scatterebbe mai.
    Livewire::test(ParcoScadenzario::class)
        ->call('apriScelta', $this->rossi->id)
        ->assertForbidden()
        ->assertSet('sceltaImpersonazione', null);
});

it('offers no way to write anything from the schedule of the client park', function () {
    // ⛔ ADR-037 concede la LETTURA cross-cliente e lascia la scrittura
    // all'impersonazione, che è per cliente e lascia una riga di audit con
    // dentro chi agiva e per conto di chi. Una scrittura da qui perderebbe quel
    // contesto **proprio dove serve di più**.
    //
    // ⚠️ I commenti si tolgono prima di cercare: questi docblock nominano le
    // forme pericolose apposta per dire che non si usano, e un guardrail che
    // legge il testo invece del codice punisce chi documenta.
    $codice = sorgentiDelloScadenzarioDelParco();

    // ⛔ Un ago per asserzione: `toContain()` è VARIADICO, e un secondo
    // argomento diventerebbe un secondo ago — rendendo l'asserzione negativa
    // vera per sempre.
    expect($codice)->not->toContain('->update(');
    expect($codice)->not->toContain('->delete(');
    expect($codice)->not->toContain('->forceDelete(');
    expect($codice)->not->toContain('->save(');
    expect($codice)->not->toContain('->insert(');
    expect($codice)->not->toContain('->increment(');
});

it('reads through the park door and never straight from the platform view', function () {
    // Il gemello locale di `ParcoBypassGuardrailTest`: i builder di
    // `VistaPiattaforma` non sono filtrati per cliente — servono a contare tutti
    // i clienti, non a elencare le righe di quelli scelti — quindi usarli qui
    // mostrerebbe «tutti» mentre il filtro in cima alla pagina dice altro.
    // `VistaPiattaforma::PERMESSO` resta lecito: è una costante, non una query.
    $codice = sorgentiDelloScadenzarioDelParco();

    expect(preg_match('/VistaPiattaforma::\w+\(/', $codice))->toBe(0);
    expect($codice)->not->toContain('withoutGlobalScope');
    expect($codice)->toContain('ParcoClienti::');
});

/**
 * Il codice della scheda — componente, supporto e vista — **senza commenti**.
 *
 * Il nome è lungo di proposito: le funzioni dichiarate in un file di test sono
 * globali per l'intera suite, e un `sorgenti()` qualunque colliderebbe col
 * primo omonimo che nasce altrove.
 */
function sorgentiDelloScadenzarioDelParco(): string
{
    $php = collect([
        app_path('Livewire/Piattaforma/ParcoScadenzario.php'),
        app_path('Support/Piattaforma/ScadenzarioParco.php'),
    ])->map(fn (string $f) => preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($f)))->implode("\n");

    $blade = preg_replace(
        '#\{\{--.*?--\}\}#s',
        '',
        file_get_contents(resource_path('views/livewire/piattaforma/parco-scadenzario.blade.php'))
    );

    return $php."\n".$blade;
}
