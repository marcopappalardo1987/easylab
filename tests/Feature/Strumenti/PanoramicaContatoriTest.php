<?php

use App\Enums\VisibilitaGaranzieRicambio;
use App\Models\Documento;
use App\Models\Fornitore;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * I contatori della Panoramica (S4 blocco 7 — 🔗 ADR-023/024, sopra ADR-020/029).
 *
 * File a sé e non dentro `PanoramicaTest`, che copre il semaforo e la sintesi
 * interventi: qui ogni caso incrocia **un'area diversa** (ricambi, documenti,
 * fornitori, garanzie ricambio) con il proprio permesso, e la domanda a cui la
 * suite deve saper rispondere è *quale area ha smesso di funzionare*, non
 * "la Panoramica è rotta".
 *
 * Il pannello è Alpine (`x-show`), quindi è sempre renderizzato server-side:
 * gli `assertSee` non simulano un click.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    /**
     * Un pezzo sulla macchina. `data = null` è il «registrato ma non ancora
     * montato» di ADR-020 — la riga che esiste e non è sulla macchina.
     */
    $this->pezzo = function (Strumento $strumento, ?string $data = '2026-06-01'): RicambioUtilizzo {
        return RicambioUtilizzo::factory()
            ->forStrumento($strumento)
            ->forRicambio(Ricambio::factory()->forTenant($this->ente)->create())
            ->create(['data' => $data]);
    };

    /**
     * Il numero **davvero renderizzato** accanto a un'etichetta, o null se
     * quella riga non c'è.
     *
     * Si legge dall'HTML e non da `viewData`, e la differenza è il senso di
     * metà di questo file: un contatore giusto nei dati del render e assente
     * (o gated male) nella vista è esattamente il difetto da trovare — e
     * `viewData` non lo vedrebbe. Il `null` distingue «riga assente» da
     * «riga a zero», che qui sono due cose diverse.
     */
    $this->contatore = function ($componente, string $etichetta): ?string {
        $trovato = preg_match(
            '/'.preg_quote($etichetta, '/').'<\/dt>\s*<dd[^>]*>\s*([0-9]+)\s*<\/dd>/',
            $componente->html(),
            $match,
        );

        return $trovato === 1 ? $match[1] : null;
    };
});

// --- Ricambi: montati e in attesa sono due numeri, non uno ---

it('counts the parts on the machine apart from those still waiting to be fitted', function () {
    ($this->pezzo)($this->strumento);
    ($this->pezzo)($this->strumento);
    ($this->pezzo)($this->strumento, null);

    $componente = scheda($this->admin, $this->strumento);

    expect($componente->viewData('statRicambi'))
        ->toBe(['montati' => 2, 'inAttesa' => 1, 'copertiDaGaranzia' => 0])
        ->and(($this->contatore)($componente, 'Ricambi montati'))->toBe('2')
        ->and(($this->contatore)($componente, 'In attesa di montaggio'))->toBe('1');
});

it('drops the waiting line entirely when every registered part is on the machine', function () {
    // Zero non si scrive: «In attesa: 0» è rumore su una macchina che non ha
    // nulla in sospeso. È il contrappeso del caso precedente — senza, la riga
    // potrebbe essere sempre presente e nessuno se ne accorgerebbe.
    ($this->pezzo)($this->strumento);

    $componente = scheda($this->admin, $this->strumento);

    expect(($this->contatore)($componente, 'Ricambi montati'))->toBe('1');
    $componente->assertDontSee('In attesa di montaggio');
});

