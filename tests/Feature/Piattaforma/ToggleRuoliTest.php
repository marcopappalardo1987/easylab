<?php

use App\Livewire\Piattaforma\EditorRuoli;
use App\Livewire\Piattaforma\RegistroAudit;
use App\Models\User;
use App\Support\Audit\SoggettiAudit;
use App\Support\AuditLog;
use App\Support\Rbac;
use App\Support\Tenancy\VistaPiattaforma;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 🔴 Il **toggle**: la prima cosa di questa feature che scrive (S6 — ADR-016).
 *
 * I due file che precedono hanno diviso il lavoro in modo netto — `MatriceRuoliTest`
 * prova la **regola** senza pagina, `GrigliaRuoliTest` prova la **resa** senza
 * scrittura — e questo prova il terzo pezzo: che la pagina e la regola siano
 * attaccate nel verso giusto. Ciò che va provato qui non è che la guardia
 * funzioni (lo prova l'altro file, sul metodo di dominio), ma che **la pagina
 * non offra una scorciatoia intorno alla guardia**, e che ciò che offre finisca
 * davvero nel registro leggibile.
 *
 * ⚠️ **Sulle asserzioni di questo file vale una regola imparata mutando**: il
 * verdetto HTTP di un componente Livewire **non basta**. `render()` gata da sé,
 * quindi una risposta 403 arriva anche quando l'azione ha già scritto — misurato
 * il 23 Ago 2026 togliendo `Gate::authorize()` da `commuta()`: `assertForbidden()`
 * restava verde e il pivot cambiava lo stesso. Dove c'è un rifiuto da provare,
 * si asserisce **sul pivot**.
 *
 * 🔗 `App\Support\Rbac\MatriceRuoli` (la regola), ADR-016, ADR-018.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superadmin = utenteConRuolo('Superadmin');

    // Il componente montato come lo monterebbe la pagina. `Livewire::test()` non
    // passa dai middleware, ed è deliberato: il `can:` di rotta ha il suo test
    // in `AccessoEditorRuoliTest`, qui si guarda ciò che regge **senza** di lui.
    $this->editor = fn () => Livewire::actingAs($this->superadmin)->test(EditorRuoli::class);
});

/** Se il ruolo ha il permesso **nel pivot**, senza passare dal registrar. */
function nelPivot(string $ruolo, string $permesso): bool
{
    return Role::findByName($ruolo, 'web')->permissions()->where('name', $permesso)->exists();
}

/**
 * Spegne una cella **fuori dalla UI**, che è un'operazione di codice.
 *
 * ⚠️ Serve più spesso di quanto sembri, e la ragione è un fatto della matrice
 * seminata che vale la pena scrivere: `Admin` e `Superadmin` possiedono **ogni
 * permesso non bloccato del catalogo**. Ne segue che sulle loro colonne non
 * esiste, oggi, una cella spenta da accendere — e il solo gesto che questa
 * pagina compie senza chiedere conferma (la concessione a un ruolo che ha già il
 * 2FA obbligatorio) non è raggiungibile finché qualcuno non toglie prima
 * qualcosa. Il caso va quindi **costruito**, o il test sarebbe verde per assenza
 * di caso.
 */
