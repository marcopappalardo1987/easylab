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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;

/**
 * L'indice dell'archivio documentale in PDF (🔗 ADR-026/031 —
 * `/documenti/export.pdf`).
 *
 * **È un indice, non un pacchetto dei file**: uno ZIP di N documenti sarebbe una
 * sola riga di audit per N file, cioè lo smontaggio di ADR-026. Qui si verifica
 * *cosa dice* il foglio e *chi può chiederlo*, non come è impaginato.
 *
 * ⛔ **La rotta `documenti.export-pdf` vive in `routes/web.php`, che in questa
 * lavorazione è tenuto da una mano sola.** I casi che dipendono dai suoi due
 * middleware si **saltano dichiarandolo**, e riprendono da soli appena la rotta
 * esiste.
 *
 * 🔴 **Ma l'autorizzazione non è più appesa a quei tre casi saltati.** Lo era:
 * il controller non scriveva nessun `Gate::authorize` «per avere una sola
 * catena», la catena non esisteva ancora, e per tutto quel tempo la suite era
 * verde senza che una sola asserzione dicesse chi può portarsi via l'archivio —
 * mentre una rotta registrata domani con il solo `can:documenti.view` avrebbe
 * consegnato al Tecnico l'intero archivio documentale in PDF. Ora i due permessi
 * sono imposti **dentro** il controller e provati invocandolo a mano (`refuses
 * the Tecnico…`, `refuses a guest…`, col loro positivo di controllo); i `can:`
 * di rotta restano difesa in profondità, e i casi saltati restano a verificarne
 * la *dichiarazione*.
 *
 * I casi sul contenuto girano invocando il controller direttamente: sono ciò che
 * prova ADR-031, cioè che sul foglio non entri ciò che chi lo esporta non
 * vedrebbe a schermo.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake(Documento::DISCO);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Microbiologia']);
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->admin = User::factory()->create([
        'tenant_id' => $this->ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('Admin');

    /**
     * Il controller invocato direttamente, con i parametri in query string.
     *
     * ⚠️ Serve perché la rotta non è ancora registrata **e** perché il contenuto
     * di un PDF non si cerca nei byte: dompdf comprime. Ciò che il foglio dice
     * lo si legge dai dati che il controller passa alla view — catturati con un
     * view composer, che è l'unico punto in cui quei dati esistono in chiaro.
     */
    $this->esporta = function (array $parametri = []): array {
        $dati = null;
        View::composer('pdf.elenco-documenti', function ($view) use (&$dati) {
            $dati = $view->getData();
        });

        $richiesta = Request::create('/documenti/export.pdf', 'GET', $parametri);
        $risposta = app(EsportaElencoDocumenti::class)($richiesta);

        return [$risposta, $dati];
    };

    /** @return list<string> i nomi file finiti sul foglio */
    $this->nomiSulFoglio = fn (array $dati) => $dati['righe']->pluck('nome')->all();
});

// --- Il foglio ---

it('returns a real PDF', function () {
    Documento::factory()->perStrumento($this->strumento)->create();

    $this->actingAs($this->admin);
    [$risposta] = ($this->esporta)();

    expect($risposta->headers->get('content-type'))->toBe('application/pdf')
        // La firma del formato, non la sola intestazione HTTP: dompdf può
        // restituire una pagina d'errore col content-type giusto.
        ->and(substr($risposta->getContent(), 0, 4))->toBe('%PDF');
});

it('never puts on the sheet a document the exporter cannot see', function () {
    // 🔴 ADR-031 letta per il suo scopo: un PDF non sa degradare per permesso
    // una volta uscito dall'applicazione, quindi ciò che chi lo esporta non
    // vedrebbe a schermo non ci deve **entrare**. Due direzioni insieme — un
    // altro Ente e un altro ramo dello stesso Ente — perché sono due scope
    // diversi e cadono in due modi diversi.
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create();
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($altroEnte)->create();
    $strumentoB = Strumento::factory()->forNode($deptB)->create();
    Documento::factory()->perStrumento($strumentoB)->create(['nome' => 'di-un-altro-ente.pdf']);

    $altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Chimica']);
    $fuori = Strumento::factory()->forNode($altroDept)->create();
    Documento::factory()->perStrumento($fuori)->create(['nome' => 'fuori-dal-mio-ramo.pdf']);

    Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'del-mio-ramo.pdf']);

    $resp = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dept->id);

    $this->actingAs($resp);
    [, $dati] = ($this->esporta)();

    // Sull'insieme **esatto**, non «al più»: la forma che morde.
    expect(($this->nomiSulFoglio)($dati))->toBe(['del-mio-ramo.pdf']);

    // E anche sull'HTML del foglio, che è ciò che finisce davvero in mano a
    // qualcuno. (Il PDF compresso non si può ispezionare; la view sì.)
    $foglio = view('pdf.elenco-documenti', $dati)->render();

    expect($foglio)->toContain('del-mio-ramo.pdf');
    expect($foglio)->not->toContain('di-un-altro-ente.pdf');
    expect($foglio)->not->toContain('fuori-dal-mio-ramo.pdf');
});

