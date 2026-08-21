<?php

use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Rbac;
use App\Support\Tenancy\VistaPiattaforma;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * 🔴 La porta unica delle viste di piattaforma (ADR-018).
 *
 * Area rossa: qui si rimuovono deliberatamente le guardie su cui poggia
 * l'isolamento dell'intero progetto. I negativi contano più dei positivi, e in
 * particolare quello di **non-trapelamento**: aprire la porta per una vista
 * aggregata non deve allentare lo scope per il resto della richiesta.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Due clienti distinti, ciascuno con la sua sede e il suo strumento.
    $this->accountA = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->enteA = UnitaOrganizzativa::factory()->ente()->perAccount($this->accountA)->create(['nome' => 'Sede Rossi']);
    $this->strumentoA = Strumento::factory()->forNode($this->enteA)->create(['nome' => 'Autoclave Rossi']);

    $this->accountB = Account::factory()->create(['ragione_sociale' => 'Lab Bianchi']);
    $this->enteB = UnitaOrganizzativa::factory()->ente()->perAccount($this->accountB)->create(['nome' => 'Sede Bianchi']);
    $this->strumentoB = Strumento::factory()->forNode($this->enteB)->create(['nome' => 'Autoclave Bianchi']);

    $this->superadmin = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $this->superadmin->assignRole('Superadmin');
    $this->accountA->aggiungiMembro($this->superadmin);
});

// ─── I negativi: la porta è chiusa ───────────────────────────────────────────

it('refuses to open without the platform permission', function (string $ruolo) {
    $utente = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $utente->assignRole($ruolo);

    $this->actingAs($utente);

    expect(fn () => VistaPiattaforma::accounts())->toThrow(AuthorizationException::class)
        ->and(fn () => VistaPiattaforma::enti())->toThrow(AuthorizationException::class)
        ->and(fn () => VistaPiattaforma::strumenti())->toThrow(AuthorizationException::class);
})->with(['Admin', 'Responsabile Reparto', 'Tenant', 'Tecnico']);

it('refuses to open for a guest', function () {
    expect(fn () => VistaPiattaforma::accounts())->toThrow(AuthorizationException::class);
});

it('throws instead of handing back an empty builder', function () {
    // Un builder vuoto si leggerebbe come «non ci sono clienti», che è la forma
    // peggiore di negare: silenziosa e plausibile. 403, non zero.
    $admin = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $admin->assignRole('Admin');

    $this->actingAs($admin);

    expect(fn () => VistaPiattaforma::enti()->count())->toThrow(AuthorizationException::class);
});

// ─── Il positivo: la porta si apre, e vede tutto ─────────────────────────────

it('sees every tenant once opened', function () {
    $this->actingAs($this->superadmin);

    expect(VistaPiattaforma::accounts()->pluck('ragione_sociale')->all())
        ->toContain('Gruppo Rossi', 'Lab Bianchi')
        ->and(VistaPiattaforma::enti()->pluck('nome')->all())
        ->toContain('Sede Rossi', 'Sede Bianchi')
        ->and(VistaPiattaforma::strumenti()->pluck('nome')->all())
        ->toContain('Autoclave Rossi', 'Autoclave Bianchi');
});

it('keeps the trash out, because the scopes come off by name', function () {
    // ⚠️ Il difetto già pagato una volta in `Account::enti()`: con
    // `withoutGlobalScopes()` nudo se ne va anche `SoftDeletingScope`, e le sedi
    // cestinate tornano nei conteggi. Qui gonfierebbe i KPI con righe che
    // nessuno ha più.
    $this->enteB->delete();
    $this->strumentoB->delete();

    $this->actingAs($this->superadmin);

    expect(VistaPiattaforma::enti()->pluck('nome')->all())->not->toContain('Sede Bianchi')
        ->and(VistaPiattaforma::strumenti()->pluck('nome')->all())->not->toContain('Autoclave Bianchi');
});

// ─── Non-trapelamento: la porta non allenta nulla per il resto ───────────────

it('opens for a department-scoped user, and still fences them afterwards', function () {
    // ⚠️ **È il solo test che prova `DepartmentScope::class` nella porta**, e la
    // sua assenza era un buco: per un Superadmin quello scope è già un no-op
    // (`AccessibleNodes` torna null a chi non è Responsabile), quindi toglierlo
    // dalla porta lasciava tutto verde tranne un confronto di stringhe.
    //
    // Il caso non è teorico: la matrice dei permessi è modificabile a runtime
    // (ADR-016), quindi un Responsabile può ricevere `tenants.view_all`. Senza
    // quella voce la vista aggregata verrebbe troncata al suo sotto-albero — un
    // KPI che SOTTO-conta, gemello del difetto sui cestinati e altrettanto muto.
    $dipartimento = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create();

    $responsabile = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $responsabile->assignRole('Responsabile Reparto');
    $responsabile->unitaResponsabili()->attach($dipartimento->id);
    $responsabile->givePermissionTo('tenants.view_all');

    $this->actingAs($responsabile->fresh());

    // Dalla porta vede tutta la piattaforma, non il proprio ramo.
    expect(VistaPiattaforma::enti()->pluck('nome')->all())
        ->toContain('Sede Rossi', 'Sede Bianchi');

    // E **nella stessa richiesta** resta recintato dove deve: questa è la sonda
    // di non-trapelamento che morde, perché qui uno scope è davvero attivo.
    // (La prima stesura usava lo switcher Ente, che gira già non-scopato: era
    // il componente meno sensibile del progetto a un residuo di scope.)
    expect(UnitaOrganizzativa::pluck('nome')->all())->toBe([$dipartimento->nome]);
});

