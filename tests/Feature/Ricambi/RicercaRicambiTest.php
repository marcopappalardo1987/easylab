<?php

use App\Livewire\Ricambi\RicercaRicambi;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Ricerca incrociata «dove è montato questo pezzo» (🔗 ADR-008).
 *
 * L'albero delle fixture è quello che serve ai negativi, e non uno di comodo:
 * Ente A con due dipartimenti fratelli (perché il Responsabile di uno non deve
 * vedere i montaggi dell'altro) e un Ente B con un pezzo dallo STESSO nome
 * (perché il confine fra Enti si prova con un omonimo, non con un estraneo:
 * un nome diverso passerebbe il test anche senza isolamento).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->labA1 = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create(['nome' => 'Chimica']);
    $this->labA2 = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create(['nome' => 'Microbiologia']);

    $this->enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $this->labB = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create(['nome' => 'Lab B']);

    $this->admin = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    $this->guarnizione = Ricambio::factory()->forTenant($this->enteA)->create(['nome' => 'Guarnizione O-Ring 12']);

    // Monta $ricambio su una macchina NUOVA del nodo dato, e restituisce la
    // macchina: ogni chiamata è una macchina diversa, così i conteggi per
    // laboratorio si leggono dal numero di chiamate.
    $this->montaSu = function (
        UnitaOrganizzativa $nodo,
        Ricambio $ricambio,
        array $attributi = [],
        bool $nonMontato = false,
    ): Strumento {
        $strumento = Strumento::factory()->forNode($nodo)->create();

        $factory = RicambioUtilizzo::factory()->forStrumento($strumento)->forRicambio($ricambio);

        $factory = $nonMontato ? $factory->nonMontato() : $factory;

        $factory->create($attributi);

        return $strumento;
    };
});

it('redirects guests to login', function () {
    $this->get(route('ricambi.index'))->assertRedirect(route('login'));
});

it('forbids a user without ricambi.view', function () {
    $senzaRuolo = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);

    $this->actingAs($senzaRuolo)->get(route('ricambi.index'))->assertForbidden();
});

it('answers where a piece is mounted, with machines and laboratories', function () {
    $bilancia = ($this->montaSu)($this->labA1, $this->guarnizione);
    $stufa = ($this->montaSu)($this->labA1, $this->guarnizione);
    $cappa = ($this->montaSu)($this->labA2, $this->guarnizione);

    $componente = Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
        ->set('search', 'guarnizione');

    $risultato = collect($componente->viewData('risultati'))->firstWhere('id', $this->guarnizione->id);

    expect($risultato['totale_macchine'])->toBe(3)
        ->and($risultato['macchine']->pluck('id')->all())
        ->toEqualCanonicalizing([$bilancia->id, $stufa->id, $cappa->id])
        // Il conteggio per laboratorio è la ragione d'essere della pagina.
        ->and($risultato['laboratori']->pluck('macchine', 'nome')->all())
        ->toBe(['Chimica' => 2, 'Microbiologia' => 1]);

    $componente->assertSee('Guarnizione O-Ring 12')->assertSee('Chimica')->assertSee('Microbiologia');
});

it('sums the quantities and counts the pieces not yet mounted apart', function () {
    // Due pezzi montati…
    ($this->montaSu)($this->labA1, $this->guarnizione, ['quantita' => 2]);
    // …e uno registrato ma non ancora montato (ADR-020: `data` NULL).
    ($this->montaSu)($this->labA2, $this->guarnizione, nonMontato: true);

    $risultato = collect(
        Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
            ->set('search', 'guarnizione')
            ->viewData('risultati')
    )->firstWhere('id', $this->guarnizione->id);

    expect($risultato['totale_pezzi'])->toBe(3)
        ->and($risultato['non_montati'])->toBe(1);
});

