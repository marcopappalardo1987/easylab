<?php

use App\Jobs\InviaAllertaErrore;
use App\Models\Errore;
use App\Notifications\NuovoErrore;
use App\Support\Errori\CatturaErrori;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Mailer\SentMessage;

/**
 * 🔴 L'**allerta** dell'error tracker interno (S6 — 🔗 ADR-017;
 * Privacy §3 e riga T8): `App\Notifications\NuovoErrore` e il ramo che la fa
 * partire in `App\Support\Errori\CatturaErrori`.
 *
 * Quattro domande diverse, e vale la pena tenerle distinte perché si rompono
 * per ragioni diverse:
 *
 * 1. **quando** parte — issue nuova o riapertura, mai due volte per lo stesso
 *    fatto, mai per una issue zittita, e col tetto giornaliero;
 * 2. 🔴 **cosa contiene** — classe, `file:riga`, conteggio e link, e nient'altro:
 *    l'email esce dal perimetro del gate più stretto del progetto;
 * 3. 🔴 che un alert **fallito** non diventi un secondo errore;
 * 4. il **contratto di coda**, che sotto `sync` non si può provare altrimenti.
 *
 * ⚠️ **Si legge la posta VERA, dal transport `array`**, invece di `Mail::fake()`
 * o `Notification::fake()`. La differenza conta proprio per il punto 2: un fake
 * conserva l'**oggetto notifica**, e asserire su quello proverebbe cosa si è
 * passato al costruttore, non cosa è finito nel corpo dell'email. Qui si guarda
 * la stringa consegnata, che è ciò che un lettore della casella vedrebbe.
 *
 * ⚠️ **Il tracker è acceso per tutta la suite** e queste prove creano eccezioni
 * apposta: dove si conta, si conta **per impronta**, mai con `Errore::count()`.
 */

/**
 * Il `throw` vero, in un punto **solo**.
 *
 * ⚠️ **Due funzioni e non una, ed è il pezzo che rende falsificabili metà dei
 * test qui sotto.** L'impronta è `sha1(classe + punto d'origine + chiamante
 * applicativo)`: con un unico helper, ogni chiamata da una riga di test diversa
 * avrebbe un **chiamante** diverso, cioè una issue diversa — e i test della
 * riapertura e del «una volta sola» starebbero misurando due bug distinti senza
 * accorgersene. Con l'intermedio qui sotto il chiamante è sempre la stessa riga,
 * quindi ogni invocazione, da qualunque test, colpisce la **stessa** issue.
 */
function lancioDaAllertare(string $messaggio): never
{
    throw new RuntimeException($messaggio);
}

/** Una o più occorrenze della **stessa** issue, riportate come le riporta Laravel. */
function scatenaAlert(int $volte = 1, string $messaggio = 'guasto da allertare'): void
{
    for ($i = 0; $i < $volte; $i++) {
        try {
            lancioDaAllertare($messaggio);
        } catch (Throwable $e) {
            report($e);
        }
    }
}

/** Una issue **diversa** dalla precedente: chiamante diverso, impronta diversa. */
function scatenaSecondoAlert(): void
{
    try {
        lancioDaAllertare('seconda issue');
    } catch (Throwable $e) {
        report($e);
    }
}

/** Una terza issue ancora. */
function scatenaTerzoAlert(): void
{
    try {
        lancioDaAllertare('terza issue');
    } catch (Throwable $e) {
        report($e);
    }
}

/** @return Collection<int, SentMessage> */
function postaInviata(): Collection
{
    return Mail::mailer()->getSymfonyTransport()->messages();
}

/**
 * Tutto il testo che un destinatario può leggere: oggetto, corpo HTML e corpo
 * testuale.
 *
 * ⚠️ **Tutti e tre, e non solo l'HTML.** Il canale mail di una notifica
 * markdown costruisce **due** corpi, e una fuga che stesse solo in quello
 * testuale sarebbe invisibile a un test che guarda il primo — con l'aggravante
 * che è il corpo testuale quello che i client di posta più vecchi mostrano.
 */
