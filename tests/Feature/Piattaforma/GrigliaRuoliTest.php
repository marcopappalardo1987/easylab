<?php

use App\Support\Rbac;
use App\Support\Rbac\MatriceRuoli;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * La **resa** della matrice ruolo→permesso, in sola lettura (S6 — ADR-016).
 *
 * ⚠️ **Questo file prova la presentazione, non la protezione**, e va detto in
 * testa perché è il modo in cui questa feature si può credere sicura senza
 * esserlo. `Livewire::test()` e le richieste di test non passano dai middleware
 * come una richiesta vera, e soprattutto un `@if` in Blade si toglie in un
 * secondo: nessuna asserzione su questo HTML impedisce a una richiesta forgiata
 * a mano di scrivere sul pivot. La guardia vera vive in
 * `App\Support\Rbac\MatriceRuoli`, rifiuta **prima** di toccare il database, ed
 * è provata in `MatriceRuoliTest` — che è anche il posto dove sta il conteggio
 * delle query della matrice, per la ragione scritta là: contarlo sul montaggio
 * del componente lo renderebbe fragile rispetto all'ordine dei test, perché
 * `render()` porta con sé il gate, la sessione e l'utente.
 *
 * La divisione fra i due file è netta e voluta: **là la regola, qui la resa**.
 *
 * 🔗 `Schema Ruoli e Permessi.md` §5 (l'orientamento della tabella), ADR-016,
 * ADR-019 (i `letture_contaore.*` che diventarono orfani).
 */

/**
 * L'ordine dei ruoli come lo scrive `Schema Ruoli §5`, trascritto a mano.
 *
 * ⚠️ Sì, è un elenco parallelo — e qui è il **punto**, non il difetto: esiste
 * per rendere rossa la divergenza fra lo schermo e il documento che gli starà
 * accanto. Il test lo confronta con `Rbac::roleNames()` *e* con l'intestazione
 * resa, quindi le tre cose non possono separarsi in silenzio. Un elenco
 * parallelo è dannoso quando è una **seconda fonte di verità**; questo è una
 * rete, e non lo consuma nessuno tranne l'asserzione qui sotto.
 */
const RUOLI_IN_ORDINE_DI_DOCUMENTO = ['Developer', 'Superadmin', 'Admin', 'Responsabile Reparto', 'Tenant', 'Tecnico', 'Gestore'];

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // La pagina come la vede un browser: richiesta vera, middleware inclusi.
    // `Livewire::test()` renderebbe lo stesso HTML ma è la strada che non passa
    // dalle guardie, e su questa pagina è meglio non prenderci l'abitudine.
    $this->griglia = fn (): string => $this->actingAs(utenteConRuolo('Superadmin'))
        ->get(route('piattaforma.ruoli'))
        ->assertOk()
        ->getContent();
});

/** Il `<th>` di intestazione di una colonna-ruolo. */
function intestazioneDelRuolo(string $html, string $ruolo): string
{
    preg_match('/<th[^>]*data-ruolo="'.preg_quote($ruolo, '/').'"[^>]*>.*?<\/th>/s', $html, $blocco);

    return $blocco[0] ?? '';
}

/** L'intestazione della griglia — il primo `<thead>`, cioè quello della matrice. */
function intestazioneDellaGriglia(string $html): string
{
    preg_match('/<thead.*?<\/thead>/s', $html, $blocco);

    return $blocco[0] ?? '';
}

// ─── L'orientamento ──────────────────────────────────────────────────────────