it('searches the normalized column, so case and stray spaces do not matter', function () {
    ($this->montaSu)($this->labA1, $this->guarnizione);

    Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
        // Maiuscole, spazi multipli e uno spazio unificatore (NBSP) da
        // copia-incolla: `Ricambio::normalizzaNome()` li appiattisce tutti, ed è
        // l'unica definizione della regola — in SQL non ne esiste una seconda.
        ->set('search', "  GUARNIZIONE\u{00A0}  O-RING ")
        ->assertSee('Guarnizione O-Ring 12');
});

it('reads the normalized column and not the name as typed', function () {
    // Il test che distingue davvero le due colonne, e ci vuole un accento.
    //
    // Su `nome` la ricerca sembrerebbe funzionare comunque **in locale**: `nome`
    // e `nome_normalizzato` differiscono solo per il caso, e il `like` di SQLite
    // è case-insensitive — cioè il motore girerebbe sulla colonna sbagliata e
    // nessun test se ne accorgerebbe, per poi rompersi su Postgres, dove `like`
    // è case-SENSITIVE. Con una maiuscola ACCENTATA cade su entrambi i driver:
    // `mb_strtolower` fa il folding di Ò→ò (è scritto nel docblock di
    // `Ricambio::normalizzaNome`), mentre il `like` di SQLite piega solo l'ASCII.
    $accentato = Ricambio::factory()->forTenant($this->enteA)->create(['nome' => 'Guarnizione PERÒ']);
    ($this->montaSu)($this->labA1, $accentato);

    Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
        ->set('search', 'però')
        ->assertSee('Guarnizione PERÒ');
});

it('shows nothing at all for an empty search', function () {
    ($this->montaSu)($this->labA1, $this->guarnizione);

    // Il catalogo intero NON è un risultato: una casella vuota non è una domanda.
    //
    // ⚠️ Si asserisce sui DATI e non solo sul reso: la vista ha un suo ramo per
    // la casella vuota, e guardare la sola pagina avrebbe promosso l'etichetta
    // a prova. La prova di mutazione lo ha dimostrato — sostituendo l'uscita
    // anticipata del componente con un `like '%%'` la pagina restava identica.
    Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
        ->assertSet('search', '')
        ->assertSee('Scrivi il nome di un pezzo')
        ->assertDontSee('Guarnizione O-Ring 12')
        ->tap(fn ($c) => expect($c->viewData('risultati'))->toBeEmpty());
});

it('shows nothing for a search made of whitespace only', function () {
    ($this->montaSu)($this->labA1, $this->guarnizione);

    Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
        // NBSP: `trim()` non lo toglie, `ripulisciNome()` sì. Senza la guardia
        // giusta questa ricerca diventerebbe `like '%%'`, cioè tutto.
        ->set('search', "  \u{00A0} ")
        ->assertDontSee('Guarnizione O-Ring 12')
        // Stessa pagina della casella vuota, e non «nessuna corrispondenza»:
        // chi non ha ancora scritto nulla non ha fatto una ricerca a vuoto. È
        // il motivo per cui la vista chiede `$domandaPosta` al componente
        // invece di ricalcolare la regola con un `trim()`, che sull'NBSP
        // risponderebbe diversamente.
        ->assertSee('Scrivi il nome di un pezzo')
        ->tap(fn ($c) => expect($c->viewData('risultati'))->toBeEmpty());
});

it('treats % and _ as text and not as wildcards', function () {
    ($this->montaSu)($this->labA1, $this->guarnizione);

    Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
        ->set('search', '%')
        ->assertDontSee('Guarnizione O-Ring 12');

    Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
        ->set('search', 'guarnizione_o-ring')
        ->assertDontSee('Guarnizione O-Ring 12');
});

it('still finds a piece whose own name contains a % ', function () {
    // Il gemello del test qui sopra, e senza di lui la difesa sarebbe monca:
    // neutralizzare i jolly con un backslash **senza dichiarare `escape`**
    // funziona su Postgres e non su SQLite, dove il backslash non è carattere
    // di escape per default — e il pezzo diventerebbe irraggiungibile.
    $filtro = Ricambio::factory()->forTenant($this->enteA)->create(['nome' => 'Filtro 50% HEPA']);
    ($this->montaSu)($this->labA1, $filtro);

    Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
        ->set('search', '50%')
        ->assertSee('Filtro 50% HEPA');
});

