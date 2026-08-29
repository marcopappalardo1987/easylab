<?php

use App\Livewire\Piattaforma\ParcoRicambi;
use App\Models\Account;
use App\Models\Ricambio;
use App\Models\Scopes\GaranziaRicambioPrivacyScope;
use App\Models\Scopes\TenantScope;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Piattaforma\ParcoClienti;
use App\Support\Piattaforma\Perimetro;
use App\Support\Piattaforma\RicambiDelParco;
use App\Support\Tenancy\VistaPiattaforma;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * 🔴 Parco clienti — scheda **Ricambi** (🔗 ADR-037), area rossa.
 *
 * La scheda elenca il catalogo pezzi di **tutti** i clienti nel perimetro, cioè
 * righe di Enti che non sono quello di chi guarda. L'isolamento qui non è più
 * una proprietà del framework — il global scope è tolto di proposito dentro
 * `ParcoClienti` — ma una proprietà del **codice**: va difesa con dei test, e i
 * negativi contano più dei positivi.
 *
 * Le quattro specie di negativo, e nessuna copre le altre:
 *
 *   1. **Il permesso**: chi non ha `tenants.view_all` prende un 403, non una
 *      pagina vuota. E `utenti.impersonate` è un permesso **diverso**: stare in
 *      questa pagina non è poter entrare in casa di un cliente.
 *   2. **Il perimetro**: arriva dal browser, quindi ogni forma di forgiatura —
 *      selezione vuota, id inventato, id dell'account di piattaforma, modo
 *      inesistente, piano fuori catalogo — deve **restringere**, mai allargare.
 *   3. **La privacy dei ricambi** (🔗 ADR-029): questa scheda non deve mostrare
 *      per vie traverse né garanzie né utilizzi. ⛔ E la difesa è il **non-uso**,
 *      non uno scope: `GaranziaRicambioPrivacyScope` lo registra `Garanzia`,
 *      non `Ricambio`, quindi righe di garanzia prese da qui uscirebbero senza
 *      nessun filtro.
 *   4. **La sola lettura**: da qui si guarda. Ogni modifica passa
 *      dall'impersonazione, che lascia una riga di audit col contesto.
 *
 * ⚠️ **Due guardie sono falsificabili solo per via strutturale**, e va detto
 * invece di lasciar credere che un test comportamentale le provi:
 *   - il **tie-break** sull'id nell'ordinamento paginato — toglierlo lascia la
 *     suite verde su entrambi i driver a seconda del piano scelto (CLAUDE.md,
 *     25 Ago 2026), quindi si guarda l'**SQL** e non le righe;
 *   - il **non-uso** di garanzie e utilizzi — un'assenza non si osserva
 *     guardando una pagina che già non li mostra, si osserva leggendo il
 *     sorgente.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // ── Gruppo Rossi: due sedi, perché la diffusione si conta per CLIENTE ────
    $this->rossi = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi', 'piano' => 'free']);
    $this->sedeRossiCentro = UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)
        ->create(['nome' => 'Sede Rossi Centro']);
    $this->sedeRossiNord = UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)
        ->create(['nome' => 'Sede Rossi Nord']);

    Ricambio::factory()->forTenant($this->sedeRossiCentro)->create(['nome' => 'Guarnizione Alfa']);
    Ricambio::factory()->forTenant($this->sedeRossiCentro)->conCodice('FR-100')->create(['nome' => 'Filtro Rosso']);
    Ricambio::factory()->forTenant($this->sedeRossiNord)->create(['nome' => 'Guarnizione Alfa']);

    // ── Lab Bianchi: un altro cliente, su un altro piano ─────────────────────
    $this->bianchi = Account::factory()->saas()->create(['ragione_sociale' => 'Lab Bianchi']);
    $this->sedeBianchi = UnitaOrganizzativa::factory()->ente()->perAccount($this->bianchi)
        ->create(['nome' => 'Sede Bianchi']);

    Ricambio::factory()->forTenant($this->sedeBianchi)->create(['nome' => 'Guarnizione Alfa']);
    Ricambio::factory()->forTenant($this->sedeBianchi)->create(['nome' => 'Sonda Blu']);

    // ── EasyLab stessa: non è un cliente, e non deve comparire mai ───────────
    $this->easylab = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);
    $this->sedeEasylab = UnitaOrganizzativa::factory()->ente()->perAccount($this->easylab)
        ->create(['nome' => 'Sede EasyLab']);

    Ricambio::factory()->forTenant($this->sedeEasylab)->create(['nome' => 'Guarnizione Alfa']);
    Ricambio::factory()->forTenant($this->sedeEasylab)->create(['nome' => 'Cinghia Interna']);

    $this->superadmin = User::factory()->create([
        'tenant_id' => $this->sedeRossiCentro->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->superadmin->assignRole('Superadmin');
    $this->rossi->aggiungiMembro($this->superadmin);

    // Un membro impersonabile per Rossi, e per Bianchi il solo ruolo che
    // `canBeImpersonated()` protegge: le due celle devono dire cose diverse.
    $this->membroRossi = User::factory()->create(['tenant_id' => $this->sedeRossiCentro->id]);
    $this->membroRossi->assignRole('Admin');
    $this->rossi->aggiungiMembro($this->membroRossi);

    $this->developerBianchi = User::factory()->create(['tenant_id' => $this->sedeBianchi->id]);
    $this->developerBianchi->assignRole('Developer');
    $this->bianchi->aggiungiMembro($this->developerBianchi);
});

/** La scheda montata su un perimetro, come la userebbe il browser. */
function schedaRicambi(array $property = [])
{
    return Livewire::test(ParcoRicambi::class, $property);
}