it('counts as covered only the fitted parts whose warranty has not expired', function () {
    $coperto = ($this->pezzo)($this->strumento);
    Garanzia::factory()->forRicambio($coperto)->attiva()->create();

    // Garanzia finita: la riga esiste, la copertura no. Contarla direbbe
    // «coperto» di un pezzo che non lo è — ed è il numero su cui qualcuno
    // deciderebbe di non ricomprare il pezzo.
    $scoperto = ($this->pezzo)($this->strumento);
    Garanzia::factory()->forRicambio($scoperto)->scaduta()->create();

    ($this->pezzo)($this->strumento); // montato, senza garanzia

    // Non ancora montato ma con garanzia viva: non è sulla macchina, quindi
    // non c'è niente da coprire (stessa regola con cui non pesa sul semaforo).
    $inAttesa = ($this->pezzo)($this->strumento, null);
    Garanzia::factory()->forRicambio($inAttesa)->attiva()->create();

    $componente = scheda($this->admin, $this->strumento);

    expect($componente->viewData('statRicambi'))
        ->toBe(['montati' => 3, 'inAttesa' => 1, 'copertiDaGaranzia' => 1])
        ->and(($this->contatore)($componente, 'Ricambi coperti da garanzia'))->toBe('1');
});

// --- Documenti: quelli che il tab elenca, macchina E interventi ---

it('counts every documento the Documenti tab lists, the interventi ones included', function () {
    // I certificati di taratura vivono sull'INTERVENTO (ADR-009) e sono la
    // maggioranza dei documenti di una macchina: un contatore che guardasse il
    // solo morph verso Strumento direbbe «1» su una macchina che ne ha due.
    Documento::factory()->perStrumento($this->strumento)->create();

    $taratura = Intervento::factory()->forStrumento($this->strumento)->fatto()->create();
    Documento::factory()->perIntervento($taratura)->create();

    $componente = scheda($this->admin, $this->strumento);

    expect(($this->contatore)($componente, 'Documenti allegati'))->toBe('2')
        ->and($componente->viewData('documenti'))->toHaveCount(2);
});

// --- Nessun contatore conta righe di un'altra macchina ---

it('never counts a row that belongs to another machine', function () {
    $altra = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Centrifuga']);
    ($this->pezzo)($altra);
    ($this->pezzo)($altra, null);
    $garanziaAltrui = ($this->pezzo)($altra);
    Garanzia::factory()->forRicambio($garanziaAltrui)->attiva()->create();
    Documento::factory()->perStrumento($altra)->create();

    $mio = ($this->pezzo)($this->strumento);
    Garanzia::factory()->forRicambio($mio)->attiva()->create();
    Documento::factory()->perStrumento($this->strumento)->create();

    $componente = scheda($this->admin, $this->strumento);

    expect(($this->contatore)($componente, 'Ricambi montati'))->toBe('1')
        ->and(($this->contatore)($componente, 'Ricambi coperti da garanzia'))->toBe('1')
        ->and(($this->contatore)($componente, 'Documenti allegati'))->toBe('1');

    // Il pezzo non montato dell'ALTRA macchina non deve nemmeno far comparire
    // la riga: un conteggio sbagliato per eccesso qui si vedrebbe come una
    // riga di troppo, non come un numero diverso.
    $componente->assertDontSee('In attesa di montaggio');
});

// --- Permessi: sparisce il singolo contatore, non il pannello (🔴) ---

it('takes away only the documenti counter from who cannot see documenti', function () {
    Documento::factory()->perStrumento($this->strumento)->create();
    ($this->pezzo)($this->strumento);

    // Ruolo sintetico, e va detto: nessun ruolo reale ha oggi i ricambi senza
    // i documenti. Una guardia non falsificabile è una guardia di cui fra sei
    // mesi nessuno saprà dire se serve ancora.
    Role::create(['name' => 'Magazziniere'])
        ->givePermissionTo(['strumenti.view', 'interventi.view', 'ricambio_utilizzo.view']);
    $magazziniere = User::factory()->create(['tenant_id' => $this->ente->id]);
    $magazziniere->assignRole('Magazziniere');

    $componente = scheda($magazziniere, $this->strumento);

    // Il pannello resta, e con esso le aree che questo ruolo ha davvero.
    expect(($this->contatore)($componente, 'Ricambi montati'))->toBe('1')
        ->and(($this->contatore)($componente, 'Interventi ultimi 12 mesi'))->toBe('0')
        ->and(($this->contatore)($componente, 'Documenti allegati'))->toBeNull();

    $componente->assertSee('Statistiche')->assertDontSee('Documenti allegati');
});

