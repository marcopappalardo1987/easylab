<?php

use App\Livewire\Piattaforma\Cabina;
use App\Livewire\Piattaforma\Concerns\EsportaClienti;
use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Listino\GovernoListino;
use App\Support\Piattaforma\CsvSicuro;
use App\Support\Piattaforma\EsportazioneClienti;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * 🔴 Le esportazioni della cabina di regia: l'unica superficie che porta i dati
 * dei clienti **fuori** dall'applicazione (S6).
 *
 * Il rischio di questa funzione non è il file storto: è il file **giusto con
 * dentro una riga di troppo**. Un export che ignorasse i filtri, includesse
 * EasyLab o portasse una colonna che a schermo sta dietro un secondo permesso
 * non darebbe nessun sintomo — il file si apre, le colonne tornano, e nessuno
 * confronta ciò che ha scaricato con ciò che vedeva. Per questo i negativi qui
 * sono la maggioranza e vengono prima (Policy di Code Review §🔴), e ciascuno
 * porta accanto l'asserzione positiva che lo rende non-vacuo: un
 * `->not->toContain()` da solo è verde anche su un file vuoto.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $this->actingAs($this->superadmin->fresh());

    $this->rossi = Account::factory()->saas()->create([
        'ragione_sociale' => 'Gruppo Rossi',
        'partita_iva' => '01234567890',
    ]);
    $this->sedeRossi = UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)
        ->create(['nome' => 'Laboratorio San Raffaele']);

    $this->bianchi = Account::factory()->create(['ragione_sociale' => 'Bianchi SRL']);
    $this->sedeBianchi = UnitaOrganizzativa::factory()->ente()->perAccount($this->bianchi)
        ->create(['nome' => 'Sede di Bergamo']);
});

/**
 * Il CSV **come arriva al browser**: si legge dall'effect di download, non
 * chiamando l'azione sull'istanza.
 *
 * ⚠️ Non è pignoleria. `SupportFileDownloads` riconosce solo `StreamedResponse`
 * e `BinaryFileResponse`: una risposta di tipo diverso viene buttata via **in
 * silenzio** e l'utente non scarica nulla. Leggere qui il valore di ritorno del
 * metodo salterebbe proprio il pezzo che si rompe, e il test resterebbe verde
 * su un bottone che non fa niente.
 */
function csvDi(Testable $t): string
{
    return base64_decode(data_get($t->call('esportaCsv')->effects, 'download.content'));
}

/** Le sole righe dati del CSV: senza BOM, senza intestazione, senza riga vuota finale. */
function righeCsv(string $csv): array
{
    $linee = explode("\n", trim(str_replace(CsvSicuro::BOM, '', $csv)));

    return array_slice($linee, 1);
}

/**
 * Un metodo **interno** del componente, letto senza aprirgli una porta.
 *
 * ⛔ `matriceClienti()` e `datiFoglioClienti()` sono `protected` di proposito:
 * in Livewire ogni metodo pubblico non statico è invocabile dal browser, quindi
 * renderli pubblici «per comodità di test» aggiungerebbe una via che porta
 * fuori l'intero portafoglio **senza** la riga di audit (vedi
 * `gives the browser no way out of the portfolio that skips the audit row`).
 * La closure legata alla classe del componente li raggiunge dal test senza
 * cambiare la superficie: la comodità di test non paga con una porta in più.
 */
function interno(Testable $t, string $metodo): mixed
{
    $componente = $t->instance();

    return Closure::bind(fn () => $this->{$metodo}(), $componente, $componente::class)();
}

/** Il foglio PDF renderizzato in HTML, con **esattamente** i dati che l'azione gli passa. */
function foglioDi(Testable $t): string
{
    return view('pdf.clienti-piattaforma', interno($t, 'datiFoglioClienti'))->render();
}

/** Una riga della matrice, indicizzata per nome di colonna. */
function rigaEsportata(Testable $t, string $ragioneSociale): array
{
    [$intestazioni, $righe] = interno($t, 'matriceClienti');

    foreach ($righe as $riga) {
        if ($riga[0] === $ragioneSociale) {
            return array_combine($intestazioni, $riga);
        }
    }

    return [];
}