function spegniFuoriDallaUi(string $ruolo, string $permesso): void
{
    Role::findByName($ruolo, 'web')->revokePermissionTo($permesso);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * Il sorgente di un file **senza commenti**, per i guardrail che leggono codice.
 *
 * Il docblock è il posto in cui questo progetto scrive perché una cosa NON si
 * fa, quindi cercare una stringa nel testo grezzo rende rosso proprio chi ha
 * spiegato. Si toglie anche `T_WHITESPACE`: senza, una chiamata mandata a capo
 * dal formatter sfugge al confronto — la lezione già pagata in
 * `BypassNudiGuardrailTest`.
 */
function codiceSenzaCommenti(string $percorso): string
{
    return collect(token_get_all(file_get_contents($percorso)))
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');
}

// ─── I rifiuti, dalla porta da cui la pagina scrive ──────────────────────────

it('the toggle of a locked cell fails even when the request is hand-made', function () {
    // 🔴 La risposta al «cosa succede se qualcuno manda la richiesta a mano».
    // La griglia non rende un bottone su una riga bloccata, ma quella è
    // **presentazione**: `Livewire::test()` non passa dai middleware e un `@if`
    // in Blade si toglie in un secondo. Qui si chiama l'azione direttamente,
    // che è ciò che un `fetch` verso `/livewire/update` può fare.
    //
    // ⚠️ Si verifica **sul pivot** e non sul valore di ritorno: la risposta di
    // un componente Livewire dice ciò che ha fatto `render()`, non ciò che ha
    // fatto l'azione — e le due cose divergono proprio nel caso peggiore.
    expect(Rbac::isLocked('roles.manage'))->toBeTrue();

    Activity::query()->delete();

    ($this->editor)()
        ->call('commuta', 'Admin', 'roles.manage')
        // La chiave dell'errore dice **quale** guardia ha parlato, ed è ciò che
        // chi legge il rosso deve trovare per primo.
        ->assertHasErrors('permesso');

    expect(nelPivot('Admin', 'roles.manage'))->toBeFalse()
        // La guardia rifiuta **prima** di toccare il database: niente riga nel
        // pivot e niente riga nel registro.
        ->and(Activity::query()->count())->toBe(0);
});

it("the toggle of the protected role's column fails even when the request is hand-made", function () {
    // La gemella sul ruolo. Il set bloccato non copre questo caso — protegge
    // sette permessi, la colonna del Developer ne ha cinquantaquattro — e
    // `Developer => ['all' => true]` è la chiave di riserva della piattaforma:
    // non esiste alcun `Gate::before` da super-admin, quindi il Developer
    // dipende davvero dalla matrice a database.
    expect(Rbac::isLocked('strumenti.view'))->toBeFalse()
        ->and(Rbac::ruoliProtetti())->toBe(['Developer']);

    Activity::query()->delete();

    ($this->editor)()
        ->call('commuta', 'Developer', 'strumenti.view')
        ->assertHasErrors('ruolo');

    expect(nelPivot('Developer', 'strumenti.view'))->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);
});

it('gates the toggle itself, and not only the render', function () {
    // 🔴 **Il motivo per cui esistono due guardie**, e il test che lo dimostra.
    // Il `can:` di rotta è provato altrove (`keeps the permission on a real
    // Livewire update`), e resterebbe verde anche senza questa riga: qui si
    // monta il componente **senza middleware**, cioè per la strada che un domani
    // resterebbe sola se la rotta cambiasse gruppo, o se l'azione guadagnasse
    // `skipRender()` — nel qual caso nemmeno il gate di `render()` girerebbe.
    //
    // ⚠️ **Il verdetto HTTP non prova niente qui, e va detto perché è la
    // trappola di questo file.** Misurato il 23 Ago 2026 togliendo
    // `Gate::authorize()` da `commuta()`: `assertForbidden()` restava **verde**,
    // perché a rispondere 403 è il `render()` che gira *dopo* l'azione — e
    // intanto il permesso era stato scritto. L'unica asserzione che morde è
    // quella sul pivot.
    $componente = ($this->editor)();

    // Il permesso sparisce mentre la pagina è aperta: lo snapshot resta valido,
    // ed è esattamente il caso che la guardia in azione deve fermare.
    auth()->logout();
    $this->actingAs(utenteConRuolo('Admin'));

    $componente->call('commuta', 'Tenant', 'spostamenti.view')->assertForbidden();

    expect(nelPivot('Tenant', 'spostamenti.view'))->toBeFalse();
});

// ─── I controlli, dove ci sono e dove no ─────────────────────────────────────

