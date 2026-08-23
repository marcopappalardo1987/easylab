<?php

use App\Livewire\Piattaforma\RegistroAudit;
use App\Support\Rbac;
use App\Support\Rbac\MatriceRuoli;
use App\Support\Tenancy\VistaPiattaforma;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\PendingCommand;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * 🔴 `RolesAndPermissionsSeeder` come **reset**, cioè come arma carica (S6 — ADR-016 §7).
 *
 * `RbacSeederTest` prova che i default seminati siano quelli giusti. Questo file
 * prova un'altra cosa, che esiste solo da quando la matrice si modifica a
 * runtime: che rilanciare il seeder su un database **vivo** dica cosa sta per
 * distruggere, lo distrugga davvero, e ne lasci traccia.
 *
 * Il fatto da cui discende tutto è meccanico: `syncPermissions()` **detacha
 * tutto e riattacca** dalla config. Non fonde, non preserva, non chiede. E la
 * sorgente del rischio è un'istruzione **corretta** — `CLAUDE.md` ordina di
 * riseminare dopo ogni modifica a `config/rbac.php` — che dal rilascio
 * dell'editor cancella, eseguita alla lettera, la matrice di runtime. La
 * semantica non si tocca (un reset che non resetta è peggio): si rende
 * impossibile eseguirlo alla cieca.
 *
 * ⚠️ **Nessuno di questi test finge che il riseeding preservi qualcosa.** Un test
 * che affermasse il contrario sarebbe una bugia congelata — la forma di errore
 * che questo progetto ha già pagato tre volte su `garanzie.ricambio.*`
 * (ADR-004 → ADR-027 → ADR-029), dove un divieto mai deciso è sopravvissuto per
 * mesi *perché un test lo difendeva*.
 *
 * 🔗 ADR-016 §7, `App\Support\Rbac\MatriceRuoli`, `/piattaforma/ruoli` (dove il
 * diff si legge **prima** di lanciare il comando).
 */

/** Il reset, lanciato come lo lancerebbe un operatore. */
function riseminaMatrice(): PendingCommand
{
    return test()->artisan('db:seed', ['--class' => RolesAndPermissionsSeeder::class]);
}

/** I permessi di un ruolo **dal pivot**, senza passare dal registrar. */
function permessiNelPivot(string $ruolo): array
{
    return Role::findByName($ruolo, 'web')->permissions()->pluck('name')->sort()->values()->all();
}

// ─── Il prompt che non c'è ───────────────────────────────────────────────────

it('prints the diff without ever asking', function () {
    // 🔴 **La rete contro il difetto che la prima stesura del piano conteneva.**
    // Il piano metteva qui un `confirm()` condizionato al diff non vuoto,
    // motivandolo con «su un database appena creato il diff è vuoto per
    // costruzione, quindi il prompt non nasce nei test». È **falso**: su un
    // database appena creato i ruoli non esistono, `firstOrCreate()` li crea
    // vuoti e il diff non è vuoto — è **massimo**, 219 concessioni. Il prompt
    // sarebbe nato in ognuno degli **91 classi di test** che seminano.
    //
    // ⚠️ E il guasto non sarebbe stato un appendimento su cui si arriva col
    // timeout: `$this->seed()` passa da `$this->artisan()`, che aggiunge
    // `--no-interaction` e **mocka** `OutputStyle` con un partial mock sul solo
    // `askQuestion`. Una domanda inattesa fa fallire il test con «Unexpected
    // question», cioè un errore di Mockery immediato. Chi avesse cercato un
    // appendimento non l'avrebbe trovato e avrebbe concluso che la guardia
    // funzionava.
    //
    // Quel meccanismo è anche ciò che rende questo test una prova e non una
    // dichiarazione: **girare sotto `$this->artisan()` senza fallire è già la
    // metà «non interroga mai»**. La metà «stampa il diff» sono le attese qui
    // sotto.
    $this->seed(RolesAndPermissionsSeeder::class);

    $revocato = collect(Rbac::permissionsForRole('Tenant'))->first();

    MatriceRuoli::concedi('Tecnico', 'strumenti.delete');
    MatriceRuoli::revoca('Tenant', $revocato);

    // ⚠️ **Le attese vanno scritte nell'ordine dell'output e senza
    // sovrapporsi.** Mockery assegna ogni riga stampata alla **prima** attesa
    // che la accetta: due sottostringhe che stanno sulla stessa riga si rubano
    // il posto a vicenda, e quella rimasta senza riga fallisce pur essendo stata
    // stampata. Da qui le righe intere invece dei frammenti — che è anche il
    // modo per congelare la **forma** del diff e non solo i nomi che contiene.
    //
    // Il verso è quello del **gesto del reset**, non quello del confronto: `-` è
    // ciò che il reset porta via, `+` ciò che rimette. Chi legge un diff prima di
    // un comando distruttivo non deve indovinare da che parte si guarda, quindi
    // la parola sta accanto al segno. L'ordine dei ruoli è quello del catalogo,
    // cioè `Tenant` prima di `Tecnico`.
    riseminaMatrice()
        // La frase che spiega **perché** quelle righe compaiono: un elenco di
        // permessi senza la ragione accanto si legge come un resoconto di
        // successo.
        ->expectsOutputToContain('Le personalizzazioni fatte da /piattaforma/ruoli sono state cancellate')
        ->expectsOutputToContain('Tenant — 1 concessi dal reset, 0 revocati dal reset')
        ->expectsOutputToContain('+ '.$revocato)
        ->expectsOutputToContain('Tecnico — 0 concessi dal reset, 1 revocati dal reset')
        ->expectsOutputToContain('- strumenti.delete')
        ->assertExitCode(0);
});