it('exports exactly what the screen shows, filters included', function () {
    // 🔴 La prova che `FiltroDocumenti` è l'**unica** definizione: gli stessi
    // parametri, applicati dalle due strade, devono dare lo stesso insieme. Due
    // copie divergerebbero, e il giorno in cui divergono il foglio direbbe una
    // cosa e lo schermo un'altra senza che nessuno dei due sembri rotto.
    $altra = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Cappa']);

    Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'manuale-autoclave.pdf', 'tipo' => TipoDocumento::Manuale]);
    Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'conformita-autoclave.pdf', 'tipo' => TipoDocumento::Conformita]);
    Documento::factory()->perStrumento($altra)->create(['nome' => 'manuale-cappa.pdf', 'tipo' => TipoDocumento::Manuale]);

    $parametri = [
        'tipo' => TipoDocumento::Manuale->value,
        'strumentoId' => (string) $this->strumento->id,
        'cerca' => 'manuale',
    ];

    $this->actingAs($this->admin);
    [, $dati] = ($this->esporta)($parametri);

    $aSchermo = Livewire::actingAs($this->admin)->test(ElencoDocumenti::class)
        ->set('tipo', $parametri['tipo'])
        ->set('strumentoId', $this->strumento->id)
        ->set('cerca', $parametri['cerca'])
        ->viewData('documenti')
        ->pluck('nome')
        ->all();

    expect($aSchermo)->toBe(['manuale-autoclave.pdf'])
        ->and(($this->nomiSulFoglio)($dati))->toBe($aSchermo);
});

it('reads a malformed machine id the same way on the screen and on the sheet', function () {
    // 🔴 **La gemella lato-schermo del caso qui sotto, ed è quella che mancava.**
    // `FiltroDocumenti` esiste per non avere due definizioni di «quali documenti
    // sta guardando l'utente»: se le sue due porte validano `strumentoId` con
    // regole diverse, per gli **stessi identici parametri** lo schermo mostra un
    // insieme e il foglio un altro — che è esattamente ciò che la classe esiste
    // per impedire, e nessuno dei due sembra rotto.
    //
    // Trovato su `?strumentoId=-5`: il controller lo scartava (`ctype_digit`),
    // il componente lo teneva (coercizione di Livewire sulla property `?int`).
    // Lo schermo scriveva «Nessun documento con questi filtri», il foglio
    // stampava l'intero archivio dicendo «Nessun filtro».
    Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'unico.pdf']);

    // `+5` e `3.0` non sono capricci: sono i valori su cui `ctype_digit` e la
    // coercizione a `int` di PHP danno risposte diverse.
    foreach (['-5', '0', 'abc', '3.0', '+5', ' 5', '5abc', '3.7'] as $grezzo) {
        $this->actingAs($this->admin);
        [, $dati] = ($this->esporta)(['strumentoId' => $grezzo]);

        $schermo = Livewire::withQueryParams(['strumentoId' => $grezzo])
            ->actingAs($this->admin)
            ->test(ElencoDocumenti::class);

        expect(($this->nomiSulFoglio)($dati))
            ->toBe($schermo->viewData('documenti')->pluck('nome')->all(), "strumentoId={$grezzo}");

        // E non basta che gli **insiemi** coincidano: anche la spiegazione deve.
        // Il foglio annuncia i filtri in una frase, lo schermo alza il flag che
        // sceglie il messaggio del vuoto: se divergessero, uno dei due direbbe
        // «filtrato» e l'altro «tutto l'archivio» sugli stessi dati.
        expect($schermo->viewData('filtriApplicati'))
            ->toBe(! str_contains($dati['filtri'], 'Nessun filtro'), "strumentoId={$grezzo}");
    }
});

it('ignores a machine id that is not a number instead of emptying the sheet', function () {
    // ⚠️ `(int) 'abc'` è `0`: un cast nudo avrebbe filtrato su un id che nessuno
    // ha chiesto, e il foglio sarebbe uscito vuoto senza spiegazione. Un id non
    // numerico semplicemente non è un filtro.
    Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'manuale.pdf']);

    $this->actingAs($this->admin);
    [, $dati] = ($this->esporta)(['strumentoId' => 'abc']);

    expect(($this->nomiSulFoglio)($dati))->toBe(['manuale.pdf']);
});