/**
 * Un cliente con **due** membri impersonabili, creato dentro il test che lo usa.
 *
 * ⚠️ Sta qui e non nel `beforeEach` di proposito: il ramo «più di un candidato»
 * serve a due prove sole, e aggiungere un terzo cliente alla fixture comune
 * cambierebbe i conteggi di diffusione di mezzo file — cioè un test che si
 * rompe per una ragione che non c'entra con ciò che dice.
 *
 * Il pezzo si chiama «Cuscinetto Verde» e non «Guarnizione Alfa» esattamente per
 * quel motivo.
 *
 * @return array{0: Account, 1: User, 2: User}
 */
function studioVerdiConDueMembri(): array
{
    $verdi = Account::factory()->create(['ragione_sociale' => 'Studio Verdi', 'piano' => 'free']);
    $sede = UnitaOrganizzativa::factory()->ente()->perAccount($verdi)->create(['nome' => 'Sede Verdi']);

    Ricambio::factory()->forTenant($sede)->create(['nome' => 'Cuscinetto Verde']);

    $primo = User::factory()->create(['tenant_id' => $sede->id, 'email' => 'anna.verdi@example.test']);
    $primo->assignRole('Admin');
    $verdi->aggiungiMembro($primo);

    $secondo = User::factory()->create(['tenant_id' => $sede->id, 'email' => 'bruno.verdi@example.test']);
    $secondo->assignRole('Tecnico');
    $verdi->aggiungiMembro($secondo);

    return [$verdi, $primo, $secondo];
}

// ─── 1. Il permesso: 403, non una pagina vuota ───────────────────────────────

it('refuses the page to every role without the platform permission', function (string $ruolo) {
    $utente = User::factory()->create([
        'tenant_id' => $this->sedeRossiCentro->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $utente->assignRole($ruolo);
    $this->rossi->aggiungiMembro($utente);

    $this->actingAs($utente)
        ->get(route('piattaforma.parco.ricambi'))
        ->assertForbidden();
})->with(RUOLI_SENZA_PIATTAFORMA);

it('opens the page for both platform roles', function (string $ruolo) {
    $utente = User::factory()->create([
        'tenant_id' => $this->sedeRossiCentro->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $utente->assignRole($ruolo);
    $this->rossi->aggiungiMembro($utente);

    $this->actingAs($utente)
        ->get(route('piattaforma.parco.ricambi'))
        ->assertOk()
        ->assertSeeLivewire(ParcoRicambi::class);
})->with(RUOLI_CON_PIATTAFORMA);

it('throws instead of handing back an empty list when the component is mounted without the permission', function () {
    // ⚠️ Il negativo di rotta non copre questo: chi montasse il componente da
    // un altro contesto deve ricevere un 403 dalla PORTA, non zero righe — che
    // si leggerebbero come «i clienti non hanno pezzi a catalogo», cioè la forma
    // peggiore di negare, silenziosa e plausibile.
    $admin = User::factory()->create(['tenant_id' => $this->sedeRossiCentro->id]);
    $admin->assignRole('Admin');

    $this->actingAs($admin);

    // ⚠️ Senza `withoutExceptionHandling()` il test sarebbe **verde per il
    // motivo sbagliato**: il banco di Livewire cattura l'eccezione e le
    // sostituisce la pagina 403 del framework, quindi un `toThrow()` fallirebbe
    // e un `assertSee()` passerebbe anche se la porta fosse stata tolta e la
    // pagina fosse semplicemente vuota. Qui interessa che sia la PORTA a negare.
    $this->withoutExceptionHandling();

    expect(fn () => schedaRicambi())->toThrow(AuthorizationException::class);
});

it('declares the very same permission as the rest of the parco', function () {
    expect(ParcoRicambi::PERMESSO)->toBe('tenants.view_all')
        ->and(ParcoRicambi::PERMESSO)->toBe(VistaPiattaforma::PERMESSO);
});

// ─── 2. Le righe dicono di CHI sono ──────────────────────────────────────────

it('shows the client and the site on every single row', function () {
    // 🔴 È il requisito posto per primo dal committente, ed è anche ciò che
    // impedisce di agire sul cliente sbagliato: da questa pagina si passa
    // all'impersonazione, e il nome accanto al pulsante è l'unica cosa che dice
    // in casa di chi si sta per entrare.
    $this->actingAs($this->superadmin);

    schedaRicambi()
        ->assertOk()
        ->assertSee('Gruppo Rossi')
        ->assertSee('Sede Rossi Centro')
        ->assertSee('Sede Rossi Nord')
        ->assertSee('Lab Bianchi')
        ->assertSee('Sede Bianchi')
        ->assertSee('Filtro Rosso')
        ->assertSee('Sonda Blu');
});

it('shows the manufacturer code next to the part', function () {
    $this->actingAs($this->superadmin);

    schedaRicambi()->assertSee('FR-100');
});

it('never shows the platform account own catalogue', function () {
    // EasyLab non è un cliente: `VistaPiattaforma::accounts()` la esclude per
    // costruzione, e il parco eredita quell'esclusione passando dalla porta.
    $this->actingAs($this->superadmin);

    schedaRicambi()
        ->assertDontSee('Cinghia Interna')
        ->assertDontSee('Sede EasyLab');
});

// ─── 3. Il perimetro: restringe o non cambia, mai allarga ────────────────────

it('treats an empty hand-picked selection as nobody, never as everybody', function () {
    // ⛔ «Nessuno selezionato» è la condizione più facile da leggere come
    // «nessun filtro», e su una vista cross-cliente la differenza fra le due
    // letture è fra zero righe e le righe di tutti.
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::SCELTI, 'clientiScelti' => []])
        ->assertDontSee('Filtro Rosso')
        ->assertDontSee('Sonda Blu')
        ->assertSee('Nessun cliente selezionato');
});

it('never widens the perimeter with a forged account id', function () {
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::SCELTI, 'clientiScelti' => [999_999]])
        ->assertDontSee('Filtro Rosso')
        ->assertDontSee('Sonda Blu');
});

