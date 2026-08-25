<?php

use App\Jobs\InviaAllertaErrore;
use App\Notifications\NuovoErrore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\File;
use Spatie\Permission\PermissionRegistrar;

/**
 * 🔴 Meta-test del **buco dichiarato e non chiuso** di ADR-016: la cache dei
 * permessi nei worker di coda in daemon.
 *
 * ## Il buco, letto nel vendor e non supposto
 *
 * `PermissionServiceProvider` registra `PermissionRegistrar` come **singleton**
 * (`PermissionServiceProvider.php:59`) e `clearPermissionsCollection()` gira
 * solo alla prima risoluzione del `Gate` in boot — spatie stessa lo scrive nel
 * proprio docblock: «*this is only intended to be called by the
 * PermissionServiceProvider on boot, so that long-running instances like Octane
 * or Swoole don't keep old data in memory*». Un `queue:work` in daemon **è** una
 * long-running instance: tiene la matrice in memoria per tutta la vita del
 * processo, e una modifica fatta da `/piattaforma/ruoli` gli è invisibile finché
 * non riparte.
 *
 * ## Perché NON si chiude, e perché è una decisione e non una pigrizia
 *
 * ⚠️ **Oggi non ha superficie**: nessun job, nessuna notifica, nessun listener
 * in coda interroga permessi — verificato anche che di listener accodati e di
 * closure accodate questo progetto non ne abbia affatto. È esattamente ciò che il test qui sotto
 * verifica, invece di lasciarlo affermato in un docblock.
 *
 * Chiuderlo comunque — un listener su `JobProcessing` che azzera il registrar a
 * ogni job — sarebbe un meccanismo che difende da un rischio **senza
 * superficie**, cioè la cosa che questo progetto ha per regola esplicita di non
 * fare. E costerebbe: la forma sbagliata di quel listener costa una lettura di
 * cache **per job**, e quella ancora più sbagliata la paga tutta
 * l'applicazione.
 *
 * ## 🔴 Il giorno in cui la superficie nasce, il rimedio NON è `queue:restart`
 *
 * ⚠️ **Attribuzione corretta**: quel rimedio *non* è mai stato scritto in
 * ADR-016 — verificato, quell'ADR non nomina code, worker, registrar né
 * restart. Vive nel docblock di `MatriceRuoli` e nella Roadmap, che a sua volta
 * dice «già in ADR-016». È la stessa deriva di citazione che ADR-029 racconta
 * di sé, e sarebbe stata un'affermazione falsa dentro il blocco che esiste per
 * correggerne una.
 *
 * Comunque sia scritto, **da S6 non regge più**. Due
 * fatti l'hanno superato:
 *
 * 1. **su Laravel Cloud i worker si riavviano da soli a ogni deploy**, e
 *    `Setup Repository e Ambienti.md` §3.2 elenca `queue:restart` fra i comandi
 *    che **non** vanno messi nei deploy command, perché lì è rumore;
 * 2. **la matrice non cambia più al rilascio, cambia a RUNTIME.** Da quando
 *    esiste l'editor `/piattaforma/ruoli`, il gesto che invalida la cache è un
 *    click di un Superadmin dentro una richiesta web. Una «checklist di
 *    rilascio» non scatta mai su un gesto che non è un rilascio.
 *
 * Il rimedio giusto, quando servirà, è quello che spatie stessa indica per i
 * processi lunghi: agganciare `Queue::looping()` (o `JobProcessing`) a
 * **`app(PermissionRegistrar::class)->clearPermissionsCollection()`**.
 *
 * ⚠️ **E non `forgetCachedPermissions()`**, per quanto il nome sembri quello
 * giusto: quel metodo fa in più `$this->cache->forget($this->cacheKey)`, cioè
 * cancella la chiave `spatie.permission.cache` su uno store **condiviso fra
 * tutti i processi**. Chiamato a ogni job, farebbe rileggere la matrice da
 * database a **ogni php-fpm dell'applicazione**, ogni volta che un job passa: il
 * rimedio costerebbe più del guasto, e lo costerebbe a chi il guasto non ce
 * l'ha. `clearPermissionsCollection()` dimentica solo la copia in memoria di
 * questo processo, che è precisamente ciò che è stantio.
 *
 * ## ⚠️ Limite dichiarato
 *
 * Si guarda il sorgente di ciò che gira in coda — le classi `ShouldQueue` e le
 * `Notification`, vedi `eseguitiInCoda()` per il perché della seconda metà —
 * **non la loro chiusura transitiva**: un job che chiami un service che legge
 * permessi passerebbe. Non è un buco nascosto, è dove finisce l'analisi statica
 * onesta, ed è la stessa riga che `ScrittureRbacGuardrailTest` scrive di sé.
 * Ciò che questo file garantisce è che chi scrive `$this->user->can(…)`
 * **dentro un job o dentro una notifica** ci sbatta contro, ed è la forma in cui
 * la superficie nascerebbe la prima volta.
 *
 * 🔗 ADR-016, `App\Support\Rbac\MatriceRuoli` (docblock «La cache dei
 * permessi»), `docs/Roadmap/Roadmap Completa Easy Lab.md`.
 */

