<?php

use App\Enums\TipoDocumento;
use App\Http\Controllers\EsportaElencoDocumenti;
use App\Livewire\Documenti\ElencoDocumenti;
use App\Models\Documento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * L'elenco e i suoi cinque filtri (🔗 ERD §8.1, ADR-026 — `/documenti`).
 *
 * Chi entra e cosa vede sta in `AccessoElencoDocumentiTest`; qui si verifica che
 * l'elenco dica il vero: che attraversi davvero il parco, che i filtri filtrino
 * quello che dicono, e che le due trappole di questo progetto — il confine di
 * data e il tie-break di paginazione — siano chiuse **sull'SQL** e non per
 * gentilezza del driver.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake(Documento::DISCO);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->admin = User::factory()->create([
        'tenant_id' => $this->ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('Admin');

    $this->elenco = fn () => Livewire::actingAs($this->admin)->test(ElencoDocumenti::class);

    /** @return list<int> */
    $this->idsInPagina = fn ($test) => $test->viewData('documenti')->pluck('id')->all();

    /**
     * Un documento con un `created_at` deciso.
     *
     * ⚠️ `forceFill` + `saveQuietly` e non un attributo della factory:
     * `created_at` non è fillable e i timestamp verrebbero comunque riscritti al
     * salvataggio. `saveQuietly` evita anche una seconda riga di audit.
     */
    $this->documentoDel = function (string $quando, array $attributi = [], ?Strumento $su = null): Documento {
        $documento = Documento::factory()->perStrumento($su ?? $this->strumento)->create($attributi);
        $documento->forceFill(['created_at' => $quando, 'updated_at' => $quando])->saveQuietly();

        return $documento->refresh();
    };
});

it('lists documents of every machine of the Ente, both machine and intervento ones', function () {
    // La ragione d'essere della pagina: il tab della scheda mostra una macchina
    // alla volta, qui si attraversa il parco.
    $altra = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Cappa']);
    $intervento = Intervento::factory()->forStrumento($altra)->create(['descrizione' => 'Taratura annuale']);

    $suAutoclave = Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'manuale-autoclave.pdf']);
    $suCappa = Documento::factory()->perStrumento($altra)->create(['nome' => 'manuale-cappa.pdf']);
    $suIntervento = Documento::factory()->perIntervento($intervento)->create(['nome' => 'certificato-2026.pdf']);

    $test = ($this->elenco)();

    expect(($this->idsInPagina)($test))->toHaveCount(3)
        ->toContain($suAutoclave->id)
        ->toContain($suCappa->id)
        ->toContain($suIntervento->id);

    $test->assertSee('manuale-autoclave.pdf')
        ->assertSee('manuale-cappa.pdf')
        ->assertSee('certificato-2026.pdf')
        // Il documento dell'intervento dice a cosa è appeso, e quello della
        // macchina no: sono due righe della stessa lista con soggetti diversi.
        ->assertSee('Taratura annuale');
});

it('filters by document type', function () {
    $manuale = Documento::factory()->perStrumento($this->strumento)
        ->create(['nome' => 'manuale.pdf', 'tipo' => TipoDocumento::Manuale]);
    Documento::factory()->perStrumento($this->strumento)
        ->create(['nome' => 'conformita.pdf', 'tipo' => TipoDocumento::Conformita]);

    $test = ($this->elenco)()->set('tipo', TipoDocumento::Manuale->value);

    expect(($this->idsInPagina)($test))->toBe([$manuale->id]);
});

it('ignores a document type that did not come from the whitelist', function () {
    // `tipo` arriva dalla query string senza passare da `updatingTipo()`: un
    // valore arbitrario non deve né filtrare né essere **annunciato** come
    // filtro, o il messaggio del vuoto darebbe la colpa a una causa che non c'è.
    $documento = Documento::factory()->perStrumento($this->strumento)->create();

    $test = ($this->elenco)()->set('tipo', 'inventato');

    expect(($this->idsInPagina)($test))->toBe([$documento->id])
        ->and($test->viewData('filtriApplicati'))->toBeFalse();
});

