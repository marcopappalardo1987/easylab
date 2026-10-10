<?php

use App\Livewire\Anagrafica\Albero;
use App\Livewire\Billing\PaginaAbbonamento;
use App\Livewire\Strumenti\ImportStrumenti;
use App\Models\Account;
use App\Models\Fornitore;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Billing\TettoStrumenti;
use App\Support\Billing\TettoStrumentiRaggiunto;
use App\Support\Listino\CatalogoPiani;
use App\Support\Piani;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;
use Tests\Support\BancoRegistrazione;

/**
 * Il tetto di strumenti del piano (🔗 ADR-049; ADR-032 il tetto di Enti, di cui
 * è il gemello; ADR-035 il listino).
 *
 * Il piano dice **quanti** e **a che cosa si applica** il numero: ogni sede per
 * conto suo, o il cliente in tutto. Qui si prova la regola, i due gesti che la
 * fanno rispettare (l'albero e l'import CSV) e le pagine che la dichiarano.
 *
 * Mondo: «Gruppo Rossi» sul piano `saas`, con due sedi e un reparto ciascuna;
 * «Lab Bianchi», un altro cliente sullo stesso piano, che fa da controprova.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $sede = function (Account $account, string $nome): array {
        $ente = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => $nome]);
        $reparto = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => "Reparto {$nome}"]);

        return [$ente, $reparto];
    };

    $this->rossi = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    [$this->sede, $this->reparto] = $sede($this->rossi, 'Milano');
    [$this->altraSede, $this->altroReparto] = $sede($this->rossi, 'Torino');

    $this->bianchi = Account::factory()->saas()->create(['ragione_sociale' => 'Lab Bianchi']);
    [$this->sedeBianchi, $this->repartoBianchi] = $sede($this->bianchi, 'Bianchi');

    $this->admin = User::factory()->create(['tenant_id' => $this->sede->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
    $this->rossi->aggiungiMembro($this->admin);

    // Il fornitore è obbligatorio nel form dell'albero (ADR-023).
    $this->fornitore = Fornitore::factory()->forTenant($this->sede)->create();
});

/** Mette un tetto al piano, come farebbe il listino. */
function tettoAlPiano(?int $max, string $conteggio = 'per_sede', string $piano = 'saas'): void
{
    Piani::modello($piano)->forceFill(['max_strumenti' => $max, 'conteggio_strumenti' => $conteggio])->save();
    app(CatalogoPiani::class)->dimentica();
}

/** Strumenti in un reparto. Va chiamata **prima** di autenticare qualcuno: `BelongsToTenant` forza la sede di chi è connesso. */
function strumentiNel(UnitaOrganizzativa $reparto, int $quanti): void
{
    Strumento::factory()->forNode($reparto)->count($quanti)->create();
}

function strumentiDellaSede(UnitaOrganizzativa $sede): int
{
    return DB::table('strumenti')->where('tenant_id', $sede->id)->whereNull('deleted_at')->count();
}

function csvDiStrumenti(int $righe, string $ubicazione): UploadedFile
{
    $contenuto = "nome;modello;matricola;data_installazione;ubicazione;provenienza\n";

    for ($i = 1; $i <= $righe; $i++) {
        $contenuto .= "Macchina {$i};;;;{$ubicazione};\n";
    }

    return UploadedFile::fake()->createWithContent('strumenti.csv', $contenuto);
}

// ─── La regola ───────────────────────────────────────────────────────────────

it('has no cap on a plan that declares none, and does not even count', function () {
    strumentiNel($this->reparto, 4);

    DB::enableQueryLog();
    $tetto = TettoStrumenti::dellEnte($this->sede->id);
    $query = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($tetto->massimo)->toBeNull()
        ->and($tetto->residui())->toBeNull()
        ->and($tetto->consente(10_000))->toBeTrue()
        // Nessun tetto, nessuna query sugli strumenti: su un piano illimitato
        // creare una macchina non deve costare un conteggio in più.
        ->and($query->filter(fn (string $q) => str_contains($q, '"strumenti"'))->all())->toBe([]);
});