it('offers a control exactly where the matrix can be changed', function () {
    // La metà che nel Blocco 3 «non poteva fallire» — allora *nessuna* cella
    // aveva un `wire:click`, quindi «le bloccate non ne hanno» era vero per
    // costruzione. Da adesso è viva, e questo test è il suo complemento
    // obbligatorio: senza la parte positiva, una griglia che perdesse **tutti**
    // i bottoni resterebbe verde.
    //
    // ⚠️ Si asserisce su `wire:click` e su `<button`, cioè su **ciò che l'utente
    // riceve davvero**, e non su un marcatore `data-*` inventato per il test: un
    // attributo che nessuno legge tranne l'asserzione misura una copia privata e
    // lascia libera la cosa vera. È il difetto trovato nel Blocco 3.
    //
    // Il caso «non seminato» va costruito, come sempre: dopo il seeder i 54
    // permessi ci sono tutti.
    DB::table('permissions')->where('name', 'spostamenti.view')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $html = $this->actingAs($this->superadmin)->get(route('piattaforma.ruoli'))->assertOk()->getContent();

    $riga = fn (string $permesso) => preg_match(
        '/<tr wire:key="permesso-'.preg_quote($permesso, '/').'".*?<\/tr>/s', $html, $m
    ) === 1 ? $m[0] : '';

    // (a) Una cella libera su un ruolo libero: bottone e `wire:click`.
    $libera = $riga('strumenti.view');

    expect($libera)->not->toBe('')
        ->and($libera)->toContain('wire:click="chiedi(\'Tenant\', \'strumenti.view\')"')
        ->and($libera)->toContain('<button');

    // (b) La colonna del ruolo protetto, sulla **stessa riga**: nessun controllo.
    //     Il confronto sta nello stesso corpo perché è ciò che rende falsificabile
    //     la (a) — una riga senza bottoni affatto passerebbe la sola (b).
    expect($libera)->not->toContain("chiedi('Developer'");

    // (c) La riga bloccata: nessun controllo su nessuna delle sei colonne.
    foreach (Rbac::locked() as $bloccato) {
        expect($riga($bloccato))->not->toBe('', "La riga di «{$bloccato}» non è stata resa.")
            ->and($riga($bloccato))->not->toContain('wire:click');
    }

    // (d) La riga «da seminare»: la cella non è accendibile, perché
    //     `Permission::findByName()` lancerebbe. Un bottone qui prometterebbe un
    //     gesto che il dominio rifiuta.
    $daSeminare = $riga('spostamenti.view');

    expect($daSeminare)->toContain('data-da-seminare="1"')
        ->and($daSeminare)->not->toContain('wire:click');

    // (e) E l'orfano, che vive fuori dalla griglia: nemmeno lui.
    expect($html)->not->toContain('chiedi(\'Tenant\', \'letture_contaore');
});