function testoAlert(int $indice = 0): string
{
    $email = postaInviata()[$indice]->getOriginalMessage();

    return implode("\n", [$email->getSubject(), (string) $email->getHtmlBody(), (string) $email->getTextBody()]);
}

beforeEach(function () {
    // I ruoli servono ai due test che compiono un gesto umano sulla issue
    // (`risolvi()`, `ignora()`) e a quello che fa la richiesta da autenticato.
    $this->seed(RolesAndPermissionsSeeder::class);

    // Una casella nominata, così i test non dipendono dal fallback sul
    // Developer (che ha un default in `config/easylab.php` e in una suite
    // renderebbe indistinguibile «configurato» da «ripiegato»).
    config(['easylab.errori.alert_email' => 'allerta@easylab.test']);
});

it('sends once for a new issue, and never again for the same one', function () {
    // Cinquanta occorrenze dello **stesso** bug: è il caso che riempie la
    // casella se l'alert si aggancia all'occorrenza invece che alla issue.
    scatenaAlert(50);

    $issue = Errore::where('classe', RuntimeException::class)
        ->where('messaggio', 'guasto da allertare')
        ->sole();

    expect($issue->occorrenze)->toBe(50)
        ->and(postaInviata())->toHaveCount(1)
        // Il timbro è ciò che il tetto giornaliero conta: senza, il cap non
        // saprebbe che questa email è partita.
        ->and($issue->alert_inviato_at)->not->toBeNull();

    expect(testoAlert())->toContain('Errore nuovo');
});

it('sends again when a resolved issue comes back, and never for an ignored one', function () {
    $developer = utenteConRuolo('Developer');

    scatenaAlert();

    $issue = Errore::where('messaggio', 'guasto da allertare')->sole();

    expect(postaInviata())->toHaveCount(1);

    // «Credo di averlo sistemato» — e non ha tenuto. La regressione è un fatto
    // nuovo, e va detto: è la sola prova leggibile che una correzione è
    // fallita.
    $issue->risolvi($developer);
    scatenaAlert();

    expect(postaInviata())->toHaveCount(2)
        ->and(testoAlert(1))->toContain('Errore riaperto')
        // ⚠️ Legato al **testo visibile**: se un domani la distinzione fra
        // «nuovo» e «riaperto» sparisse dal corpo, questa riga diventa rossa.
        // Asserire sulla property della notifica non lo direbbe.
        ->and(testoAlert(1))->toContain('risultava risolto');

    // 🔴 «So che c'è e non me ne importa»: l'unico interruttore di silenzio del
    // tracker vale **anche per la posta**, che è dove contava di più. Non c'è
    // una guardia dedicata e non serve — `incrementa()` riapre solo i
    // `risolto`, quindi lo stato zittito non produce nessuno dei due rami.
    $issue->refresh()->ignora($developer);
    scatenaAlert(10);

    expect(postaInviata())->toHaveCount(2)
        ->and(Errore::find($issue->id)->stato)->toBe('ignorato');
});

it('stays quiet when no address is configured', function () {
    // Entrambe le sorgenti svuotate: la casella dedicata **e** il fallback sul
    // Developer. Con una sola delle due il test proverebbe il fallback, non il
    // silenzio.
    config([
        'easylab.errori.alert_email' => null,
        'easylab.piattaforma.developer.email' => null,
    ]);

    scatenaAlert();

    $issue = Errore::where('messaggio', 'guasto da allertare')->sole();

    // Registrare gli errori ha valore anche senza email: la issue c'è, la posta
    // no, e nessuno si è lamentato.
    expect(postaInviata())->toHaveCount(0)
        ->and($issue->occorrenze)->toBe(1)
        ->and($issue->alert_inviato_at)->toBeNull();
});

it('respects the daily cap', function () {
    // Il tetto è **globale e non per-issue**: protegge dalla tempesta di issue
    // *diverse* di un deploy sbagliato, non da un errore che si ripete — quello
    // manda una email sola e basta (test qui sopra). Da cui tre issue distinte.
    config(['easylab.errori.alert_max_giornalieri' => 2]);

    scatenaAlert();
    scatenaSecondoAlert();
    scatenaTerzoAlert();

    expect(postaInviata())->toHaveCount(2);

    // 🔴 E la terza issue **esiste comunque**: il cap strozza la posta, non la
    // registrazione. Il verso opposto — non registrare oltre il tetto —
    // perderebbe proprio gli errori del giorno peggiore.
    expect(Errore::where('messaggio', 'terza issue')->sole()->alert_inviato_at)->toBeNull();
});