it('never lets the platform account be picked into the perimeter', function () {
    // Un id vero, ma di un account che non è un cliente: l'intersezione con
    // `VistaPiattaforma::accounts()` lo fa sparire invece di ammetterlo.
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::SCELTI, 'clientiScelti' => [$this->easylab->id]])
        ->assertDontSee('Cinghia Interna')
        ->assertDontSee('Filtro Rosso');
});

it('never lets a soft-deleted client back into the perimeter', function () {
    $this->actingAs($this->superadmin);

    $this->bianchi->delete();

    schedaRicambi(['modo' => Perimetro::SCELTI, 'clientiScelti' => [$this->bianchi->id]])
        ->assertDontSee('Sonda Blu');
});

it('falls back to nobody when the mode is not one of the three', function () {
    // ⚠️ Il `default` non è «tutti»: un input che non si è capito, su una vista
    // cross-cliente, vale «niente».
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => 'tutto-quanto'])
        ->assertDontSee('Filtro Rosso')
        ->assertDontSee('Sonda Blu')
        ->assertSee('Nessun cliente selezionato');
});

it('falls back to nobody when the plan is not on the catalogue', function () {
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::PER_PIANO, 'piano' => 'platino'])
        ->assertDontSee('Filtro Rosso')
        ->assertDontSee('Sonda Blu');
});

it('falls back to nobody when the plan filter is left empty', function () {
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::PER_PIANO, 'piano' => ''])
        ->assertDontSee('Filtro Rosso')
        ->assertDontSee('Sonda Blu');
});

it('keeps only the clients on the chosen plan', function () {
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::PER_PIANO, 'piano' => 'free'])
        ->assertSee('Filtro Rosso')
        ->assertDontSee('Sonda Blu');
});

it('keeps only the hand-picked client', function () {
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::SCELTI, 'clientiScelti' => [$this->rossi->id]])
        ->assertSee('Filtro Rosso')
        ->assertDontSee('Sonda Blu');
});

// ─── 4. La diffusione: si conta nel perimetro, e per cliente ─────────────────

it('counts how many clients carry the same part, by client and not by site', function () {
    // Gruppo Rossi ha «Guarnizione Alfa» in DUE sedi: contare le sedi direbbe
    // «3 clienti» dove i clienti sono due, e gonfierebbe il numero proprio sui
    // clienti più grandi — cioè dove verrebbe guardato di più.
    $this->actingAs($this->superadmin);

    schedaRicambi()->assertSee('2 clienti')->assertDontSee('3 clienti');
});

it('counts the spread inside the perimeter and not across the whole platform', function () {
    // Con il solo Gruppo Rossi nel perimetro, «Guarnizione Alfa» è presso un
    // cliente solo: un numero che ignorasse il filtro direbbe «2 clienti» sopra
    // una pagina che ne mostra uno, e chi legge non saprebbe quale delle due
    // cifre risponde alla sua domanda.
    //
    // 🧪 **Il confine è su DUE livelli, e la prova di mutazione lo dice
    // esplicitamente** — è la stessa forma già registrata su `RicercaRicambi`,
    // e va saputa prima di «semplificare» togliendone uno, perché sembrerebbe
    // gratis e la suite resterebbe verde. Passare `Perimetro::tutti()` ai soli
    // «gemelli» NON fa cadere nessun test: la mappa `sede → cliente` è a sua
    // volta filtrata dalla porta, quindi le sedi degli altri clienti non si
    // risolvono e il conteggio le scarta. Cade la mutazione che li toglie
    // **insieme** — gemelli *e* sedi — ed è quella la coppia da rifare il
    // giorno in cui si tocca `contesto()`.
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::SCELTI, 'clientiScelti' => [$this->rossi->id]])
        ->assertSee('solo qui')
        ->assertDontSee('2 clienti');
});

it('never counts the platform account into the spread', function () {
    $this->actingAs($this->superadmin);

    // EasyLab ha anch'essa «Guarnizione Alfa»: se entrasse nel conto sarebbero 3.
    schedaRicambi()->assertDontSee('3 clienti');
});

// ─── 5. La ricerca ───────────────────────────────────────────────────────────

it('finds a part by name across every client in scope', function () {
    $this->actingAs($this->superadmin);

    schedaRicambi(['search' => 'guarnizione'])
        ->assertSee('Guarnizione Alfa')
        ->assertDontSee('Filtro Rosso')
        ->assertDontSee('Sonda Blu');
});

it('finds a part by manufacturer code', function () {
    $this->actingAs($this->superadmin);

    schedaRicambi(['search' => 'fr-100'])
        ->assertSee('Filtro Rosso')
        ->assertDontSee('Sonda Blu');
});

it('does not let a LIKE wildcard behave as a wildcard', function () {
    // Chi cerca «%» cerca il pezzo che si chiama così. Senza l'escape la
    // ricerca tornerebbe l'intero catalogo di ogni cliente, cioè mostrerebbe
    // di più di quanto è stato chiesto — la direzione di errore che su questa
    // superficie non si vede.
    $this->actingAs($this->superadmin);

    schedaRicambi(['search' => '%'])
        ->assertDontSee('Filtro Rosso')
        ->assertDontSee('Sonda Blu');
});

it('does not let an underscore behave as a wildcard either', function () {
    $this->actingAs($this->superadmin);

    schedaRicambi(['search' => '_'])
        ->assertDontSee('Filtro Rosso')
        ->assertDontSee('Sonda Blu');
});