it('renders the roles in the same order as the matrix document', function () {
    // 🔴 Congela l'orientamento, che è una decisione e non un caso: **7 ruoli in
    // colonna, 54 permessi in riga**, come la tabella di `Schema Ruoli §5`.
    // Questa pagina verrà affiancata a quel documento — è il posto dove si
    // scopre che il documento è vecchio, e oggi lo è — e chi confronta i due non
    // deve trasporre a mente. Trasporre la griglia non romperebbe nulla di
    // funzionale: si vedrebbe solo il giorno in cui qualcuno si arrende al
    // confronto.
    $html = ($this->griglia)();
    $intestazione = intestazioneDellaGriglia($html);

    preg_match_all('/data-ruolo="([^"]+)"/', $intestazione, $trovati);

    expect($trovati[1])->toBe(RUOLI_IN_ORDINE_DI_DOCUMENTO)
        // La rete che tiene onesto l'elenco trascritto: se `config/rbac.php`
        // riordinasse i ruoli, o ne aggiungesse uno (la Dashboard Developer è la
        // voce di roadmap successiva), è qui che si vede — invece che da un
        // confronto a occhio col documento, che è esattamente ciò che questa
        // pagina esiste per evitare.
        ->and($trovati[1])->toBe(Rbac::roleNames());

    // L'altra metà dell'orientamento, senza la quale la prima non prova niente:
    // i **permessi** non stanno nell'intestazione, stanno nelle righe.
    expect($intestazione)->not->toContain('strumenti.view')
        ->and(rigaDelPermesso($html, 'strumenti.view'))->not->toBe('');
});

it('renders one row per catalogue permission, and not one per database row', function () {
    // Il conteggio è l'invariante più economico di tutta la griglia: 54 righe,
    // né una in più né una in meno. Le righe si costruiscono dal **catalogo**
    // incrociato col database, non da `Permission::all()` — e questo assert è ciò
    // che rende rossa quella sostituzione nel momento in cui il database contiene
    // una riga che il catalogo non conosce (vedi il test degli orfani).
    $html = ($this->griglia)();

    expect(substr_count($html, 'wire:key="permesso-'))->toBe(count(Rbac::permissions()))
        ->and(count(Rbac::permissions()))->toBe(54);
});

it('shows a tick exactly where the role holds the permission', function () {
    // La griglia legge `MatriceRuoli::stato()` con `isset($matrice[$ruolo][$permesso])`
    // e **mai** `$role->hasPermissionTo()` per cella. Questo test non lo prova
    // (lo prova il conteggio delle query, in `MatriceRuoliTest`): prova la cosa
    // che quel modo di leggere può sbagliare in silenzio, cioè **invertire i due
    // indici**. Una matrice trasposta rende 324 celle plausibili e tutte
    // sbagliate, e a occhio non si vede.
    $html = ($this->griglia)();

    $reso = [];

    foreach (Rbac::permissions() as $permesso) {
        $riga = rigaDelPermesso($html, $permesso);

        expect($riga)->not->toBe('', "La riga di «{$permesso}» non è stata resa.");

        // ⚠️ **Si cattura la cella intera, non il solo `data-stato`.** Quel
        // marcatore esiste *unicamente per questo test* — nessun altro file lo
        // legge — quindi asserire solo su di lui misura una copia privata e
        // lascia libero ciò che l'utente vede davvero: verificato, invertendo il
        // ternario dell'emoji la griglia diceva il contrario del vero su tutte e
        // 324 le celle e questo file restava **tutto verde**. Su una pagina il
        // cui scopo dichiarato è «verificare a occhio che la matrice dica il
        // vero», è il difetto peggiore possibile.
        preg_match_all('/data-ruolo="([^"]+)"\s+data-stato="([^"]+)"(.*?)<\/td>/s', $riga, $celle, PREG_SET_ORDER);

        expect($celle)->toHaveCount(count(Rbac::roleNames()));

        foreach ($celle as [, $ruolo, $stato, $contenuto]) {
            // I tre strati devono dire la stessa cosa: il marcatore per i test,
            // l'emoji per chi guarda, il testo `sr-only` per chi ascolta.
            $atteso = $stato === 'si';

            expect(str_contains($contenuto, $atteso ? '✅' : '❌'))->toBeTrue(
                "La cella «{$ruolo}» di «{$permesso}» mostra l'emoji opposta al proprio stato."
            )->and(str_contains($contenuto, $atteso ? '✅' : '❌'))->toBeTrue()
                ->and($contenuto)->toContain($ruolo.': '.($atteso ? 'sì' : 'no'));

            if ($atteso) {
                $reso[$ruolo][] = $permesso;
            }
        }
    }

    foreach (Rbac::roleNames() as $ruolo) {
        expect($reso[$ruolo] ?? [])->toEqualCanonicalizing(
            Rbac::permissionsForRole($ruolo),
            "La colonna «{$ruolo}» non corrisponde alla matrice."
        );
    }

    // E la controprova che il ✅ non è stampato su tutto: il Tenant è il ruolo
    // più ristretto, e se lo fosse questo assert cadrebbe da solo.
    expect($reso['Tenant'])->not->toContain('strumenti.delete')
        ->and($reso['Developer'])->toHaveCount(54);
});