it('never asks a question, on any path', function () {
    // La rete durevole accanto a quella meccanica. Il test qui sopra prova che
    // *questa* esecuzione non interroga; questo prova che **non c'è nessuna
    // strada** che interroghi — compreso un ramo raggiunto da uno stato che
    // nessun test costruisce.
    //
    // ⚠️ I commenti si tolgono prima di cercare: il docblock del seeder nomina
    // `confirm()` proprio per spiegare perché non c'è, e un guardrail che legge
    // il testo invece del codice punisce chi documenta. È la correzione che
    // `AuditCoverageGuardrailTest` e `BypassNudiGuardrailTest` hanno già dovuto
    // fare su sé stessi.
    $codice = collect(token_get_all(file_get_contents(database_path('seeders/RolesAndPermissionsSeeder.php'))))
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');

    foreach (['confirm(', 'ask(', 'askWithCompletion(', 'choice(', 'anticipate(', 'secret(', 'confirmToProceed('] as $domanda) {
        expect($codice)->not->toContain($domanda);
    }

    // La rete che tiene onesto il ciclo qui sopra: se un giorno il file venisse
    // rinominato o svuotato, cercare stringhe assenti in un sorgente vuoto
    // resterebbe verde. È la stessa forma dell'allowlist del guardrail di
    // `sbloccaPerStripe()` — «verifica che il file in allowlist la contenga
    // davvero».
    expect($codice)->toContain('syncPermissions(')
        ->and($codice)->toContain('warn(');
});

// ─── L'arma carica, documentata ──────────────────────────────────────────────

it('restores the config defaults, destroying every runtime customization', function () {
    // 🔴 **Il test che documenta l'arma carica.** Non finge che il riseeding
    // fonda le due sorgenti: `syncPermissions()` detacha tutto e riattacca dalla
    // config, e scrivere il contrario congelerebbe una bugia.
    //
    // Le due direzioni servono entrambe, e la seconda è quella che sorprende: la
    // revoca fatta dalla UI viene **rimessa**. Chi ha tolto `strumenti.view` al
    // Tenant per contratto se lo ritrova concesso, e non è un caso limite — è
    // metà dei gesti che questa pagina compie.
    $this->seed(RolesAndPermissionsSeeder::class);

    $revocato = collect(Rbac::permissionsForRole('Tenant'))->first();

    MatriceRuoli::concedi('Tecnico', 'strumenti.delete');
    MatriceRuoli::revoca('Tenant', $revocato);

    expect(permessiNelPivot('Tecnico'))->toContain('strumenti.delete')
        ->and(permessiNelPivot('Tenant'))->not->toContain($revocato);

    riseminaMatrice()->run();

    // Ogni ruolo torna **esattamente** ai default, in entrambe le direzioni.
    foreach (Rbac::roleNames() as $ruolo) {
        expect(permessiNelPivot($ruolo))->toEqualCanonicalizing(
            Rbac::permissionsForRole($ruolo),
            "Il ruolo «{$ruolo}» non è tornato ai default."
        );
    }

    expect(permessiNelPivot('Tecnico'))->not->toContain('strumenti.delete')
        ->and(permessiNelPivot('Tenant'))->toContain($revocato);
});

