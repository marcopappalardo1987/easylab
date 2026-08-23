<?php

use App\Livewire\Piattaforma\EditorRuoli;
use App\Support\Rbac;
use App\Support\Rbac\MatriceRuoli;
use App\Support\Tenancy\VistaPiattaforma;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 🔴 La riconciliazione fra il **database** e `config/rbac.php` (S6 — ADR-016).
 *
 * È l'ultima tappa dell'editor, e nasce da un fatto che le tappe precedenti
 * hanno **creato**: da quando la matrice si modifica da una pagina web, la
 * config smette di essere la verità e diventa il *default*. Le due sorgenti
 * divergono per costruzione — la divergenza è la feature — e ciò che è
 * pericoloso è che sia invisibile, perché `CLAUDE.md` ordina di riseminare dopo
 * ogni modifica a `config/rbac.php` e `RolesAndPermissionsSeeder` fa
 * `syncPermissions()`, cioè **detacha tutto e riattacca dai default**. Eseguito
 * alla lettera, quell'ordine *corretto* cancella la matrice di runtime.
 *
 * Questo file prova la metà in pagina: le celle divergenti si vedono, dicono
 * **entrambi** i valori, e si isolano con un interruttore. L'altra metà — il
 * seeder che stampa ciò che sta per portare via e ne lascia una riga nel
 * registro — è in `tests/Feature/ResetMatriceRuoliTest.php`.
 *
 * ⚠️ Come `GrigliaRuoliTest`, prova la **resa** e non la protezione: la guardia
 * vive in `App\Support\Rbac\MatriceRuoli`. Qui non si difende niente — si
 * *mostra* qualcosa, ed è il contrario di una guardia: un marcatore mancante non
 * apre una porta, fa prendere una decisione sbagliata a chi lancia un comando
 * distruttivo.
 *
 * 🔗 `CLAUDE.md` («le modifiche a `config/rbac.php` vanno riseminate»), ADR-016 §7
 * (il seeder come reset ai default), ADR-019 (i `letture_contaore.*` orfani).
 */
/** Il riquadro della riconciliazione, isolato: fuori di lì i numeri si ripetono. */
function pannelloRiconciliazione(string $html): string
{
    preg_match('/data-riconciliazione="[^"]*".*?<\/div>\s*<\/div>/s', $html, $m);

    return $m[0] ?? '';
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->griglia = fn (): string => $this->actingAs(utenteConRuolo('Superadmin'))
        ->get(route('piattaforma.ruoli'))
        ->assertOk()
        ->getContent();
});

/** La cella di un ruolo dentro la riga di un permesso. */
function cellaDelRuolo(string $riga, string $ruolo): string
{
    preg_match('/<td data-ruolo="'.preg_quote($ruolo, '/').'".*?<\/td>/s', $riga, $blocco);

    return $blocco[0] ?? '';
}

/** La cella `[$permesso][$ruolo]` della griglia intera. */
function cella(string $html, string $permesso, string $ruolo): string
{
    return cellaDelRuolo(rigaDelPermesso($html, $permesso), $ruolo);
}

// ─── Il marcatore ────────────────────────────────────────────────────────────

