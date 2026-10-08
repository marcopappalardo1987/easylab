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
use Spatie\Activitylog\Models\Activity;

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

    $this->superadmin = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $this->accountA->aggiungiMembro($this->superadmin);
});

// ─── I negativi: la porta è chiusa ───────────────────────────────────────────

/** Gli scope di `Strumento` le cui sottoquery restano scopate (S6 blocco A). */
const SCOPE_SEMAFORO = ['conStato', 'obsoleti', 'ordinaPerStato'];

it('refuses to open without the platform permission', function (string $ruolo) {
    $utente = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $utente->assignRole($ruolo);

    $this->actingAs($utente);

    // ⚠️ Questa catena **enumera i metodi a mano**, quindi va estesa a ogni
    // metodo nuovo della porta: dimenticarlo lascia la suite tutta verde con un
    // ingresso non provato. Un test che elenca è un test che invecchia.
    expect(fn () => VistaPiattaforma::accounts())->toThrow(AuthorizationException::class)
        ->and(fn () => VistaPiattaforma::accountsInclusaPiattaforma())->toThrow(AuthorizationException::class)
        ->and(fn () => VistaPiattaforma::enti())->toThrow(AuthorizationException::class)
        ->and(fn () => VistaPiattaforma::strumenti())->toThrow(AuthorizationException::class)
        ->and(fn () => VistaPiattaforma::audit())->toThrow(AuthorizationException::class);
})->with(['Admin', 'Responsabile Reparto', 'Tenant', 'Tecnico', 'Gestore']);

it('refuses to open for a guest', function () {
    expect(fn () => VistaPiattaforma::accounts())->toThrow(AuthorizationException::class)
        ->and(fn () => VistaPiattaforma::audit())->toThrow(AuthorizationException::class);
});

it('names every public method of the door, so none can be added without a negative', function () {
    // 🔴 La rete che rende inutile ricordarsi di estendere le catene qui sopra.
    // I due test negativi elencano i metodi **a mano**: un ingresso nuovo alla
    // porta non provato è la peggiore delle omissioni possibili, e non ha alcun
    // segnale. Questo confronta l'elenco con la riflessione sulla classe.
    $pubblici = collect((new ReflectionClass(VistaPiattaforma::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $m) => $m->class === VistaPiattaforma::class)
        ->map(fn (ReflectionMethod $m) => $m->name)
        ->values()->all();

    expect($pubblici)->toEqualCanonicalizing([
        'accounts', 'accountsInclusaPiattaforma', 'enti', 'strumenti', 'audit',
    ]);
});

it('keeps Activity free of global scopes, or the audit door would be scoped in silence', function () {
    // Stessa forma del test su `Account`, e per lo stesso rischio: `audit()` non
    // toglie nulla perché il model di vendor non ha scope. Se ne guadagnasse uno
    // a un `composer update`, quel metodo diventerebbe scopato mentre gli altri
    // restano nudi — e la porta smetterebbe di essere non-scopata per un quinto
    // di sé, senza che niente lo dica.
    expect(array_keys((new Activity)->getGlobalScopes()))->toBe([]);
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

    $colpevoli = $sorgenti
        ->filter(fn (string $f) => scrittureSullaPortaDiPiattaforma(file_get_contents($f)) !== [])
        ->values();

    expect($colpevoli)->toBeEmpty(
        'La vista di piattaforma è per LEGGERE: un update/delete concatenato le passa attraverso senza '.
        'gli hook di BelongsToTenant e tocca ogni cliente. Trovato in: '.$colpevoli->implode(', ')
    );
});

it('never lets a tenant-scoped semaforo scope be chained onto the platform view', function () {
    // 🔴 **Un numero plausibile e sbagliato, che nessuna somma smaschera.**
    // `Strumento::scopeConStato()`, `scopeObsoleti()` e `scopeOrdinaPerStato()`
    // (S6 blocco A) compongono sottoquery che partono da `Intervento::query()` e
    // `Garanzia::query()`, cioè **con** i loro global scope. Concatenati a
    // `VistaPiattaforma::strumenti()`, che gli scope li ha tolti, la query
    // esterna vedrebbe tutti gli Enti e le sottoquery solo il proprio: le
    // macchine altrui uscirebbero **verdi** invece che arancioni.
    //
    // ⚠️ E la partizione — l'invariante su cui poggiano i quattro numeri della
    // dashboard — **tornerebbe lo stesso**, perché verde+arancione+rosso
    // continua a fare il totale: il difetto non si presenta come una cifra
    // mancante ma come una cifra credibile. Misurato su due Enti: `arancioni`
    // vuoto con una macchina scaduta in pancia.
    //
    // Chi vorrà davvero i conteggi cross-tenant deve costruire fonti NON
    // scopate, non riusare queste.
    $sorgenti = collect([app_path(), resource_path('views')])
        ->flatMap(fn (string $dir) => collect(
            iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)))
        )->filter(fn ($f) => $f->isFile() && str_ends_with($f->getFilename(), '.php'))->map->getPathname());

    $colpevoli = $sorgenti
        ->filter(fn (string $f) => metodiConcatenatiAllaPorta(file_get_contents($f), SCOPE_SEMAFORO) !== [])
        ->values();

    expect($colpevoli)->toBeEmpty(
        'Gli scope del semaforo compongono sottoquery SCOPATE: sulla porta di piattaforma classificano '.
        'come verdi le macchine degli altri Enti, e la somma torna lo stesso. Trovato in: '.$colpevoli->implode(', ')
    );
});