it('counts each sede on its own when the plan says per sede', function () {
    tettoAlPiano(3, 'per_sede');

    strumentiNel($this->reparto, 3);
    strumentiNel($this->altroReparto, 1);
    strumentiNel($this->repartoBianchi, 9);
    // Un cestinato libera il suo posto.
    Strumento::factory()->forNode($this->altroReparto)->create()->delete();

    $piena = TettoStrumenti::dellEnte($this->sede->id);
    $libera = TettoStrumenti::dellEnte($this->altraSede->id);

    expect([$piena->massimo, $piena->presenti, $piena->residui(), $piena->consente()])->toBe([3, 3, 0, false])
        ->and([$libera->presenti, $libera->residui()])->toBe([1, 2])
        // Esattamente quanti ce ne stanno, non uno di più.
        ->and($libera->consente(2))->toBeTrue()
        ->and($libera->consente(3))->toBeFalse();
});

it('counts all the sedi of the contract together when the plan says per cliente', function () {
    tettoAlPiano(5, 'per_cliente');

    strumentiNel($this->reparto, 3);
    strumentiNel($this->altroReparto, 2);
    // 🔴 Un altro cliente sullo stesso piano non entra nel conto di questo.
    strumentiNel($this->repartoBianchi, 4);

    foreach ([$this->sede, $this->altraSede] as $sede) {
        $tetto = TettoStrumenti::dellEnte($sede->id);

        expect([$tetto->presenti, $tetto->residui(), $tetto->consente()])->toBe([5, 0, false]);
    }

    expect(TettoStrumenti::dellEnte($this->sedeBianchi->id)->residui())->toBe(1);
});

it('leaves a closed sede out of the count of the open ones', function () {
    tettoAlPiano(5, 'per_cliente');

    strumentiNel($this->reparto, 3);
    strumentiNel($this->altroReparto, 2);

    // Una sede chiusa non occupa per sempre il tetto di quelle aperte.
    $this->altraSede->delete();

    expect(TettoStrumenti::dellEnte($this->sede->id)->presenti)->toBe(3);
});

it('applies no cap where there is no plan to read it from', function (string $caso) {
    tettoAlPiano(1);
    strumentiNel($this->reparto, 3);

    match ($caso) {
        // Una sede senza contratto: nessun piano da applicare.
        'sede senza contratto' => DB::table('unita_organizzativa')->where('id', $this->sede->id)->update(['account_id' => null]),
        // Un piano uscito dal catalogo: si ripara dalla cabina, e non deve
        // fermare il lavoro di un laboratorio che non ne ha colpa.
        'piano fuori catalogo' => DB::table('accounts')->where('id', $this->rossi->id)->update(['piano' => 'fantasma']),
    };

    $tetto = TettoStrumenti::dellEnte($this->sede->id);

    expect($tetto->massimo)->toBeNull()
        ->and($tetto->consente())->toBeTrue();

    TettoStrumenti::esigi($this->sede->id, 50);
})->with(['sede senza contratto', 'piano fuori catalogo']);

it('keeps what is already there when the cap comes down, and only stops the next one', function () {
    strumentiNel($this->reparto, 4);

    // Il piano si restringe sotto ciò che il cliente ha già.
    tettoAlPiano(2);

    $tetto = TettoStrumenti::dellEnte($this->sede->id);

    expect($tetto->residui())->toBe(-2)
        ->and($tetto->consente())->toBeFalse()
        // 🔴 Nessuno strumento è stato tolto: il tetto è una condizione
        // d'ingresso, non un'espulsione.
        ->and(strumentiDellaSede($this->sede))->toBe(4);

    expect(fn () => TettoStrumenti::esigi($this->sede->id))->toThrow(TettoStrumentiRaggiunto::class);
});