it('treats a search made of non-breaking spaces as no question at all', function () {
    // `trim()` non toglie l'NBSP dei copia-incalla da PDF: senza
    // `ripulisciNome()` una casella che SEMBRA vuota sarebbe una ricerca attiva
    // su un carattere invisibile, e la pagina direbbe «nessun pezzo» a chi non
    // ha chiesto niente.
    $this->actingAs($this->superadmin);

    schedaRicambi(['search' => "\u{00A0}\u{00A0}"])
        ->assertSee('Filtro Rosso')
        ->assertSee('Sonda Blu')
        ->assertSee('fatto di soli spazi');
});

// ─── 6. L'ordinamento: la parte che solo l'SQL può provare ───────────────────

it('always closes a paginated ordering with a unique tie-break', function () {
    // 🔴 La prova va fatta sull'**SQL**, non sui dati: togliendo il tie-break la
    // suite resta verde su entrambi i driver a seconda del piano scelto, e un
    // test che coglie il difetto una volta su tre non è una rete (CLAUDE.md,
    // 25 Ago 2026). Su questa scheda i pari non sono l'eccezione — clienti
    // diversi chiamano lo stesso pezzo allo stesso modo — quindi Postgres può
    // riordinarli fra pagina 1 e pagina 2, e una riga esce da entrambe.
    $this->actingAs($this->superadmin);

    foreach (array_keys(RicambiDelParco::COLONNE_ORDINE) as $colonna) {
        foreach (['asc', 'desc'] as $direzione) {
            $sql = RicambiDelParco::elenco(
                Perimetro::tutti(),
                RicambiDelParco::filtriNormalizzati('', $colonna, $direzione, 20)
            )->toSql();

            $ordine = mb_substr($sql, (int) mb_strrpos($sql, 'order by'));

            expect(str_ends_with($ordine, '"ricambi"."id" asc'))->toBeTrue(
                "L'ordinamento per «{$colonna} {$direzione}» non chiude sull'id."
            );
        }
    }
});

it('orders by the normalised name and never by the raw one', function () {
    // `nome` dipenderebbe dalla collation del driver: due ambienti darebbero
    // due ordini per la stessa pagina.
    $this->actingAs($this->superadmin);

    $sql = RicambiDelParco::elenco(Perimetro::tutti(), RicambiDelParco::filtriNormalizzati('', 'nome', 'asc', 20))->toSql();
    $ordine = mb_substr($sql, (int) mb_strrpos($sql, 'order by'));

    expect($ordine)->toContain('"ricambi"."nome_normalizzato"');
});

it('refuses an ordering column that is not on the whitelist', function () {
    // ⚠️ `sortBy` è `#[Url]`: vale ciò che c'è nella query string, e finisce
    // dentro un `orderBy()`.
    $this->actingAs($this->superadmin);

    $sql = RicambiDelParco::elenco(Perimetro::tutti(), RicambiDelParco::filtriNormalizzati('', 'password', 'asc', 20))->toSql();

    expect($sql)->not->toContain('password')
        ->and($sql)->toContain('"ricambi"."nome_normalizzato"');
});

it('refuses a page size that is not on offer', function () {
    expect(RicambiDelParco::filtriNormalizzati('', 'nome', 'asc', 5_000)['perPage'])->toBe(20)
        ->and(RicambiDelParco::filtriNormalizzati('', 'nome', 'asc', 50)['perPage'])->toBe(50);
});

it('refuses to sort by a column the page cannot honestly sort by', function () {
    // Cliente, sede e codice NON sono ordinabili, e la ragione è nel docblock:
    // le prime due vorrebbero una join che perde gli scope o una sottoquery
    // scopata che torna NULL per gli altri clienti; il terzo è nullable, e i
    // NULL vanno primi su SQLite e ultimi su Postgres.
    expect(RicambiDelParco::COLONNE_ORDINE)->not->toHaveKey('cliente')
        ->and(RicambiDelParco::COLONNE_ORDINE)->not->toHaveKey('sede')
        ->and(RicambiDelParco::COLONNE_ORDINE)->not->toHaveKey('codice');
});

it('ignores a click on a column that is not sortable', function () {
    $this->actingAs($this->superadmin);

    schedaRicambi()
        ->call('ordina', 'cliente')
        ->assertSet('sortBy', 'nome')
        ->assertSet('sortDir', 'asc');
});

// ─── 7. L'impersonazione: il confine che rende rapida la sola lettura ────────

it('offers impersonation next to the rows of a client that has an impersonable member', function () {
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::SCELTI, 'clientiScelti' => [$this->rossi->id]])
        ->assertSee('Impersona');
});

it('says so instead of leaving an empty cell when nobody can be impersonated', function () {
    // ⚠️ Il Developer non è mai impersonabile, quindi Lab Bianchi non ha
    // candidati. Un vuoto muto farebbe chiedere se sia un difetto — è successo
    // davvero su questa piattaforma.
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::SCELTI, 'clientiScelti' => [$this->bianchi->id]])
        ->assertSee('Nessun membro impersonabile')
        ->assertDontSee('Impersona');
});

it('offers no impersonation at all to someone who has the parco but not the permission', function () {
    // `tenants.view_all` e `utenti.impersonate` sono permessi DIVERSI: stare in
    // questa pagina non è poter entrare in casa di un cliente.
    //
    // 🧪 **Anche qui la guardia è doppia, e nessuna delle due mutazioni cade da
    // sola.** `candidatiDellaPagina()` (nel concern condiviso con la cabina)
    // torna liste vuote a chi non ha il permesso, e il `$puoImpersonare` della
    // vista toglie il markup: sostituire il secondo con `true` lascia la suite
    // verde, perché il primo ha già svuotato le liste; e bypassare il primo
    // lascia la suite verde, perché il secondo non disegna niente. Cade la
    // coppia. La riga della vista non è quindi ridondante: è ciò che regge se
    // un giorno i candidati arrivassero da un'altra strada.
    Role::findByName('Superadmin')->revokePermissionTo('utenti.impersonate');

    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::SCELTI, 'clientiScelti' => [$this->rossi->id]])
        ->assertSee('Filtro Rosso')
        ->assertDontSee('Impersona');
});