// ─── La traccia nel registro ─────────────────────────────────────────────────

it('writes one audit row when a reset actually changes something', function () {
    // 🔴 Chiude l'**unico punto cieco** della feature. La via di recupero da una
    // matrice rotta è `db:seed --class=…` seguito da `permission:cache-reset`:
    // senza questa riga, il reset di emergenza sarebbe il solo gesto di questa
    // feature invisibile nel registro — cioè si potrebbe riportare indietro
    // chi-può-cosa per tutti i clienti insieme senza che nessuno ricostruisca chi
    // l'ha deciso, che è precisamente ciò che ADR-016 chiede di impedire.
    //
    // ⚠️ Si legge **attraverso `VistaPiattaforma::audit()`** e non da
    // `Activity::query()`: il pavimento `where('log_name', AuditLog::NAME)` sta
    // dentro la porta, quindi una riga scritta sul canale `default` esisterebbe a
    // database e non comparirebbe mai nel registro. Solo così la mutazione «tolto
    // `AuditLog::NAME`» diventa rossa.
    //
    // ⚠️ E `Activity` si svuota prima del gesto: le factory scrivono audit da sé.
    $this->seed(RolesAndPermissionsSeeder::class);

    // ⚠️ **L'utente si autentica PRIMA del reset**, e non è un dettaglio di
    // setup: è ciò che rende falsificabile l'assert sul causer più sotto. Con il
    // seeder lanciato da un processo senza sessione, `causer_id` sarebbe NULL
    // anche togliendo `causedByAnonymous()`, e il test proverebbe una proprietà
    // dell'ambiente invece che del codice.
    $superadmin = utenteConRuolo('Superadmin');
    $this->actingAs($superadmin);

    MatriceRuoli::concedi('Tecnico', 'strumenti.delete');

    Activity::query()->delete();

    riseminaMatrice()->run();

    $righe = VistaPiattaforma::audit()->get();

    expect($righe)->toHaveCount(1);

    $riga = $righe->first();

    expect($riga->description)->toBe('Matrice ruoli riportata ai default')
        // 🔴 **Senza causer, anche con un utente autenticato in sessione.**
        // `ActivityLogger` risolve il causer dalla guard alla prima chiamata del
        // builder, quindi l'omissione non basta: il gesto è del **comando**, e
        // attribuirlo a chi per caso era loggato sarebbe peggio che non
        // attribuirlo. La riga nasce così nella scorciatoia che il registro ha
        // già — «solo azioni senza utente autenticato», dove vivono console,
        // coda e webhook.
        ->and($riga->causer_id)->toBeNull()
        // `event` NULL: è un **atto**, non il diff delle colonne di un model.
        ->and($riga->event)->toBeNull()
        // Il diff nelle `properties`, in **stringhe piatte**: `DettaglioAttivita`
        // rende gli array come JSON pretty-printed, cioè «JSON grezzo al posto
        // dell'unica informazione che l'espansione esiste per dare».
        ->and($riga->properties->get('ruoli'))->toBe('Tecnico')
        ->and($riga->properties->get('revocati dal reset'))->toBe('Tecnico: strumenti.delete')
        ->and($riga->properties->get('concessi dal reset'))->toBe('nessuno');

    // E la riga è **raggiungibile** dalle due scorciatoie del registro che la
    // riguardano: gli atti e le azioni senza utente. Asserire su `causer_id` e
    // `event` dice com'è fatta; questo dice a cosa serve.
    // Lo stesso utente di prima e non uno nuovo: le factory scrivono audit da
    // sé, e un `User::factory()` qui aggiungerebbe righe **dopo** lo svuotamento
    // — cioè falserebbe i conteggi che seguono.
    $ids = fn (callable $filtra) => $filtra(
        Livewire::actingAs($superadmin)->test(RegistroAudit::class)
    )->viewData('righe')->pluck('id')->all();

    expect($ids(fn ($c) => $c->set('azione', RegistroAudit::ATTI)))->toBe([$riga->id])
        ->and($ids(fn ($c) => $c->set('senzaUtente', true)))->toBe([$riga->id]);
});