it('keeps two laboratories with the same name apart', function () {
    // Due nodi omonimi sotto genitori diversi: raggruppare per ETICHETTA li
    // fonderebbe in una riga sola, ed è la stessa trappola del `keyBy` su un id
    // che si ripete. Si raggruppa per id.
    $altraChimica = UnitaOrganizzativa::factory()->dipartimento()->under($this->labA2)->create(['nome' => 'Chimica']);

    ($this->montaSu)($this->labA1, $this->guarnizione);
    ($this->montaSu)($altraChimica, $this->guarnizione);

    $risultato = collect(
        Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
            ->set('search', 'guarnizione')
            ->viewData('risultati')
    )->firstWhere('id', $this->guarnizione->id);

    expect($risultato['laboratori'])->toHaveCount(2)
        ->and($risultato['laboratori']->pluck('id')->all())
        ->toEqualCanonicalizing([$this->labA1->id, $altraChimica->id]);
});

it('offers the Ricambi menu entry to whoever may see them, and to nobody else', function () {
    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertSee(route('ricambi.index'), escape: false);

    $senzaRuolo = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);

    $this->actingAs($senzaRuolo)->get(route('dashboard'))
        ->assertDontSee(route('ricambi.index'), escape: false);
});

it('never shows a piece, or a machine, belonging to another tenant', function () {
    // Stesso nome, altro Ente: se l'isolamento non ci fosse, la riga di A
    // porterebbe con sé le macchine di B.
    $omonimo = Ricambio::factory()->forTenant($this->enteB)->create(['nome' => 'Guarnizione O-Ring 12']);
    $macchinaB = ($this->montaSu)($this->labB, $omonimo);
    $macchinaA = ($this->montaSu)($this->labA1, $this->guarnizione);

    $risultati = collect(
        Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
            ->set('search', 'guarnizione')
            ->viewData('risultati')
    );

    expect($risultati->pluck('id')->all())->toBe([$this->guarnizione->id])
        ->and($risultati->first()['macchine']->pluck('id')->all())->toBe([$macchinaA->id])
        ->and($risultati->first()['macchine']->pluck('id')->all())->not->toContain($macchinaB->id);

    Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
        ->set('search', 'guarnizione')
        ->assertDontSee('Lab B');
});

it('limits a Responsabile to the mountings of their own sub-tree', function () {
    $suoi = ($this->montaSu)($this->labA1, $this->guarnizione);
    $altrui = ($this->montaSu)($this->labA2, $this->guarnizione);

    $responsabile = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $responsabile->assignRole('Responsabile Reparto');
    $responsabile->unitaResponsabili()->attach($this->labA1->id);

    $risultato = collect(
        Livewire::actingAs($responsabile)->test(RicercaRicambi::class)
            ->set('search', 'guarnizione')
            ->viewData('risultati')
    )->firstWhere('id', $this->guarnizione->id);

    // La voce di catalogo la vede (è dell'Ente, non del reparto — vedi il
    // docblock di Ricambio); il montaggio del dipartimento fratello no.
    expect($risultato['macchine']->pluck('id')->all())->toBe([$suoi->id])
        ->and($risultato['totale_macchine'])->toBe(1)
        ->and($risultato['macchine']->pluck('id')->all())->not->toContain($altrui->id);

    Livewire::actingAs($responsabile)->test(RicercaRicambi::class)
        ->set('search', 'guarnizione')
        ->assertSee('Chimica')
        ->assertDontSee('Microbiologia');
});

it('does not show a piece that has been removed from the machine', function () {
    $rimasta = ($this->montaSu)($this->labA1, $this->guarnizione);
    $smontata = ($this->montaSu)($this->labA2, $this->guarnizione);

    RicambioUtilizzo::where('strumento_id', $smontata->id)->first()->cestinaConGaranzia();

    $risultato = collect(
        Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
            ->set('search', 'guarnizione')
            ->viewData('risultati')
    )->firstWhere('id', $this->guarnizione->id);

    expect($risultato['macchine']->pluck('id')->all())->toBe([$rimasta->id])
        ->and($risultato['totale_macchine'])->toBe(1);
});