// --- Negativi: chi e cosa NON deve finire nel file ---

it('refuses the export to whoever cannot see the platform', function (string $ruolo) {
    // ⚠️ **Questo test non falsifica il `Gate::authorize()` dentro l'azione**, e
    // va detto invece di lasciarlo credere: togliendolo, a negare sarebbe la
    // porta di `VistaPiattaforma` una riga più sotto, e il test resterebbe
    // verde. Prova l'invariante che conta per la privacy — chi non entra nella
    // pagina non ottiene il file — e la difesa in profondità resta dichiarata
    // nel docblock di `EsportaClienti`, dove si dice anche quando comincerebbe
    // a servire davvero (uno `skipRender()`, o una lettura da memoria).
    //
    // ⚠️ Le azioni si chiamano su un'**istanza nuda**, non su `Livewire::test()`:
    // montare il componente con un ruolo senza permesso restituisce già un 403
    // dal `render()`, quindi `->call()` non ci arriverebbe mai e il test
    // proverebbe la porta della pagina invece di quella dell'azione. È la strada
    // che percorrerebbe un `skipRender()`.
    $this->actingAs(utenteConRuolo($ruolo));

    expect(fn () => (new Cabina)->esportaCsv())->toThrow(AuthorizationException::class);
    expect(fn () => (new Cabina)->esportaPdf())->toThrow(AuthorizationException::class);

    // E la pagina che li offre resta chiusa: il bottone non è nemmeno servito.
    Livewire::actingAs(utenteConRuolo($ruolo))->test(Cabina::class)->assertForbidden();
})->with(RUOLI_SENZA_PIATTAFORMA);

it('never puts EasyLab in the customers file', function () {
    // La cabina non mostra EasyLab, quindi il file non deve contenerla: chi non
    // vede una riga a schermo non la trova nell'export. Sul ricavo peggiora —
    // la riga aggiungerebbe all'MRR un prezzo di listino che Stripe non incassa
    // da nessuno.
    Account::factory()->saas()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab Piattaforma']);

    $t = Livewire::test(Cabina::class);
    $csv = csvDi($t);

    // ⛔ L'ago positivo NON è un di più: senza, l'asserzione negativa sarebbe
    // soddisfatta anche da un file vuoto o da un'azione che non produce nulla.
    expect($csv)->toContain('Gruppo Rossi')
        ->and($csv)->not->toContain('EasyLab Piattaforma');

    $foglio = foglioDi($t);

    expect($foglio)->toContain('Gruppo Rossi')
        ->and($foglio)->not->toContain('EasyLab Piattaforma');
});

it('never puts a trashed customer in the file', function () {
    // Prova che il `SoftDeletingScope` è ancora applicato, cioè che nessuno ha
    // sostituito la porta con un `withoutGlobalScopes()` nudo — che porterebbe
    // via anche il soft delete, come già successo su `Account::enti()`.
    Account::factory()->saas()->create(['ragione_sociale' => 'Verdi Cessata'])->delete();

    $csv = csvDi(Livewire::test(Cabina::class));

    expect($csv)->toContain('Gruppo Rossi')
        ->and($csv)->not->toContain('Verdi Cessata');
});

it('exports only the rows the active filters leave on screen', function () {
    // 🔴 Il difetto di privacy vero di questa funzione: un file che esporta
    // tutto mentre la pagina ne mostra dodici. Non è una scomodità — è la
    // differenza fra «ho scaricato i bloccati» e «ho scaricato il portafoglio».
    Account::factory()->bloccato()->create(['ragione_sociale' => 'Neri Bloccata']);

    $t = Livewire::test(Cabina::class)->set('stato', 'bloccato_manuale');
    $csv = csvDi($t);

    expect($csv)->toContain('Neri Bloccata')
        ->and($csv)->not->toContain('Gruppo Rossi');

    // Il conteggio si lega a ciò che la **pagina** dichiara di aver trovato: è
    // l'invariante «stesso perimetro», non «stesso numero per caso».
    expect(righeCsv($csv))->toHaveCount($t->viewData('clienti')->total());
});

