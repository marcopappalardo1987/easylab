<?php

use App\Livewire\Piattaforma\RegistroAudit;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * I cinque filtri, la scorciatoia e la riga espansa (S6 — blocco 3).
 *
 * **File a sé e non in coda a `RegistroAuditTest`**: quello prova che la tabella
 * è *completa* — nessuna riga persa fra due pagine, nessun soggetto muto — cioè
 * un'unica proprietà, e le sue fixture sono costruite per quella. Qui si prova
 * il contrario: che la tabella si sappia **restringere** senza mentire. Mescolare
 * le due cose renderebbe illeggibile il `beforeEach` di entrambe.
 *
 * ⚠️ **Le factory scrivono audit da sé** (il trait `AuditsDomainWrites` logga
 * ogni `created`): dove la fixture crea un modello, `Activity::query()->delete()`
 * prima delle righe in prova, o un `assertSee` vede il dato da un'altra riga.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $this->actingAs($this->superadmin->fresh());

    Activity::query()->delete();
});

/** Gli id in pagina, che è l'unico modo di distinguere righe con la stessa descrizione. */
function idsInPagina(Testable $test): array
{
    return $test->viewData('righe')->pluck('id')->sort()->values()->all();
}

/**
 * Una riga del registro, con quel che serve al test e niente altro.
 *
 * ⚠️ Non `rigaAudit()`: le funzioni definite in un file di test Pest sono
 * **globali** su tutta la suite, e quel nome è già di `SoggettiAuditTest` — che
 * per giunta ha un default diverso (`event => 'updated'`, qui sbagliato metà
 * delle volte). Il fatal di ridichiarazione arriva solo lanciando la suite
 * intera, mai il singolo file.
 */
function rigaDelRegistro(array $attributi = []): Activity
{
    return Activity::create(array_merge([
        'log_name' => AuditLog::NAME,
        'description' => 'Riga',
    ], $attributi));
}

// ─────────────────────────────────────────────────────────────────────────────
// Il dettaglio della riga espansa
// ─────────────────────────────────────────────────────────────────────────────

it('never renders an empty detail box', function () {
    // 🔴 Le tre righe qui sotto sono i tre modi in cui «non c'è niente» si
    // presenta: colonne a NULL, colonne a `{}`, e un contenuto che la denylist
    // svuota per intero.
    //
    // ⚠️ Ciò che questo test protegge è `vuoto()` e la guardia del Blade che lo
    // interroga, **non** il `blank()` dentro la classe: quello collassa sullo
    // stesso array vuoto in entrambi i rami, quindi sostituirlo con `!== null`
    // non lo rende rosso — verificato. Chi cerca la mutazione che morde la trova
    // su `vuoto()`.
    $nuda = rigaDelRegistro(['description' => 'Login fallito']);
    $collezioniVuote = rigaDelRegistro(['description' => '2FA confermato', 'properties' => [], 'attribute_changes' => []]);
    // Tutto oscurato: la scatola sarebbe tecnicamente non vuota e utilmente
    // muta. Vale come «niente», o la denylist creerebbe da sé il caso che
    // questo test vieta.
    $soloSegreti = rigaDelRegistro(['description' => 'Reset', 'properties' => ['password' => 'Segret1ssima!']]);
    $conDettaglio = rigaDelRegistro(['description' => 'Login', 'properties' => ['ip' => '10.0.0.7']]);

    $vista = Livewire::test(RegistroAudit::class);

    $vista->assertDontSeeHtml('espandi('.$nuda->id.')')
        ->assertDontSeeHtml('espandi('.$collezioniVuote->id.')')
        ->assertDontSeeHtml('espandi('.$soloSegreti->id.')')
        // …e il controllo positivo, o il test sarebbe verde su un pulsante che
        // non compare mai.
        ->assertSeeHtml('espandi('.$conDettaglio->id.')');

    // Anche forzando la property dal browser la scatola non si apre: la
    // condizione sta in un posto solo, ed è la stessa che nasconde il pulsante.
    Livewire::test(RegistroAudit::class)
        ->set('espanso', $nuda->id)
        ->assertDontSeeHtml('id="dettaglio-'.$nuda->id.'"');
});