it('marks a cell as customised when the database and the config disagree', function () {
    // 🔴 **Il pannello che `CLAUDE.md` riga 47 chiede di fare a mano.** «Prima di
    // riseminare, confrontare ruolo per ruolo DB e config per accertarsi che non
    // si perdano personalizzazioni»: un confronto su 324 celle che nessuno fa
    // davvero, e che qui costa uno sguardo.
    //
    // Le **due direzioni** contano entrambe, e una sola non basterebbe: una
    // concessione fatta dalla UI (il database dice sì, la config no) e una revoca
    // (la config dice sì, il database no). Con la sola concessione, un confronto
    // scritto al contrario — `config \ database` invece di `database ≠ config` —
    // resterebbe verde su metà dei casi.
    $concesso = 'strumenti.delete';
    $revocato = collect(Rbac::permissionsForRole('Tenant'))->first();

    expect(Rbac::permissionsForRole('Tecnico'))->not->toContain($concesso)
        ->and($revocato)->not->toBeNull();

    MatriceRuoli::concedi('Tecnico', $concesso);
    MatriceRuoli::revoca('Tenant', $revocato);

    $html = ($this->griglia)();

    // ⚠️ **Sul testo visibile e non sul solo `data-*`.** È il difetto trovato due
    // volte in questo stesso lavoro (la griglia del blocco 3, l'avviso 2FA del
    // blocco 4): un attributo che nessun CSS e nessun JS legge esiste
    // *unicamente* per il test, quindi asserire solo su di lui misura una copia
    // privata e lascia libera la frase che l'operatore legge davvero.
    //
    // E il marcatore mostra **i due valori**, non solo il fatto che divergano:
    // «personalizzato» da solo obbligherebbe ad aprire `config/rbac.php` per
    // sapere dove il riseeding riporterebbe la cella, cioè a rifare a mano metà
    // del confronto che questo pannello esiste per togliere.
    expect(cella($html, $concesso, 'Tecnico'))->toContain('data-personalizzato="concesso"')
        ->and(cella($html, $concesso, 'Tecnico'))->toContain('personalizzato')
        ->and(cella($html, $concesso, 'Tecnico'))->toContain('config no')
        ->and(cella($html, $concesso, 'Tecnico'))->toContain('adesso sì')
        ->and(cella($html, $revocato, 'Tenant'))->toContain('data-personalizzato="revocato"')
        ->and(cella($html, $revocato, 'Tenant'))->toContain('config sì')
        ->and(cella($html, $revocato, 'Tenant'))->toContain('adesso no');

    // La metà che rende falsificabile la prima: le celle **d'accordo** non
    // portano il marcatore. Senza, un Blade che marcasse tutto passerebbe — e
    // «tutto personalizzato» è indistinguibile da «niente personalizzato» per
    // chi deve decidere se lanciare il reset.
    expect(cella($html, $concesso, 'Tenant'))->not->toContain('data-personalizzato')
        ->and(cella($html, $revocato, 'Admin'))->not->toContain('data-personalizzato')
        ->and(substr_count($html, 'data-personalizzato="'))->toBe(2)
        // E il conteggio in cima, che è la cifra su cui si decide.
        ->and($html)->toContain('data-riconciliazione="2"')
        // 🔴 **E sul testo che l'operatore legge davvero.** `data-riconciliazione`
        // esiste unicamente per questo test: nessun CSS e nessun JS lo consuma.
        // Verificato che, lasciando l'attributo intatto e mettendo a `999` il
        // numero reso, questo file restava tutto verde — cioè il pannello poteva
        // dire una cifra qualunque sopra il comando che distrugge. È la terza
        // volta che lo stesso difetto ricompare in questa feature, e le prime
        // due erano in blocchi che dichiaravano di esserne immuni.
        ->and(pannelloRiconciliazione($html))->toContain('<strong>2</strong>')
        ->and(pannelloRiconciliazione($html))->toContain('celle non dicono');
});

it('marks nothing at all when the database is exactly what the config says', function () {
    // Il caso normale — subito dopo il bootstrap — ed è il rovescio senza il
    // quale il test precedente non prova niente: un marcatore sempre acceso
    // sarebbe un pannello che dice «attenzione» ogni giorno, cioè un pannello che
    // si smette di leggere.
    $html = ($this->griglia)();

    expect($html)->not->toContain('data-personalizzato')
        ->and($html)->toContain('data-riconciliazione="0"')
        ->and($html)->toContain('coincide')
        // E l'interruttore non si offre quando non c'è niente da isolare.
        ->and($html)->not->toContain('data-filtro-differenze');
});

it('never calls an un-seeded permission customised', function () {
    // ⚠️ **La trappola di questo pannello**, ed è quella che un confronto scritto
    // di getto avrebbe preso in pieno. Un permesso dichiarato dal catalogo e
    // assente dal database è spento su **tutte e sei** le colonne, mentre la
    // config lo assegna a parecchi ruoli: un `database ≠ config` letterale lo
    // segnerebbe come cinque o sei **personalizzazioni deliberate**.
    //
    // Sarebbero false, e nella direzione peggiore: manderebbero a *non* lanciare
    // il seeder per paura di perdere qualcosa, mentre il seeder è precisamente il
    // gesto che ripara quella riga. Il marcatore giusto ce l'ha già — «da
    // seminare» — e dice un'altra cosa perché porta a un altro gesto.
    expect(Rbac::permissions())->toContain('spostamenti.view')
        ->and(Rbac::permissionsForRole('Admin'))->toContain('spostamenti.view');

    DB::table('permissions')->where('name', 'spostamenti.view')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $html = ($this->griglia)();

    expect(rigaDelPermesso($html, 'spostamenti.view'))->toContain('data-da-seminare="1"')
        ->and(rigaDelPermesso($html, 'spostamenti.view'))->not->toContain('data-personalizzato')
        ->and($html)->toContain('data-riconciliazione="0"')
        // E il gesto che la ripara è scritto in pagina, col comando per esteso:
        // un marcatore che non porta a un gesto lascia l'operatore a metà strada.
        ->and($html)->toContain('data-non-seminati="1"')
        ->and($html)->toContain('db:seed --class=RolesAndPermissionsSeeder');
});