it('never puts the stack trace, the input or the user in the alert', function () {
    // 🔴 **Il test che vale per tutto il blocco.** L'email va a una casella, che
    // non ha né permesso né registro di audit: se ci finisse il dettaglio,
    // scavalcherebbe per posta `system.logs.view` — il gate più stretto del
    // progetto, del solo Developer. La regola è la stessa già scritta in Privacy
    // §3 per il digest scadenze.
    $developer = utenteConRuolo('Developer');

    Route::middleware('web')->post('/prova/alert', fn () => throw new RuntimeException('Utente 42 non trovato: marcatore-messaggio-7781'));

    $this->actingAs($developer)->post('/prova/alert', [
        'nota' => 'marcatore-input-9912',
    ]);

    $issue = Errore::where('classe', RuntimeException::class)
        ->where('file', 'tests/Feature/Piattaforma/AlertErroriTest.php')
        ->sole();

    $occorrenza = $issue->occorrenzeErrore()->sole();

    // ⚠️ **Prima si prova che il tracker ha registrato tutto**, o il test
    // sarebbe verde anche con la cattura rotta: «l'email non contiene lo stack
    // trace» è un'affermazione vuota se lo stack trace non esiste da nessuna
    // parte.
    expect($occorrenza->stack_trace)->toContain('#0 ')
        ->and($occorrenza->messaggio)->toContain('marcatore-messaggio-7781')
        ->and($occorrenza->input['nota'])->toBe('marcatore-input-9912')
        ->and($occorrenza->user_id)->toBe($developer->id);

    $testo = testoAlert();

    // Ciò che l'email **deve** dire: dove guardare.
    expect($testo)->toContain(RuntimeException::class)
        ->and($testo)->toContain('tests/Feature/Piattaforma/AlertErroriTest.php:'.$issue->riga);

    // 🔴 E ciò che non deve dire. Un `toContain()` variadico qui sarebbe un
    // tranello (basta un argomento in meno e diventa una chiamata vuota):
    // ciascuna asserzione è scritta da sola, e ciascuna nomina il proprio
    // marcatore.
    expect($testo)->not->toContain('marcatore-messaggio-7781')
        ->and($testo)->not->toContain('Utente 42 non trovato')
        ->and($testo)->not->toContain('marcatore-input-9912')
        ->and($testo)->not->toContain('#0 ')
        ->and($testo)->not->toContain($occorrenza->stack_trace)
        // ⚠️ **L'utente si cerca per email e per nome, mai per id**: un id è
        // `1`, e in questa email c'è già dentro l'URL della scheda — un
        // `not->toContain('1')` sarebbe rosso sempre, o verde per caso.
        ->and($testo)->not->toContain($developer->email)
        ->and($testo)->not->toContain($developer->name);

    // Il link, con **etichetta e href sullo stesso elemento**: un pulsante che
    // perde l'uno o l'altro è un pulsante rotto, e due asserzioni separate non
    // se ne accorgerebbero.
    $html = (string) postaInviata()[0]->getOriginalMessage()->getHtmlBody();

    expect($html)->toMatch('~<a[^>]+href="[^"]*/piattaforma/errori/'.$issue->id.'"[^>]*>\s*Apri la scheda\s*</a>~s');
});