it('applies the search filter to the file too', function () {
    // La stessa invariante per l'altro filtro, e su una sede: la ricerca per
    // nome di sede è la strada che passa da una sottoquery, cioè quella dove un
    // export scritto a parte perderebbe un pezzo di catena senza accorgersene.
    $t = Livewire::test(Cabina::class)->set('search', 'San Raffaele');
    $csv = csvDi($t);

    expect($csv)->toContain('Gruppo Rossi')
        ->and($csv)->not->toContain('Bianchi SRL')
        ->and(righeCsv($csv))->toHaveCount($t->viewData('clienti')->total());
});

it('does not carry the fiscal data the table does not show', function () {
    // ⚠️ Il perimetro non è solo di riga: è anche **di colonna**. A schermo pec,
    // codice SDI e codice fiscale stanno dietro la modale `apriFiscali()`,
    // gatata da `@can('manage', $cliente)`; le due `*_reason` sono annotazione
    // interna per ADR-013 e non si mostrano nemmeno al cliente su `/bloccato`.
    // Un file, una volta uscito, non ha modo di degradare per permesso.
    $this->rossi->forceFill([
        'pec' => 'pecriservata@example.test',
        'codice_destinatario_sdi' => 'SDIXYZ9',
        'codice_fiscale' => 'RSSMRA80A01H501U',
        'locked_reason' => 'Annotazione interna sul contenzioso',
        'stripe_lock_reason' => 'Annotazione interna di Stripe',
    ])->save();

    $t = Livewire::test(Cabina::class);
    $csv = csvDi($t);
    $foglio = foglioDi($t);

    // ⛔ Un ago per chiamata: `toContain()` è variadico, e un secondo argomento
    // diventerebbe un secondo ago che rende l'asserzione negativa vera sempre.
    foreach ([$csv, $foglio] as $file) {
        expect($file)->toContain('Gruppo Rossi')
            ->and($file)->not->toContain('pecriservata@example.test')
            ->and($file)->not->toContain('SDIXYZ9')
            ->and($file)->not->toContain('RSSMRA80A01H501U')
            ->and($file)->not->toContain('Annotazione interna sul contenzioso')
            ->and($file)->not->toContain('Annotazione interna di Stripe')
            // La P.IVA invece c'è: la tabella la mostra sotto la ragione
            // sociale, quindi è dentro il perimetro di colonna.
            ->and($file)->toContain('01234567890');
    }
});

it('lists only the filters actually applied', function () {
    // 🔴 La lezione di `ordinamentoEffettivo()`, spostata dalla freccia di
    // intestazione al foglio — con la differenza che una freccia si corregge
    // ricaricando e un PDF resta. Le property sono `#[Url]`: valgono ciò che c'è
    // nella query string, e possono valere `password` mentre la query ha usato
    // il fallback.
    $t = Livewire::test(Cabina::class)
        ->set('stato', 'password')
        ->set('sortBy', 'password')
        ->set('piano', 'inesistente');

    $foglio = foglioDi($t);

    expect($foglio)->toContain('Nessun filtro: tutti i clienti.')
        ->and($foglio)->not->toContain('Filtri attivi')
        ->and($foglio)->not->toContain('password')
        ->and($foglio)->not->toContain('inesistente');

    // E le righe restano davvero non filtrate: il foglio dice il vero perché la
    // query ha fatto il vero, non perché la frase è generica.
    expect(interno($t, 'matriceClienti')[1])->toHaveCount(2);
});

// --- Positivi: il file fa il suo mestiere ---

it('lets both platform roles take the file out', function (string $ruolo) {
    // ⛔ La metà che rende non-vacuo il negativo qui sopra: senza, un'azione che
    // lanciasse **sempre** — per un errore qualunque, non per il permesso —
    // lascerebbe verde l'intero blocco di autorizzazione.
    Livewire::actingAs(utenteConRuolo($ruolo))->test(Cabina::class)
        ->call('esportaCsv')
        ->assertFileDownloaded('clienti-easylab-'.now()->format('Y-m-d').'.csv');
})->with(RUOLI_CON_PIATTAFORMA);