it('refuses to open the member chooser without the impersonation permission', function () {
    // La property arriva dal browser: il `@can` della vista è la guardia più
    // debole, e da solo non reggerebbe nulla.
    Role::findByName('Superadmin')->revokePermissionTo('utenti.impersonate');

    $this->actingAs($this->superadmin);
    $this->withoutExceptionHandling();

    expect(fn () => schedaRicambi()->call('apriScelta', $this->rossi->id))
        ->toThrow(AuthorizationException::class);
});

it('refuses to open the member chooser for an account outside the platform view', function () {
    $this->actingAs($this->superadmin);
    $this->withoutExceptionHandling();

    expect(fn () => schedaRicambi()->call('apriScelta', $this->easylab->id))
        ->toThrow(ModelNotFoundException::class);
});

it('closes the chooser as the only modal of the page', function () {
    $this->actingAs($this->superadmin);

    schedaRicambi()
        ->call('apriScelta', $this->rossi->id)
        ->assertSet('sceltaImpersonazione', $this->rossi->id)
        ->call('chiudiOgniModale')
        ->assertSet('sceltaImpersonazione', null);
});

// ─── 8. La privacy dei ricambi (ADR-029) e la sola lettura ───────────────────

it('never reaches for warranties nor for mountings on this tab', function (string $vietato) {
    // 🔴 `ParcoClienti::ricambi()` toglie il solo `TenantScope` e consegna il
    // catalogo: né garanzie né utilizzi. ⛔ E — si veda il test qui sotto — non
    // c'è nessuno scope di privacy che li filtrerebbe se qualcuno andasse a
    // prenderli da qui: quello di ADR-029 lo registra `Garanzia`, non
    // `Ricambio`. La sola difesa è il non-uso, ed è questo test.
    //
    // ⚠️ Un'assenza non si osserva guardando una pagina che già non li mostra:
    // si osserva leggendo il sorgente. I commenti si tolgono, perché i docblock
    // spiegano per esteso PERCHÉ non si fa — e un guardrail che legge il testo
    // invece del codice punisce chi documenta.
    expect(sorgentiDellaSchedaRicambi())->not->toContain($vietato);
})->with([
    'il model delle garanzie' => ['Garanzia'],
    'la porta delle garanzie' => ['garanzie'],
    'il model degli utilizzi' => ['RicambioUtilizzo'],
    'la tabella degli utilizzi' => ['ricambio_utilizzo'],
]);

it('never credits the ricambi door with a privacy scope that Ricambio does not register', function () {
    // 🔴 Il guardrail qui sopra dice il VERO sul comportamento e per anni ha
    // detto il FALSO sulla ragione: che le garanzie non compaiano perché
    // `ParcoClienti::ricambi()` «lascia applicato» il filtro di ADR-029. Quel
    // filtro su questo builder non c'è mai stato, e una garanzia dichiarata che
    // non esiste è peggio di nessuna garanzia — è la riga che si legge invece
    // di riverificare, il giorno in cui qualcuno aggiunge la colonna «in
    // garanzia?» e la crede già coperta.
    $this->actingAs($this->superadmin);

    // ── 1. Il fatto ─────────────────────────────────────────────────────────
    // `GaranziaRicambioPrivacyScope` lo registra `Garanzia::booted()`, ed è
    // l'unico punto del progetto che lo faccia. `Ricambio` monta
    // `BelongsToTenant` (cioè `TenantScope`) e `SoftDeletes`, e nient'altro.
    $registrati = array_keys((new Ricambio)->getGlobalScopes());

    expect($registrati)->toContain(TenantScope::class)
        ->and($registrati)->toContain(SoftDeletingScope::class)
        ->and($registrati)->not->toContain(GaranziaRicambioPrivacyScope::class)
        // La porta toglie il solo `TenantScope`: su questo builder resta il
        // soft delete, e nessun filtro di privacy da ereditare.
        ->and(ParcoClienti::ricambi(Perimetro::tutti())->removedScopes())->toBe([TenantScope::class]);

    // ── 2. Ciò che il blocco DICE del fatto ─────────────────────────────────
    // ⚠️ Qui si legge il sorgente **coi commenti**, al contrario di ogni altro
    // guardrail di questo file: l'oggetto della prova è proprio la prosa. Le
    // tre formule sono quelle che la bugia aveva usato — «lascia applicato»,
    // «resta applicato», «restano dove sono» — cercate nella stessa frase del
    // nome dello scope (`[^.]` non attraversa il punto). Restano vietate finché
    // vale il fatto qui sopra: il giorno in cui `Ricambio` registrasse davvero
    // quello scope, è il punto 1 a diventare rosso per primo, e allora questa
    // metà va rilassata invece che aggirata.
    //
    // ⛔ Lo scanner NON legge questo file di test: il pattern stesso contiene
    // sia il nome sia le formule, e si troverebbe da solo.
    $formule = '(?:lascia(?:no)?\s+applicat|rest(?:a|ano)\s+applicat|rest(?:a|ano)\s+dove\s+sono)';
    $nome = 'GaranziaRicambioPrivacyScope';

    preg_match_all(
        "/(?:{$nome}[^.]{0,240}{$formule}|{$formule}[^.]{0,240}{$nome})/iu",
        sorgentiGrezzeDellaSchedaRicambi(),
        $bugie,
    );

    // L'array dei riscontri e non un conteggio: sul rosso Pest stampa la frase
    // colpevole, che è l'unica cosa che serve per correggerla.
    expect($bugie[0])->toBe([]);
});