it('filters by machine', function () {
    $altra = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Cappa']);
    $suAutoclave = Documento::factory()->perStrumento($this->strumento)->create();
    Documento::factory()->perStrumento($altra)->create();

    $test = ($this->elenco)()->set('strumentoId', $this->strumento->id);

    expect(($this->idsInPagina)($test))->toBe([$suAutoclave->id]);
});

it('does not widen the list with a machine id from another Ente', function () {
    // 🔴 Il gemello negativo del caso qui sopra: un id estraneo non deve
    // **allargare** nulla. La query è già scopata, quindi produce zero righe —
    // che è la risposta giusta — e non un elenco che tradisce l'esistenza di
    // quella macchina.
    Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'mio.pdf']);

    $altroEnte = UnitaOrganizzativa::factory()->ente()->create();
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($altroEnte)->create();
    $strumentoB = Strumento::factory()->forNode($deptB)->create();
    $altrui = Documento::factory()->perStrumento($strumentoB)->create(['nome' => 'altrui.pdf']);

    $test = ($this->elenco)()->set('strumentoId', $strumentoB->id);

    expect(($this->idsInPagina)($test))->toBe([]);
    expect(($this->idsInPagina)($test))->not->toContain($altrui->id);
    $test->assertDontSee('altrui.pdf');
});

it('includes the whole final day of the date range', function () {
    // 🔴 Il confine è **mezzo aperto**: `>= dal` e `< al + 1 giorno`.
    // `documenti.created_at` è un timestamp, quindi `<= $al` taglierebbe via
    // tutta la giornata di `$al` tranne la sua mezzanotte — e su SQLite, dove le
    // date sono stringhe e i confronti lessicografici, l'errore si comporta
    // diversamente che su Postgres.
    $prima = ($this->documentoDel)('2026-08-17 23:59:59', ['nome' => 'fuori-prima.pdf']);
    $apertura = ($this->documentoDel)('2026-08-18 00:00:00', ['nome' => 'dentro-apertura.pdf']);
    $mezzanotte = ($this->documentoDel)('2026-08-20 00:00:00', ['nome' => 'dentro-mezzanotte.pdf']);
    $sera = ($this->documentoDel)('2026-08-20 23:59:00', ['nome' => 'dentro-sera.pdf']);
    $dopo = ($this->documentoDel)('2026-08-21 00:00:00', ['nome' => 'fuori-dopo.pdf']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $test = ($this->elenco)()->set('dal', '2026-08-18')->set('al', '2026-08-20');
    $sql = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $q) => str_contains($q, 'from "documenti"'))
        ->values();
    DB::disableQueryLog();

    $ids = ($this->idsInPagina)($test);

    expect($ids)->toBe([$sera->id, $mezzanotte->id, $apertura->id]);
    expect($ids)->not->toContain($prima->id);
    expect($ids)->not->toContain($dopo->id);
    expect($sql)->not->toBeEmpty();

    // ⚠️ E l'assert sull'**SQL**, perché `whereDate()` darebbe il risultato
    // giusto e il piano sbagliato: avvolge la colonna in una funzione
    // (`strftime` su SQLite, `::date` su Postgres), rende inutilizzabile
    // l'indice e cambia semantica col driver. Il solo esito sarebbe verde per la
    // ragione sbagliata.
    foreach ($sql as $query) {
        expect($query)->not->toContain('strftime');
        expect($query)->not->toContain('::date');
    }
});

it('ignores an unreadable date instead of erroring', function () {
    // Il valore arriva dalla query string: `?dal=ieri` mostra l'archivio senza
    // quel filtro, non una pagina d'errore.
    $documento = Documento::factory()->perStrumento($this->strumento)->create();

    $test = ($this->elenco)()->set('dal', 'ieri')->set('al', 'domani mattina');

    expect(($this->idsInPagina)($test))->toBe([$documento->id])
        ->and($test->viewData('filtriApplicati'))->toBeFalse();
});