it('exports every filtered row and not only the visible page', function () {
    // L'invariante **opposta** a quella dei filtri, e va congelata o qualcuno la
    // «aggiusterà» esportando la sola pagina: la paginazione non è un filtro di
    // privacy, perché chi vede la prima pagina può sfogliare fino all'ultima.
    Account::factory()->count(23)->create();

    $t = Livewire::test(Cabina::class);

    expect($t->viewData('clienti')->perPage())->toBe(20)
        ->and($t->viewData('clienti')->count())->toBe(20)
        // 23 + i due della fixture.
        ->and(righeCsv(csvDi($t)))->toHaveCount(25);
});

it('escapes a cell Excel would run as a formula', function () {
    // 🔴 La ragione sociale è testo che noi non scriviamo, e questo file esce
    // dall'applicazione e finisce sul portatile di chi amministra.
    Account::factory()->create(['ragione_sociale' => "=cmd|' /C calc'!A0"]);

    expect(csvDi(Livewire::test(Cabina::class)))->toContain("'=cmd|' /C calc'!A0");
});

it('never explodes on a customer whose plan left the catalogue', function () {
    // ⛔ `Account::valoreMensileCent()` **lancia** su un piano fuori catalogo, e
    // la cabina offre apposta il filtro per trovare quelle righe: esportare con
    // quel metodo nel ciclo manderebbe in 500 esattamente l'unico filtro che
    // esiste per riparare il dato.
    Account::factory()->create(['ragione_sociale' => 'Orfani SPA', 'piano' => 'vecchio_2019']);

    $t = Livewire::test(Cabina::class)->set('piano', Cabina::FUORI_CATALOGO);

    $riga = rigaEsportata($t, 'Orfani SPA');

    expect($riga['a_catalogo'])->toBe('no')
        ->and($riga['valore_mensile_eur'])->toBe('0')
        // ⚠️ `?` e `illimitato` restano **distinti**: fonderli farebbe leggere il
        // caso corrotto come il più permissivo dei due, proprio sulla riga che
        // la pagina invita a riparare.
        ->and($riga['sedi_max'])->toBe('?')
        ->and($riga['piano_etichetta'])->toContain('fuori catalogo');

    // E i due formati rispondono davvero, invece di morire sul piano orfano.
    expect(csvDi($t))->toContain('Orfani SPA')
        ->and(foglioDi($t))->toContain('Orfani SPA');
});

it('keeps the unlimited plan apart from the unknown one', function () {
    // 🔴 L'altra metà della distinzione qui sopra, e la metà che **conta**:
    // `?` = «non lo sappiamo» (piano fuori catalogo), `illimitato` = «il piano
    // non ha tetto». Fonderle farebbe leggere una riga sana come dato corrotto,
    // o il contrario, proprio sul foglio che si stampa per decidere dove
    // intervenire.
    //
    // ⛔ **La fixture deve contenere un piano SENZA tetto**, o il test non
    // esercita il ramo che dice di congelare: la versione precedente asseriva
    // `'5'` su un piano `saas` — un valore finito, cioè né `?` né `illimitato`
    // — e la mutazione «`null` → `?`», che fonde esattamente i due casi,
    // lasciava tutta la suite verde. Il piano Enterprise senza tetto è previsto
    // dal listino (`GovernoListino`: «vuoto per illimitato»), quindi non è un
    // caso di laboratorio.
    GovernoListino::crea([
        'codice' => 'enterprise',
        'etichetta' => 'Enterprise',
        'prezzo_mensile_cent' => 99900,
        'max_enti' => '',
    ]);

    $senzaTetto = Account::factory()->create([
        'ragione_sociale' => 'Enterprise SPA',
        'piano' => 'enterprise',
    ]);

    $t = Livewire::test(Cabina::class);

    expect(rigaEsportata($t, 'Enterprise SPA')['sedi_max'])->toBe('illimitato')
        // I tre valori restano **tre**: un tetto finito non diventa né `?` né
        // `illimitato`, e il fuori catalogo resta `?` (test qui sopra).
        ->and(rigaEsportata($t, 'Gruppo Rossi')['sedi_max'])->toBe('5')
        ->and($senzaTetto->piano)->toBe('enterprise');

    // E la parola arriva davvero **nel file**, non solo nella matrice: è lì che
    // qualcuno la legge.
    expect(csvDi($t))->toContain('illimitato')
        ->and(foglioDi($t))->toContain('illimitato');
});