it('never drops a field present on one side only', function () {
    // 🔴 Non è un caso limite teorico: `LogsActivity::buildChanges()` fa
    // `unset($properties['attributes'])` sull'evento `deleted` e non scrive
    // `old` sul `created`. Iterare un lato solo renderebbe **muta** ogni riga di
    // eliminazione, cioè quelle per cui un registro si apre.
    $riga = rigaDelRegistro([
        'description' => 'Modifica strumento',
        'event' => 'updated',
        'attribute_changes' => [
            'attributes' => ['nome' => 'Autoclave B', 'solo_dopo' => 'comparso'],
            'old' => ['nome' => 'Autoclave A', 'solo_prima' => 'sparito'],
        ],
    ]);

    Livewire::test(RegistroAudit::class)
        ->call('espandi', $riga->id)
        ->assertSee('nome')
        ->assertSee('Autoclave A')
        ->assertSee('Autoclave B')
        // Il campo che sta solo fra i nuovi valori…
        ->assertSee('solo_dopo')
        ->assertSee('comparso')
        // …e quello che sta solo fra i vecchi.
        ->assertSee('solo_prima')
        ->assertSee('sparito');

    // La forma vera di un'eliminazione: `old` senza `attributes`.
    $eliminata = rigaDelRegistro([
        'description' => 'Eliminazione garanzia',
        'event' => 'deleted',
        'attribute_changes' => ['old' => ['durata_mesi' => 24]],
    ]);

    Livewire::test(RegistroAudit::class)
        ->call('espandi', $eliminata->id)
        ->assertSee('durata_mesi')
        ->assertSee('24');
});

it('never renders a redacted key', function () {
    // 🔴 Il valore di un segreto non deve uscire dal database per **nessuna**
    // strada: né in una cella, né dentro un JSON annidato, né in un attributo.
    // La voce si toglie del tutto — chiave compresa — perché un «(oscurato)»
    // lascerebbe in piedi una scatola che dice solo «c'era qualcosa».
    $riga = rigaDelRegistro([
        'description' => 'Riga con segreti',
        'properties' => [
            'ip' => '10.0.0.7',
            'password' => 'Segret1ssima',
            'API_Token' => 'tokABCDEF',
            'stripe_secret' => 'sklive000',
            // Annidato: la denylist di primo livello non lo vedrebbe, e il JSON
            // lo porterebbe in pagina per intero.
            'payload' => ['remember_token' => 'rememberXYZ', 'guard' => 'web'],
        ],
    ]);

    $vista = Livewire::test(RegistroAudit::class)->call('espandi', $riga->id);

    // I valori, mai.
    $vista->assertDontSee('Segret1ssima')
        ->assertDontSee('tokABCDEF')
        ->assertDontSee('sklive000')
        ->assertDontSee('rememberXYZ')
        // Le chiavi, nemmeno.
        ->assertDontSee('password')
        ->assertDontSee('API_Token')
        ->assertDontSee('stripe_secret')
        ->assertDontSee('remember_token')
        // Il controllo positivo: la scatola c'è ed è utile, non è muta perché
        // il dettaglio non si è mai aperto.
        ->assertSee('10.0.0.7')
        ->assertSee('web');
});

// ─────────────────────────────────────────────────────────────────────────────
// I filtri
// ─────────────────────────────────────────────────────────────────────────────

it('groups without reading description', function () {
    // 🔴 ⚠️ **Non** col refuso storico e non con una descrizione parlante: quel
    // refuso è stato corretto e la factory produce ormai la descrizione nuova,
    // quindi un test costruito lì sarebbe verde provando il **contrario** del
    // proprio nome. Qui le due righe hanno la **stessa** descrizione arbitraria:
    // nessun predicato su `description` può distinguerle, quindi riscrivere il
    // filtro su `description LIKE` fa cadere questo test e nessun altro.
    $crud = rigaDelRegistro(['description' => 'Zibaldone', 'event' => 'created']);
    $atto = rigaDelRegistro(['description' => 'Zibaldone']);

    expect(idsInPagina(Livewire::test(RegistroAudit::class)->set('azione', 'created')))
        ->toBe([$crud->id])
        ->and(idsInPagina(Livewire::test(RegistroAudit::class)->set('azione', RegistroAudit::ATTI)))
        ->toBe([$atto->id]);
});

it('asks for the acts with a sentinel, which an empty value could not', function () {
    // `''` significa già «tutte le azioni»: senza una sentinella, «solo gli
    // atti» — le diciotto `activity()` esplicite, che nessuno marca con
    // `->event()` — non sarebbe una domanda esprimibile.
    $atto = rigaDelRegistro(['description' => 'Semaforo forzato']);
    $crud = rigaDelRegistro(['description' => 'Modifica strumento', 'event' => 'updated']);

    expect(idsInPagina(Livewire::test(RegistroAudit::class)))
        ->toBe([$atto->id, $crud->id])
        ->and(idsInPagina(Livewire::test(RegistroAudit::class)->set('azione', RegistroAudit::ATTI)))
        ->toBe([$atto->id]);
});