it('neutralises LIKE wildcards in the search', function () {
    // Senza la neutralizzazione, `%` da solo restituisce **tutte** le righe e
    // `_` diventa «un carattere qualunque»: un filtro che si aggira digitando un
    // carattere non è un filtro.
    Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'manuale.pdf']);
    Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'certificato.pdf']);

    $per = fn (string $termine) => ($this->idsInPagina)(($this->elenco)()->set('cerca', $termine));

    expect($per('%'))->toBe([])
        ->and($per('_'))->toBe([])
        ->and($per('%anua%'))->toBe([])
        // Il positivo di controllo: la ricerca vera funziona ancora.
        ->and($per('manuale'))->toHaveCount(1);
});

it('does not lose rows between pages', function () {
    // 🔴 Trenta documenti con `created_at` **identico**: è il caso reale (i
    // documenti caricati nella stessa richiesta hanno lo stesso secondo), ed è
    // l'unico in cui il tie-break morde. Senza `orderByDesc('id')` Postgres può
    // riordinare i pari fra la query di pagina 1 e quella di pagina 2, e una
    // riga esce da entrambe.
    Documento::factory()->count(30)->perStrumento($this->strumento)->create();
    Documento::query()->update(['created_at' => '2026-08-20 10:00:00']);

    $pagina1 = ($this->idsInPagina)(($this->elenco)());
    $pagina2 = ($this->idsInPagina)(($this->elenco)()->call('gotoPage', 2));

    expect($pagina1)->toHaveCount(25)
        ->and($pagina2)->toHaveCount(5)
        ->and(array_unique(array_merge($pagina1, $pagina2)))->toHaveCount(30);
});

it('carries the tie-break into the query, and not by the driver being kind', function () {
    // ⚠️ **L'asserzione sulle due pagine non morde da sola**: su SQLite l'ordine
    // naturale di scansione è stabile, quindi due pagine tornano coerenti anche
    // senza tie-break e il test resterebbe verde su una query che in Postgres
    // perde righe. La sola prova che non dipende da quanto è gentile il motore
    // sotto è quella sull'SQL.
    Documento::factory()->perStrumento($this->strumento)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();
    ($this->elenco)();
    $sql = collect(DB::getQueryLog())
        ->pluck('query')
        ->first(fn (string $q) => str_contains($q, 'from "documenti"') && str_contains($q, 'order by'));
    DB::disableQueryLog();

    expect($sql)->not->toBeNull()
        ->and($sql)->toContain('order by "created_at" desc, "id" desc');
});

it('stays flat in query count', function () {
    // Scaldare la cache dei permessi prima di misurare, o si confronta il primo
    // render col secondo invece del numero di righe.
    ($this->elenco)();

    $conta = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->elenco)();
        $n = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'from "documenti"')
                || str_contains($q['query'], 'from "strumenti"')
                || str_contains($q['query'], 'from "interventi"')
                || str_contains($q['query'], 'from "users"'))
            ->count();
        DB::disableQueryLog();

        return $n;
    };

    // ⚠️ Ogni riga con **macchina diversa**, **soggetto** e **caricatore**, o il
    // test non misura niente: su fixture senza relazioni nessuna colonna farebbe
    // una query e l'N+1 passerebbe inosservato.
    $riga = function (int $i): void {
        $macchina = Strumento::factory()->forNode($this->dept)->create(['nome' => "Macchina {$i}"]);
        $caricatore = User::factory()->create(['tenant_id' => $this->ente->id]);

        if ($i % 2 === 0) {
            $intervento = Intervento::factory()->forStrumento($macchina)->create();
            Documento::factory()->perIntervento($intervento)->create(['caricato_da' => $caricatore->id]);

            return;
        }

        Documento::factory()->perStrumento($macchina)->create(['caricato_da' => $caricatore->id]);
    };

    foreach (range(1, 2) as $i) {
        $riga($i);
    }
    $conDue = $conta();

    foreach (range(3, 20) as $i) {
        $riga($i);
    }
    $conVenti = $conta();

    expect($conVenti)->toBe($conDue);
});