it('keeps the two lockout sources apart, because they are two', function () {
    // ADR-013: uno sblocco manuale deciso guardando un foglio che dice solo
    // «bloccato» riaprirebbe un contenzioso a un pagamento riuscito.
    Account::factory()->bloccatoDaStripe()->create(['ragione_sociale' => 'Insoluta SRL']);

    $t = Livewire::test(Cabina::class);
    $insoluta = rigaEsportata($t, 'Insoluta SRL');
    $sana = rigaEsportata($t, 'Gruppo Rossi');

    expect($insoluta['bloccato'])->toBe('sì')
        ->and($insoluta['bloccato_per_insoluto'])->toBe('sì')
        ->and($insoluta['bloccato_a_mano'])->toBe('no')
        ->and($sana['bloccato'])->toBe('no');
});

it('counts the sedi and the instruments the same way the table does', function () {
    Strumento::factory()->count(3)->forNode($this->sedeRossi)->create();
    UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)->create(['nome' => 'Sede di Pavia']);

    $riga = rigaEsportata(Livewire::test(Cabina::class), 'Gruppo Rossi');

    expect($riga['sedi'])->toBe('2')
        ->and($riga['strumenti'])->toBe('3');
});

it('says on the sheet that the four numbers do not follow the filters', function () {
    // ⚠️ Un foglio che stampasse i KPI di piattaforma sopra una tabella filtrata
    // senza ripetere questa didascalia **mentirebbe per accostamento**: chi lo
    // legge le due cose le somma. Accanto ci va il totale a listino delle sole
    // righe in elenco, etichettato come tale — quello sì segue i filtri.
    // ⚠️ **I numeri della fixture sono scelti perché DISCRIMININO.** Con un solo
    // cliente SaaS attivo e uno bloccato, il totale del filtrato (49 €) e l'MRR
    // di piattaforma (98 €) sono diversi, e «2 clienti» è diverso da «3»: un
    // foglio che stampasse il KPI al posto del totale in elenco, o viceversa,
    // diventa rosso. Con fixture che coincidono il test sarebbe una descrizione
    // del comportamento attuale, non una rete.
    Account::factory()->saas()->bloccato()->create(['ragione_sociale' => 'Neri Bloccata']);

    $t = Livewire::test(Cabina::class)->set('stato', 'attivo');
    $foglio = foglioDi($t);

    expect($foglio)->toContain('non seguono i filtri')
        ->and($foglio)->toContain('Filtri attivi')
        // Le etichette sono quelle delle `<option>` a schermo: un foglio che
        // scrivesse «bloccato_stripe» costringerebbe a tradurre, e chi traduce
        // sbaglia.
        ->and($foglio)->toContain('Stato Attivi')
        ->and($foglio)->toContain('2 clienti su 3')
        ->and($foglio)->toContain('Totale a listino delle righe in elenco: 49')
        // Il KPI di piattaforma resta quello **non** filtrato, e sta sul foglio
        // con la sua didascalia: 98 € comprende il cliente bloccato, perché
        // ADR-013 dice che il blocco è una porta chiusa, non una disdetta.
        ->and($foglio)->toContain('98 &euro; a listino');
});