it('drops a mounting whose machine has been trashed, totals included', function () {
    $viva = ($this->montaSu)($this->labA1, $this->guarnizione);
    $cestinata = ($this->montaSu)($this->labA2, $this->guarnizione);
    $cestinata->delete();

    $risultato = collect(
        Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
            ->set('search', 'guarnizione')
            ->viewData('risultati')
    )->firstWhere('id', $this->guarnizione->id);

    // I totali si contano sulle macchine ELENCATE: un "2 macchine" sopra una
    // riga sola farebbe dubitare di tutta la pagina.
    expect($risultato['macchine']->pluck('id')->all())->toBe([$viva->id])
        ->and($risultato['totale_macchine'])->toBe(1);
});

it('shows a catalogue entry that is mounted nowhere, without pretending otherwise', function () {
    $risultato = collect(
        Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
            ->set('search', 'guarnizione')
            ->viewData('risultati')
    )->firstWhere('id', $this->guarnizione->id);

    expect($risultato)->not->toBeNull()
        ->and($risultato['totale_macchine'])->toBe(0);
});

it('hides the mountings from a role that may see the catalogue but not the usages', function () {
    ($this->montaSu)($this->labA1, $this->guarnizione);

    // Ruolo che oggi non esiste in `config/rbac.php` ma che l'editor di S6
    // potrà comporre: `ricambi.view` senza `ricambio_utilizzo.view`. Il
    // permesso della rotta apre la pagina, non i dati.
    $ruolo = Role::create(['name' => 'Solo catalogo']);
    $ruolo->givePermissionTo('ricambi.view');

    $utente = User::factory()->create(['tenant_id' => $this->enteA->id, 'two_factor_confirmed_at' => now()]);
    $utente->assignRole($ruolo);

    Livewire::actingAs($utente)->test(RicercaRicambi::class)
        ->set('search', 'guarnizione')
        ->assertSee('Non hai il permesso')
        ->assertDontSee('Chimica');
});

it('resolves machines and laboratories in a constant number of queries (no N+1)', function () {
    $conta = function (int $macchine): array {
        foreach (range(1, $macchine) as $i) {
            ($this->montaSu)($i % 2 === 0 ? $this->labA1 : $this->labA2, $this->guarnizione);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->admin)->test(RicercaRicambi::class)->set('search', 'guarnizione');
        $log = collect(DB::getQueryLog());
        DB::disableQueryLog();

        return [
            'strumenti' => $log->filter(fn ($q) => str_contains($q['query'], 'from "strumenti"'))->count(),
            'nodi' => $log->filter(fn ($q) => str_contains($q['query'], 'from "unita_organizzativa"'))->count(),
        ];
    };

    // Il confronto fra due grandezze, e non un numero assoluto: è il numero a
    // dover restare FERMO, e un assoluto si romperebbe al primo scope in più.
    expect($conta(2))->toBe($conta(8));
});

it('links every machine to its own scheda', function () {
    $strumento = ($this->montaSu)($this->labA1, $this->guarnizione);

    Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
        ->set('search', 'guarnizione')
        ->assertSee(route('strumenti.show', $strumento->id), escape: false);
});

it('caps the catalogue results and says so instead of truncating in silence', function () {
    Ricambio::factory()->forTenant($this->enteA)
        ->count(RicercaRicambi::MAX_RISULTATI + 1)
        ->sequence(fn ($s) => ['nome' => 'Filtro aria '.$s->index])
        ->create();

    $componente = Livewire::actingAs($this->admin)->test(RicercaRicambi::class)->set('search', 'filtro aria');

    expect($componente->viewData('risultati'))->toHaveCount(RicercaRicambi::MAX_RISULTATI)
        ->and($componente->viewData('troncato'))->toBeTrue();

    $componente->assertSee('Restringi il nome');
});