it('never asks the object store whether each file is there', function () {
    // ⛔ `Documento::esisteSulDisco()` è `Storage::disk('documenti')->exists()`,
    // cioè una **richiesta di rete a Backblaze per riga**: venticinque round-trip
    // dentro un render, invisibili in sviluppo perché il disco locale risponde in
    // microsecondi. Il controllo di esistenza vive in `ScaricaDocumento`, dov'è
    // uno solo.
    //
    // Due prove, perché una sola non basterebbe: quella **strutturale** coglie
    // il costo (una chiamata in un ciclo di righe), quella **comportamentale**
    // coglie l'altra forma dello stesso errore — una riga o un link nascosti
    // perché il file non c'è.
    //
    // ⚠️ I commenti si tolgono **prima** di cercare: il docblock del componente
    // spiega per esteso perché quella chiamata non va fatta, e un controllo che
    // legge il testo invece del codice punirebbe chi documenta. Stessa forma di
    // `BypassNudiGuardrailTest`.
    $senzaCommentiPhp = fn (string $percorso) => collect(token_get_all(file_get_contents($percorso)))
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');

    $componente = $senzaCommentiPhp(app_path('Livewire/Documenti/ElencoDocumenti.php'));
    $vista = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents(
        resource_path('views/livewire/documenti/elenco-documenti.blade.php')
    ));

    // Il controllo di controllo: senza, una regex sbagliata svuoterebbe i due
    // sorgenti e l'asserzione negativa sarebbe soddisfatta **sempre**.
    expect($componente)->toContain('Documento::query()')
        ->and($vista)->toContain('dimensioneLeggibile');

    expect($componente)->not->toContain('esisteSulDisco')
        ->and($vista)->not->toContain('esisteSulDisco');

    // Nessuno di questi file esiste sul disco finto: l'elenco li mostra lo
    // stesso, perché elencare non è scaricare.
    $documento = Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'senza-file.pdf']);
    Storage::disk(Documento::DISCO)->assertMissing($documento->path);

    ($this->elenco)()->assertOk()->assertSee('senza-file.pdf')->assertSee('Scarica');
});

it('separates seeing a document from downloading it, on the link too', function () {
    // ⚠️ **Caso sintetico e dichiarato tale**: nessun ruolo reale ha oggi
    // `documenti.view` senza `documenti.download`. Serve comunque, ed è la
    // stessa ragione per cui `DocumentiTest` ne tiene uno sul controller:
    // togliendo il `@can('documenti.download')` dal link «Scarica» la suite
    // resterebbe verde, perché la pagina è gatata su un permesso **diverso**
    // (`view`) e nessun altro test distingue le due domande. Una guardia non
    // falsificabile è una guardia di cui nessuno saprà dire se serve ancora.
    Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'manuale.pdf']);

    $ruolo = Role::create(['name' => 'Solo lettura documenti', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(['strumenti.view', 'documenti.view']);

    $utente = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $utente->assignRole($ruolo);

    Livewire::actingAs($utente)->test(ElencoDocumenti::class)
        // Vede la riga…
        ->assertSee('manuale.pdf')
        // …e non il modo per portarsela via.
        ->assertDontSee('Scarica');
});

it('survives a document whose machine has been binned', function () {
    // 🔴 Trovato **facendo la prova di mutazione**, non ragionando: la relazione
    // `strumento` può essere `null` su una riga perfettamente visibile.
    // `AccessibleStrumenti` toglie di proposito il `SoftDeletingScope` (lo dice
    // nel proprio docblock: «le righe appese a uno strumento cestinato restano
    // visibili, esattamente come le vede l'Admin»), mentre `belongsTo` passa
    // dagli scope di `Strumento` e non trova più nulla. Il primo abbozzo di
    // questa pagina faceva `route('strumenti.show', $d->strumento)` e cadeva con
    // una `UrlGenerationException` — un 500 in pagina per una macchina
    // cestinata, cioè per un gesto ordinario.
    $documento = Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'orfano.pdf']);
    $this->strumento->delete();

    ($this->elenco)()
        ->assertOk()
        ->assertSee('orfano.pdf')
        ->assertSee('Macchina cestinata');

    expect(($this->idsInPagina)(($this->elenco)()))->toBe([$documento->id]);
});