it('says the numbers and what to do, in the words of the way it counts', function () {
    tettoAlPiano(3, 'per_sede');
    strumentiNel($this->reparto, 3);

    expect(TettoStrumenti::dellEnte($this->sede->id)->spiegazione())
        ->toBe('Il piano SaaS include fino a 3 strumenti per sede, e questa sede ne ha già 3. Per aggiungerne altri serve un piano più ampio.');

    tettoAlPiano(5, 'per_cliente');
    strumentiNel($this->altroReparto, 1);

    expect(TettoStrumenti::dellEnte($this->sede->id)->spiegazione(4))
        ->toBe("Il file aggiungerebbe 4 strumenti, ma non c'è posto per tutti. Il piano SaaS include fino a 5 strumenti in tutto, "
            .'e le sedi del contratto ne hanno già 4: ce ne sta ancora 1. Togli righe dal file o passa a un piano più ampio.');

    tettoAlPiano(10, 'per_sede');

    expect(TettoStrumenti::dellEnte($this->sede->id)->spiegazione(9))->toContain('questa sede ne ha già 3: ce ne stanno ancora 7.');

    // Sopra il tetto il posto è zero, non un numero negativo.
    tettoAlPiano(1, 'per_sede');

    expect(TettoStrumenti::dellEnte($this->sede->id)->spiegazione(2))
        ->toContain('fino a 1 strumento per sede')
        ->toContain('non ce ne stanno altri');
});

it('locks the account before counting, because two saves at once would both see one place left', function () {
    // SQLite ignora `FOR UPDATE`, quindi la concorrenza non è riproducibile in
    // suite: si asserisce sul sorgente, come per l'ultimo Admin di un Ente.
    $sorgente = file_get_contents(app_path('Support/Billing/TettoStrumenti.php'));

    expect($sorgente)->toContain("DB::table('accounts')->where('id', \$accountId)->lockForUpdate()");
    // E il lock viene prima del conteggio, non dopo.
    expect(strpos($sorgente, '->lockForUpdate()'))->toBeLessThan(strpos($sorgente, "DB::table('strumenti')"));
});

// ─── L'albero ────────────────────────────────────────────────────────────────

it('fills the cap exactly, then says why the next instrument does not open', function () {
    tettoAlPiano(3, 'per_sede');
    strumentiNel($this->reparto, 2);

    $albero = Livewire::actingAs($this->admin)->test(Albero::class)->call('open', $this->reparto->id);

    // Il terzo ci sta.
    $albero->call('addStrumento')
        ->assertSet('showStrumentoForm', true)
        ->set('strumentoForm.fornitore_id', $this->fornitore->id)
        ->set('strumentoForm.nome', 'Terza')
        ->call('saveStrumento')
        ->assertHasNoErrors()
        ->assertSet('notice', null);

    expect(strumentiDellaSede($this->sede))->toBe(3);

    // Il quarto no, e lo si dice prima del form, coi numeri.
    $albero->call('addStrumento')
        ->assertSet('showStrumentoForm', false)
        ->assertSee('Il piano SaaS include fino a 3 strumenti per sede, e questa sede ne ha già 3.');
});

it('refuses the save itself, not only the button that leads to it', function () {
    tettoAlPiano(3, 'per_sede');
    strumentiNel($this->reparto, 3);

    // 🔴 L'azione chiamata senza passare da `addStrumento()`: è ciò che fa un
    // form rimasto aperto mentre un collega occupava l'ultimo posto.
    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('open', $this->reparto->id)
        ->set('strumentoForm.fornitore_id', $this->fornitore->id)
        ->set('strumentoForm.nome', 'Di troppo')
        ->call('saveStrumento')
        ->assertSet('showStrumentoForm', false)
        ->assertSee('questa sede ne ha già 3');

    expect(strumentiDellaSede($this->sede))->toBe(3)
        ->and(DB::table('strumenti')->where('nome', 'Di troppo')->exists())->toBeFalse();
});

it('fills the cap of the whole sede, not of the part of it somebody can see', function () {
    // 🔴 Un Responsabile vede solo il proprio reparto (`DepartmentScope`).
    // Contando con gli scope il suo tetto non si riempirebbe mai: qui il
    // reparto che vede ha una macchina, la sede ne ha tre.
    tettoAlPiano(3, 'per_sede');

    $secondo = UnitaOrganizzativa::factory()->dipartimento()->under($this->sede)->create(['nome' => 'Secondo reparto']);
    strumentiNel($this->reparto, 2);
    strumentiNel($secondo, 1);

    $responsabile = User::factory()->create(['tenant_id' => $this->sede->id, 'two_factor_confirmed_at' => now()]);
    $responsabile->assignRole('Responsabile Reparto');
    $responsabile->unitaResponsabili()->attach($secondo->id);

    $this->actingAs($responsabile);

    expect(Strumento::count())->toBe(1);

    Livewire::test(Albero::class)
        ->call('open', $secondo->id)
        ->call('addStrumento')
        ->assertSet('showStrumentoForm', false)
        ->assertSee('questa sede ne ha già 3');
});