it('spots the semaforo scopes on the platform view through a variable too', function () {
    // La rete della rete, come per le scritture: un guardrail aggirabile da come
    // si formatta una chiamata sembra coprire e non copre.
    $diretta = '<?php VistaPiattaforma::strumenti()->conStato($s);';
    $perVariabile = '<?php class A { function f() { $q = VistaPiattaforma::strumenti(); $q->where("a", 1)->obsoleti(); } }';
    $innocente = '<?php VistaPiattaforma::strumenti()->count();';

    expect(metodiConcatenatiAllaPorta($diretta, SCOPE_SEMAFORO))->toContain('conStato')
        ->and(metodiConcatenatiAllaPorta($perVariabile, SCOPE_SEMAFORO))->toContain('obsoleti')
        ->and(metodiConcatenatiAllaPorta($innocente, SCOPE_SEMAFORO))->toBeEmpty();
});

it('follows the platform view through a variable and through a method that returns it', function () {
    // 🔴 **Il guardrail del guardrail**, e non è zelo: la prima stesura cercava
    // la sola catena **diretta** (`VistaPiattaforma::audit()->delete()`), e
    // bastava un passaggio per uscirne. Verificato sperimentalmente il 22 Ago
    // 2026 sul registro di audit: una cancellazione di massa scritta come
    // `$this->filtrata()->delete()` lasciava la suite **tutta verde**, sulla
    // tabella che è append-only per definizione.
    //
    // Le due forme non sono ipotesi: la variabile è la forma che `ElencaClienti`
    // usa da sempre, il metodo è quella che il registro di audit ha introdotto.
    // Un guardrail aggirabile da come si formatta una chiamata è peggio di
    // nessun guardrail, perché **sembra** coprire — è la stessa lezione già
    // scritta qui sopra a proposito degli argomenti con le parentesi.
    $diretta = '<?php VistaPiattaforma::audit()->delete();';
    $perVariabile = '<?php class A { function f() { $q = VistaPiattaforma::audit(); $q->where("a", 1)->delete(); } }';
    $perMetodo = '<?php class A {
        private function porta(): Builder { return VistaPiattaforma::audit(); }
        public function purga(): void { $this->porta()->delete(); }
    }';

    expect(scrittureSullaPortaDiPiattaforma($diretta))->not->toBeEmpty()
        ->and(scrittureSullaPortaDiPiattaforma($perVariabile))->not->toBeEmpty()
        ->and(scrittureSullaPortaDiPiattaforma($perMetodo))->not->toBeEmpty();
});