it('never turns a failed alert into a second error', function () {
    // 🔴 Il tracker gira **dentro** il gestore delle eccezioni: un alert che non
    // parte deve costare l'email, non una seconda riga in `errori` — che sarebbe
    // il tracker che si alimenta di sé stesso — né una risposta diversa per chi
    // ha subito l'errore vero.
    Log::spy();

    Event::listen(MessageSending::class, function (): void {
        throw new RuntimeException('SMTP irraggiungibile');
    });

    Route::middleware('web')->get('/prova/alert-rotto', fn () => throw new RuntimeException('guasto con alert rotto'));

    $prima = Errore::count();

    $risposta = $this->get('/prova/alert-rotto');

    // La risposta è quella che sarebbe stata comunque: l'errore vero, non il
    // guasto della posta.
    $risposta->assertStatus(500);

    expect(Errore::count())->toBe($prima + 1)
        ->and(Errore::where('messaggio', 'like', '%SMTP irraggiungibile%')->count())->toBe(0);

    $issue = Errore::where('messaggio', 'guasto con alert rotto')->sole();

    expect($issue->occorrenze)->toBe(1);

    // E il fallimento **non è muto**: finisce nel log dell'ambiente, che è
    // l'unico canale che non dipende dal database — a differenza di
    // `InvitoUtente::failed()`, che scrive anche una riga di audit perché là il
    // fallimento riguarda una persona.
    Log::shouldHaveReceived('error')->withArgs(
        fn (string $messaggio, array $contesto): bool => $messaggio === 'Alert errore non consegnato'
            && $contesto['classe'] === RuntimeException::class
            && $contesto['motivo'] === 'SMTP irraggiungibile',
    );

    // 🔴 **E il timbro sopravvive al fallimento**, che è la proprietà su cui
    // poggia il cap giornaliero: `alert_inviato_at` si scrive **prima**
    // dell'invio, o sotto una coda vera il conteggio non arriverebbe mai in
    // tempo e una tempesta di issue nuove svuoterebbe la casella. Il verso
    // sbagliato costa un alert perso; quello opposto costa una casella
    // intasata. Senza questa riga la scelta era invertibile senza che nessun
    // test se ne accorgesse — verificato.
    expect(Errore::latest('id')->first()->alert_inviato_at)->not->toBeNull();
});

it('never lets the failure handler write into the tracker, where the guard cannot reach', function () {
    // 🔴 **Il test qui sopra da solo NON basta, e l'ha dimostrato una
    // mutazione.** Aggiungendo un `report($e)` dentro `NuovoErrore::failed()`
    // quello resta **verde**: sotto `sync` `failed()` gira *dentro*
    // `CatturaErrori::cattura()`, dove la guardia di rientranza è ancora alzata
    // e si mangia la seconda riga. Ma la guardia è `static` e vive sullo stack
    // di quella chiamata: **con una coda vera `failed()` gira nel worker**, dopo
    // tre tentativi e minuti più tardi, e là non c'è nessuna guardia — la riga
    // in `errori` ci finirebbe davvero, e il tracker si alimenterebbe di sé
    // stesso ogni volta che l'SMTP è giù.
    //
    // Questo test riproduce **quella** condizione: `failed()` invocato fuori dal
    // gestore delle eccezioni, e per la strada vera — è
    // `SendQueuedNotifications` a chiamarlo, e solo se il metodo esiste
    // (`method_exists`). Chiamandolo a mano sulla notifica il test resterebbe
    // verde anche se il metodo si chiamasse `fallito()`.
    Log::spy();

    $prima = Errore::count();

    (new SendQueuedNotifications(
        new AnonymousNotifiable,
        new NuovoErrore(RuntimeException::class, 'app/Support/Prova.php:42', 3, 12),
    ))->failed(new RuntimeException('SMTP irraggiungibile'));

    expect(Errore::count())->toBe($prima);

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $messaggio, array $contesto): bool => $messaggio === 'Alert errore non consegnato',
    );
});

it('hands its retry policy to the job Laravel actually runs', function () {
    // ⚠️ **Asserito sul job, non sulla notifica**, ed è la cicatrice di
    // `InvitoUtente`: `SendQueuedNotifications` legge `tries` e `backoff` per
    // **nome**, quindi una property battezzata `$maxTries` sarebbe ignorata in
    // silenzio e un test sulla notifica resterebbe verde mentre il worker usa i
    // default.
    $job = new InviaAllertaErrore(
        'allerta@easylab.test',
        new NuovoErrore(RuntimeException::class, 'app/Support/Prova.php:42', 3, 12),
    );

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([60, 300]);
});