it('ignores an action that is not one of the four verbs', function () {
    // `azione` arriva dalla query string e finisce in un `where`.
    $riga = rigaDelRegistro(['event' => 'updated']);

    expect(idsInPagina(Livewire::test(RegistroAudit::class)->set('azione', 'drop table')))
        ->toBe([$riga->id]);
});

it('filters by subject type, and ignores a type it cannot name', function () {
    $strumento = Strumento::factory()->forNode(UnitaOrganizzativa::factory()->ente()->create())->create();
    Activity::query()->delete();

    $suStrumento = rigaDelRegistro([
        'description' => 'Modifica strumento',
        'subject_type' => $strumento->getMorphClass(),
        'subject_id' => $strumento->id,
    ]);
    $senzaSoggetto = rigaDelRegistro(['description' => 'Login']);

    expect(idsInPagina(Livewire::test(RegistroAudit::class)->set('soggetto', Strumento::class)))
        ->toBe([$suStrumento->id])
        // Un FQCN che la mappa non conosce **non** finisce in un `where`: il
        // valore viene dalla query string.
        ->and(idsInPagina(Livewire::test(RegistroAudit::class)->set('soggetto', 'App\\Models\\Inesistente')))
        ->toBe([$suStrumento->id, $senzaSoggetto->id]);
});

it('cannot let the «Chi» filter be defeated by a wildcard', function () {
    // 🔴 Senza `addcslashes` un `%` battuto da solo restituisce **tutte** le
    // righe con un causer, e `_` diventa «un carattere qualunque»: un filtro
    // che si aggira digitando un carattere non è un filtro.
    $rossi = User::factory()->create(['name' => 'Mario Rossi', 'email' => 'mario@rossi.test']);
    $bianchi = User::factory()->create(['name' => 'Anna Bianchi', 'email' => 'anna@bianchi.test']);
    Activity::query()->delete();

    $suoRossi = rigaDelRegistro(['causer_type' => $rossi->getMorphClass(), 'causer_id' => $rossi->id]);
    rigaDelRegistro(['causer_type' => $bianchi->getMorphClass(), 'causer_id' => $bianchi->id]);

    $per = fn (string $termine) => idsInPagina(Livewire::test(RegistroAudit::class)->set('chi', $termine));

    expect($per('rossi'))->toBe([$suoRossi->id])
        // Anche per email, che è come si cerca una persona di cui non si ricorda
        // l'ortografia del cognome.
        ->and($per('mario@rossi'))->toBe([$suoRossi->id])
        ->and($per('%'))->toBe([])
        ->and($per('_'))->toBe([])
        ->and($per('%rossi%'))->toBe([]);
});

it('keeps the whole «Al» day inside the range, midnight included', function () {
    // 🔴 Il confine è **mezzo aperto**: `>= dal` e `< al + 1 giorno`.
    //
    // ⚠️ Le due mutazioni sbagliano in direzioni diverse e nessuna delle due si
    // vede su entrambi i driver: `<= $al` taglia via tutta la giornata di `$al`
    // tranne la sua mezzanotte (perché `created_at` è un timestamp e la data
    // nuda vale `00:00:00`), mentre `whereDate()` dà il **risultato giusto** e
    // il piano sbagliato — avvolge la colonna in `strftime`/`::date`, rende
    // inutilizzabile l'indice `(created_at, id)` e cambia semantica col driver.
    // Perciò qui si asserisce **anche sull'SQL**: su SQLite le date sono
    // stringhe e i confronti lessicografici, quindi il solo esito potrebbe
    // essere verde per la ragione sbagliata.
    $prima = rigaDelRegistro(['description' => 'Fuori, prima', 'created_at' => '2026-08-17 23:59:59', 'updated_at' => '2026-08-17 23:59:59']);
    $apertura = rigaDelRegistro(['description' => 'Dentro, apertura', 'created_at' => '2026-08-18 00:00:00', 'updated_at' => '2026-08-18 00:00:00']);
    $mezzanotteAl = rigaDelRegistro(['description' => 'Dentro, mezzanotte di Al', 'created_at' => '2026-08-20 00:00:00', 'updated_at' => '2026-08-20 00:00:00']);
    $seraAl = rigaDelRegistro(['description' => 'Dentro, sera di Al', 'created_at' => '2026-08-20 23:59:59', 'updated_at' => '2026-08-20 23:59:59']);
    $dopo = rigaDelRegistro(['description' => 'Fuori, giorno dopo', 'created_at' => '2026-08-21 00:00:00', 'updated_at' => '2026-08-21 00:00:00']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $vista = Livewire::test(RegistroAudit::class)->set('dal', '2026-08-18')->set('al', '2026-08-20');
    $sql = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $q) => str_contains($q, 'from "activity_log"'))
        ->values();
    DB::disableQueryLog();

    expect(idsInPagina($vista))->toBe([$apertura->id, $mezzanotteAl->id, $seraAl->id])
        ->and(idsInPagina($vista))->not->toContain($prima->id)
        ->and(idsInPagina($vista))->not->toContain($dopo->id)
        ->and($sql)->not->toBeEmpty();

    // Nessuna funzione sulla colonna: né `strftime(...)` (SQLite) né
    // `"created_at"::date` (Postgres), che è ciò che `whereDate()` emette.
    foreach ($sql as $query) {
        expect($query)->not->toContain('strftime')
            ->and($query)->not->toContain('date(')
            ->and($query)->not->toContain('::date');
    }
});

