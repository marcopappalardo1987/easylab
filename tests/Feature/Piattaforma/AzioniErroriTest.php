<?php

use App\Livewire\Piattaforma\Errori;
use App\Livewire\Piattaforma\RegistroAudit;
use App\Livewire\Piattaforma\SchedaErrore;
use App\Models\Errore;
use App\Support\Audit\SoggettiAudit;
use App\Support\AuditLog;
use App\Support\Tenancy\VistaPiattaforma;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/**
 * 🔴 I tre gesti dell'error tracker interno — risolvi, ignora, riapri (S6,
 * blocco 6).
 *
 * Sono le **uniche scritture** del tracker fuori dal gestore delle eccezioni, e
 * vivono su `SchedaErrore`: `Errori` resta in sola lettura, perché zittire una
 * issue senza averla aperta è il gesto che poi non ci si ricorda di aver fatto.
 *
 * ## Le due cose che questo file esiste per non far perdere
 *
 * 🔴 **`assertForbidden()` su un'azione Livewire non è prova di rifiuto.** Le tre
 * azioni non fanno `skipRender()` — dopo il gesto la scheda deve mostrare il
 * badge nuovo — quindi `render()` gira **dopo** l'azione e il suo
 * `Gate::authorize()` risponde 403 **a scrittura già avvenuta**. Togliendo il
 * gate dall'azione un test che si fermasse al codice di stato resterebbe verde
 * mentre la issue è già stata chiusa da chi non poteva. Qui il rifiuto si
 * asserisce quindi **sul dato**: `$errore->fresh()->stato` e il conteggio delle
 * righe di `Activity`.
 *
 * 🔴 **Il messaggio dell'errore non deve raggiungere il registro di audit.** Il
 * registro si legge con `tenants.view_all`, cioè dal **Superadmin**, che
 * `system.logs.view` non ce l'ha e a cui `/piattaforma/errori` risponde 403.
 * L'etichetta del soggetto è la `classe` e le `properties` portano la stessa
 * cosa: mettere il messaggio — interpolato, e con dentro dati di richiesta —
 * lo farebbe filtrare attraverso il gate più stretto del progetto.
 *
 * ⚠️ **`Activity` va svuotata prima di ogni gesto**: il seeder dei ruoli lascia
 * una riga nel registro da sé, e senza quella pulizia ogni conteggio misurerebbe
 * anche il rumore della fixture.
 *
 * ⚠️ Il tracker è **acceso durante la suite**: dove si conta si conta per id o
 * per impronta, mai con `Errore::count()`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->developer = utenteConRuolo('Developer');
    $this->superadmin = utenteConRuolo('Superadmin');
});

/** La scheda montata come Developer, cioè l'unico ruolo che la può aprire. */
function schedaConAzioni(Errore $errore)
{
    return Livewire::actingAs(test()->developer)
        ->test(SchedaErrore::class, ['errore' => $errore]);
}

/**
 * Il gruppo dei tre pulsanti, estratto per il suo `aria-label`.
 *
 * ⚠️ Si estrae il **blocco** e poi si asserisce sul **testo visibile**, mai su un
 * marcatore `data-*`: in questa feature la scorciatoia è ricomparsa quattro
 * volte, e un `data-*` non prova ciò che l'utente legge — il giorno in cui
 * qualcuno lo toglie porta via l'asserzione insieme al difetto. Sull'HTML intero
 * non basterebbe nemmeno: la pagina stampa «Risolto» nel badge e «riaperto»
 * nella frase esplicativa, quindi un `toContain` fuori dal blocco sarebbe verde
 * anche senza un solo pulsante.
 */
function bloccoAzioni(string $html): string
{
    preg_match('/<div[^>]*aria-label="Azioni sull\'errore".*?<\/div>/s', $html, $blocco);

    // Non `?? ''`: un blocco assente e uno vuoto vanno distinti, o un
    // `not->toContain()` sarebbe verde proprio quando i pulsanti sono spariti.
    expect($blocco)->not->toBeEmpty();

    return $blocco[0];
}