it('says on the page which roles have no mandatory second factor', function () {
    // 🔴 **In pagina, non solo nella modale.** Il secondo fattore si gata per
    // nome di ruolo (`EnsureTwoFactorIsEnabled` legge
    // `Rbac::twoFactorRequiredRoles()`), quindi concedere un potere forte a un
    // ruolo fuori da quell'elenco lo consegna a chi entra con la sola password.
    // È la direzione in cui questa pagina fa danno davvero ed è la meno
    // intuitiva: dirlo solo nella modale significa dirlo quando la decisione è
    // già stata presa.
    $html = $this->actingAs($this->superadmin)->get(route('piattaforma.ruoli'))->assertOk()->getContent();

    // ⚠️ Si asserisce dentro **blocchi circoscritti**, non con un `assertSee`
    // sulla pagina: «Tenant» compare 54 volte in questa griglia, e
    // un'asserzione che non può fallire occupa il posto di quella vera.
    preg_match('/<p[^>]*data-avviso-2fa="pagina"[^>]*>.*?<\/p>/s', $html, $avviso);

    expect($avviso[0] ?? '')->not->toBe('');

    // 🔴 **I due elenchi vanno separati, o l'asserzione non può fallire.** La
    // prima stesura chiedeva solo che i nomi comparissero *da qualche parte* nel
    // paragrafo: verificato, **scambiando i due elenchi** la pagina diceva
    // l'esatto contrario del vero — «oggi lo richiedono Tenant, Tecnico…»,
    // «concedere a Developer, Superadmin, Admin lo dà a chi entra con la sola
    // password» — e la suite restava tutta verde. Su una schermata che cambia
    // chi-può-cosa per tutti i clienti insieme, questa è *la* frase che
    // l'operatore legge prima di decidere.
    $dopo = fn (string $ago) => mb_substr($avviso[0], mb_strpos($avviso[0], $ago) + mb_strlen($ago));

    $richiedono = mb_substr($dopo('oggi lo richiedono'), 0, mb_strpos($dopo('oggi lo richiedono'), '.'));
    preg_match_all('/<strong>(.*?)<\/strong>/s', $avviso[0], $forti);
    $senza = end($forti[1]);

    // ⚠️ Niente messaggio come secondo argomento: `toContain()` di Pest è
    // **variadico**, quindi una frase esplicativa diventerebbe un secondo ago da
    // cercare — un'asserzione che asserisce se stessa. Il nome del ruolo nel
    // ciclo basta a individuare il rosso.
    foreach (Rbac::twoFactorRequiredRoles() as $ruolo) {
        expect($richiedono)->toContain($ruolo)
            ->and($senza)->not->toContain($ruolo);
    }

    foreach (array_diff(Rbac::roleNames(), Rbac::twoFactorRequiredRoles()) as $ruolo) {
        expect($senza)->toContain($ruolo)
            ->and($richiedono)->not->toContain($ruolo);
    }

    // E la stessa cosa detta **sopra la colonna**, dove si sta per cliccare.
    $intestazione = function (string $ruolo) use ($html): string {
        preg_match('/<th[^>]*data-ruolo="'.preg_quote($ruolo, '/').'"[^>]*>.*?<\/th>/s', $html, $m);

        return $m[0] ?? '';
    };

    foreach (Rbac::roleNames() as $ruolo) {
        $senza = ! in_array($ruolo, Rbac::twoFactorRequiredRoles(), true);

        expect($intestazione($ruolo))->not->toBe('');

        // La rete che rende falsificabile la prima metà: la nota c'è **solo**
        // dove serve. Un marcatore appiccicato a tutte le colonne passerebbe
        // un'asserzione a senso unico.
        // ⚠️ **E sul testo visibile, non solo sul marcatore.** `data-senza-2fa`
        // esiste unicamente per questo test — nessun CSS e nessun JS lo legge —
        // quindi asserire solo su di lui misura una copia privata: verificato,
        // svuotando lo `<span>` e lasciando l'attributo, la colonna perdeva
        // l'avvertenza e la suite restava verde. È lo stesso difetto trovato
        // sulla griglia del blocco precedente, ricomparso un livello più in là.
        $senza
            ? expect($intestazione($ruolo))->toContain('data-senza-2fa="1"')
                ->and($intestazione($ruolo))->toContain('senza 2FA')
            : expect($intestazione($ruolo))->not->toContain('data-senza-2fa')
                ->and($intestazione($ruolo))->not->toContain('senza 2FA');
    }

    expect(substr_count($html, 'data-senza-2fa="1"'))
        ->toBe(count(Rbac::roleNames()) - count(Rbac::twoFactorRequiredRoles()));
});

// ─── La conferma ─────────────────────────────────────────────────────────────

it('asks before revoking, and says how many people it affects', function () {
    // Concedere a un ruolo che ha già il 2FA obbligatorio non chiede; revocare
    // chiede **sempre**. Non perché una singola revoca sia grave — è il gesto
    // più facile da annullare — ma perché nessuna singola revoca *sembra* grave:
    // quattordici click e il Tenant non fa più niente, per tutti gli Enti
    // insieme. Il numero di persone col ruolo è ciò che trasforma la conferma in
    // una decisione invece che in un ostacolo.
    User::factory()->count(3)->create()->each(fn (User $u) => $u->assignRole('Tenant'));
    // Un utente di un altro ruolo: senza, un conteggio che ignorasse il filtro
    // (`User::count()`) resterebbe verde.
    User::factory()->create()->assignRole('Tecnico');

    Activity::query()->delete();

    $componente = ($this->editor)()->call('chiedi', 'Tenant', 'strumenti.view');

    // ⚠️ **Non ha scritto niente**, ed è la metà che conta: una conferma che
    // arriva dopo la scrittura è un avviso, non una conferma.
    expect(nelPivot('Tenant', 'strumenti.view'))->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);

    $componente->assertSet('ruoloInConferma', 'Tenant')
        ->assertSet('permessoInConferma', 'strumenti.view');

    $html = $componente->html();

    preg_match('/<div data-conferma="([^"]+)".*?<\/div>/s', $html, $modale);

    expect($modale[1] ?? '')->toBe('revoca')
        // ⚠️ **Il numero dice «quanti hanno il ruolo», non «quanti perdono
        // l'accesso»**: un utente con due ruoli conserva il permesso dall'altro.
        // Oggi l'app assegna un ruolo solo, quindi i due numeri coincidono — ma
        // è un fatto di come sono i dati adesso, non una proprietà del sistema,
        // e la frase in pagina dev'essere quella vera.
        ->and($modale[0])->toContain('data-utenti-col-ruolo="3"')
        ->and($modale[0])->toContain('Non è detto che tutti perdano l\'accesso');

    // E il gesto si compie solo alla conferma.
    $componente->call('procedi');

    expect(nelPivot('Tenant', 'strumenti.view'))->toBeFalse()
        ->and(VistaPiattaforma::audit()->count())->toBe(1);
});