it('ignores a date it cannot read instead of throwing', function () {
    // Il valore arriva dalla query string: `?dal=ieri` deve mostrare il registro
    // senza quel filtro, non una pagina di errore.
    $riga = rigaDelRegistro();

    expect(idsInPagina(Livewire::test(RegistroAudit::class)->set('dal', 'ieri')->set('al', '')))
        ->toBe([$riga->id]);
});

it('searches the description, and that is the only unindexed predicate', function () {
    $unito = rigaDelRegistro(['description' => 'Unito il ricambio «Guarnizione» in «Guarnizione OR»']);
    rigaDelRegistro(['description' => 'Login fallito']);

    expect(idsInPagina(Livewire::test(RegistroAudit::class)->set('cerca', 'guarnizione')))
        ->toBe([$unito->id])
        // I jolly si neutralizzano anche qui: la ricerca non è una via d'uscita
        // dal resto dei filtri.
        ->and(idsInPagina(Livewire::test(RegistroAudit::class)->set('cerca', '%')))
        ->toBe([]);
});

it('reaches the rows that no person filter could reach', function () {
    // Login falliti, console, webhook, coda: le righe che contano di più in un
    // registro di sicurezza sono proprio quelle senza un utente da cercare.
    $utente = User::factory()->create(['name' => 'Chi Agisce']);
    Activity::query()->delete();

    $anonima = rigaDelRegistro(['description' => 'Login fallito']);
    rigaDelRegistro(['description' => 'Login', 'causer_type' => $utente->getMorphClass(), 'causer_id' => $utente->id]);

    expect(idsInPagina(Livewire::test(RegistroAudit::class)->set('senzaUtente', true)))
        ->toBe([$anonima->id]);
});

it('closes the open row when a filter changes', function () {
    // Una riga espansa che i nuovi filtri non lasciano in elenco resterebbe a
    // schermo come una scheda senza la propria intestazione.
    $riga = rigaDelRegistro(['description' => 'Login', 'properties' => ['ip' => '10.0.0.7']]);

    Livewire::test(RegistroAudit::class)
        ->call('espandi', $riga->id)
        ->assertSet('espanso', $riga->id)
        ->set('cerca', 'tutt\'altro')
        ->assertSet('espanso', null);
});

it('resets the page when a filter changes', function () {
    foreach (range(1, 30) as $n) {
        rigaDelRegistro(['description' => 'Riga '.$n]);
    }

    Livewire::test(RegistroAudit::class)
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('senzaUtente', true)
        ->assertSet('paginators.page', 1);
});

// ─────────────────────────────────────────────────────────────────────────────
// L'espansione riapre con la porta
// ─────────────────────────────────────────────────────────────────────────────