/**
 * Il **testo che si legge davvero** dentro un pezzo di HTML.
 *
 * ⚠️ Serve perché asserire su `'>Risolvi'` non funziona e sarebbe un modo
 * elaborato di guardare di nuovo il markup: Blade manda a capo il contenuto dei
 * componenti e Livewire ci infila i propri marcatori `<!--[if BLOCK]>`, quindi
 * fra il `>` del tag e la parola ci sono ritorni a capo e commenti. Togliendo i
 * tag e normalizzando gli spazi resta ciò che l'utente vede — che è l'unica cosa
 * su cui questi test devono poter fallire.
 */
function testoVisibile(string $html): string
{
    return trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
}

/**
 * Un'eccezione **sempre dallo stesso punto**, riportata davvero.
 *
 * Serve ai test che mettono i gesti umani a confronto con la riapertura
 * automatica: due chiamate condividono l'impronta, quindi incrementano la stessa
 * issue invece di crearne due.
 *
 * ⚠️ Il `throw` sta in una funzione a parte, come in `CatturaErroriTest`: è la
 * forma su cui l'impronta è già provata stabile fra chiamanti diversi.
 */
function lanciaPerIGesti(): never
{
    throw new RuntimeException('lo stesso guasto di prima');
}

function scatenaErrorePerIGesti(): void
{
    try {
        lanciaPerIGesti();
    } catch (Throwable $e) {
        report($e);
    }
}

// ─── I tre gesti, sul dato ───────────────────────────────────────────────────

it('resolves an issue, recording who closed it and when', function () {
    $errore = issueErrore();

    schedaConAzioni($errore)->call('risolvi')->assertOk();

    $fresco = $errore->fresh();

    expect($fresco->stato)->toBe('risolto')
        ->and($fresco->risolto_da)->toBe($this->developer->id)
        ->and($fresco->risolto_at)->not->toBeNull();
});

it('writes the columns that a mass assignment would have dropped in silence', function () {
    // 🔴 La prova che il gesto usa `forceFill()` e non `update()`. `stato`,
    // `contesti` e i timestamp di chiusura sono **fuori** da `$fillable` di
    // proposito: un `update()` di massa li scarterebbe senza lanciare niente, il
    // metodo tornerebbe `void` come se avesse funzionato e la issue resterebbe
    // aperta. La prima riga congela il fatto che rende possibile quel guasto, la
    // seconda che non sia avvenuto.
    $errore = issueErrore();

    expect($errore->isFillable('stato'))->toBeFalse()
        ->and($errore->isFillable('contesti'))->toBeFalse()
        ->and($errore->isFillable('risolto_da'))->toBeFalse();

    schedaConAzioni($errore)->call('risolvi');

    expect($errore->fresh()->stato)->toBe('risolto');
});

it('ignores an issue, and never leaves someone credited with a closure they did not make', function () {
    // ⚠️ «Ignorato» e «chiuso da qualcuno» sono due fatti diversi. Partendo da una
    // issue **risolta** — che porta quindi `risolto_da` — il gesto deve staccare
    // l'attribuzione, o la scheda continuerebbe a dire che quella persona l'ha
    // chiusa mentre lo stato dice tutt'altro.
    $errore = issueErrore([
        'stato' => 'risolto',
        'risolto_at' => now()->subDay(),
        'risolto_da' => $this->developer->id,
    ]);

    schedaConAzioni($errore)->call('ignora')->assertOk();

    $fresco = $errore->fresh();

    expect($fresco->stato)->toBe('ignorato')
        ->and($fresco->risolto_da)->toBeNull()
        ->and($fresco->risolto_at)->toBeNull();
});