// ─── Il set bloccato e la riga protetta, resi ────────────────────────────────

it('never offers a control on a locked column', function () {
    // ⚠️ **Metà di questo test non può fallire oggi, e va detto invece di
    // lasciarlo credere.** In questa tappa la griglia è in sola lettura: *nessuna*
    // cella porta un `wire:click`, quindi «le celle bloccate non ne hanno» è vero
    // per costruzione e resterebbe vero anche cancellando ogni traccia del set
    // bloccato dal Blade. L'asserzione che morde è quella sul **marcatore**: il
    // 🔒 c'è sulle sette righe bloccate e **non c'è** sulle altre
    // quarantasette. Senza la seconda metà, un Blade che marcasse tutto passerebbe.
    //
    // La metà inerte non è però inutile: dal Blocco 4 in poi, quando i controlli
    // esisteranno, è l'assert che diventa vivo senza doverlo scrivere allora — e
    // scriverlo allora è precisamente ciò che nessuno si ricorda di fare.
    //
    // ⚠️ E resta un test di **presentazione**. La protezione è in
    // `MatriceRuoli::applica()`, che rifiuta prima di toccare il database: chi
    // legge questo file non deve concludere che il set bloccato è difeso da qui.
    $html = ($this->griglia)();

    expect(Rbac::locked())->toHaveCount(7);

    foreach (Rbac::locked() as $bloccato) {
        $riga = rigaDelPermesso($html, $bloccato);

        expect($riga)->not->toBe('', "La riga di «{$bloccato}» non è stata resa.")
            ->and($riga)->toContain('data-bloccato="1"')
            ->and($riga)->toContain('🔒')
            ->and($riga)->not->toContain('wire:click');
    }

    // La metà che rende falsificabile la prima: un permesso ridistribuibile non
    // deve portare il lucchetto. `strumenti.view` sta agli antipodi di
    // `roles.manage` — è il permesso più diffuso del catalogo.
    $libera = rigaDelPermesso($html, 'strumenti.view');

    expect($libera)->not->toContain('data-bloccato')
        ->and($libera)->not->toContain('🔒')
        // E il conteggio, che chiude la strada a un marcatore appiccicato a
        // mano su una riga sola: sette righe bloccate, quante ne dice la config.
        ->and(substr_count($html, 'data-bloccato="1"'))->toBe(count(Rbac::locked()));
});

it('marks the protected role column as untouchable, and only that one', function () {
    // La colonna del Developer è inerte in entrambe le direzioni: il set bloccato
    // non la copre, perché protegge sette permessi e non gli altri quarantasette,
    // e `['all' => true]` è la chiave di riserva della piattaforma — non esiste
    // alcun `Gate::before` da super-admin, quindi il Developer dipende davvero
    // dalla matrice a database.
    //
    // Il marcatore si deriva da `Rbac::isRuoloProtetto()` e da nient'altro: è
    // **la** definizione, e una seconda copia scritta nel Blade il giorno in cui
    // la prima cambia resterebbe indietro in silenzio (la disciplina che
    // `OffreImpersonazione::candidatiDi()` documenta a proposito di
    // `canBeImpersonated()`).
    $html = ($this->griglia)();

    expect(Rbac::ruoliProtetti())->toBe(['Developer']);

    foreach (Rbac::roleNames() as $ruolo) {
        $intestazione = intestazioneDelRuolo($html, $ruolo);

        expect($intestazione)->not->toBe('', "La colonna «{$ruolo}» non è stata resa.");

        Rbac::isRuoloProtetto($ruolo)
            ? expect($intestazione)->toContain('data-inerte="1"')
            : expect($intestazione)->not->toContain('data-inerte');
    }
});