it('carries the queueing on the job, not on the notification', function () {
    // 🔴 **La notifica NON deve essere accodabile**, e il test lo congela: se lo
    // fosse, l'invio avverrebbe in un **secondo** job e il `try/catch` di
    // `InviaAllertaErrore` non coprirebbe nulla — l'eccezione uscirebbe di
    // nuovo e il worker la riporterebbe, cioè il giro che il job esiste per
    // chiudere.
    //
    // ⚠️ **Limite dichiarato.** `tests/TestCase.php` forza `QUEUE_CONNECTION=sync`,
    // quindi nessuna asserzione di questa suite distingue «accodato» da
    // «inviato subito». Ciò che resta falsificabile è il **contratto**, e va
    // scritto invece di lasciar credere che il comportamento sia coperto.
    //
    // ⚠️ E **nessun `onQueue()`**: la managed queue di Cloud processa la sola
    // coda `default`, quindi un nome diverso sarebbe un alert che nessun worker
    // prende in carico.
    $alert = new NuovoErrore(RuntimeException::class, 'app/Support/Prova.php:42', 3, 12);
    $job = new InviaAllertaErrore('allerta@easylab.test', $alert);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($alert)->not->toBeInstanceOf(ShouldQueue::class)
        ->and($job->queue)->toBeNull()
        ->and($alert->via(new AnonymousNotifiable))->toBe(['mail']);
});

it('never feeds itself when the alert fails where the worker would report it', function () {
    // 🔴 **La garanzia cadeva esattamente in produzione, e la difesa stava sulla
    // porta sbagliata.** Non è `failed()` a scrivere nel tracker: è il worker.
    // `NotificationSender::sendToNotifiable()` cattura, emette
    // `NotificationFailed` e poi **rilancia**; l'eccezione esce dal job e
    // `Worker::runJob()` chiama `report()` — cioè questo stesso aggancio, in un
    // processo dove la guardia di rientranza non è sullo stack.
    //
    // Riprodotto con coda `database` e `queue:work --once`: alert fallito →
    // riga nuova in `errori` → issue nuova → secondo alert.
    //
    // ⚠️ Qui si esegue `handle()` **fuori** da `cattura()`, che è la posizione
    // del worker. Una stesura precedente provava un `send()` scritto sulla
    // notifica: **codice morto**, perché il mittente di Laravel chiama
    // `$manager->driver($canale)->send(...)` e mai `$notifica->send()` — cioè un
    // test che verificava sé stesso.
    Event::listen(MessageSending::class, function () {
        throw new RuntimeException('SMTP irraggiungibile');
    });

    CatturaErrori::cattura(new RuntimeException('il guasto vero'));

    $prima = Errore::count();

    // Il job come lo eseguirebbe il worker. Se lanciasse, il worker riporterebbe.
    (new InviaAllertaErrore(
        'allerta@easylab.test',
        new NuovoErrore(RuntimeException::class, 'app/Support/Prova.php:42', 1, 1),
    ))->handle();

    // ⚠️ Asserito **sul dato**: nessuna issue nuova, e il guasto è finito solo
    // nel log — il canale primario, che su Cloud si legge comunque.
    expect(Errore::count())->toBe($prima);
});

it('falls back to the developer address, which the docs tell people to rely on', function () {
    // 🔴 Il ripiego sull'email del Developer non era coperto da **niente**:
    // toglierlo lasciava la suite verde. Eppure `.env.example` e la Privacy §T8
    // lo dichiarano entrambi in grassetto — «lasciare vuota `ERRORI_ALERT_EMAIL`
    // NON spegne gli alert, li manda al Developer» — cioè è un comportamento su
    // cui il progetto dice di contare per decidere il silenzio.
    config([
        'easylab.errori.alert_email' => null,
        'easylab.piattaforma.developer.email' => 'developer@easylab.test',
    ]);

    CatturaErrori::cattura(new RuntimeException('un guasto qualunque'));

    $inviate = Mail::mailer()->getSymfonyTransport()->messages();

    expect($inviate)->toHaveCount(1)
        ->and($inviate[0]->getOriginalMessage()->getTo()[0]->getAddress())
        ->toBe('developer@easylab.test');
});