it('never lets a write be chained onto the platform view', function () {
    // 🔴 I builder che la porta consegna **sono scrivibili**: `Builder::update()`
    // passa dal query builder e non emette eventi, quindi gli hook di
    // `BelongsToTenant` che riforzano `tenant_id` non girano. Un update di massa
    // riscriverebbe ogni riga di ogni cliente dietro un permesso `.view_all`.
    //
    // La prima stesura provava questo con un **Admin**, e non provava niente: la
    // porta lanciava prima che l'update esistesse, cioè era un duplicato del
    // dataset dei negativi. Il caso pericoloso è il Superadmin, che il permesso
    // ce l'ha — e contro di lui l'unica difesa è che nessuno scriva quella riga.
    $sorgenti = collect([app_path(), resource_path('views')])
        ->flatMap(fn (string $dir) => collect(
            iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)))
        )->filter(fn ($f) => $f->isFile() && str_ends_with($f->getFilename(), '.php'))->map->getPathname());

    // ⚠️ **Si tokenizza, non si cerca col regex sul testo grezzo.** Il pattern
    // precedente saltava gli argomenti con `[^)]*\)`, che non attraversa una
    // parentesi **dentro una stringa** — forma introdotta il giorno dopo da
    // `MetrichePiattaforma` con `selectRaw('… count(*) …')`. Verificato: una
    // scrittura scritta così passava indisturbata. È lo stesso buco già
    // corretto su `BypassNudiGuardrailTest`, dove bastava uno spazio: un
    // guardrail aggirabile da come si formatta un argomento è peggio di
    // nessun guardrail, perché sembra coprire.
    $scritture = ['update', 'delete', 'forceDelete', 'increment', 'decrement', 'truncate', 'upsert', 'insert'];

    $colpevoli = $sorgenti->filter(function (string $f) use ($scritture) {
        $token = collect(token_get_all(file_get_contents($f)))
            ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_CONSTANT_ENCAPSED_STRING], true))
            ->map(fn ($t) => is_array($t) ? $t[1] : $t)
            ->implode('');

        // Tolte stringhe e spazi la catena è lineare: la porta, e prima del `;`
        // uno dei metodi di scrittura.
        return collect($scritture)->contains(
            fn (string $m) => preg_match('/VistaPiattaforma::\w+\(\)[^;]*->'.$m.'\(/', $token) === 1
        );
    })->values();

    expect($colpevoli)->toBeEmpty(
        'La vista di piattaforma è per LEGGERE: un update/delete concatenato le passa attraverso senza '.
        'gli hook di BelongsToTenant e tocca ogni cliente. Trovato in: '.$colpevoli->implode(', ')
    );
});

it('opens for the Developer, and for nobody without the permission', function () {
    // Dataset derivato dalla config invece che copiato a mano: se S6 aggiunge un
    // ruolo, questo test lo copre da sé invece di restare verde per omissione.
    $conPermesso = collect(Rbac::roleNames())
        ->filter(fn (string $r) => in_array(VistaPiattaforma::PERMESSO, Rbac::permissionsForRole($r), true));

    expect($conPermesso->all())->toEqualCanonicalizing(['Developer', 'Superadmin']);

    foreach ($conPermesso as $ruolo) {
        $utente = User::factory()->create(['tenant_id' => $this->enteA->id]);
        $utente->assignRole($ruolo);
        $this->actingAs($utente->fresh());

        expect(VistaPiattaforma::accounts()->count())->toBeGreaterThan(0);
    }
});

it('closes while impersonating, because the gate reads the impersonated user', function () {
    // lab404 sostituisce l'utente della guard, quindi `Gate::authorize` legge i
    // permessi dell'IMPERSONATO: la porta non eredita quelli di chi impersona.
    // È il comportamento giusto (ADR-018: cross-tenant solo via impersonazione,
    // e dentro l'impersonazione si è l'altro), ma senza questo test nessuno lo
    // congela — e sarebbe la prima cosa che qualcuno «aggiusterebbe» trovando
    // un 403 inatteso.
    $tenant = User::factory()->create(['tenant_id' => $this->enteB->id]);
    $tenant->assignRole('Tenant');

    $this->actingAs($this->superadmin)->get(route('impersonate', $tenant->id));

    expect(fn () => VistaPiattaforma::accounts())->toThrow(AuthorizationException::class);
});

it('excludes EasyLab itself, or the platform would count as its own customer', function () {
    // ⚠️ Il difetto che il confronto ha trovato: il blocco A ha aggiunto una
    // colonna e un backfill perché la piattaforma non fosse contata fra i
    // clienti, e la porta la rimetteva dentro. Il `beforeEach` non seminava il
    // SuperadminSeeder, quindi nel test l'account di piattaforma non esisteva
    // nemmeno — e nulla se ne accorgeva.
    $piattaforma = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);

    $this->actingAs($this->superadmin);

    expect(VistaPiattaforma::accounts()->pluck('ragione_sociale')->all())
        ->not->toContain('EasyLab')
        ->and(VistaPiattaforma::accountsInclusaPiattaforma()->pluck('ragione_sociale')->all())
        ->toContain('EasyLab')
        ->and($piattaforma->fresh()->di_piattaforma)->toBeTrue();
});

it('keeps Account free of global scopes, or a third of this class would be scoped in silence', function () {
    // `accounts()` non toglie nulla perché `Account` non ha global scope. Se
    // domani ne guadagnasse uno (un `PiattaformaScope`, o Cashier), questo
    // metodo diventerebbe scopato mentre gli altri due restano nudi — e la
    // classe smetterebbe di essere «non-scopata» per un terzo di sé, in
    // silenzio. È l'unico dei tre senza copertura meccanica.
    expect(array_keys((new Account)->getGlobalScopes()))
        ->toBe([SoftDeletingScope::class]);
});