/**
 * I **token** che dicono «qui si stanno leggendo permessi o ruoli».
 *
 * Non è la copia di un elenco che viva altrove nel progetto: è l'API pubblica di
 * spatie (`hasPermissionTo`, `hasRole`, …) più i due modi di Laravel di
 * chiedere al `Gate` (`can`/`cannot`, `authorize`/`allows`/`denies`).
 */
const LETTURE_DI_PERMESSO = [
    'can', 'cannot', 'authorize', 'authorizeForUser', 'allows', 'denies',
    'hasPermissionTo', 'hasAnyPermission', 'hasAllPermissions', 'hasDirectPermission',
    'checkPermissionTo', 'hasRole', 'hasAnyRole', 'hasAllRoles', 'hasExactRoles',
    // ⚠️ **E le letture che non chiedono «posso?» ma caricano comunque la
    // matrice.** Sfuggivano tutte: verificato, un `getAllPermissions()` o un
    // `Role::findByName()` dentro una notifica lasciava il guardrail verde.
    // Sono anzi *peggio* delle altre ai fini di questo file — non decidono
    // niente, ma popolano il registrar in memoria, che è esattamente il
    // meccanismo per cui il worker resta indietro.
    'getAllPermissions', 'getPermissionsViaRoles', 'getDirectPermissions', 'getRoleNames',
    'findByName', 'findById', 'findOrCreate',
];

/**
 * Le letture di permesso che questo sorgente contiene.
 *
 * ⚠️ **I commenti si tolgono prima di cercare**, ed è la lezione già pagata da
 * `AuditCoverageGuardrailTest`: il docblock di un job che spiegasse *perché* non
 * interroga permessi verrebbe letto come una violazione, cioè il guardrail
 * punirebbe chi documenta — l'esatto contrario di ciò che il progetto chiede.
 *
 * ⚠️ **Il confine di parola davanti conta**: senza, `scan(` finirebbe fra le
 * violazioni per via del `can` in coda, e `Notification::route(` per via di
 * niente. Con `(?<![\w$>-])` restano solo le chiamate vere e i metodi
 * concatenati (`->can(`), che è quello che si cerca.
 *
 * @return list<string>
 */
function letturePermessiNelSorgente(string $sorgente): array
{
    $codice = collect(token_get_all($sorgente))
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');

    $nomi = implode('|', LETTURE_DI_PERMESSO);

    // `Gate::` a parte: è la facciata, e la si intercetta sul nome perché
    // qualunque cosa le si chieda è una decisione di autorizzazione.
    preg_match_all('/(?:(?<![\w])Gate\s*::\s*\w+|(?<![\w])(?:'.$nomi.')\s*\()/', $codice, $trovate);

    return array_values(array_unique($trovate[0]));
}

