<?php

use App\Livewire\Documenti\ElencoDocumenti;
use App\Models\Documento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * 🔴 Chi entra nell'archivio documentale d'Ente, e cosa ci trova dentro
 * (🔗 ADR-018/026/031 — `/documenti`).
 *
 * **Tre aree rosse insieme** — rotta nuova, autorizzazioni, isolamento fra Enti
 * — quindi i negativi vengono per primi e sono la maggior parte del file. La
 * domanda che questo file esiste per rendere falsificabile è una sola: *questa
 * pagina è una seconda superficie che scavalca gli scope?* La risposta deve
 * essere no, e deve restare no anche fra sei mesi.
 *
 * ⚠️ `Livewire::test()` **non esegue i middleware di rotta**, quindi il `can:`
 * non è provabile da lì: i 403 si provano con una GET HTTP vera, e la catena
 * dichiarata sulla rotta con un assert strutturale su `gatherMiddleware()`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // I test non toccano mai il bucket vero.
    Storage::fake(Documento::DISCO);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);
    $this->intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    // ⚠️ `two_factor_confirmed_at`, o `two-factor.enforce` devia Admin,
    // Superadmin e Developer sul setup della sicurezza invece che sulla pagina.
    $this->utente = function (string $ruolo, ?UnitaOrganizzativa $ente = null): User {
        $u = User::factory()->create([
            'tenant_id' => ($ente ?? $this->ente)->id,
            'two_factor_confirmed_at' => now(),
        ]);
        $u->assignRole($ruolo);

        return $u;
    };

    $this->admin = ($this->utente)('Admin');

    /** @return list<int> gli id delle righe in pagina */
    $this->idsInPagina = fn ($test) => $test->viewData('documenti')->pluck('id')->all();
});

// --- I negativi, che vengono per primi ---

it('redirects guests to login', function () {
    $this->get(route('documenti.index'))->assertRedirect(route('login'));
});

it('forbids whoever lacks documenti.view', function () {
    // Utente dell'Ente **senza ruolo**: passa `auth`, cade sul `can:`.
    $senzaRuolo = User::factory()->create([
        'tenant_id' => $this->ente->id,
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($senzaRuolo)->get(route('documenti.index'))->assertForbidden();
});

it('declares its guard on the route itself', function () {
    // Assert **strutturale**: `Livewire::test()` non esegue i middleware, quindi
    // senza questa riga la catena della rotta potrebbe essere smontata senza che
    // nessun test se ne accorga — il `Gate::authorize()` di `render()` terrebbe
    // verdi tutti gli altri casi.
    $middleware = Route::getRoutes()->getByName('documenti.index')->gatherMiddleware();

    expect($middleware)->toContain('can:documenti.view')
        ->and($middleware)->toContain('auth')
        ->and($middleware)->toContain('account.lockout')
        ->and($middleware)->toContain('two-factor.enforce');
});

it('never lists a document of another Ente', function () {
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($altroEnte)->create();
    $strumentoB = Strumento::factory()->forNode($deptB)->create(['nome' => 'Cappa altrui']);
    $altrui = Documento::factory()->perStrumento($strumentoB)->create(['nome' => 'segreto-di-b.pdf']);

    $mio = Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'manuale-mio.pdf']);

    $test = Livewire::actingAs($this->admin)->test(ElencoDocumenti::class);

    // Sui **dati**, non solo sull'HTML: un `assertDontSee` da solo sarebbe verde
    // anche con la riga in elenco ma il nome non renderizzato.
    expect(($this->idsInPagina)($test))->toBe([$mio->id]);

    $test->assertDontSee('segreto-di-b.pdf')->assertSee('manuale-mio.pdf');
});

it('keeps a Responsabile out of documents from outside their sub-tree', function () {
    $altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip 2']);
    $fuori = Strumento::factory()->forNode($altroDept)->create();
    $documentoFuori = Documento::factory()->perStrumento($fuori)->create(['nome' => 'fuori-albero.pdf']);
    $documentoDentro = Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'dentro-albero.pdf']);

    $resp = ($this->utente)('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dept->id);

    $test = Livewire::actingAs($resp)->test(ElencoDocumenti::class);

    // La prova che questa pagina **non** è una seconda superficie che scavalca
    // `DepartmentThroughStrumentoScope`: l'elenco d'Ente contiene solo il
    // sotto-albero, esattamente come il tab della singola macchina.
    expect(($this->idsInPagina)($test))->toBe([$documentoDentro->id]);
    expect(($this->idsInPagina)($test))->not->toContain($documentoFuori->id);
});

it('shows nothing to an authenticated user without a tenant', function () {
    // Fail-closed (ADR-018): elenco **vuoto**, e non un errore né tutte le
    // righe. Il permesso c'è, il tenant no.
    Documento::factory()->perStrumento($this->strumento)->create();

    $orfano = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $orfano->assignRole('Admin');

    $test = Livewire::actingAs($orfano)->test(ElencoDocumenti::class);

    expect(($this->idsInPagina)($test))->toBe([]);
});

it('never resurrects a soft-deleted document', function () {
    // Retention: il file resta sul bucket, la riga no — e un archivio che
    // rimostrasse una riga cestinata renderebbe l'eliminazione una bugia.
    $vivo = Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'vivo.pdf']);
    $cestinato = Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'cestinato.pdf']);
    $cestinato->delete();

    $test = Livewire::actingAs($this->admin)->test(ElencoDocumenti::class);

    expect(($this->idsInPagina)($test))->toBe([$vivo->id]);
    $test->assertDontSee('cestinato.pdf');
});