it('refuses to expand a row of another log channel', function () {
    // 🔴 Il pavimento `log_name` vive **dentro** `VistaPiattaforma::audit()`:
    // rileggere di lì è ciò che rende irraggiungibile una riga che la tabella
    // non mostrerebbe mai. Senza la rilettura, `espandi()` accetterebbe
    // qualunque id — e il giorno in cui l'espansione mostrerà un dato letto per
    // conto proprio, lo mostrerebbe di un'altra riga.
    $altrove = Activity::create(['log_name' => 'default', 'description' => 'Rumore di un altro canale', 'properties' => ['ip' => '10.0.0.7']]);

    expect(fn () => Livewire::test(RegistroAudit::class)->call('espandi', $altrove->id))
        ->toThrow(ModelNotFoundException::class);

    // ⚠️ E l'altra strada: `espanso` è una property pubblica, e Livewire ne
    // accetta l'update dal browser **senza passare da `espandi()`**. Le due
    // strade devono dire la stessa cosa, o l'invariante sembra chiusa e non lo è.
    expect(fn () => Livewire::test(RegistroAudit::class)->set('espanso', $altrove->id))
        ->toThrow(ModelNotFoundException::class);
});

it('opens and closes a row of its own channel', function () {
    $riga = rigaDelRegistro(['description' => 'Login', 'properties' => ['ip' => '10.0.0.7']]);

    Livewire::test(RegistroAudit::class)
        ->call('espandi', $riga->id)
        ->assertSet('espanso', $riga->id)
        ->assertSee('10.0.0.7')
        ->call('espandi', $riga->id)
        ->assertSet('espanso', null)
        ->assertDontSee('10.0.0.7');

    // Chiudere resta possibile anche dalla property.
    Livewire::test(RegistroAudit::class)->set('espanso', null)->assertSet('espanso', null);
});

it('never repeats in the detail box what the «Chi» column already says by name', function () {
    // 🔴 Il timbro dell'impersonazione sta su **ogni** riga scritta durante
    // un'impersonazione (blocco 0), e la tabella lo rende come «per conto di
    // <nome>» spendendo una query apposta per non mostrare un id nudo.
    //
    // Su una riga esplicita che non porta altre `properties` — «2FA abilitato»,
    // «Logout» — il timbro sarebbe l'**unico** contenuto della scatola: la
    // freccia si offrirebbe per aprirsi su un numero opaco, che è il caso che
    // `vuoto()` esiste per impedire, rientrato da un'altra porta.
    $developer = User::factory()->create(['name' => 'Developer EasyLab']);
    $impersonato = User::factory()->create(['name' => 'Anna Rossi']);

    Activity::query()->delete();

    $solo = rigaDelRegistro([
        'description' => '2FA abilitato',
        'causer_type' => $impersonato->getMorphClass(),
        'causer_id' => $impersonato->id,
        'properties' => ['impersonato_da' => $developer->id],
    ]);
    $anche = rigaDelRegistro([
        'description' => 'Login',
        'properties' => ['impersonato_da' => $developer->id, 'ip' => '10.0.0.7'],
    ]);

    $vista = Livewire::test(RegistroAudit::class);

    // La riga col solo timbro non offre l'espansione; quella che porta anche un
    // fatto proprio sì — ma senza il timbro dentro.
    $vista->assertDontSeeHtml('wire:click="espandi('.$solo->id.')"')
        ->assertSeeHtml('wire:click="espandi('.$anche->id.')"');

    $html = $vista->call('espandi', $anche->id)->html();

    expect($html)->toContain('10.0.0.7')
        ->and($html)->not->toContain('impersonato_da')
        // Il nome, non l'id: è la colonna «Chi» a dirlo, ed è il punto.
        ->and($html)->toContain('per conto di Developer EasyLab');
});

it('never blames filters that are not there', function () {
    // Un registro genuinamente vuoto e una ricerca che non trova mandano a fare
    // due cose opposte: la prima ad aspettare che qualcosa succeda, la seconda a
    // togliere un filtro. Un messaggio solo manda a cercare un filtro che non c'è.
    Livewire::test(RegistroAudit::class)
        ->assertSee('Nessuna riga nel registro.')
        ->assertDontSee('Nessuna riga con questi filtri.');

    // Con un filtro che ha **davvero** ristretto, il messaggio cambia.
    Livewire::test(RegistroAudit::class)
        ->set('cerca', 'introvabile')
        ->assertSee('Nessuna riga con questi filtri.')
        ->assertDontSee('Nessuna riga nel registro.');

    // ⚠️ E un valore **rifiutato** dalla whitelist non conta come filtro: non ha
    // ristretto niente, quindi dare a lui la colpa del vuoto spiegherebbe la
    // pagina con una causa che non esiste.
    Livewire::test(RegistroAudit::class)
        ->set('soggetto', 'App\\Models\\NonEsiste')
        ->assertSee('Nessuna riga nel registro.');
});