// ─── Le due asimmetrie del catalogo ──────────────────────────────────────────

it('shows a permission that exists in the catalogue but not in the database as un-seeded', function () {
    // ⚠️ **Il caso non esiste sotto il seeder e va costruito.** Dopo
    // `RolesAndPermissionsSeeder` i 54 permessi ci sono tutti: un test scritto
    // senza questo setup sarebbe verde **per assenza di caso**, non per la resa —
    // ed è la forma di errore che questo progetto ha già pagato (il piano lo dice
    // per nome: la prima stesura citava `fornitori.view` come caso vivo, e non lo
    // è più dal 15 Ago 2026).
    //
    // La riga cancellata a mano è esattamente ciò che resterebbe dopo l'aggiunta
    // di un permesso al catalogo senza riseeding: la config lo dichiara, il
    // database non ce l'ha, e la cella **non è accendibile** perché
    // `Permission::findByName()` lancerebbe.
    expect(Rbac::permissions())->toContain('spostamenti.view');

    DB::table('permissions')->where('name', 'spostamenti.view')->delete();

    // `Permission::findByName()` e i model interrogano il **registrar**, non il
    // database: senza questo, la riga cancellata continuerebbe a esistere in
    // cache e il test proverebbe il contrario di ciò che dice.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $html = ($this->griglia)();

    $mancante = rigaDelPermesso($html, 'spostamenti.view');

    // La riga c'è — perché le righe vengono dal **catalogo** — ed è marcata per
    // ciò che è. Il ❌ da solo direbbe «nessuno ce l'ha», che è un'altra cosa e
    // manda a fare un altro gesto: qui non è che nessuno ce l'ha, è che il
    // permesso non esiste ancora.
    expect($mancante)->not->toBe('')
        ->and($mancante)->toContain('data-da-seminare="1"')
        ->and($mancante)->toContain('da seminare')
        // E il permesso vicino, che è seminato: senza, un Blade che marcasse
        // tutto passerebbe.
        ->and(rigaDelPermesso($html, 'strumenti.view'))->not->toContain('data-da-seminare')
        ->and(substr_count($html, 'data-da-seminare="1"'))->toBe(1);
});

it('shows a permission that is in the database but no longer in the catalogue, apart', function () {
    // 🔴 Gli **orfani**. `permissions` sopravvive alle proprie definizioni: il
    // seeder usa `firstOrCreate` e non cancella mai ciò che ha smesso di
    // conoscere. Non è teorico — i `letture_contaore.*`, tolti dalla config in
    // S3-bis (ADR-019), restarono attaccati a quattro ruoli del DB di sviluppo
    // fino all'8 Ago 2026.
    //
    // ⚠️ Anche questo caso va **costruito**: sotto il seeder non esistono orfani,
    // e il test sarebbe verde per assenza di caso.
    //
    // Il fatto che deve reggere è duplice: l'orfano **si vede** (una pagina che
    // lo nascondesse direbbe che il database è pulito quando non lo è) e **non
    // sta fra le 54 righe** (mescolarcelo lo legittimerebbe, e nel Blocco 4
    // gli darebbe un controllo che `MatriceRuoli` poi rifiuta).
    $orfano = Permission::create(['name' => 'letture_contaore.view', 'guard_name' => 'web']);
    Role::findByName('Tecnico', 'web')->givePermissionTo($orfano);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Rbac::permissions())->not->toContain('letture_contaore.view');

    $html = ($this->griglia)();

    expect($html)->toContain('data-orfani')
        ->and($html)->toContain('data-orfano="letture_contaore.view"')
        // 🔴 L'assert che rende rossa la costruzione delle righe da
        // `Permission::all()`: l'orfano non ha una riga nella griglia.
        ->and($html)->not->toContain('wire:key="permesso-letture_contaore.view"')
        ->and(substr_count($html, 'wire:key="permesso-'))->toBe(count(Rbac::permissions()));
});