it('tells an empty archive from an over-filtered one', function () {
    // Due messaggi, perché sono due fatti diversi e mandano a fare due cose
    // opposte: «Nessun documento con questi filtri» davanti a un archivio
    // genuinamente vuoto manda a cercare un filtro da togliere che non esiste.
    ($this->elenco)()
        ->assertSee('Nessun documento caricato.')
        ->assertDontSee('Nessun documento con questi filtri.');

    Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'manuale.pdf']);

    ($this->elenco)()->set('cerca', 'niente-di-simile')
        ->assertSee('Nessun documento con questi filtri.')
        ->assertDontSee('Nessun documento caricato.');
});

it('clears the machine filter when Tutte is picked back', function () {
    // ⚠️ **«Tutte» è l'`<option value="">` del select**, cioè una stringa vuota
    // spedita a una property tipizzata `?int`. Che torni `null` e non resti il
    // valore di prima dipende da come Livewire converte, non da questa pagina:
    // se un giorno smettesse di farlo, il filtro resterebbe attaccato e la
    // pagina ignorerebbe il gesto **senza nessun errore** da cui accorgersene.
    $altra = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Cappa']);
    $mio = Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'autoclave.pdf']);
    $suo = Documento::factory()->perStrumento($altra)->create(['nome' => 'cappa.pdf']);

    $test = ($this->elenco)()->set('strumentoId', (string) $this->strumento->id);

    expect(($this->idsInPagina)($test))->toBe([$mio->id]);

    $test->set('strumentoId', '');

    expect(($this->idsInPagina)($test))->toBe([$suo->id, $mio->id])
        ->and($test->viewData('filtriApplicati'))->toBeFalse();
});

it('does not call a full archive empty on a page past the end', function () {
    // 🔴 **Il messaggio del vuoto mandava a fare la cosa sbagliata.** Un link o
    // un segnalibro su `?page=2` sopravvive alla cancellazione dei documenti di
    // quella pagina: `paginate()` torna zero righe con `total()` pieno e nessun
    // filtro attivo, quindi il ramo scriveva «Nessun documento caricato. Si
    // allegano dalla scheda di una macchina» davanti a un archivio che ne
    // contiene tre — cioè «carica il primo documento» a chi ne ha già tre. E la
    // riga del totale stampava «– di 3 documenti», con l'intervallo senza
    // numeri, perché `firstItem()` e `lastItem()` sono `null` fuori intervallo.
    Documento::factory()->count(3)->perStrumento($this->strumento)->create();

    $test = ($this->elenco)()->call('gotoPage', 9);

    $test->assertDontSee('Nessun documento caricato')
        ->assertDontSee('Nessun documento con questi filtri')
        // Niente apostrofi negli aghi: `assertSee` escapa, e un `dell'elenco`
        // diventerebbe `dell&#039;elenco` senza combaciare col template.
        ->assertSee('Nessun documento a pagina 9')
        ->assertSee('Torna alla prima pagina')
        // L'intervallo senza numeri non deve comparire.
        ->assertDontSee('– di 3 documenti');

    // E il gesto offerto deve funzionare, o è un invito a vuoto.
    $test->call('gotoPage', 1);

    expect(($this->idsInPagina)($test))->toHaveCount(3);
});

it('offers only machines that actually have documents in the filter', function () {
    // Un'opzione che porta a zero risultati è un filtro che può solo frustrare —
    // e su un Ente reale il parco è nell'ordine delle migliaia di voci.
    $conDocumenti = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Con documenti']);
    Strumento::factory()->forNode($this->dept)->create(['nome' => 'Senza documenti']);
    Documento::factory()->perStrumento($conDocumenti)->create();

    $macchine = ($this->elenco)()->viewData('macchine');

    expect($macchine->pluck('id')->all())->toBe([$conDocumenti->id]);
});