/**
 * Tutto ciò che, sotto `app/`, viene **eseguito in coda**.
 *
 * ⚠️ Derivato con `class_implements()` e non cercando `implements ShouldQueue`
 * nel testo: un job che eredita il contratto da una classe base sfuggirebbe a
 * una `grep`, e nascondere un job al guardrail estraendo una superclasse è
 * proprio il modo in cui una rete smette di coprire senza dirlo. È lo stesso
 * motivo per cui `TenantScopeGuardrailTest` guarda `class_uses_recursive()`.
 *
 * ## 🔴 E `ShouldQueue` da solo NON basta — misurato, non previsto
 *
 * La prima stesura di questo file guardava solo le classi `ShouldQueue`, e la
 * prova di mutazione l'ha bocciata: mettendo un `$notifiable->hasRole('Developer')`
 * dentro `NuovoErrore::via()` la suite **restava verde**. `NuovoErrore` non è
 * `ShouldQueue` — e il suo docblock spiega bene perché non deve esserlo: ad
 * accodare è `InviaAllertaErrore`, che avvolge l'invio in un `try/catch` che una
 * seconda accodata renderebbe inutile. Ma `via()` e `toMail()` girano **dove
 * gira chi le manda**, cioè dentro il worker: la notifica è eseguita in coda pur
 * non essendo accodata lei.
 *
 * Era esattamente «una guardia messa nel posto sbagliato», cioè codice morto con
 * un test verde sopra. Le `Notification` entrano quindi nell'insieme per
 * appartenenza — `is_subclass_of(Notification::class)`, non un elenco di nomi —
 * perché in questa applicazione una notifica o è accodata lei, o è mandata da
 * qualcosa che lo è.
 *
 * ⚠️ **Resta il limite dichiarato in testa al file**: la chiusura transitiva
 * completa non si fa. Questo è il primo salto, ed è quello che il progetto ha
 * già percorso davvero.
 *
 * @return list<class-string>
 */
function eseguitiInCoda(): array
{
    return collect(File::allFiles(app_path()))
        ->filter(fn ($f) => $f->getExtension() === 'php')
        ->map(fn ($f) => 'App\\'.str_replace('/', '\\', substr(
            str_replace(app_path().'/', '', $f->getPathname()), 0, -4
        )))
        ->filter(fn (string $c) => class_exists($c))
        ->filter(fn (string $c) => in_array(ShouldQueue::class, class_implements($c) ?: [], true)
            || is_subclass_of($c, Notification::class))
        ->sort()->values()->all();
}

it('finds something that actually runs on the queue', function () {
    // 🔴 La rete della rete: il giorno in cui questa derivazione smettesse di
    // trovare i job — un rename, una cartella nuova, un `class_exists` che non
    // risolve più — il test qui sotto diventerebbe `[] === []`, cioè verde per
    // vuoto, e resterebbe verde per sempre proprio mentre smette di guardare.
    // ⚠️ Si nominano **tutte e due le nature**, e non per simmetria: il job è
    // accodato da sé, la notifica gira in coda perché ce la manda il job. La
    // seconda riga è quella che la prova di mutazione ha dovuto aggiungere —
    // senza, `NuovoErrore::via()` poteva leggere ruoli con la suite verde.
    expect(eseguitiInCoda())->not->toBeEmpty()
        ->toContain(InviaAllertaErrore::class)
        ->toContain(NuovoErrore::class);
});