it('never calls an orphan permission customised', function () {
    // La gemella dall'altro lato dell'asimmetria: una riga a database che il
    // catalogo non dichiara più non è confrontabile con la config, che non ne sa
    // niente. Ha la sua striscia a parte, e il gesto che la riguarda è una
    // rimozione a mano — non un click, e non un reset.
    $orfano = Permission::create(['name' => 'letture_contaore.view', 'guard_name' => 'web']);
    Role::findByName('Tecnico', 'web')->givePermissionTo($orfano);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $html = ($this->griglia)();

    expect($html)->toContain('data-orfano="letture_contaore.view"')
        ->and($html)->not->toContain('data-personalizzato')
        ->and($html)->toContain('data-riconciliazione="0"')
        // ⚠️ E la striscia dice cosa il reset fa e non fa **a loro**, che è la
        // domanda immediata di chi ha appena letto, due riquadri più su, che quel
        // comando riporta tutto ai default: non li cancella (il seeder usa
        // `firstOrCreate`), ma li stacca da ogni ruolo.
        ->and($html)->toContain('non</strong> li rimuove');
});

// ─── L'interruttore ──────────────────────────────────────────────────────────

it('shows only the differing rows when the switch is on', function () {
    // L'interruttore è ciò che rende il pannello usabile su 324 celle: due
    // differenze in mezzo a cinquantaquattro righe si trovano solo scorrendo, ed
    // è esattamente lo scorrimento che nessuno fa prima di lanciare un comando.
    //
    // ⚠️ Si monta il componente invece di passare dalla rotta, perché serve
    // **premere** l'interruttore: il gate della pagina ha i suoi negativi in
    // `AccessoEditorRuoliTest`, e qui si guarda la lente.
    MatriceRuoli::concedi('Tecnico', 'strumenti.delete');

    $componente = Livewire::actingAs(utenteConRuolo('Superadmin'))->test(EditorRuoli::class);

    // Spento: la griglia intera, cioè le 54 righe del catalogo.
    expect(substr_count($componente->html(), 'wire:key="permesso-'))->toBe(count(Rbac::permissions()));

    $acceso = $componente->set('soloDifferenze', true)->html();

    // Acceso: **solo** le righe che hanno almeno una cella divergente. Il
    // conteggio esatto è ciò che rende rosso un filtro che filtra a metà.
    expect(substr_count($acceso, 'wire:key="permesso-'))->toBe(1)
        ->and($acceso)->toContain('wire:key="permesso-strumenti.delete"')
        ->and(rigaDelPermesso($acceso, 'strumenti.delete'))->toContain('data-personalizzato="concesso"')
        // ⚠️ **La riga resta intera**: si filtrano le righe, non le celle. Una
        // riga mostrata a metà — solo le colonne divergenti — direbbe «il Tecnico
        // ha `strumenti.delete`» senza far vedere che l'Admin ce l'ha per
        // default, cioè toglierebbe il confronto fra colonne, che è l'unica cosa
        // che una griglia sa fare.
        ->and(substr_count(rigaDelPermesso($acceso, 'strumenti.delete'), 'data-ruolo="'))
        ->toBe(count(Rbac::roleNames()))
        // E i gruppi rimasti senza righe spariscono, invece di lasciare
        // un'intestazione vuota che si legge come un guasto.
        ->and(substr_count($acceso, 'data-gruppo="'))->toBe(1)
        ->and($acceso)->toContain('data-filtro-differenze="attivo"')
        // L'etichetta del bottone cambia col suo stato, o si preme al buio.
        ->and($acceso)->toContain('Mostra tutti i permessi');

    // E si torna indietro: l'interruttore è una lente, non uno stato in cui si
    // resta chiusi.
    expect(substr_count($componente->set('soloDifferenze', false)->html(), 'wire:key="permesso-'))
        ->toBe(count(Rbac::permissions()));
});