it('takes away only the ricambi counters from who cannot see them', function () {
    Documento::factory()->perStrumento($this->strumento)->create();
    ($this->pezzo)($this->strumento);
    ($this->pezzo)($this->strumento, null);

    Role::create(['name' => 'Archivista'])
        ->givePermissionTo(['strumenti.view', 'documenti.view']);
    $archivista = User::factory()->create(['tenant_id' => $this->ente->id]);
    $archivista->assignRole('Archivista');

    $componente = scheda($archivista, $this->strumento);

    expect(($this->contatore)($componente, 'Documenti allegati'))->toBe('1')
        ->and(($this->contatore)($componente, 'Ricambi montati'))->toBeNull()
        // Senza `interventi.view` sparisce anche la coppia interventi: le tre
        // aree della card sono davvero indipendenti.
        ->and(($this->contatore)($componente, 'Interventi ultimi 12 mesi'))->toBeNull();

    $componente->assertDontSee('In attesa di montaggio');
});

it('renders no Statistiche card at all when none of its three areas is visible', function () {
    // L'unico caso in cui a sparire è il pannello: una card col titolo e
    // nessuna riga sarebbe peggio della sua assenza.
    ($this->pezzo)($this->strumento);
    Role::create(['name' => 'Ospite'])->givePermissionTo('strumenti.view');
    $ospite = User::factory()->create(['tenant_id' => $this->ente->id]);
    $ospite->assignRole('Ospite');

    scheda($ospite, $this->strumento)->assertDontSee('Statistiche');
});

// --- ADR-029: il contatore delle coperture segue l'impostazione dell'Ente ---

it('hides the covered-parts counter from a Tenant whose Ente hides the spare-part warranties', function () {
    $pezzo = ($this->pezzo)($this->strumento);
    Garanzia::factory()->forRicambio($pezzo)->attiva()->create();

    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');
    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Nascosta);

    $componente = scheda($tenant->fresh(), $this->strumento);

    // Il pezzo lo vede (ha `ricambio_utilizzo.view`); quanto sia coperto no.
    expect(($this->contatore)($componente, 'Ricambi montati'))->toBe('1')
        ->and(($this->contatore)($componente, 'Ricambi coperti da garanzia'))->toBeNull();

    $componente->assertDontSee('Ricambi coperti da garanzia');
});

it('gives the same Tenant the counter back when the Ente only takes the writing away', function () {
    // Contrappeso obbligatorio: senza, il divieto qui sopra sarebbe soddisfatto
    // anche da un contatore che non compare mai a nessun Tenant — cioè da
    // ADR-004, che ADR-029 ha superato. `lettura` toglie la scrittura, non le
    // righe, ed è il confine che l'ADR chiede di non confondere.
    $pezzo = ($this->pezzo)($this->strumento);
    Garanzia::factory()->forRicambio($pezzo)->attiva()->create();

    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');
    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Lettura);

    $componente = scheda($tenant->fresh(), $this->strumento);

    expect(($this->contatore)($componente, 'Ricambi coperti da garanzia'))->toBe('1');
});

// --- Fornitore nel blocco «In sintesi» (ADR-023) ---

it('names the fornitore in the summary block', function () {
    $fornitore = Fornitore::factory()->forTenant($this->ente)
        ->create(['ragione_sociale' => 'Tecnobiomedica SpA']);
    $this->strumento->update(['fornitore_id' => $fornitore->id]);

    scheda($this->admin, $this->strumento->fresh())
        ->assertSee('Fornitore')
        ->assertSee('Tecnobiomedica SpA');
});