it('never lets anything running on the queue read a permission', function () {
    $colpevoli = collect(eseguitiInCoda())
        ->mapWithKeys(fn (string $c) => [
            $c => letturePermessiNelSorgente(file_get_contents((new ReflectionClass($c))->getFileName())),
        ])
        ->filter(fn (array $letture) => $letture !== []);

    expect($colpevoli->keys()->all())->toBe([], $colpevoli->isEmpty() ? '' :
        "Qualcosa che gira in coda ha cominciato a leggere permessi.\n\n".
        "Non è vietato: è il momento in cui il buco dichiarato di ADR-016 SMETTE di essere teorico.\n".
        "`PermissionRegistrar` è un singleton, quindi un `queue:work` in daemon tiene la matrice in\n".
        "memoria per tutta la vita del processo: una modifica fatta da /piattaforma/ruoli è invisibile\n".
        "a quel worker finché non riparte, e il worker può restare su per giorni.\n\n".
        "⚠️ Il rimedio NON è `queue:restart` in checklist di rilascio: su Cloud i worker si riavviano\n".
        "già da soli a ogni deploy, e la matrice non cambia al rilascio — cambia a runtime, col click\n".
        "di un Superadmin. Una checklist di rilascio non scatta su un gesto che non è un rilascio.\n\n".
        "Il rimedio è agganciare `Queue::looping()` a:\n".
        "    app(PermissionRegistrar::class)->clearPermissionsCollection()\n".
        "e NON a `forgetCachedPermissions()`, che cancella la chiave di cache CONDIVISA e farebbe\n".
        "rileggere la matrice a ogni processo web a ogni job.\n\n".
        'Trovato in: '.$colpevoli->map(fn (array $l, string $c) => $c.' → '.implode(', ', $l))->implode('; ')
    );
});

it('keeps the premise of the decision true, instead of only asserting it', function () {
    // 🔴 **La decisione di non chiudere il buco poggia su due fatti del vendor**,
    // e un fatto che nessun test guarda è un fatto che può cambiare sotto i
    // piedi in un `composer update`.
    //
    // (a) il registrar è **condiviso**: due risoluzioni danno la stessa istanza,
    //     che è ciò che rende la copia in memoria persistente in un daemon. Se
    //     spatie lo rendesse per-richiesta, il buco sparirebbe e questo file
    //     andrebbe riletto — non lasciato a dire una cosa non più vera;
    expect(app(PermissionRegistrar::class))->toBe(app(PermissionRegistrar::class));

    // (b) il rimedio che il docblock **nomina** deve esistere davvero, e
    //     distinguersi da quello che sembra giusto e non lo è. Un metodo
    //     rinominato in un major renderebbe la nostra istruzione una frase che
    //     non si può eseguire, e ce ne accorgeremmo il giorno in cui serve.
    expect(method_exists(PermissionRegistrar::class, 'clearPermissionsCollection'))->toBeTrue()
        ->and(method_exists(PermissionRegistrar::class, 'forgetCachedPermissions'))->toBeTrue();
});

it('tells a permission read from a word that merely ends in one', function () {
    // 🔴 Il guardrail del guardrail: il rilevatore si prova sui sorgenti
    // sintetici, sulla forma di `ScrittureRbacGuardrailTest`.
    $diretta = '<?php class J { public function handle() { $this->utente->can("strumenti.view"); } }';
    $spatie = '<?php class J { public function handle() { if ($u->hasPermissionTo("x")) {} } }';
    $ruolo = '<?php class J { public function handle() { $u->hasRole("Admin"); } }';
    $facciata = '<?php class J { public function handle() { Gate::forUser($u)->allows("x"); } }';
    $policy = '<?php class J { public function handle() { $this->authorize("view", $s); } }';

    expect(letturePermessiNelSorgente($diretta))->not->toBeEmpty()
        ->and(letturePermessiNelSorgente($spatie))->not->toBeEmpty()
        ->and(letturePermessiNelSorgente($ruolo))->not->toBeEmpty()
        ->and(letturePermessiNelSorgente($facciata))->not->toBeEmpty()
        ->and(letturePermessiNelSorgente($policy))->not->toBeEmpty();

    // ⚠️ E il rovescio, che conta quanto l'altro: un guardrail che grida su ogni
    // riga onesta viene disattivato entro la settimana.
    $parolaCheFinisceCosi = '<?php class J { public function handle() { $this->scan($x); $y->duplicate($z); } }';
    $soloDocumentato = '<?php /** Questo job non chiama mai `can()` né `hasRole()`. */ class J {}';
    $variabileOmonima = '<?php class J { public function handle() { $can = true; $hasRole = false; } }';

    expect(letturePermessiNelSorgente($parolaCheFinisceCosi))->toBeEmpty()
        ->and(letturePermessiNelSorgente($soloDocumentato))->toBeEmpty()
        ->and(letturePermessiNelSorgente($variabileOmonima))->toBeEmpty();
});