it('reopens by hand, clearing the automatic stamp and the context budget', function () {
    // 🔴 **Il budget dei contesti si azzera anche sulla riapertura a mano**, ed è
    // la ragione per cui `riapri()` non è un semplice `stato = 'aperto'`.
    //
    // L'azzeramento esiste già sulla riapertura *automatica*
    // (`CatturaErrori::incrementa()`), ma quel ramo scatta **solo** su una issue
    // `risolto`: dopo una riapertura a mano lo stato è `aperto`, quindi la
    // prossima occorrenza non ci passerebbe e il budget resterebbe a venti — cioè
    // al tetto. La issue riaperta non catturerebbe **mai più** un contesto,
    // proprio la prova per cui la si sta riaprendo.
    //
    // ⚠️ E si azzera `riaperto_automaticamente_at`, che significa «l'ultima
    // riapertura è avvenuta da sé» e che la scheda stampa come «riaperto
    // automaticamente il …». Lasciarla dopo un gesto umano farebbe dire alla
    // pagina una cosa falsa sul solo fatto che distingue una regressione da una
    // decisione.
    $errore = issueErrore([
        'stato' => 'risolto',
        'risolto_at' => now()->subDay(),
        'risolto_da' => $this->developer->id,
        'riaperto_automaticamente_at' => now()->subDays(2),
        'contesti' => 20,
        'ultimo_contesto_at' => now()->subDay(),
    ]);

    schedaConAzioni($errore)->call('riapri')->assertOk();

    $fresco = $errore->fresh();

    expect($fresco->stato)->toBe('aperto')
        ->and($fresco->contesti)->toBe(0)
        ->and($fresco->ultimo_contesto_at)->toBeNull()
        ->and($fresco->riaperto_automaticamente_at)->toBeNull()
        ->and($fresco->risolto_at)->toBeNull()
        ->and($fresco->risolto_da)->toBeNull();
});

it('lets an ignored issue stay silent, while a resolved one is contradicted by the next occurrence', function () {
    // La semantica dei due stati, provata **attraverso i gesti** e non con una
    // fixture scritta a mano: «risolto» vuol dire «credo di averlo sistemato» e
    // una nuova occorrenza lo contraddice; «ignorato» vuol dire «so che c'è e non
    // me ne importa», ed è l'unico interruttore di silenzio del tracker. Se una
    // issue ignorata tornasse aperta alla prima occorrenza successiva, ignorare
    // non vorrebbe dire niente.
    scatenaErrorePerIGesti();

    $errore = Errore::sole();

    $errore->risolvi($this->developer);
    scatenaErrorePerIGesti();

    expect($errore->fresh()->stato)->toBe('aperto')
        ->and($errore->fresh()->riaperto_automaticamente_at)->not->toBeNull();

    $errore->refresh()->ignora($this->developer);
    scatenaErrorePerIGesti();

    expect($errore->fresh()->stato)->toBe('ignorato')
        // Il contatore cresce lo stesso: la riga continua a dire la verità su
        // quante volte succede, anche mentre tace.
        ->and($errore->fresh()->occorrenze)->toBe(3);
});

// ─── Il rifiuto, asserito sul DATO ───────────────────────────────────────────

it('refuses the action to a Superadmin, asserted on the data and not on the status code', function () {
    // 🔴 **Il test dell'intero blocco.** Le azioni non fanno `skipRender()`,
    // quindi `render()` gira **dopo** e il suo `Gate::authorize()` risponde 403
    // anche quando l'azione non ne ha uno proprio — cioè **a scrittura già
    // avvenuta**. Verificato su questo progetto: togliendo il `Gate::authorize()`
    // da `SchedaErrore::risolvi()` la risposta resta 403 e la issue risulta
    // chiusa dal Superadmin. Un test fermo al codice di stato sarebbe rimasto
    // verde su quella mutazione.
    //
    // Il soggetto è il Superadmin e non un utente senza ruoli: ha ogni altro
    // permesso di piattaforma e non questo, quindi è l'unico che nomina davvero
    // il confine. Un utente nudo verrebbe respinto da qualunque cosa.
    //
    // ⚠️ Si monta **come Developer** e si cambia utente dopo: il montaggio
    // gatterebbe già in `render()`, e senza montaggio non ci sarebbe azione da
    // chiamare. È esattamente la forma dell'attacco vero — una scheda aperta e
    // uno snapshot presentato da qualcun altro.
    $errore = issueErrore();
    $componente = schedaConAzioni($errore);

    Activity::query()->delete();
    Livewire::actingAs($this->superadmin);

    $componente->call('risolvi')->assertForbidden();

    expect($errore->fresh()->stato)->toBe('aperto')
        ->and($errore->fresh()->risolto_da)->toBeNull()
        ->and($errore->fresh()->risolto_at)->toBeNull()
        // Nessuna riga di audit: se la scrittura fosse avvenuta ce ne sarebbe
        // una, e direbbe il nome del Superadmin accanto a un gesto che non
        // poteva compiere.
        ->and(Activity::query()->count())->toBe(0);
});