it('shows which roles still hold an orphan permission', function () {
    // Il complemento del test qui sopra, separato perché prova un'altra cosa:
    // non «l'orfano sta a parte» ma «la striscia dice il vero». Chi deve ripulire
    // a mano ha bisogno di sapere *a chi* è ancora attaccato prima di cancellare,
    // perché `Permission::delete()` cascata su `role_has_permissions` **e**
    // `model_has_permissions`.
    $orfano = Permission::create(['name' => 'letture_contaore.view', 'guard_name' => 'web']);
    Role::findByName('Tecnico', 'web')->givePermissionTo($orfano);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $html = ($this->griglia)();

    preg_match('/<tr wire:key="orfano-letture_contaore\.view".*?<\/tr>/s', $html, $blocco);

    expect($blocco[0] ?? '')->not->toBe('');

    preg_match_all('/data-ruolo="([^"]+)"\s+data-stato="([^"]+)"/', $blocco[0], $celle, PREG_SET_ORDER);

    $conOrfano = [];

    foreach ($celle as [, $ruolo, $stato]) {
        if ($stato === 'si') {
            $conOrfano[] = $ruolo;
        }
    }

    expect($celle)->toHaveCount(count(Rbac::roleNames()))
        ->and($conOrfano)->toBe(['Tecnico']);
});

it('shows no orphan strip when the database is clean', function () {
    // Il rovescio, e non è cortesia: una sezione «⚠️ permessi non più nel
    // catalogo» sempre presente e sempre vuota insegna a non guardarla, e il
    // giorno in cui contiene qualcosa nessuno la vede. Il caso normale — subito
    // dopo il bootstrap — è che di orfani non ce ne siano.
    $html = ($this->griglia)();

    expect(array_diff(Permission::query()->pluck('name')->all(), Rbac::permissions()))->toBe([])
        ->and($html)->not->toContain('data-orfani')
        ->and($html)->not->toContain('Permessi non più nel catalogo');
});

// ─── Il confine con la regola ────────────────────────────────────────────────

it('leaves the matrix untouched by merely looking at it', function () {
    // Una griglia in sola lettura è una promessa, e le promesse in questo
    // progetto si congelano. Rendere la pagina non deve scrivere nulla: né sul
    // pivot, né nel registro di audit. Vale soprattutto **adesso**, perché nel
    // Blocco 4 arriveranno le scritture e il modo più facile di sbagliarle è
    // farle partire da `render()`.
    $prima = MatriceRuoli::stato();

    ($this->griglia)();

    expect(MatriceRuoli::stato())->toEqual($prima);
});

it('reads the database, not the configuration it was seeded from', function () {
    // 🔴 **L'invariante su cui poggia l'intera pagina**, e la sola che questo
    // file non poteva provare: subito dopo il seeder, DB e `config/rbac.php`
    // coincidono, quindi confrontare il reso con `Rbac::permissionsForRole()`
    // non distingue le due sorgenti. Verificato: sostituendo la lettura della
    // matrice con `in_array($permesso, Rbac::permissionsForRole($ruolo))` questo
    // file restava **tutto verde** — cioè una pagina che esiste per dire *cosa
    // c'è scritto adesso* avrebbe potuto leggere da una copia congelata al
    // rilascio, e nessuno se ne sarebbe accorto finché qualcuno non avesse
    // creduto a una riga sbagliata.
    //
    // Le due direzioni contano entrambe: un permesso **aggiunto** dopo il seed e
    // uno **tolto**. Con la sola aggiunta, una lettura dalla config sbaglierebbe
    // solo per difetto e potrebbe passare per un ritardo di cache.
    $aggiunto = 'strumenti.delete';
    $tolto = collect(Rbac::permissionsForRole('Tenant'))->first();

    expect(Rbac::permissionsForRole('Tecnico'))->not->toContain($aggiunto)
        ->and($tolto)->not->toBeNull();

    MatriceRuoli::concedi('Tecnico', $aggiunto);
    MatriceRuoli::revoca('Tenant', $tolto);

    $html = ($this->griglia)();

    $stato = function (string $html, string $permesso, string $ruolo): string {
        preg_match(
            '/data-ruolo="'.preg_quote($ruolo, '/').'"\s+data-stato="([^"]+)"/',
            rigaDelPermesso($html, $permesso),
            $m
        );

        return $m[1] ?? '';
    };

    // La griglia segue il database in **entrambe** le direzioni, e in entrambe
    // dice il contrario della config.
    expect($stato($html, $aggiunto, 'Tecnico'))->toBe('si')
        ->and($stato($html, $tolto, 'Tenant'))->toBe('no');
});