it('asks before granting to a role that has no mandatory second factor', function () {
    // 🔴 **È il lato che l'istinto sbaglia.** «Concedere è additivo e
    // reversibile» vale finché si guardano i permessi; ma `EnsureTwoFactorIsEnabled`
    // gata il secondo fattore **per nome di ruolo**, quindi dare `semaforo.force`
    // al Tenant lo dà a chi entra con la sola password, con un click e per tutti
    // i clienti insieme.
    expect(Rbac::twoFactorRequiredRoles())->not->toContain('Tenant')
        ->and(nelPivot('Tenant', 'semaforo.force'))->toBeFalse();

    $componente = ($this->editor)()->call('chiedi', 'Tenant', 'semaforo.force');

    expect(nelPivot('Tenant', 'semaforo.force'))->toBeFalse();

    preg_match('/<div data-conferma="([^"]+)".*?<\/div>/s', $componente->html(), $modale);

    expect($modale[1] ?? '')->toBe('concessione')
        // La modale nomina il **meccanismo**, non dà un avviso generico: chi
        // legge deve poter verificare che sia vero.
        ->and($modale[0])->toContain('data-avviso-2fa="modale"')
        ->and($modale[0])->toContain('sola password');

    $componente->call('procedi');

    expect(nelPivot('Tenant', 'semaforo.force'))->toBeTrue();
});

it('does not ask when granting to a role that already requires a second factor', function () {
    // Il rovescio, e senza di lui la conferma non è una decisione ma un
    // ostacolo: se ogni gesto chiedesse, chiedere smetterebbe di significare
    // qualcosa.
    //
    // ⚠️ **Il caso va costruito.** `Admin` possiede oggi ogni permesso non
    // bloccato del catalogo, quindi sulla sua colonna non esiste una cella
    // spenta da accendere: senza questa riga il test sarebbe verde per assenza
    // di caso. Si spegne **fuori dalla UI**, cioè in codice — la distinzione che
    // ADR-027 ha già usato.
    spegniFuoriDallaUi('Admin', 'audit.view');

    expect(Rbac::twoFactorRequiredRoles())->toContain('Admin')
        ->and(nelPivot('Admin', 'audit.view'))->toBeFalse();

    $componente = ($this->editor)()->call('chiedi', 'Admin', 'audit.view');

    // Nessuna modale, e il gesto è già fatto.
    expect($componente->viewData('conferma'))->toBeNull()
        ->and(nelPivot('Admin', 'audit.view'))->toBeTrue();
});

it('writes nothing when the confirmation is dismissed', function () {
    // Annullare deve **annullare**. È il ramo che non si prova mai e che rende
    // la conferma una promessa invece di un rituale.
    Activity::query()->delete();

    ($this->editor)()
        ->call('chiedi', 'Tenant', 'strumenti.view')
        ->call('annulla')
        ->assertSet('ruoloInConferma', '')
        ->assertSet('permessoInConferma', '');

    expect(nelPivot('Tenant', 'strumenti.view'))->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);
});