it('returns a real PDF and a named CSV', function () {
    $giorno = now()->format('Y-m-d');

    $pdf = Livewire::test(Cabina::class)->call('esportaPdf')
        ->assertFileDownloaded('clienti-easylab-'.$giorno.'.pdf');

    // ⛔ Il tipo di risposta è la cosa che si rompe: `Pdf::download()` rende un
    // `Illuminate\Http\Response`, che `SupportFileDownloads` **non riconosce** —
    // il file verrebbe buttato via in silenzio. Qui si verifica che sia arrivato
    // un PDF vero, non solo che l'azione non abbia lanciato.
    expect(substr(base64_decode(data_get($pdf->effects, 'download.content')), 0, 4))->toBe('%PDF');

    $csv = Livewire::test(Cabina::class)->call('esportaCsv')
        ->assertFileDownloaded('clienti-easylab-'.$giorno.'.csv');

    expect(base64_decode(data_get($csv->effects, 'download.content')))->toStartWith(CsvSicuro::BOM);
});

it('declares on the page which rows the file will carry', function () {
    // ⚠️ L'invariante va detta **a chi la usa**, non solo scritta nel codice:
    // senza, qualcuno esporterebbe credendo di aver preso venti clienti — o
    // crederebbe di averli presi tutti mentre un filtro è acceso. È anche la
    // prova che le due azioni hanno un modo di essere invocate: un metodo
    // pubblico senza bottone è codice morto che i test tengono in vita.
    Account::factory()->count(23)->create();

    // ⛔ Niente apostrofi negli aghi: `assertSee` di Livewire **escapa** l'ago,
    // e «L'esportazione» arriverebbe nell'HTML come `L&#039;esportazione`.
    Livewire::test(Cabina::class)
        ->assertSee('esportazione segue i filtri e copre tutti i 25')
        ->assertSee('wire:click="esportaCsv"', false)
        ->assertSee('wire:click="esportaPdf"', false);
});

it('makes the CSV and the sheet carry the same rows', function () {
    // La prova che la matrice è **una** (`EsportazioneClienti`) e non due: due
    // generatori divergono, e il giorno in cui divergono nessuno se ne accorge
    // perché nessuno apre i due file uno accanto all'altro.
    Account::factory()->bloccato()->create(['ragione_sociale' => 'Neri Bloccata']);

    $t = Livewire::test(Cabina::class);

    [$intestazioni, $righe] = interno($t, 'matriceClienti');
    $dati = interno($t, 'datiFoglioClienti');

    // Le **righe** sono le stesse: è questa l'invariante «una matrice sola». Le
    // intestazioni no, e di proposito — vedi il test sulle intestazioni in prosa
    // qui sotto — ma restano appaiate una a una.
    expect($dati['righe'])->toBe($righe)
        ->and($dati['intestazioni'])->toHaveCount(count($intestazioni))
        ->and(righeCsv(csvDi($t)))->toHaveCount(count($righe));

    $foglio = foglioDi($t);

    foreach (['Gruppo Rossi', 'Bianchi SRL', 'Neri Bloccata'] as $cliente) {
        expect($foglio)->toContain($cliente);
    }
});

it('exports the rows in the same order the page shows them, tie-break included', function () {
    // ⚠️ L'ordine **si riusa**, non si ricostruisce: `queryClienti()` finisce con
    // `->orderBy($sortBy, $sortDir)->orderBy('id')`, e su Postgres i pari si
    // riordinano fra una query e l'altra — chi perde il tie-break ottiene un
    // file con una riga doppia e una mancante (CLAUDE.md, 25 Ago 2026).
    $pari = Account::factory()->count(3)->create(['ragione_sociale' => 'Pari Merito']);

    $t = Livewire::test(Cabina::class)->set('sortBy', 'ragione_sociale')->set('sortDir', 'desc');

    [, $righe] = interno($t, 'matriceClienti');
    $nelFile = array_column($righe, 0);

    // Discendente: «Pari Merito» ×3, poi «Gruppo Rossi», poi «Bianchi SRL».
    expect($nelFile)->toBe([
        'Pari Merito', 'Pari Merito', 'Pari Merito', 'Gruppo Rossi', 'Bianchi SRL',
    ]);

    // La pagina e il file cominciano dalla stessa riga, con lo stesso ordine.
    expect($t->viewData('clienti')->pluck('ragione_sociale')->all())->toBe($nelFile);

    // ⛔ **La prova del tie-break si fa sull'SQL, non sui dati** (CLAUDE.md, 25
    // Ago 2026): togliendo `->orderBy('id')` la suite resta verde su entrambi i
    // driver a seconda del piano scelto, e un test che coglie il difetto una
    // volta su tre non è una rete, è un aneddoto.
    DB::enableQueryLog();
    DB::flushQueryLog();
    interno($t, 'matriceClienti');
    $sql = collect(DB::getQueryLog())->pluck('query')
        ->first(fn (string $q) => str_contains($q, 'from "accounts"'));
    DB::disableQueryLog();

    expect($sql)->toContain('order by "ragione_sociale" desc, "id" asc')
        ->and($pari)->toHaveCount(3);
});