it('refuses every one of the three gestures to a Superadmin, on the data', function (string $azione) {
    $errore = issueErrore(['stato' => 'risolto', 'risolto_da' => test()->developer->id]);
    $componente = schedaConAzioni($errore);

    Activity::query()->delete();
    Livewire::actingAs($this->superadmin);

    $componente->call($azione)->assertForbidden();

    expect($errore->fresh()->stato)->toBe('risolto')
        ->and($errore->fresh()->risolto_da)->toBe($this->developer->id)
        ->and(Activity::query()->count())->toBe(0);
})->with(['risolvi', 'ignora', 'riapri']);

it('refuses the gesture on a real Livewire update, and the issue stays open', function () {
    // 🔴 L'altra metà della coppia: un POST vero a `/livewire/update`, dove
    // Livewire rilegge `memo.path`, rimatcha la rotta e riapplica i middleware
    // persistenti — fra cui il `can:`. È la guardia **larga**, quella che regge
    // anche se un domani l'azione guadagnasse `skipRender()` e `render()` non
    // girasse affatto.
    //
    // ⚠️ Lo snapshot si prende **per nome del componente**: il primo della pagina
    // è lo switcher di ente, montato dal layout su ogni schermata.
    $errore = issueErrore();

    $html = $this->actingAs($this->developer)
        ->get(route('piattaforma.errori.mostra', $errore))
        ->assertOk()
        ->getContent();

    $snapshot = snapshotDa($html, 'piattaforma.scheda-errore');

    expect($snapshot)->not->toBe('');

    auth()->logout();
    $this->actingAs($this->superadmin);

    // ⚠️ **Si svuota DOPO il cambio di utente, non prima**: `auth()->logout()`
    // scatena l'evento `Logout`, che `AuditLogSubscriber` traccia — una riga di
    // audit legittima, che però un conteggio a zero leggerebbe come «il gesto è
    // passato». Verificato: con la pulizia prima del logout questo test falliva
    // dicendo il contrario di ciò che stava succedendo.
    Activity::query()->delete();

    $this->withHeader('X-Livewire', '1')->postJson(route('default-livewire.update'), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'risolvi', 'params' => []]],
        ]],
    ])->assertForbidden();

    // E di nuovo **sul dato**, non sul codice di stato.
    expect($errore->fresh()->stato)->toBe('aperto')
        ->and(Activity::query()->count())->toBe(0);
});

// ─── La riga nel registro, letta dal Superadmin ──────────────────────────────

it('names the error in the audit register, instead of «Errore · #12»', function () {
    // 🔴 Senza `Errore::class` in `SoggettiAudit::SOGGETTI` l'etichetta si legge
    // «Errore · #12» e il tipo **non compare nel filtro** del registro, perché
    // `RegistroAudit::tipiSoggetto()` legge quella stessa mappa. La riga di audit
    // esisterebbe e non sarebbe interrogabile: il difetto che `Role` ha avuto
    // finché nessuno l'ha guardato.
    //
    // ⚠️ La riga si legge **attraverso `VistaPiattaforma::audit()`**, che porta
    // con sé il pavimento `log_name = audit`: una riga scritta sul canale
    // sbagliato esisterebbe a database e non comparirebbe **mai** nel registro.
    // Solo così la mutazione «`activity(AuditLog::NAME)` → `activity()` nuda»
    // diventa rossa.
    $errore = issueErrore(['classe' => 'App\\Guasti\\ConnessionePersa']);

    Activity::query()->delete();

    schedaConAzioni($errore)->call('risolvi');

    $this->actingAs($this->superadmin);

    $righe = VistaPiattaforma::audit()->get();

    expect($righe)->toHaveCount(1);

    $riga = $righe->first();
    $riga->load(['subject' => SoggettiAudit::vincolo()]);

    expect(SoggettiAudit::etichetta($riga)->testo())->toBe('Errore · App\\Guasti\\ConnessionePersa')
        ->and($riga->description)->toBe('Errore risolto')
        ->and($riga->causer_id)->toBe($this->developer->id)
        ->and($riga->subject_id)->toBe($errore->id);

    // E il tipo è offerto dal filtro, che legge la stessa mappa.
    expect(Livewire::actingAs($this->superadmin)->test(RegistroAudit::class)->instance()->tipiSoggetto())
        ->toHaveKey(Errore::class);
});