it('gates the sidebar entry on documenti.view', function () {
    // ⚠️ Si asserisce sul blocco `<nav>` **estratto**, non sulla pagina: la
    // parola «Documenti» vive anche nel tab della scheda e nel titolo, quindi un
    // `assertDontSee` sul documento intero sarebbe rosso (o verde) per il motivo
    // sbagliato. Stessa forma di `AppShellTest`.
    $sidebar = function (User $u): string {
        $html = $this->actingAs($u->fresh())->get(route('dashboard'))->assertOk()->getContent();

        preg_match('/<nav class="flex-1 space-y-1 p-3">.*?<\/nav>/s', $html, $blocco);

        // Non `?? ''`: un blocco assente e un blocco vuoto vanno distinti, o un
        // `not->toContain()` sarebbe verde proprio quando la nav è sparita.
        expect($blocco)->not->toBeEmpty('Sidebar non trovata');

        return $blocco[0];
    };

    $conVoce = $sidebar(($this->utente)('Tenant'));

    $senza = Role::create(['name' => 'Senza documenti', 'guard_name' => 'web']);
    $senza->givePermissionTo(['strumenti.view']);
    $altro = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $altro->assignRole($senza);

    $senzaVoce = $sidebar($altro);

    expect($conVoce)->toContain('Documenti')
        // Il positivo di controllo, senza cui il caso negativo sarebbe verde
        // anche con la sidebar svuotata.
        ->and($senzaVoce)->toContain('Strumenti')
        ->and($senzaVoce)->not->toContain('Documenti');
});

it('forbids the component itself, and not only the route, to whoever lacks documenti.view', function () {
    // 🔴 **La gemella del caso qui sopra, dall'altra porta.** Quel 403 è una GET
    // HTTP: cade sul `can:` del middleware e a `render()` non arriva mai. Il
    // `Gate::authorize()` scritto dentro `render()` esiste per il montaggio
    // diretto — `Livewire::test()` non esegue i middleware — e finché nessun
    // test lo monta senza il permesso quella riga è **inosservabile**: si può
    // cancellare e la suite resta verde. Una guardia che nessuno può falsificare
    // è una riga che il primo refactor porta via in silenzio.
    $senza = Role::create(['name' => 'Solo strumenti', 'guard_name' => 'web']);
    $senza->givePermissionTo(['strumenti.view']);

    $utente = User::factory()->create([
        'tenant_id' => $this->ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $utente->assignRole($senza);

    Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'riservato.pdf']);

    // Il positivo di controllo: l'utente è autenticabile e ha *un* permesso, o
    // il caso sarebbe verde anche per un utente che non può fare niente.
    expect($utente->can('strumenti.view'))->toBeTrue()
        ->and($utente->can('documenti.view'))->toBeFalse();

    // ⚠️ `Livewire::test()` **non** rilancia l'eccezione: `RequestBroker` tiene
    // `AuthorizationException` fra quelle ancora gestite, quindi il rifiuto si
    // legge come stato 403 sulla risposta. E qui — a differenza di un'azione —
    // il 403 è prova piena: la pagina è in sola lettura e non c'è nessuna
    // scrittura che possa essere già avvenuta prima di `render()`.
    Livewire::actingAs($utente)->test(ElencoDocumenti::class)
        ->assertForbidden()
        ->assertDontSee('riservato.pdf');
});

it('names the Ente on every row for whoever crosses more than one', function () {
    // 🔴 **Il Tecnico è l'unico ruolo che attraversa gli Enti** (ADR-007/030:
    // portafoglio ∪ assegnazioni sostituiscono il confine Ente), e questa pagina
    // è un'aggregazione: senza una colonna Ente gli manca il dato che distingue
    // il documento di un cliente da quello di un altro, mescolati nella stessa
    // lista paginata e ordinati per data. È anche ciò che rende vera la riga sul
    // Registro dei trattamenti — «l'unione, **per Ente**» — che per il solo
    // ruolo cross-Ente era falsa.
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($altroEnte)->create();
    $strumentoB = Strumento::factory()->forNode($deptB)->create(['nome' => 'Cappa di B']);

    Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'di-a.pdf']);
    Documento::factory()->perStrumento($strumentoB)->create(['nome' => 'di-b.pdf']);

    // Tecnico **esterno** (`tenant_id` NULL): il caso in cui non c'è nessun Ente
    // di appartenenza da cui dedurre di chi siano le righe.
    $tecnico = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole('Tecnico');
    $tecnico->portafoglioClienti()->attach([$this->ente->id, $altroEnte->id]);

    $test = Livewire::actingAs($tecnico)->test(ElencoDocumenti::class);

    // Il presupposto: le due righe **sono** davvero mescolate. Senza, il caso
    // sarebbe verde per il motivo sbagliato.
    expect($test->viewData('documenti')->pluck('nome')->sort()->values()->all())
        ->toBe(['di-a.pdf', 'di-b.pdf'])
        ->and($test->viewData('mostraEnte'))->toBeTrue();

    $test->assertSee('Ente A')->assertSee('Ente B');
});

it('does not add the Ente column to whoever never leaves their own', function () {
    // Per l'Admin ogni riga direbbe lo stesso nome: una colonna che ripete
    // l'ovvio è rumore che si smette di leggere, e la query in più non si paga.
    Documento::factory()->perStrumento($this->strumento)->create();

    $test = Livewire::actingAs($this->admin)->test(ElencoDocumenti::class);

    expect($test->viewData('mostraEnte'))->toBeFalse();
    $test->assertDontSee('Ente A');
});