it('writes one audit row per export, with the normalised filters', function () {
    // 🚩 Terza eccezione al perimetro di ADR-027 («si tracciano le scritture,
    // non le letture»), dopo il download documenti (ADR-026) e l'accesso tecnico
    // (ADR-030). La riga nell'ADR la scrive Marco: qui c'è il codice, e la
    // decisione si annulla cancellando `tracciaEsportazione()`.
    Livewire::test(Cabina::class)->set('stato', 'password')->call('esportaCsv');

    $riga = Activity::where('log_name', AuditLog::NAME)->latest('id')->first();

    expect($riga->description)->toBe('Esportazione clienti')
        ->and($riga->causer_id)->toBe($this->superadmin->id)
        // Senza soggetto: il soggetto sarebbero *tutti* i clienti.
        ->and($riga->subject_id)->toBeNull()
        ->and($riga->properties['formato'])->toBe('csv')
        ->and($riga->properties['righe'])->toBe(2)
        // ⚠️ I filtri sono quelli **normalizzati**: `stato = password` non è mai
        // stato applicato, e un registro che lo elencasse direbbe di aver
        // esportato un insieme diverso da quello uscito.
        ->and($riga->properties['filtri'])->toBe([]);
});

it('does not carry the Stripe id nor the people who are not the subject of the list', function () {
    // ⚠️ Le altre **due** metà del perimetro di colonna, che il docblock di
    // `EsportazioneClienti` dichiara e che nessun test copriva:
    //  · `stripe_id` — identificativo di un sistema terzo, inutile su un foglio
    //    e sufficiente a **correlare** due esportazioni fatte a mesi di
    //    distanza;
    //  · i **membri** e le loro email — dati personali di persone che non sono
    //    l'oggetto di questo elenco (ADR-020, minimizzazione).
    // Senza queste righe, una colonna `stripe_id` «per riconciliare col foglio
    // di Stripe» o una `referente_email` «per avere un contatto accanto al
    // cliente» entrerebbero senza far diventare rosso nulla.
    $this->rossi->forceFill(['stripe_id' => 'cus_CORRELABILE9'])->save();

    $referente = User::factory()->create([
        'name' => 'Referente Non Oggetto',
        'email' => 'referente.privato@example.test',
    ]);
    $this->rossi->aggiungiMembro($referente);

    $t = Livewire::test(Cabina::class);
    $csv = csvDi($t);
    $foglio = foglioDi($t);

    // ⛔ Un ago per chiamata: `toContain()` è variadico, e un secondo argomento
    // diventerebbe un secondo ago che rende l'asserzione negativa vera sempre.
    foreach ([$csv, $foglio] as $file) {
        expect($file)->toContain('Gruppo Rossi')
            ->and($file)->not->toContain('cus_CORRELABILE9')
            ->and($file)->not->toContain('referente.privato@example.test')
            ->and($file)->not->toContain('Referente Non Oggetto');
    }

    // La controprova che il membro **esiste** davvero: senza, i tre negativi
    // sarebbero soddisfatti da una fixture che non ha mai attaccato nessuno.
    expect($this->rossi->fresh()->membri()->pluck('email')->all())
        ->toContain('referente.privato@example.test');
});