it('never writes anything from this tab', function (string $vietato) {
    // ADR-037 concede la LETTURA cross-cliente e lascia la scrittura
    // all'impersonazione, che è per cliente e lascia una riga di audit con
    // dentro chi agiva e per conto di chi. Una scrittura scritta qui perderebbe
    // quel contesto proprio dove serve di più.
    expect(sorgentiDellaSchedaRicambi())->not->toContain($vietato);
})->with([
    'salvataggio' => ['->save('],
    'aggiornamento' => ['->update('],
    'cancellazione' => ['->delete('],
    'cancellazione definitiva' => ['->forceDelete('],
    'query builder nudo' => ['DB::'],
]);

it('never strips a global scope by hand, and never reads from the cabina door', function () {
    // Il locale del guardrail globale (`ParcoBypassGuardrailTest`), tenuto anche
    // qui perché il blocco si verifica da solo: la porta si usa, non si aggira.
    $codice = sorgentiDellaSchedaRicambi();

    expect($codice)->not->toContain('withoutGlobalScope')
        ->and(preg_match('/VistaPiattaforma::\w+\(/', $codice))->toBe(0)
        // ⚠️ Il rovescio: la costante del permesso è lecita, ed è ciò che il
        // componente dichiara. Un guardrail che cercasse la sola stringa
        // `VistaPiattaforma::` sarebbe rosso il giorno in cui è stato scritto.
        ->and($codice)->toContain('VistaPiattaforma::PERMESSO');
});

// ─── 9. Il non-trapelamento ──────────────────────────────────────────────────

it('does not loosen the tenant scope for the rest of the request', function () {
    // Aprire la porta per questa vista non allenta lo scope per ciò che viene
    // dopo: fuori dalla porta il Superadmin resta tenant-bound come chiunque
    // altro (🔗 ADR-018), e vede i due pezzi della propria sede.
    $this->actingAs($this->superadmin);

    schedaRicambi()->assertOk();

    expect(Ricambio::query()->count())->toBe(2)
        ->and(Ricambio::query()->pluck('nome')->sort()->values()->all())
        ->toBe(['Filtro Rosso', 'Guarnizione Alfa']);
});

// ─── 10. I controlli in cima devono DIRE IL VERO ─────────────────────────────

it('never lets the perimeter dropdown claim «all clients» over a page that shows none', function () {
    // ⚠️ La tendina è disegnata da `wire:model`, cioè dal VALORE della property e
    // non dal markup: un `modo` fuori catalogo non ha nessuna `<option>` che gli
    // corrisponda, e il browser evidenzia la prima — «Tutti i clienti» — sopra
    // una pagina che non mostra niente. Peggio: riselezionare «Tutti i clienti»
    // non emette nessun evento, perché il valore mostrato è già quello, e da
    // quello stato non si esce.
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => 'tutto-quanto'])
        ->assertSet('modo', Perimetro::SCELTI)
        ->assertSee('Nessun cliente selezionato')
        ->assertDontSee('Filtro Rosso');
});

it('normalises an unknown mode that arrives from an update, not only from the url', function () {
    // `mount()` non rigira sugli update: la property arriva dal browser a ogni
    // richiesta, quindi la stessa normalizzazione serve anche di là.
    $this->actingAs($this->superadmin);

    schedaRicambi()
        ->set('modo', 'tutto-quanto')
        ->assertSet('modo', Perimetro::SCELTI)
        ->assertDontSee('Filtro Rosso');
});

it('asks for a plan, and not for a client, while the plan filter is still empty', function () {
    // 🔴 Scegliere «Per piano» mandava `modo=piano` col piano ancora vuoto, e
    // `eNessuno()` non distingue «selezione vuota» da «piano non ancora scelto»:
    // la pagina consigliava «scegli almeno un cliente» sotto una tendina di
    // PIANI, senza nessuna lista di clienti da usare.
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::PER_PIANO])
        ->assertSee('Nessun piano scelto')
        ->assertDontSee('Nessun cliente selezionato')
        ->assertDontSee('Filtro Rosso');
});

it('forgets a plan that is not on the catalogue instead of showing it as chosen', function () {
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::PER_PIANO, 'piano' => 'platino'])
        ->assertSet('piano', '')
        ->assertSee('Nessun piano scelto');
});

it('keeps the plan the operator actually chose', function () {
    // Il rovescio delle due prove qui sopra: una normalizzazione troppo avida
    // svuoterebbe il filtro buono, e la pagina direbbe «scegli un piano» a chi
    // un piano l'ha appena scelto.
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::PER_PIANO, 'piano' => 'free'])
        ->assertSet('modo', Perimetro::PER_PIANO)
        ->assertSet('piano', 'free')
        ->assertSee('Filtro Rosso')
        ->assertDontSee('Nessun piano scelto');
});

it('never offers the platform account, nor a binned client, among the clients to pick', function () {
    // ⚠️ La tendina è l'unico posto in cui le ragioni sociali compaiono in
    // chiaro senza passare da una riga: un cliente cestinato elencato lì si
    // legge, e un EasyLab scegliibile dà zero righe senza nessuna spiegazione.
    $this->actingAs($this->superadmin);

    $this->bianchi->delete();

    schedaRicambi(['modo' => Perimetro::SCELTI, 'clientiScelti' => []])
        // La tendina è davvero piena: senza questa riga le due negative sarebbero
        // soddisfatte anche da una `<select>` vuota.
        ->assertSee('Gruppo Rossi')
        ->assertDontSee('EasyLab')
        ->assertDontSee('Lab Bianchi');
});