it('writes no audit row when the reset changes nothing', function () {
    // 🔴 **Il rovescio, e senza di lui la riga di sopra sarebbe un disastro.** Un
    // seeder che scrive una riga a ogni esecuzione inquinerebbe `activity_log` in
    // **ogni test della suite** — sono 85 i file che seminano — e farebbe cadere
    // gli `assertSee` e i conteggi di chi legge il registro.
    //
    // I due casi che devono restare muti sono diversi e servono entrambi.
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->actingAs(utenteConRuolo('Superadmin'));

    Activity::query()->delete();

    // Il reset di una matrice che è già ai default: non c'è niente da riportare
    // indietro, quindi non c'è niente da raccontare. Il gemello — il **bootstrap**
    // su un database vuoto, che è il caso da cui dipende la suite intera — è il
    // test qui sotto.
    riseminaMatrice()->run();

    expect(VistaPiattaforma::audit()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

it('stays silent on a bootstrap, where there was nothing to destroy', function () {
    // 🔴 **Il caso che decide la sorte dell'intera suite**, ed è quello che la
    // formulazione ovvia — «traccia quando il diff non è vuoto» — sbaglia in
    // pieno. Su un database appena creato i ruoli **non esistono**: il diff non è
    // vuoto, è *massimo* (219 concessioni su sei ruoli). Preso alla lettera, quel
    // criterio scriverebbe una riga di audit e stamperebbe 219 righe a **ogni**
    // `RefreshDatabase` della suite.
    //
    // La condizione giusta non è «il diff è non vuoto» ma «**c'era qualcosa da
    // distruggere**»: contano solo i ruoli che esistevano *prima* di questa
    // esecuzione. Il bootstrap resta muto, com'è sempre stato.
    expect(Role::count())->toBe(0);

    riseminaMatrice()
        ->doesntExpectOutputToContain('personalizzazioni')
        ->assertExitCode(0);

    // ⚠️ Si asserisce sul conteggio **grezzo** e non attraverso
    // `VistaPiattaforma::audit()`, che è la lettura preferita altrove, per due
    // ragioni: qui non c'è nessun utente da autenticare (i ruoli non esistevano
    // fino a un attimo fa, quindi non esiste un Superadmin a cui la porta possa
    // rispondere), e soprattutto il conteggio grezzo è **più severo** — prende
    // anche una riga scritta sul canale `default`, che sarebbe invisibile nel
    // registro ma non nei conteggi degli altre 90 classi, cioè il modo peggiore di
    // rompere una suite: rosso lontano dalla causa.
    expect(Role::count())->toBe(count(Rbac::roleNames()))
        ->and(Activity::query()->count())->toBe(0);
});

it('traces the diff while the customization is still there, not after wiping it', function () {
    // 🔴 «Stampa ciò che **sta per** portare via» è una promessa che CLAUDE.md,
    // ADR-016 e la pagina fanno tutti e tre. La prima stesura la mancava: il
    // diff era *calcolato* prima, ma stampato e tracciato **dopo** l'intero
    // ciclo di sincronizzazione — quindi un guasto a metà strada avrebbe
    // lasciato metà matrice riportata ai default e **zero righe di audit**,
    // cioè il danno fatto e nessuna traccia di cosa fosse stato portato via.
    //
    // ⚠️ Non si prova simulando un guasto — un'eccezione forzata cade in un
    // punto che dipende da quali eventi spatie emette, e la prima stesura di
    // questo test misurava così una cosa diversa da quella che credeva.
    // Si prova sull'**ordine**: nell'istante in cui la riga di audit nasce, la
    // personalizzazione dev'essere ancora al suo posto. Se lo fosse dopo, il
    // reset avrebbe già cancellato ciò che la riga dice di aver cancellato.
    $this->seed(RolesAndPermissionsSeeder::class);

    MatriceRuoli::concedi('Tecnico', 'strumenti.delete');
    Activity::query()->delete();

    $ancoraPersonalizzato = null;

    Activity::created(function () use (&$ancoraPersonalizzato) {
        $ancoraPersonalizzato = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.name', 'Tecnico')
            ->where('permissions.name', 'strumenti.delete')
            ->exists();
    });

    $this->artisan('db:seed', ['--class' => RolesAndPermissionsSeeder::class]);

    Activity::flushEventListeners();

    expect($ancoraPersonalizzato)->toBeTrue()
        // E il reset ha comunque fatto il proprio lavoro, o si starebbe provando
        // che la traccia precede un gesto che non è avvenuto.
        ->and(permessiDiRuolo('Tecnico'))->not->toContain('strumenti.delete');
});