it('says which subset it is talking about', function () {
    // Un indice di venti righe senza la riga dei filtri si legge come «l'archivio
    // ha venti documenti», che è una conclusione sbagliata a partire da un dato
    // giusto. E la macchina si nomina, non si numera: «#7» non dice niente a chi
    // ritrova il foglio fra un anno.
    Documento::factory()->perStrumento($this->strumento)->create();

    $this->actingAs($this->admin);
    [, $conFiltri] = ($this->esporta)([
        'tipo' => TipoDocumento::Manuale->value,
        'strumentoId' => (string) $this->strumento->id,
        'dal' => '2026-08-01',
        'al' => '2026-08-31',
        'cerca' => 'manu',
    ]);

    expect($conFiltri['filtri'])
        ->toContain('Manuale')
        ->toContain('Autoclave')
        ->toContain('01/08/2026')
        ->toContain('31/08/2026')
        ->toContain('manu');

    [, $senzaFiltri] = ($this->esporta)();

    // Il caso non filtrato dice **anche** lui qualcosa, invece di tacere: il
    // silenzio si legge come «filtro dimenticato».
    expect($senzaFiltri['filtri'])->toContain('Nessun filtro');
});

it('carries the exporter, the Ente and the date, and the same tie-break as the screen', function () {
    Documento::factory()->perStrumento($this->strumento)->create();

    $this->actingAs($this->admin);

    DB::flushQueryLog();
    DB::enableQueryLog();
    [, $dati] = ($this->esporta)();
    $sql = collect(DB::getQueryLog())
        ->pluck('query')
        ->first(fn (string $q) => str_contains($q, 'from "documenti"') && str_contains($q, 'order by'));
    DB::disableQueryLog();

    // ⚠️ Lo **stesso** tie-break dello schermo: senza, «le prime 2000 righe» non
    // sarebbero un insieme stabile fra due esportazioni identiche.
    expect($sql)->not->toBeNull()
        ->and($sql)->toContain('order by "created_at" desc, "id" desc')
        // Il tetto dichiarato, chiesto al DB come 2000+1 per **sapere** di aver
        // troncato senza contare l'intera tabella con una seconda query.
        ->and($sql)->toContain('limit 2001');

    $foglio = view('pdf.elenco-documenti', $dati)->render();

    // `e()` e non il nome nudo: faker produce anche cognomi con l'apostrofo
    // (O'Reilly), che Blade scrive `O&#039;Reilly`. Senza escape il test passa
    // quasi sempre e fallisce quando capita quel nome — cioè in CI.
    expect($foglio)->toContain(e($this->admin->name))
        ->toContain('Ente A')
        ->toContain(now()->format('d/m/Y'));
});

it('declares the truncation instead of silently cutting', function () {
    // Un limite dichiarato vale più di un limite scoperto: un foglio che finisce
    // a metà senza dirlo è un documento che mente. La soglia vera (2000 righe)
    // non si prova creando 2001 documenti — costerebbe minuti a ogni giro; si
    // prova che il tetto è **chiesto al DB** (l'assert su `limit 2001` qui
    // sopra) e che il foglio lo **dichiara** quando scatta.
    $dati = [
        'righe' => collect(),
        'troncato' => true,
        'massimo' => 2000,
        'filtri' => 'Nessun filtro.',
        'generatoIl' => now(),
        'generatoDa' => null,
        'ente' => 'Ente A',
    ];

    expect(view('pdf.elenco-documenti', $dati)->render())->toContain('Elenco troncato');

    // E tace quando non c'è nulla da dichiarare, o la riga diventerebbe rumore
    // che si smette di leggere.
    expect(view('pdf.elenco-documenti', [...$dati, 'troncato' => false])->render())
        ->not->toContain('Elenco troncato');
});

it('keeps a trashed intervento from breaking the sheet', function () {
    // ⚠️ Il morph passa dai global scope del model puntato, e `Intervento` ha
    // `SoftDeletes`: un intervento cestinato lascia il suo documento in piedi con
    // la relazione che risolve a `null`. Un `->documentabile->descrizione` diretto
    // sarebbe un 500 — sul foglio come a schermo.
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create(['descrizione' => 'Taratura annuale']);
    Documento::factory()->perIntervento($intervento)->create(['nome' => 'certificato.pdf']);
    $intervento->delete();

    $this->actingAs($this->admin);
    [$risposta, $dati] = ($this->esporta)();

    expect(substr($risposta->getContent(), 0, 4))->toBe('%PDF')
        ->and(($this->nomiSulFoglio)($dati))->toBe(['certificato.pdf']);

    expect(view('pdf.elenco-documenti', $dati)->render())->toContain('La macchina');
});