it('does not go and fetch the client list on the modes that never draw it', function (string $modo) {
    // Una query e l'idratazione di ogni account della piattaforma, buttate via a
    // ogni battuta nella casella di ricerca.
    $this->actingAs($this->superadmin);

    $scheda = schedaRicambi(['modo' => $modo, 'piano' => 'free']);

    expect($scheda->viewData('selezionabili'))->toBeEmpty();
})->with([
    'tutti i clienti' => [Perimetro::TUTTI],
    'per piano' => [Perimetro::PER_PIANO],
]);

// ─── 11. La paginazione: si torna a pagina uno quando cambia la domanda ──────

it('goes back to page one whenever the question changes under the operator', function (string $property, mixed $valore) {
    // 🔴 Senza `resetPage()` la richiesta riparte con `page=3`: la tabella è
    // vuota e la pagina scrive «Nessun pezzo a catalogo con questi filtri» sopra
    // un perimetro che ne ha tre.
    //
    // ⚠️ `set()` e non un secondo `mount`: gli hook `updating*` vivono SOLO sulla
    // strada dell'update, e un test che costruisce lo stato al mount non li
    // attraversa mai.
    $this->actingAs($this->superadmin);

    schedaRicambi()
        ->call('gotoPage', 3)
        ->assertSet('paginators.page', 3)
        ->set($property, $valore)
        ->assertSet('paginators.page', 1);
})->with([
    'la ricerca' => ['search', 'guarnizione'],
    'il modo' => ['modo', Perimetro::SCELTI],
    'il piano' => ['piano', 'free'],
    'i clienti scelti' => ['clientiScelti', [999_999]],
    'la taglia di pagina' => ['perPage', 50],
]);

it('goes back to page one when the ordering changes', function () {
    // Cambiare ordinamento rimescola TUTTE le righe: restare a pagina 3 fa
    // atterrare a metà di un elenco che non si è mai visto dall'inizio.
    $this->actingAs($this->superadmin);

    schedaRicambi()
        ->call('gotoPage', 3)
        ->call('ordina', 'created_at')
        ->assertSet('paginators.page', 1);
});

it('paginates with the whitelisted size and never with the number from the query string', function (int $chiesto, int $atteso) {
    // ⛔ `perPage` è `#[Url]`: senza il legame fra la whitelist e la `paginate()`,
    // `?perPage=999999` chiede al DB in una sola query e senza LIMIT utile il
    // catalogo ricambi di OGNI cliente della piattaforma.
    $this->actingAs($this->superadmin);

    $scheda = schedaRicambi(['perPage' => $chiesto]);

    expect($scheda->viewData('ricambi')->perPage())->toBe($atteso);
})->with([
    'una taglia inventata' => [999_999, 20],
    'una taglia a catalogo' => [50, 50],
]);

// ─── 12. L'ordinamento visto dal dito dell'operatore ─────────────────────────

it('inverts the ordering on the first click even when the url named a column that does not exist', function () {
    // `?sortBy=codice` è fuori dalla whitelist: la query ordina per «nome» e
    // l'intestazione «Pezzo» porta già la freccia ▲. Il primo clic su «Pezzo»
    // deve INVERTIRE ciò che si sta guardando — leggendo lo stato grezzo si
    // riscrive lo stesso ordine, e ci vogliono due clic per un effetto solo.
    $this->actingAs($this->superadmin);

    schedaRicambi(['sortBy' => 'codice'])
        ->call('ordina', 'nome')
        ->assertSet('sortBy', 'nome')
        ->assertSet('sortDir', 'desc');
});

it('inverts the ordering on the first click even when the url named a direction that does not exist', function () {
    $this->actingAs($this->superadmin);

    schedaRicambi(['sortBy' => 'nome', 'sortDir' => 'DISCENDENTE'])
        ->call('ordina', 'nome')
        ->assertSet('sortDir', 'desc');
});

// ─── 13. Il bersaglio dell'impersonazione ────────────────────────────────────

it('points the impersonation link at the member, and never at the account', function () {
    // 🔴 `assertSee('Impersona')` prova che il pulsante c'è, non che porti dalla
    // persona giusta — cioè proprio ciò che questa scheda esiste per garantire.
    // Bersagliare l'Account invece dello User lascerebbe il pulsante identico e
    // porterebbe nella sessione di un utente qualunque della piattaforma che
    // porta per caso quell'id.
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::SCELTI, 'clientiScelti' => [$this->rossi->id]])
        ->assertSee('Impersona')
        ->assertSee(route('impersonate', $this->membroRossi), false);
});

it('lets the operator choose which member to enter as, when a client has more than one', function () {
    // ⚠️ «Il primo» sarebbe una decisione presa dall'ordinamento di una query:
    // quando i membri sono più d'uno la scelta è esplicita. Nella fixture comune
    // nessun cliente ha due candidati — Gruppo Rossi ne ha due ma uno è chi
    // guarda — quindi senza questo test né il pulsante «Impersona (2)» né il
    // corpo della modale vengono compilati da nessuna asserzione.
    // ⚠️ La fixture si semina **prima** di `actingAs()`: con un utente in
    // sessione gli hook di `BelongsToTenant` riscrivono il `tenant_id` delle
    // righe appena create con l'Ente di chi guarda, e il pezzo finirebbe in casa
    // del Superadmin invece che di Studio Verdi.
    [$verdi, $primo, $secondo] = studioVerdiConDueMembri();

    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::SCELTI, 'clientiScelti' => [$verdi->id]])
        ->assertSee('Impersona (2)')
        ->call('apriScelta', $verdi->id)
        ->assertSee('Impersona un membro di Studio Verdi')
        ->assertSee($primo->email)
        ->assertSee($secondo->email)
        ->assertSee(route('impersonate', $primo), false)
        ->assertSee(route('impersonate', $secondo), false);
});