it('lets a single model read from the platform view be written like any other', function () {
    // ⚠️ Il rovescio, e conta quanto l'altro: un guardrail che grida su ogni
    // riga onesta viene disattivato entro la settimana. `->find()`,
    // `->firstOrFail()` e compagnia **materializzano un model**: da lì in poi
    // `$account->update([...])` è una scrittura Eloquent normale, che tocca
    // **una** riga ed emette i suoi eventi, quindi gli hook di
    // `BelongsToTenant` girano. È esattamente ciò che fanno le leve
    // amministrative della cabina, ed è corretto.
    $unModel = '<?php class A { function f() { $a = VistaPiattaforma::accounts()->firstOrFail(); $a->update(["x" => 1]); } }';
    $soloLettura = '<?php class A { function f() { $q = VistaPiattaforma::accounts(); return $q->where("x", 1)->paginate(); } }';

    expect(scrittureSullaPortaDiPiattaforma($unModel))->toBeEmpty()
        ->and(scrittureSullaPortaDiPiattaforma($soloLettura))->toBeEmpty();
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

/**
 * Le scritture che in questo sorgente raggiungono la porta di piattaforma.
 *
 * ⚠️ **Si tokenizza, non si cerca col regex sul testo grezzo.** Il pattern
 * precedente saltava gli argomenti con `[^)]*\)`, che non attraversa una
 * parentesi **dentro una stringa** — forma introdotta il giorno dopo da
 * `MetrichePiattaforma` con `selectRaw('… count(*) …')`. Verificato: una
 * scrittura scritta così passava indisturbata.
 *
 * **Tre strade, non una.** La porta si raggiunge (a) concatenando alla chiamata
 * statica, (b) da una **variabile** a cui è stata assegnata, (c) da un **metodo
 * che la restituisce**. La prima stesura vedeva solo la (a), e le altre due non
 * sono ipotesi di scuola: la (b) è la forma di `ElencaClienti`, la (c) quella
 * che il registro di audit ha introdotto — e con cui un `delete()` di massa
 * sull'audit è passato inosservato.
 *
 * ⚠️ **Limite dichiarato: l'analisi è per-file.** Un metodo che restituisse la
 * porta e venisse chiamato da un'**altra** classe non verrebbe seguito. Chiuderlo
 * vorrebbe dire un'analisi statica vera; qui si è scelto di coprire le forme che
 * il progetto usa davvero e di scrivere quale resta fuori, invece di lasciar
 * credere che sia tutto.
 *
 * @return list<string> i metodi di scrittura trovati, vuoto se il file è pulito
 */
function scrittureSullaPortaDiPiattaforma(string $php): array
{
    if (! str_contains($php, 'VistaPiattaforma')) {
        return [];
    }

    return metodiConcatenatiAllaPorta(
        $php,
        ['update', 'delete', 'forceDelete', 'increment', 'decrement', 'truncate', 'upsert', 'insert']
    );
}

/**
 * I metodi di `$metodi` concatenati a un builder che viene dalla porta —
 * direttamente, attraverso una variabile o attraverso un metodo che la
 * restituisce.
 *
 * ⚠️ **Estratto il 25 Ago 2026 dal guardrail sulle scritture**, e non per
 * simmetria: dal blocco A di S6 c'è una seconda famiglia di metodi che sulla
 * porta non va concatenata — gli scope del semaforo, le cui sottoquery restano
 * scopate — e la parte difficile non è l'elenco dei verbi, è **seguire** la
 * porta attraverso una variabile o un metodo. Due copie di quella parte
 * divergerebbero, e la metà che diverge smetterebbe di vedere proprio la forma
 * che qualcuno ha appena usato.
 *
 * @param  list<string>  $metodi
 * @return list<string>
 */
function metodiConcatenatiAllaPorta(string $php, array $metodi): array
{
    if (! str_contains($php, 'VistaPiattaforma')) {
        return [];
    }

    $scritture = $metodi;

    // Tolti commenti, spazi e stringhe la catena è lineare: fino al `;` c'è una
    // sola istruzione, e nessuna parentesi dentro una stringa può spezzarla.
    $token = collect(token_get_all($php))
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_CONSTANT_ENCAPSED_STRING], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');

    $sorgenti = ['VistaPiattaforma::\w+\(\)'];

    foreach (variabiliCheTengonoLaPorta($token) as $variabile) {
        $sorgenti[] = preg_quote($variabile, '/');
    }

    foreach (metodiCheRestituisconoLaPorta($token) as $metodo) {
        $sorgenti[] = '\$this->'.preg_quote($metodo, '/').'\(\)';
    }

    $trovate = [];

    foreach ($sorgenti as $sorgente) {
        foreach ($scritture as $scrittura) {
            if (preg_match('/'.$sorgente.'[^;]*->'.$scrittura.'\(/', $token) === 1) {
                $trovate[] = $scrittura;
            }
        }
    }

    return array_values(array_unique($trovate));
}

/**
 * Le variabili che tengono **il builder** della porta, non una riga già letta.
 *
 * ⚠️ La distinzione è ciò che tiene il guardrail utilizzabile. `$account =
 * VistaPiattaforma::accounts()->firstOrFail()` non è un builder: è un model, e
 * `$account->update([...])` tocca **una** riga emettendo i propri eventi, quindi
 * gli hook di `BelongsToTenant` girano. È la forma delle leve amministrative
 * della cabina, ed è corretta. Contarla come violazione farebbe gridare il
 * guardrail su ogni riga onesta — e un guardrail che grida a vuoto viene
 * disattivato entro la settimana.
 *
 * @return list<string>
 */
function variabiliCheTengonoLaPorta(string $token): array
{
    $materializzano = 'find|findOrFail|findOr|first|firstOr|firstOrFail|firstWhere|sole|get|getQuery|pluck|count|sum|avg|max|min|exists|doesntExist|paginate|simplePaginate|cursorPaginate|value|cursor|toArray|toBase';

    preg_match_all('/(\$\w+)=VistaPiattaforma::\w+\(\)([^;]*)/', $token, $trovate, PREG_SET_ORDER);

    $variabili = [];

    foreach ($trovate as [$_, $variabile, $coda]) {
        if (preg_match('/->('.$materializzano.')\(/', $coda) === 1) {
            continue;
        }

        $variabili[] = $variabile;
    }

    return array_values(array_unique($variabili));
}

/**
 * I metodi del file il cui `return` è la porta — direttamente o via una
 * variabile che la tiene.
 *
 * Le graffe si contano invece di fermarsi alla prima chiusa: il corpo di un
 * metodo ne contiene altre (`if`, `foreach`, closure), e un `[^}]*` si
 * fermerebbe alla prima, tagliando via proprio il `return` finale.
 *
 * @return list<string>
 */
function metodiCheRestituisconoLaPorta(string $token): array
{
    $variabili = variabiliCheTengonoLaPorta($token);
    $metodi = [];

    preg_match_all('/function(\w+)\(/', $token, $trovate, PREG_OFFSET_CAPTURE);

    foreach ($trovate[1] as $i => [$nome, $_]) {
        // La prima graffa **o** il primo `;`: una firma senza corpo (interfaccia,
        // metodo astratto) non ha un corpo da leggere, e prendere la graffa del
        // metodo successivo attribuirebbe a lei il corpo di un altro.
        $apertura = null;

        for ($p = $trovate[0][$i][1]; $p < strlen($token); $p++) {
            if ($token[$p] === ';') {
                break;
            }

            if ($token[$p] === '{') {
                $apertura = $p;
                break;
            }
        }

        if ($apertura === null) {
            continue;
        }

        $livello = 0;
        $corpo = null;

        for ($p = $apertura; $p < strlen($token); $p++) {
            $livello += $token[$p] === '{' ? 1 : ($token[$p] === '}' ? -1 : 0);

            if ($livello === 0) {
                $corpo = substr($token, $apertura, $p - $apertura);
                break;
            }
        }

        if ($corpo === null) {
            continue;
        }

        $restituisce = str_contains($corpo, 'returnVistaPiattaforma::')
            || collect($variabili)->contains(fn (string $v) => str_contains($corpo, 'return'.$v.';'));

        if ($restituisce) {
            $metodi[] = $nome;
        }
    }

    return array_values(array_unique($metodi));
}