it('asks the plan of the sede where the instrument lands, not of whoever is adding it', function () {
    // 🔗 ADR-046: il Superadmin lavora nelle sedi dei clienti che EasyLab
    // gestisce. Il tetto è del cliente: EasyLab, che è su un altro piano e non
    // ha strumenti, non presta il proprio posto.
    $easylab = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);
    $enteEasylab = UnitaOrganizzativa::factory()->ente()->perAccount($easylab)->create(['nome' => 'EasyLab']);

    $this->rossi->affidaManutenzione();
    tettoAlPiano(2, 'per_sede');
    strumentiNel($this->reparto, 2);

    $superadmin = User::factory()->create(['tenant_id' => $enteEasylab->id, 'two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');

    Livewire::actingAs($superadmin)->test(Albero::class)
        ->call('open', $this->reparto->id)
        ->call('addStrumento')
        ->assertSet('showStrumentoForm', false)
        ->assertSee('questa sede ne ha già 2');

    Livewire::actingAs($superadmin)->test(Albero::class)
        ->call('open', $this->reparto->id)
        ->set('strumentoForm.fornitore_id', $this->fornitore->id)
        ->set('strumentoForm.nome', 'Di troppo')
        ->call('saveStrumento');

    expect(strumentiDellaSede($this->sede))->toBe(2);
});

it('stops a sede that is not full when the contract as a whole is', function () {
    tettoAlPiano(4, 'per_cliente');
    strumentiNel($this->reparto, 1);
    strumentiNel($this->altroReparto, 3);

    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('open', $this->reparto->id)
        ->call('addStrumento')
        ->assertSet('showStrumentoForm', false)
        ->assertSee('Il piano SaaS include fino a 4 strumenti in tutto, e le sedi del contratto ne hanno già 4.');

    expect(strumentiDellaSede($this->sede))->toBe(1);
});

// ─── L'import CSV ────────────────────────────────────────────────────────────

it('refuses a file that does not fit, says how many still do, and writes none of it', function () {
    tettoAlPiano(5, 'per_sede');
    strumentiNel($this->reparto, 3);

    $import = Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvDiStrumenti(3, 'Reparto Milano'))
        ->call('analizza')
        ->assertSee('3 valide')
        ->assertSeeHtml('data-oltre-il-tetto')
        ->assertSee('Il file aggiungerebbe 3 strumenti')
        ->assertSee('ce ne stanno ancora 2')
        ->assertSee('Oltre il tetto del piano')
        ->assertDontSee('Importa 3 righe valide');

    // 🔴 Il bottone non c'è, ma l'azione si può chiamare lo stesso: tutto o
    // niente, quindi nemmeno le due righe che ci starebbero.
    $import->call('importa')
        ->assertSet('importate', null)
        ->assertSet('analizzato', true)
        ->assertSeeHtml('data-oltre-il-tetto');

    expect(strumentiDellaSede($this->sede))->toBe(3);
});

it('imports a file that fits exactly, counting only the rows that are valid', function () {
    tettoAlPiano(5, 'per_sede');
    strumentiNel($this->reparto, 3);

    // Due righe valide e una che non lo è: il tetto guarda le due.
    $csv = "nome;modello;matricola;data_installazione;ubicazione;provenienza\n"
        ."Macchina 1;;;;Reparto Milano;\n"
        ."Macchina 2;;;;Reparto Milano;\n"
        ."Macchina 3;;;;Reparto che non esiste;\n";

    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', UploadedFile::fake()->createWithContent('strumenti.csv', $csv))
        ->call('analizza')
        ->assertDontSeeHtml('data-oltre-il-tetto')
        ->assertSee('Importa 2 righe valide')
        ->call('importa')
        ->assertSet('importate', 2);

    expect(strumentiDellaSede($this->sede))->toBe(5);
});