it('prints the sheet columns in words, and the file ones in machine names', function () {
    // Un PDF non si ri-importa: lo `snake_case` di `intestazioni()` è motivato
    // dal giro di andata e ritorno in un foglio di calcolo, che vale per il CSV
    // e **solo** per il CSV. Il foglio si stampa per una riunione, e ogni altro
    // PDF del progetto (`storico-strumento`, `elenco-documenti`) intesta in
    // prosa. La decisione «una matrice sola» riguarda le **righe**.
    $foglio = foglioDi(Livewire::test(Cabina::class));

    expect($foglio)->toContain('Bloccato per insoluto')
        ->and($foglio)->toContain('Valore mensile')
        ->and($foglio)->toContain('Cliente dal')
        // ⛔ E il nome macchina non deve restare accanto: un foglio con
        // entrambe le forme sarebbe la prova che qualcuno ha aggiunto le
        // etichette senza togliere le vecchie.
        ->and($foglio)->not->toContain('bloccato_per_insoluto')
        ->and($foglio)->not->toContain('valore_mensile_eur');

    // Il CSV resta invece in `snake_case`, che è ciò che si ri-importa: le due
    // scelte sono opposte di proposito, e questo test è il posto in cui lo si
    // legge.
    $csv = csvDi(Livewire::test(Cabina::class));

    expect($csv)->toContain('bloccato_per_insoluto')
        ->and($csv)->not->toContain('Bloccato per insoluto');

    // ⚠️ Due elenchi che nominano le **stesse** colonne: la sola cosa che può
    // rompersi è aggiungerne una a uno solo dei due, e da lì in poi il foglio
    // intesterebbe la colonna sbagliata su ogni riga.
    expect(EsportazioneClienti::intestazioniLeggibili())
        ->toHaveCount(count(EsportazioneClienti::intestazioni()));
});

it('gives the browser no way out of the portfolio that skips the audit row', function () {
    // 🚩 La riga di audit è la TERZA eccezione al perimetro di ADR-027, e vale
    // quanto la strada che copre: in Livewire **ogni metodo pubblico non
    // statico è invocabile dal browser** — `HandleComponents::callMethods()`
    // costruisce l'elenco con `Utils::getPublicMethodsDefinedBySubClass()`, che
    // filtra su `isPublic() && ! isStatic()` e toglie solo `render`, e i metodi
    // di trait appiattiti in `Cabina` ci rientrano.
    //
    // 🔴 `matriceClienti()` e `datiFoglioClienti()` erano `public` «perché un
    // test possa leggerli senza passare dagli effects»: un `$wire.matriceClienti()`
    // dalla console restituiva l'intero portafoglio filtrato dentro l'effect
    // `returns`, e `datiFoglioClienti()` in più i KPI, i filtri e chi ha
    // generato — senza toccare `tracciaEsportazione()`. L'autorizzazione teneva
    // (si passa comunque da `VistaPiattaforma::porta()`), quindi non era una
    // fuga verso chi non doveva: era la **tracciabilità** a essere falsa.
    $azioni = collect((new ReflectionClass(EsportaClienti::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $m) => $m->isStatic())
        ->map(fn (ReflectionMethod $m) => $m->getName())
        ->sort()->values()->all();

    // L'elenco è **congelato**: rendere pubblico un metodo di questo trait
    // significa aggiungere una strada che porta i clienti fuori, e da qui in poi
    // va deciso a mano se traccia o no.
    expect($azioni)->toBe(['esportaCsv', 'esportaPdf']);

    foreach ($azioni as $azione) {
        Activity::where('log_name', AuditLog::NAME)->delete();

        Livewire::test(Cabina::class)->call($azione);

        expect(Activity::where('log_name', AuditLog::NAME)
            ->where('description', 'Esportazione clienti')->count())->toBe(1);
    }

    // E la porta di servizio è chiusa davvero, non solo per convenzione: il
    // browser non raggiunge la matrice.
    foreach (['matriceClienti', 'datiFoglioClienti'] as $muto) {
        expect(fn () => Livewire::test(Cabina::class)->call($muto))
            ->toThrow(MethodNotFoundException::class);
    }
});