it('keeps the page cost flat, whatever the size of the matrix', function () {
    // ⚠️ **Il conteggio di `MatriceRuoli::stato()` non copre la pagina**, e la
    // differenza non è teorica: verificato che leggendo `hasPermissionTo()` per
    // cella il test in isolamento resta **verde** mentre la pagina passa da 4
    // query a **652** — e va in errore 500 al primo permesso non seminato.
    // Quello congela il metodo, questo congela la schermata.
    //
    // ⚠️ Si misura **a caldo**: il primo montaggio riscalda la cache dei
    // permessi di spatie, che da sola vale quattro query. Senza il giro di
    // riscaldamento il numero dipenderebbe dall'ordine dei test, non dal codice.
    ($this->griglia)();

    $conta = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->griglia)();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $base = $conta();

    // ⚠️ **Piattezza e tetto sono due reti diverse, e servono entrambe.** La
    // piattezza (sotto) prende chi fa una query per **cella accesa**; non prende
    // chi ne fa una per **cella**, punto: con `hasPermissionTo()` per cella la
    // pagina costa 652 query *costanti*, quindi il confronto prima/dopo resta
    // verde. Verificato. Il tetto è quello che prende quel caso.
    //
    // Un tetto e non un numero esatto, ed è una deviazione dichiarata dallo stile
    // di questo progetto («l'insieme esatto, non ≤»): qui il numero vero dipende
    // anche dal **layout** — il conteggio delle notifiche non lette — cioè da
    // codice che non è di questa pagina, e un esatto renderebbe rosso questo
    // test per una modifica alla campanella. Ciò che va difeso è l'ordine di
    // grandezza: una griglia da 324 caselle deve costare una manciata di query,
    // non centinaia.
    // 🗓️ **Il tetto torna a 12, il 28 Ago 2026.** Era stato alzato a 14 per
    // ospitare una query in più che la campanella aveva cominciato a fare su
    // ogni pagina — un tentativo di curare la tendina che non si apriva. La
    // cura era sbagliata, è stata annullata, e il costo se n'è andato con lei:
    // un tetto alzato per una modifica poi ritirata è debito che nessuno
    // ricorda di restituire.
    expect($base)->toBeLessThan(12);

    // La matrice cresce di venti celle accese: il costo non deve muoversi di
    // una query, perché non dipende dal numero di celle ma dal numero di query
    // fisse. È la proprietà che rende sostenibile una griglia da 324 caselle.
    foreach (array_slice(Rbac::permissions(), 0, 20) as $permesso) {
        if (! Rbac::isLocked($permesso) && ! in_array($permesso, Rbac::permissionsForRole('Tecnico'), true)) {
            MatriceRuoli::concedi('Tecnico', $permesso);
        }
    }

    // ⚠️ **E un secondo riscaldamento dopo le scritture**, o si misura la cosa
    // sbagliata: ogni `concedi()` invalida la cache dei permessi di spatie, e il
    // montaggio successivo la ricostruisce spendendo **due** query in più. La
    // prima stesura di questo test contava 11 prima e 13 dopo e sembrava
    // dimostrare che il costo cresce con la matrice: cresceva con la *freddezza
    // della cache*, che è un costo reale ma pagato **una volta per modifica**,
    // non per cella. L'invariante che serve qui è un'altra — il costo non deve
    // dipendere da **quante caselle sono accese** — e la si misura solo a parità
    // di cache.
    ($this->griglia)();

    expect($conta())->toBe($base);
});