it('never opens a confirmation for a gesture the domain would refuse', function () {
    // ⚠️ Le due property della conferma sono **pubbliche**, cioè scrivibili dal
    // browser, e passano da una whitelist del catalogo.
    //
    // 🔴 **Scarto verificato rispetto a come questo test era stato scritto la
    // prima volta.** Diceva di provare che un valore arbitrario non rompe la
    // pagina, perché «arriverebbe a `User::role()`, che lancia
    // `RoleDoesNotExist` durante il render». È **falso**: `User::role()` si
    // chiama solo sul ramo della revoca, cioè quando la matrice — che viene dal
    // database — dice che quel ruolo ha quel permesso, e un ruolo che compare
    // nella matrice esiste per costruzione. Misurato togliendo la whitelist: il
    // test restava **verde**. Un test che non può fallire occupa il posto di
    // quello vero.
    //
    // Ciò che la whitelist difende davvero è un'altra cosa, e questo è il caso
    // che la mostra: l'**orfano**. Un permesso uscito dal catalogo e rimasto
    // attaccato a un ruolo (i `letture_contaore.*`, fino all'8 Ago 2026) è nella
    // matrice a database, quindi senza whitelist la modale si aprirebbe dicendo
    // «Revocare «letture_contaore.view» a «Tenant»? N utenti hanno il ruolo» — e
    // il pulsante di conferma produrrebbe un errore di validazione, perché
    // `MatriceRuoli` rifiuta di riassegnare le righe orfane. Una conferma che
    // promette un gesto impossibile è peggio di nessuna conferma.
    $orfano = Permission::create(['name' => 'letture_contaore.view', 'guard_name' => 'web']);
    Role::findByName('Tenant', 'web')->givePermissionTo($orfano);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Rbac::permissions())->not->toContain('letture_contaore.view')
        ->and(nelPivot('Tenant', 'letture_contaore.view'))->toBeTrue();

    $componente = ($this->editor)()
        ->set('ruoloInConferma', 'Tenant')
        ->set('permessoInConferma', 'letture_contaore.view')
        ->assertOk();

    expect($componente->viewData('conferma'))->toBeNull()
        ->and($componente->html())->not->toContain('data-conferma');

    // E il gesto, se qualcuno insistesse, viene rifiutato dal dominio — che è
    // dove la difesa vera è sempre stata.
    $componente->call('procedi')->assertHasErrors('permesso');

    expect(nelPivot('Tenant', 'letture_contaore.view'))->toBeTrue();

    // E le altre due famiglie che `MatriceRuoli` rifiuta, per la stessa ragione:
    // la cella bloccata e la colonna del ruolo protetto. Sono già le celle che
    // la griglia rende senza bottone, e la modale legge **le stesse
    // definizioni** — `Rbac::isLocked()` e `Rbac::isRuoloProtetto()` — non una
    // seconda copia della regola.
    $niente = function (string $ruolo, string $permesso) {
        $c = ($this->editor)()->set('ruoloInConferma', $ruolo)->set('permessoInConferma', $permesso)->assertOk();

        expect($c->viewData('conferma'))->toBeNull("«{$ruolo}»/«{$permesso}» non deve aprire una conferma.");

        $c->call('procedi')->assertHasErrors();
    };

    // `tenants.view_all` sul Superadmin: una revoca che chiuderebbe fuori da
    // /piattaforma chi la sta guardando — ed è nel set bloccato, quindi non
    // succede.
    $niente('Superadmin', 'tenants.view_all');
    $niente('Developer', 'strumenti.view');
});

// ─── La riga nel registro ────────────────────────────────────────────────────