it('keeps a binned machine from breaking the sheet', function () {
    // La gemella della prova a schermo: una macchina cestinata lascia i suoi
    // documenti in elenco con la relazione a `null` — e un PDF che esplode è un
    // PDF che nessuno riesce a stampare il giorno della verifica.
    Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'orfano.pdf']);
    $this->strumento->delete();

    $this->actingAs($this->admin);
    [$risposta, $dati] = ($this->esporta)();

    expect(substr($risposta->getContent(), 0, 4))->toBe('%PDF')
        ->and(($this->nomiSulFoglio)($dati))->toBe(['orfano.pdf']);

    expect(view('pdf.elenco-documenti', $dati)->render())->toContain('Macchina cestinata');
});

// --- Chi può chiederlo ---

it('keeps the export out of the Tecnico hands, by permission', function () {
    // Il Tecnico è l'unico ruolo che ha `documenti.view` e **non**
    // `documenti.export_pdf`: sul campo legge e chiude, ma non si porta via un
    // foglio con l'archivio di un cliente. È ciò che rende falsificabile il
    // secondo `can:` della rotta — senza un ruolo che li distingua, i due
    // middleware sarebbero indistinguibili.
    $tecnico = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole('Tecnico');

    expect($tecnico->can('documenti.view'))->toBeTrue()
        ->and($tecnico->can('documenti.export_pdf'))->toBeFalse();
});

it('refuses the Tecnico even with no route middleware in front of it', function () {
    // 🔴 **La guardia che regge da sola.** I tre casi qui sotto dipendono dalla
    // rotta, che questa lavorazione non può registrare: finché non esiste, si
    // saltano — e per tutto quel tempo l'unica affermazione su «chi può portarsi
    // via l'archivio» non verrebbe eseguita da nessuna asserzione. Peggio: il
    // giorno in cui la rotta nascesse con il solo `can:documenti.view` — la forma
    // naturale, copiata da `/documenti` — il Tecnico si porterebbe via in PDF
    // l'archivio documentale dell'Ente. Il controller autorizza quindi **da sé**,
    // e questo caso lo invoca senza alcun middleware davanti.
    $tecnico = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole('Tecnico');

    Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'da-non-portare-via.pdf']);

    $this->actingAs($tecnico);

    expect(fn () => ($this->esporta)())->toThrow(AuthorizationException::class);
});

it('refuses a guest invoking the export directly', function () {
    // Senza utente non c'è nessun permesso da concedere: `Gate::authorize()`
    // nega. Il caso vale anche a rotta registrata, perché è la prova che il
    // controller non si appoggia all'`auth` di qualcun altro per esistere.
    Documento::factory()->perStrumento($this->strumento)->create();

    expect(fn () => ($this->esporta)())->toThrow(AuthorizationException::class);
});

it('lets through whoever holds both permissions', function () {
    // Il positivo di controllo delle due righe qui sopra: senza, un
    // `Gate::authorize('mai')` le terrebbe verdi entrambe negando a tutti.
    expect($this->admin->can('documenti.view'))->toBeTrue()
        ->and($this->admin->can('documenti.export_pdf'))->toBeTrue();

    Documento::factory()->perStrumento($this->strumento)->create(['nome' => 'consentito.pdf']);

    $this->actingAs($this->admin);
    [, $dati] = ($this->esporta)();

    expect(($this->nomiSulFoglio)($dati))->toBe(['consentito.pdf']);
});

it('forbids whoever cannot export, the Tecnico first of all', function () {
    $tecnico = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole('Tecnico');

    $this->actingAs($tecnico)->get(route('documenti.export-pdf'))->assertForbidden();
})->skip(
    fn () => ! Route::has('documenti.export-pdf'),
    'La rotta documenti.export-pdf non è ancora registrata: routes/web.php è fuori dal perimetro di questa lavorazione.'
);

it('redirects guests to login', function () {
    $this->get(route('documenti.export-pdf'))->assertRedirect(route('login'));
})->skip(
    fn () => ! Route::has('documenti.export-pdf'),
    'La rotta documenti.export-pdf non è ancora registrata: routes/web.php è fuori dal perimetro di questa lavorazione.'
);

it('declares both guards on the route itself', function () {
    // Difesa in profondità: il rifiuto è già provato sul controller, che i due
    // permessi li impone da sé. Questo assert verifica che siano **dichiarati
    // anche sulla rotta**, così il 403 arriva prima di costruire la query — e se
    // un giorno la rotta nascesse con il solo `can:documenti.view`, è questa
    // riga a dirlo.
    $middleware = Route::getRoutes()->getByName('documenti.export-pdf')->gatherMiddleware();

    expect($middleware)->toContain('can:documenti.view')
        ->and($middleware)->toContain('can:documenti.export_pdf')
        ->and($middleware)->toContain('auth')
        ->and($middleware)->toContain('account.lockout')
        ->and($middleware)->toContain('two-factor.enforce');
})->skip(
    fn () => ! Route::has('documenti.export-pdf'),
    'La rotta documenti.export-pdf non è ancora registrata: routes/web.php è fuori dal perimetro di questa lavorazione.'
);