it('keeps a way back when the filter is on and there is nothing to filter', function () {
    // Il caso di bordo che si scopre solo usandolo: si accende l'interruttore, si
    // riporta l'ultima cella al default, e la griglia resta vuota. Senza
    // l'interruttore ancora in pagina non ci sarebbe **nessun modo** di tornare
    // alle 54 righe se non ricaricando — e una tabella con la sola intestazione
    // si legge come un guasto, non come una risposta.
    $componente = Livewire::actingAs(utenteConRuolo('Superadmin'))
        ->test(EditorRuoli::class)
        ->set('soloDifferenze', true);

    $html = $componente->html();

    expect(substr_count($html, 'wire:key="permesso-'))->toBe(0)
        ->and($html)->toContain('data-nessuna-differenza')
        // Lo stato vuoto deve **dirlo**, non solo marcarlo: una tabella vuota
        // senza spiegazione si legge come un guasto.
        ->and($html)->toContain('Nessuna cella diversa da')
        ->and($html)->toContain('data-filtro-differenze="attivo"');
});

// ─── Ciò che il pannello deve dire ───────────────────────────────────────────

it('names the command that would destroy the customisations, next to the count', function () {
    // 🔴 **Il punto di tutta la tappa.** Il pannello non esiste per far vedere che
    // qualcuno ha cambiato una cella: esiste perché la riga di `CLAUDE.md` che
    // ordina di riseminare è **corretta** e, dal rilascio di questa pagina in
    // poi, distruttiva. Il numero senza il comando accanto è una curiosità; il
    // comando senza il numero è l'istruzione che c'era già.
    MatriceRuoli::concedi('Tecnico', 'strumenti.delete');

    $html = ($this->griglia)();

    preg_match('/data-riconciliazione="1".*?<\/div>/s', $html, $pannello);

    expect($pannello[0] ?? '')->not->toBe('')
        ->and($pannello[0])->toContain('db:seed --class=RolesAndPermissionsSeeder')
        ->and($pannello[0])->toContain('syncPermissions')
        // «detacha e riattacca, non fonde»: è il fatto meccanico da cui dipende
        // tutto il resto, e va scritto dove si decide.
        ->and($pannello[0])->toContain('non fonde');
});

it('keeps the page and the seeder telling the same story, orphans included', function () {
    // 🔴 **Il buco che si vede solo guardando i cinque blocchi insieme.** Il
    // diff esiste in **due implementazioni indipendenti**: questa pagina lo
    // calcola da `MatriceRuoli::stato()` + `Rbac::permissionsForRole()`, il
    // seeder da `$role->permissions()->pluck('name')` + la stessa config. Sono i
    // due posti il cui unico scopo è dire all'operatore *la stessa cosa*, prima
    // e durante lo stesso comando distruttivo — e `CLAUDE.md` manda a decidere
    // **se** riseminare guardando la pagina.
    //
    // Divergono di proposito su un caso, e la divergenza va **congelata**, non
    // lasciata a un commento: un permesso **orfano** attaccato a un ruolo è per
    // il seeder una revoca da elencare, e per la pagina non è una
    // personalizzazione (la riga non è del catalogo, e segnarla manderebbe a non
    // riseminare per paura). Se un giorno un lato cambia senza l'altro, questo
    // test diventa rosso invece di lasciare due numeri che si contraddicono.
    $orfano = Permission::create(['name' => 'letture_contaore.view', 'guard_name' => 'web']);
    Role::findByName('Tecnico', 'web')->permissions()->attach($orfano->getKey());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // **La pagina**: zero personalizzazioni, e lo dice anche a parole.
    $html = ($this->griglia)();

    expect($html)->toContain('data-riconciliazione="0"')
        ->and(pannelloRiconciliazione($html))->toContain('coincide');

    // **Il seeder**: una revoca da elencare, sullo stesso identico stato.
    Activity::query()->delete();
    $this->artisan('db:seed', ['--class' => RolesAndPermissionsSeeder::class]);

    $this->actingAs(utenteConRuolo('Superadmin'));

    $riga = VistaPiattaforma::audit()
        ->where('description', 'Matrice ruoli riportata ai default')
        ->firstOrFail();

    expect($riga->properties->get('revocati dal reset'))->toContain('letture_contaore.view');

    // ⚠️ E la pagina non tace: lo dice **nell'altro riquadro**, quello degli
    // orfani. È questo che rende difendibile la divergenza — il numero in cima
    // resta 0 perché non c'è niente da decidere, e il fenomeno è spiegato dove
    // porta al gesto giusto (toglierlo a mano, non riseminare).
    expect($html)->toContain('letture_contaore.view');
});