it('never lets the error message reach the register, which a Superadmin can read', function () {
    // 🔴 **Il confine di privacy di questo blocco.** Il registro di audit sta
    // dietro `tenants.view_all`; la pagina degli errori dietro `system.logs.view`,
    // che il Superadmin **non ha** — è la prima schermata del progetto che non può
    // aprire. Se l'etichetta del soggetto fosse `messaggio` invece di `classe`, i
    // messaggi delle eccezioni — interpolati, e con dentro dati di richiesta veri
    // — gli arriverebbero comunque, filtrati attraverso il gate più stretto del
    // progetto.
    //
    // Mutazione: `['Errore', 'classe']` → `['Errore', 'messaggio']` in
    // `SoggettiAudit::SOGGETTI` → rosso, sia sull'etichetta sia sulla pagina.
    $riconoscibile = 'RSSMRA80A01H501U';

    $errore = issueErrore([
        'classe' => 'App\\Guasti\\AnagraficaAssente',
        'messaggio' => "Utente {$riconoscibile} non trovato in anagrafica",
    ]);

    Activity::query()->delete();

    schedaConAzioni($errore)->call('risolvi');

    $this->actingAs($this->superadmin);

    $riga = VistaPiattaforma::audit()->sole();
    $riga->load(['subject' => SoggettiAudit::vincolo()]);

    expect(SoggettiAudit::etichetta($riga)->testo())->not->toContain($riconoscibile)
        // ⚠️ Non basta guardare l'etichetta: le `properties` viaggiano nella
        // stessa riga e la pagina di dettaglio del registro le rende per intero.
        ->and($riga->properties->toJson())->not->toContain($riconoscibile)
        ->and($riga->description)->not->toContain($riconoscibile)
        // Il rovescio, o le tre righe sopra sarebbero verdi anche per una riga
        // vuota: la `classe` c'è, ed è ciò che tiene la riga leggibile il giorno
        // in cui la retention si porta via il soggetto.
        ->and($riga->properties->get('classe'))->toBe('App\\Guasti\\AnagraficaAssente');

    // E il registro **come lo legge davvero il Superadmin**, non solo il dato.
    Livewire::actingAs($this->superadmin)
        ->test(RegistroAudit::class)
        ->assertSee('Errore risolto')
        ->assertDontSee($riconoscibile);
});

it("keeps the audit row reachable from the register's «atti» filter", function () {
    // 🔴 Congela che `event` resti **NULL**: la riga è un **atto**, un gesto, non
    // il diff delle colonne di un model. Un `->event('updated')` scritto per
    // abitudine la farebbe scivolare fra le modifiche di dominio e **sparire da
    // questo filtro**, restando invisibile a ogni altro test.
    //
    // ⚠️ Si prova dal **filtro** e non solo dalla colonna: `event === null`
    // asserito a mano non direbbe a cosa serve.
    $errore = issueErrore();

    Activity::query()->delete();

    schedaConAzioni($errore)->call('ignora');

    $this->actingAs($this->superadmin);

    $atto = VistaPiattaforma::audit()->sole();

    expect($atto->event)->toBeNull()
        // `attribute_changes` è la colonna del trait `AuditsDomainWrites`:
        // scriverla da fuori romperebbe l'invariante che `DettaglioAttivita`
        // rende per l'intero registro.
        ->and($atto->attribute_changes)->toBeEmpty();

    // Una riga di dominio accanto, o «il filtro mostra la nostra» sarebbe vero
    // anche per un filtro che non filtra affatto.
    $crud = Activity::create([
        'log_name' => AuditLog::NAME,
        'description' => 'Modifica strumento',
        'event' => 'updated',
    ]);

    $ids = fn (string $azione) => Livewire::actingAs($this->superadmin)
        ->test(RegistroAudit::class)
        ->set('azione', $azione)
        ->viewData('righe')->pluck('id')->sort()->values()->all();

    expect($ids(RegistroAudit::ATTI))->toBe([$atto->id])
        ->and($ids('updated'))->toBe([$crud->id]);
});