it('shows no chooser at all until one is opened', function () {
    // Il rovescio della prova qui sopra: senza questa riga un `@if` sempre vero
    // attorno alla modale resterebbe verde.
    [$verdi] = studioVerdiConDueMembri();

    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::SCELTI, 'clientiScelti' => [$verdi->id]])
        ->assertDontSee('Impersona un membro di Studio Verdi');
});

// ─── 14. La rotta che atterra sulla macchina: qui NON si usa ─────────────────
//
// 🔴 Dal 29 Ago 2026 il Parco ha una seconda porta all'impersonazione,
// `piattaforma.parco.impersona`, che entra in casa del cliente e **atterra
// sulla macchina** della riga invece di rimbalzare in dashboard. Le schede in
// cui la riga È una macchina la usano; questa no, e i tre test qui sotto
// esistono perché quel «no» è una **decisione** e non una dimenticanza — cioè
// esattamente la specie di cosa che il prossimo passaggio «completa» per
// simmetria, se nessuno l'ha scritta.
//
// I due motivi, e ciascuno basta da solo: una voce di catalogo non ha una
// macchina univoca (sta su zero, una o venti), e anche se ne avesse una sola
// l'indirizzo del pulsante direbbe **dove il pezzo è montato**, che è la
// domanda a cui questa scheda non risponde (🔗 ADR-029).

it('never points the impersonation button at a machine, because a catalogue part has none', function () {
    // ⚠️ L'`assertSee` non è decorativo: senza, l'asserzione negativa sarebbe
    // soddisfatta anche da una pagina che non ha nessun pulsante — cioè un test
    // che non può fallire. Prima si prova che il pulsante c'è, poi dove porta.
    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::SCELTI, 'clientiScelti' => [$this->rossi->id]])
        ->assertSee('Impersona')
        ->assertSee(route('impersonate', $this->membroRossi), false)
        ->assertDontSee('/piattaforma/parco/impersona/', false);
});

it('keeps the member chooser on the plain route as well', function () {
    // L'altro ramo: col cliente che ha due membri il link non sta nella riga ma
    // dentro la modale, e una scheda che avesse cambiato rotta in un posto solo
    // sarebbe metà corretta — che qui vuol dire scorretta.
    [$verdi, $primo, $secondo] = studioVerdiConDueMembri();

    $this->actingAs($this->superadmin);

    schedaRicambi(['modo' => Perimetro::SCELTI, 'clientiScelti' => [$verdi->id]])
        ->call('apriScelta', $verdi->id)
        ->assertSee(route('impersonate', $primo), false)
        ->assertSee(route('impersonate', $secondo), false)
        ->assertDontSee('/piattaforma/parco/impersona/', false);
});

it('does not name the machine-landing route anywhere in its own sources', function () {
    // Il rovescio strutturale delle due prove qui sopra, che guardano UNA
    // pagina renderizzata su UN perimetro: un ramo che nessuna fixture del
    // blocco raggiunge resterebbe altrimenti scoperto.
    //
    // ⚠️ Lo scanner toglie i commenti, quindi il nome della rotta si può
    // spiegare per esteso nei docblock — dove infatti sta scritto perché non si
    // usa — senza far diventare rosso questo test: punisce il codice, non chi
    // documenta. È lo stesso criterio dei guardrail su garanzie e utilizzi.
    expect(sorgentiDellaSchedaRicambi())->not->toContain('piattaforma.parco.impersona');
});

// ─── Lo scanner del blocco ───────────────────────────────────────────────────

/**
 * I tre file della scheda: l'elenco sta **in un posto solo**, perché i due
 * scanner qui sotto ne guardano gli stessi file da due angoli diversi e due
 * elenchi che divergessero lascerebbero un file scoperto da uno dei due.
 *
 * @return list<string>
 */
function fileDellaSchedaRicambi(): array
{
    return [
        app_path('Livewire/Piattaforma/ParcoRicambi.php'),
        app_path('Support/Piattaforma/RicambiDelParco.php'),
        resource_path('views/livewire/piattaforma/parco-ricambi.blade.php'),
    ];
}

/**
 * I tre file della scheda, **senza commenti**.
 *
 * ⚠️ Nome proprio e non riuso di `codiceRipulito()` di
 * `ParcoBypassGuardrailTest`: quella funzione vive in un altro file di test, e
 * questo blocco si lancia da solo. Una funzione globale ridichiarata sarebbe un
 * errore fatale quando la suite gira intera.
 *
 * ⚠️ **La vista si tratta col testo grezzo**: `token_get_all` su un
 * `.blade.php` vede il PHP dentro `@php` come inline HTML, quindi tokenizzare
 * darebbe una falsa pulizia — cioè uno scanner che sembra coprire la vista e non
 * la copre.
 */
function sorgentiDellaSchedaRicambi(): string
{
    return collect(fileDellaSchedaRicambi())
        ->map(fn (string $percorso) => senzaCommentiScheda(file_get_contents($percorso)))
        ->implode("\n");
}

/**
 * Gli stessi tre file, **coi commenti**: l'unico guardrail del blocco a cui la
 * prosa interessa. Vive accanto allo scanner ripulito perché l'elenco dei file
 * è uno solo — due elenchi che divergono sarebbero un file scoperto.
 */
function sorgentiGrezzeDellaSchedaRicambi(): string
{
    return collect(fileDellaSchedaRicambi())
        ->map(fn (string $percorso) => file_get_contents($percorso))
        ->implode("\n");
}

function senzaCommentiScheda(string $contenuto): string
{
    $contenuto = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $contenuto);

    if (! str_starts_with(ltrim($contenuto), '<?php')) {
        // Vista: restano i soli commenti `//` dentro i blocchi `@php`.
        return (string) preg_replace('#^\s*//.*$#m', '', $contenuto);
    }

    return collect(token_get_all($contenuto))
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');
}