it('names the role in the audit register, instead of «Role · #3»', function () {
    // 🔴 Il test per cui `SoggettiAudit::SOGGETTI` ha dovuto imparare un model di
    // **vendor**: il meta-test dei soggetti cerca con `glob(app_path('Models/*.php'))`,
    // e `Role` non abita lì — la rete che il progetto crede di avere qui non
    // copriva questa feature. Senza quella riga l'etichetta è «Role · #3»: in
    // inglese, senza nome, e col tipo assente dal filtro del registro.
    //
    // ⚠️ Si legge **attraverso `VistaPiattaforma::audit()`**: il pavimento
    // `where('log_name', AuditLog::NAME)` sta dentro la porta, quindi una riga
    // scritta sul canale `default` esisterebbe a database e non comparirebbe mai
    // nel registro.
    //
    // ⚠️ E `Activity` si svuota prima del gesto: le factory scrivono audit da sé.
    Activity::query()->delete();

    ($this->editor)()->call('chiedi', 'Tenant', 'semaforo.force')->call('procedi');

    $righe = VistaPiattaforma::audit()->get();

    expect($righe)->toHaveCount(1);

    $riga = $righe->first();
    $riga->load(['subject' => SoggettiAudit::vincolo()]);

    expect(SoggettiAudit::etichetta($riga)->testo())->toBe('Ruolo · Tenant')
        ->and($riga->description)->toBe('Permesso concesso al ruolo')
        ->and($riga->causer_id)->toBe($this->superadmin->id)
        ->and($riga->properties->get('permesso'))->toBe('semaforo.force');

    // E il tipo è offerto dal filtro del registro, che legge la stessa mappa:
    // senza, la riga esisterebbe e non sarebbe interrogabile per soggetto.
    expect(Livewire::actingAs($this->superadmin)->test(RegistroAudit::class)->instance()->tipiSoggetto())
        ->toHaveKey(Role::class);
});

it("the audit row is reachable from the register's «atti» filter", function () {
    // 🔴 Congela che `event` resti **NULL**. La riga è un **atto** — un gesto,
    // non il diff delle colonne di un model — e la sentinella `__atto` è il modo
    // in cui il registro sa chiedere «solo gli atti». Un `->event('updated')`
    // scritto per abitudine la farebbe scivolare fra le modifiche di dominio e
    // **sparire da questo filtro**, restando invisibile a ogni altro test.
    //
    // ⚠️ Si prova dal **filtro** e non dalla colonna: `event === null` asserito a
    // mano è già in `MatriceRuoliTest`, e non dice a cosa serve. Qui si prova la
    // conseguenza, cioè la cosa che si perderebbe.
    Activity::query()->delete();

    ($this->editor)()->call('chiedi', 'Tenant', 'semaforo.force')->call('procedi');

    $atto = VistaPiattaforma::audit()->sole();

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

// ─── La porta di piattaforma, che qui non c'entra ────────────────────────────

it('does not ask the platform door for the users', function () {
    // ⚠️ **Il riflesso dopo il registro di audit è far passare ogni lettura
    // cross-tenant da `VistaPiattaforma`**, e qui sarebbe sbagliato due volte.
    // `User` non ha global scope — è dichiarato in
    // `TenantScopeGuardrailTest::NON_TENANT_MODELS` — quindi non c'è niente da
    // togliere; e il docblock della porta rifiuta un `utenti()` **per nome**:
    // «un bypass finto, che legittimerebbe l'idea che serva sempre». Il permesso,
    // che è l'unica cosa che la porta aggiungerebbe davvero, questa pagina lo
    // chiede già tre volte per conto proprio.
    //
    // Il gemello di `names every public method of the door`, dal verso opposto:
    // quello impedisce che un metodo nuovo resti senza negativo, questo che
    // nasca affatto.
    $pubblici = collect((new ReflectionClass(VistaPiattaforma::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $m) => $m->class === VistaPiattaforma::class)
        ->map(fn (ReflectionMethod $m) => $m->name);

    expect($pubblici->all())->not->toContain('utenti')
        ->and($pubblici->all())->not->toContain('ruoli')
        // E la pagina non la nomina affatto: se un giorno la nominasse, la
        // domanda «quale scope toglie?» dovrebbe avere una risposta.
        //
        // ⚠️ **I commenti si tolgono prima di guardare.** Il docblock di
        // `EditorRuoli` cita `VistaPiattaforma::ruoli()` proprio per spiegare
        // perché non esiste: un guardrail che legge il testo invece del codice
        // punisce chi documenta. È la stessa correzione che
        // `BypassNudiGuardrailTest` ha già dovuto fare su sé stesso.
        ->and(codiceSenzaCommenti(app_path('Livewire/Piattaforma/EditorRuoli.php')))
        ->not->toContain('VistaPiattaforma::');

    // La rete che tiene onesto il primo assert: la ragione è che `User` non ha
    // scope da togliere, e se ne guadagnasse uno questa scelta andrebbe rivista.
    expect(array_keys((new User)->getGlobalScopes()))->toBe([]);
});