it('marks a binned fornitore instead of leaving the cell silently empty', function () {
    // Cestinato PRIMA di assegnarlo: la guardia del model impedisce di
    // cancellare un fornitore ancora associato, e il caso reale è una macchina
    // rimasta agganciata a un fornitore cessato.
    $cessato = Fornitore::factory()->forTenant($this->ente)
        ->create(['ragione_sociale' => 'Fornitore cessato']);
    $cessato->delete();

    $strumento = Strumento::factory()->forNode($this->dept)->create(['fornitore_id' => $cessato->id]);

    scheda($this->admin, $strumento)
        ->assertSee('Fornitore cessato')   // il nome c'è: `withTrashed()` sulla relazione
        ->assertSee('Cestinato');          // e il badge dice perché non è più scegliibile
});

it('says «—» when the machine has no fornitore, without pretending it is binned', function () {
    scheda($this->admin, $this->strumento)
        ->assertSee('Fornitore')
        ->assertDontSee('Cestinato');

    expect(scheda($this->admin, $this->strumento)->viewData('fornitore'))->toBeNull();
});

it('hides the fornitore row from who lacks fornitori.view, and keeps the rest of the summary', function () {
    $fornitore = Fornitore::factory()->forTenant($this->ente)
        ->create(['ragione_sociale' => 'Tecnobiomedica SpA']);
    $this->strumento->update(['fornitore_id' => $fornitore->id]);

    Role::create(['name' => 'Ospite'])->givePermissionTo('strumenti.view');
    $ospite = User::factory()->create(['tenant_id' => $this->ente->id]);
    $ospite->assignRole('Ospite');

    scheda($ospite, $this->strumento->fresh())
        ->assertSee('In sintesi')          // il blocco resta...
        ->assertSee('Dip')                 // ...con l'ubicazione, che è area sua
        ->assertDontSee('Fornitore')       // ...e senza la riga dell'area altrui
        ->assertDontSee('Tecnobiomedica SpA');
});

it('never even queries the fornitori table for who cannot see it', function () {
    // Il gate sta nel render e non solo nella vista: nascondere il dato dopo
    // averlo letto sarebbe una privacy per modo di dire, e un costo pagato da
    // chi non ne ricava nulla.
    $fornitore = Fornitore::factory()->forTenant($this->ente)->create();
    $this->strumento->update(['fornitore_id' => $fornitore->id]);

    Role::create(['name' => 'Ospite'])->givePermissionTo('strumenti.view');
    $ospite = User::factory()->create(['tenant_id' => $this->ente->id]);
    $ospite->assignRole('Ospite');

    DB::flushQueryLog();
    DB::enableQueryLog();
    scheda($ospite, $this->strumento->fresh());
    $query = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "fornitori"'))->count();
    DB::disableQueryLog();

    expect($query)->toBe(0);
});

// --- Nessuna query in più di quelle dei tab riassunti (ADR-024) ---

it('adds no query of its own: the counters fold the collections the tabs already load', function () {
    // La guardia vera non è un numero assoluto — cambierebbe a ogni feature —
    // ma che il conteggio NON CRESCA con le righe: se un contatore tornasse a
    // interrogare il DB per riga, questo confronto si romperebbe.
    $queryScheda = function (Strumento $strumento): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        scheda($this->admin, $strumento);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    // ⚠️ Scaldare la cache dei permessi PRIMA di misurare: al primo render
    // `spatie` carica permessi e ruoli (4 query), e senza questa riga il
    // confronto misurerebbe l'ordine dei due render invece del numero di
    // righe. Ci è già cascato questo stesso test appena scritto.
    scheda($this->admin, $this->strumento);

    $scarna = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Scarna']);
    ($this->pezzo)($scarna);
    Documento::factory()->perStrumento($scarna)->create();

    $carica = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Carica']);
    foreach (range(1, 5) as $i) {
        $pezzo = ($this->pezzo)($carica);
        Garanzia::factory()->forRicambio($pezzo)->attiva()->create();
        Documento::factory()->perStrumento($carica)->create();
    }

    expect($queryScheda($carica))->toBe($queryScheda($scarna));
});