it('leaves one row per gesture, each saying where the issue came from and where it went', function () {
    // Tre gesti, tre righe: i gesti sono reversibili con un click **proprio
    // perché** ognuno lascia la propria traccia. Il `da`/`a` è il vocabolario che
    // `DettaglioAttivita` rende come elenco chiave/valore, lo stesso della
    // matrice dei permessi.
    $errore = issueErrore();

    Activity::query()->delete();

    $componente = schedaConAzioni($errore);
    $componente->call('risolvi')->call('ignora')->call('riapri');

    $this->actingAs($this->superadmin);

    $righe = VistaPiattaforma::audit()->orderBy('id')->get();

    expect($righe->pluck('description')->all())->toBe([
        'Errore risolto',
        'Errore ignorato',
        'Errore riaperto',
    ])->and($righe->map(fn (Activity $r) => $r->properties->get('da').'→'.$r->properties->get('a'))->all())
        ->toBe(['aperto→risolto', 'risolto→ignorato', 'ignorato→aperto']);
});

it('never writes an audit row for the automatic reopening', function () {
    // ⚠️ La riapertura automatica **non è il gesto di una persona**: il registro
    // racconta chi ha fatto cosa, e lì non c'è nessun chi. Solo i tre gesti umani
    // lasciano una riga — e questo test sta qui, accanto a loro, perché è il
    // confine che il blocco 6 poteva far saltare per simmetria.
    scatenaErrorePerIGesti();

    $errore = Errore::sole();
    $errore->risolvi($this->developer);

    Activity::query()->delete();

    scatenaErrorePerIGesti();

    expect($errore->fresh()->stato)->toBe('aperto')
        ->and($errore->fresh()->riaperto_automaticamente_at)->not->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
});

// ─── I pulsanti, sul testo che l'utente legge ────────────────────────────────

it('offers only the two gestures that lead somewhere else', function (string $stato, array $attesi, string $assente) {
    // ⚠️ Asserito sul **testo visibile** dentro il blocco estratto, mai su un
    // marcatore `data-*`: quella scorciatoia è ricomparsa quattro volte in questa
    // feature, e non prova ciò che l'utente vede. Un `toContain` sulla pagina
    // intera non basterebbe nemmeno: «Risolto» sta nel badge e «riaperto» nella
    // frase esplicativa qui sotto, quindi sarebbe verde senza un solo pulsante.
    //
    // Il gesto che riporterebbe la issue nello stato in cui già si trova non si
    // offre: scriverebbe una riga di audit che dice «da risolto a risolto», cioè
    // rumore in un registro che deve restare leggibile.
    $testo = testoVisibile(bloccoAzioni(schedaConAzioni(issueErrore(['stato' => $stato]))->html()));

    expect($testo)->toContain(...$attesi)
        ->and($testo)->not->toContain($assente);
})->with([
    'aperto' => ['aperto', ['Risolvi', 'Ignora'], 'Riapri'],
    'risolto' => ['risolto', ['Riapri', 'Ignora'], 'Risolvi'],
    'ignorato' => ['ignorato', ['Riapri', 'Risolvi'], 'Ignora'],
]);