it('keeps a binned machine among the filter options while its documents are still listed', function () {
    // 🔴 **Due definizioni di «macchina cestinata», e il select diceva il
    // contrario della pagina.** I documenti di una macchina soft-deleted restano
    // in elenco — `AccessibleStrumenti` toglie di proposito il
    // `SoftDeletingScope` — mentre le opzioni venivano da `Strumento::query()`,
    // che quel filtro ce l'ha: con `?strumentoId=7` attivo su una macchina poi
    // cestinata, l'elenco mostrava i suoi documenti e il `<select>` non aveva più
    // nessuna `<option value="7">`, quindi il browser ripiegava sulla prima voce
    // e scriveva «Tutte» su una vista filtrata a una macchina sola.
    $viva = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Viva']);
    Documento::factory()->perStrumento($viva)->create(['nome' => 'viva.pdf']);
    $suo = Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'orfano.pdf']);
    $this->strumento->delete();

    $test = ($this->elenco)()->set('strumentoId', $this->strumento->id);

    // Il presupposto: la riga è ancora in elenco. Senza, il caso sarebbe verde
    // per il motivo sbagliato — nessun filtro da spiegare.
    expect(($this->idsInPagina)($test))->toBe([$suo->id]);

    expect($test->viewData('macchine')->pluck('id')->all())
        ->toContain($this->strumento->id)
        ->toContain($viva->id);

    // E l'opzione dice **che** è cestinata, o sarebbe indistinguibile da una
    // macchina viva in un elenco che la mostra ancora.
    // L'ago è distinto da «Macchina cestinata» della riga in tabella: senza
    // questa differenza il caso sarebbe verde per il motivo sbagliato.
    $test->assertSee('Autoclave (cestinata)');
});

it('never offers a machine of another Ente in the filter', function () {
    // La sottoquery delle opzioni è scopata quanto l'elenco: `whereIn` con un
    // builder Eloquent passa da `toBase()`, che applica i global scope.
    $mia = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Mia']);
    Documento::factory()->perStrumento($mia)->create();

    $altroEnte = UnitaOrganizzativa::factory()->ente()->create();
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($altroEnte)->create();
    $sua = Strumento::factory()->forNode($deptB)->create(['nome' => 'Sua']);
    Documento::factory()->perStrumento($sua)->create();

    $macchine = ($this->elenco)()->viewData('macchine');

    expect($macchine->pluck('id')->all())->toBe([$mia->id]);
});

it('links the export with the very filters on screen, once the route exists', function () {
    // ⚠️ **Il ponte verso la rotta che questa lavorazione non può registrare.**
    // `routes/web.php` è fuori perimetro, quindi qui la rotta si registra al volo:
    // non prova l'autorizzazione (quella vive nei middleware, e ha il suo assert
    // strutturale in `EsportaElencoDocumentiTest`), prova che il **link** della
    // pagina si costruisce e porta i filtri. Senza, il primo a scoprire un
    // `route()` sbagliato sarebbe l'utente.
    if (! Route::has('documenti.export-pdf')) {
        Route::get('/documenti/export.pdf', EsportaElencoDocumenti::class)->name('documenti.export-pdf');
    }

    Documento::factory()->perStrumento($this->strumento)->create();

    Livewire::actingAs($this->admin)->test(ElencoDocumenti::class)
        ->set('tipo', TipoDocumento::Manuale->value)
        ->set('cerca', 'manu')
        ->assertSee('Indice PDF')
        ->assertSee('tipo=manuale', escape: false)
        ->assertSee('cerca=manu', escape: false);
});

it('hands the export the very filters on screen', function () {
    // I parametri del link all'indice PDF sono quelli del filtro corrente: senza,
    // il foglio e lo schermo parlerebbero di due insiemi diversi.
    $test = ($this->elenco)()
        ->set('tipo', TipoDocumento::Manuale->value)
        ->set('strumentoId', $this->strumento->id)
        ->set('dal', '2026-08-01')
        ->set('cerca', 'manu')
        // Scartato dal parser, quindi **non** deve comparire fra i parametri: il
        // foglio non deve annunciare un filtro che nessuno ha applicato.
        ->set('al', 'ieri');

    expect($test->viewData('parametriExport'))->toBe([
        'tipo' => TipoDocumento::Manuale->value,
        'strumentoId' => $this->strumento->id,
        'dal' => '2026-08-01',
        'cerca' => 'manu',
    ]);
});