it('still answers not found for a department that vanished between the analysis and the import', function () {
    // Il tetto si chiede alla sede di ogni riga, e un reparto sparito non ne ha
    // più una: non deve diventare un errore del conteggio. A dirlo resta il
    // `findOrFail` della scrittura, col 404 di sempre.
    tettoAlPiano(5, 'per_sede');

    $import = Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvDiStrumenti(2, 'Reparto Milano'))
        ->call('analizza')
        ->assertSee('Importa 2 righe valide');

    $this->reparto->delete();

    expect(fn () => $import->call('importa'))->toThrow(ModelNotFoundException::class);
    expect(strumentiDellaSede($this->sede))->toBe(0);
});

it('says nothing about a cap, and asks nothing of the database, before there is a file to weigh', function () {
    tettoAlPiano(1, 'per_sede');
    strumentiNel($this->reparto, 5);

    $this->actingAs($this->admin);

    DB::enableQueryLog();
    $pagina = Livewire::test(ImportStrumenti::class);
    $query = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $pagina->assertDontSeeHtml('data-oltre-il-tetto');

    // Il tetto si legge solo ad analisi fatta: aprire la pagina non conta
    // strumenti e non va a cercare il contratto.
    expect($query->filter(fn (string $q) => str_contains($q, 'from "strumenti"')
        || str_contains($q, 'from "accounts"')
        || str_contains($q, 'select "tenant_id", "id" from "unita_organizzativa"'))->all())->toBe([]);
});

// ─── Le pagine che lo dichiarano ─────────────────────────────────────────────

it('tells the customer how many instruments the plan leaves, in the way the plan counts them', function () {
    strumentiNel($this->reparto, 3);
    strumentiNel($this->altroReparto, 2);

    $strumenti = fn (): string => Livewire::actingAs($this->admin)->test(PaginaAbbonamento::class)->viewData('strumenti');

    expect($strumenti())->toBe('illimitati');

    tettoAlPiano(20, 'per_sede');
    expect($strumenti())->toBe('3 su 20 in questa sede');

    tettoAlPiano(20, 'per_cliente');
    expect($strumenti())->toBe('5 su 20 fra tutte le sedi');

    Livewire::actingAs($this->admin)->test(PaginaAbbonamento::class)->assertSee('5 su 20 fra tutte le sedi');
});

it('does not state a cap it cannot know, on a plan that left the catalog', function () {
    DB::table('accounts')->where('id', $this->rossi->id)->update(['piano' => 'fantasma']);

    Livewire::actingAs($this->admin->fresh())->test(PaginaAbbonamento::class)
        ->assertViewHas('strumenti', null)
        ->assertDontSeeHtml('data-strumenti');
});

it('writes the cap next to the price, where a plan is chosen', function () {
    BancoRegistrazione::apri();
    tettoAlPiano(50, 'per_cliente');

    // La pagina pubblica di registrazione.
    $this->get(route('registrazione.mostra'))
        ->assertOk()
        ->assertSee('fino a 50 strumenti in tutto');

    // E quella da cui un cliente Free sale di piano.
    $free = Account::factory()->create();
    $sede = UnitaOrganizzativa::factory()->ente()->perAccount($free)->create();
    $admin = User::factory()->create(['tenant_id' => $sede->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');
    $free->aggiungiMembro($admin);

    Livewire::actingAs($admin)->test(PaginaAbbonamento::class)
        ->assertSee('fino a 50 strumenti in tutto')
        // Il piano che ha oggi non ne dichiara uno.
        ->assertViewHas('strumenti', 'illimitati');
});

// ─── Guardrail ───────────────────────────────────────────────────────────────

it('lets no instrument be born in the application without asking the cap first', function () {
    // Il tetto vive nei gesti che creano, non nel model: un terzo punto di
    // creazione scritto domani lo salterebbe in silenzio. Qui diventa rosso.
    $senzaGuardia = [];

    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        $sorgente = $file->getContents();

        if (preg_match('/\bStrumento::(create|firstOrCreate|updateOrCreate|insert)\(/', $sorgente) === 1
            && ! str_contains($sorgente, 'TettoStrumenti::esigi(')) {
            $senzaGuardia[] = $file->getRelativePathname();
        }
    }

    expect($senzaGuardia)->toBe([]);
});