it('wires each button to its own action, on the same element as its label', function (string $stato, array $coppie) {
    // 🔴 **Etichetta e azione sullo STESSO elemento.** La prima stesura
    // asseriva che `wire:click="risolvi"` e `wire:click="ignora"` comparissero
    // *da qualche parte* nel blocco, e che le etichette comparissero *da
    // qualche parte*: due verità separate che non dicono niente sul legame.
    //
    // Verificato scambiando i due `wire:click` nel Blade — il pulsante
    // «Risolvi» chiamava `ignora` — e trovando l'intera suite di piattaforma
    // **verde**. Cioè un click su «Risolvi» avrebbe azionato l'unico
    // interruttore di silenzio del tracker, il solo stato che non si riapre
    // mai: il gesto più conseguente della pagina, sbagliato in silenzio.
    //
    // ⚠️ È la **quinta** ricomparsa del marcatore invisibile in questa feature,
    // nella forma più sottile: qui il testo visibile *era* asserito — solo, non
    // insieme al marcatore.
    $blocco = bloccoAzioni(schedaConAzioni(issueErrore(['stato' => $stato]))->html());

    // Ogni `<button>` come unità: il suo `wire:click` e il suo testo.
    preg_match_all('/<button\b[^>]*>.*?<\/button>/s', $blocco, $bottoni);

    $reso = [];

    foreach ($bottoni[0] as $bottone) {
        preg_match('/wire:click="([a-z]+)"/', $bottone, $azione);
        $reso[$azione[1] ?? '?'] = trim(preg_replace('/\s+/', ' ', strip_tags($bottone)));
    }

    foreach ($coppie as $azione => $etichetta) {
        expect($reso)->toHaveKey($azione)
            ->and($reso[$azione])->toBe($etichetta);
    }

    // E non ci sono pulsanti in più: `riapri` su una issue già aperta
    // scriverebbe «da aperto ad aperto», rumore in un registro che deve
    // restare leggibile.
    expect(array_keys($reso))->toEqualCanonicalizing(array_keys($coppie));
})->with([
    'aperto' => ['aperto', ['risolvi' => 'Risolvi', 'ignora' => 'Ignora']],
    'risolto' => ['risolto', ['riapri' => 'Riapri', 'ignora' => 'Ignora']],
    'ignorato' => ['ignorato', ['riapri' => 'Riapri', 'risolvi' => 'Risolvi']],
]);

it('never grows a write outside the three gestures', function () {
    // I metodi pubblici del componente, congelati per intento: `render`, `mount`,
    // i tre gesti e i sette di `WithPagination`. Un metodo pubblico nuovo su
    // questa classe è raggiungibile da `/livewire/update`, e questo test è il
    // posto in cui ci si accorge di averlo aggiunto — insieme alla domanda «e il
    // `Gate::authorize()` in testa?».
    // ⚠️ **I metodi di un trait risultano dichiarati dalla classe che lo usa**, e
    // qui la differenza è tutta: `$m->class === SchedaErrore::class` è vero anche
    // per i sette di `WithPagination`. Si sottraggono per nome, così l'elenco che
    // resta è davvero ciò che questa classe ha scritto — e i sette restano
    // nominati altrove per quello che sono: pubblici, raggiungibili da
    // `/livewire/update`, innocui ma esistenti.
    $delTrait = collect((new ReflectionClass(WithPagination::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $m) => $m->name)
        ->all();

    $pubblici = collect((new ReflectionClass(SchedaErrore::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $m) => $m->class === SchedaErrore::class)
        ->map(fn (ReflectionMethod $m) => $m->name)
        ->reject(fn (string $nome) => in_array($nome, $delTrait, true))
        ->sort()->values()->all();

    expect($pubblici)->toBe(['ignora', 'mount', 'render', 'riapri', 'risolvi']);
});

it('asks the same permission from every gesture, and it is the one of the page', function () {
    // Le tre azioni e i due `render()` leggono `Errori::PERMESSO`, non una
    // stringa copiata: cinque copie sono cinque occasioni di gatare un gesto su
    // un permesso e la pagina su un altro — cioè di lasciare scrivibile ciò che è
    // illeggibile.
    //
    // ⚠️ **I commenti si tolgono prima di contare**, ed è la disciplina già
    // scritta in `AuditCoverageGuardrailTest`: il docblock di questa classe nomina
    // `Gate::authorize()` per spiegare *perché* sta in testa a ogni gesto, e un
    // conteggio sul testo grezzo punirebbe chi documenta.
    //
    // Quattro: i tre gesti più `render()`. Il numero è volutamente esatto — un
    // metodo pubblico nuovo senza la sua guardia lo farebbe scendere, e con
    // `>= 3` non lo farebbe.
    $sorgente = collect(token_get_all(file_get_contents(app_path('Livewire/Piattaforma/SchedaErrore.php'))))
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');

    expect(substr_count($sorgente, 'Gate::authorize(Errori::PERMESSO)'))->toBe(4)
        ->and($sorgente)->not->toContain("'system.logs.view'")
        ->and(Errori::PERMESSO)->toBe('system.logs.view');
});
