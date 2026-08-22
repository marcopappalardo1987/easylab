<?php

use App\Livewire\Piattaforma\RegistroAudit;
use App\Models\Concerns\AuditsDomainWrites;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Audit\SoggettiAudit;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * 🔴 Il soggetto di una riga, letto **attraverso i tenant**.
 *
 * `subject` punta a dodici modelli — undici scopati, più il `Role` di vendor —
 * e chi guarda è tenant-bound come chiunque (ADR-018): senza i cinque scope
 * tolti, la pagina mostrerebbe righe senza soggetto — in silenzio, e senza dire
 * perché.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // ⚠️ **Due Enti, e il superadmin sta su quello sbagliato di proposito.**
    // La fixture della convenzione locale mette chi guarda e il soggetto nello
    // stesso Ente: copiarla renderebbe questi test verdi anche senza togliere
    // alcuno scope, cioè provando l'esatto contrario del proprio nome.
    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'EasyLab']);
    $this->enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ospedale San Giovanni']);

    $this->superadmin = User::factory()->create([
        'name' => 'Direzione',
        'tenant_id' => $this->enteA->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->superadmin->assignRole('Superadmin');

    // ⚠️ **Nessun `actingAs()` qui.** I soggetti vanno creati da NON
    // autenticati: `BelongsToTenant::creating` timbra le righe nuove col
    // `tenant_id` di chi scrive, quindi creando lo strumento «dell'Ente B»
    // mentre si è loggati come superadmin dell'Ente A si otterrebbe una riga
    // dell'Ente A — e il test proverebbe di vedere un soggetto del **proprio**
    // tenant, cioè esattamente niente. Ogni test si autentica con `guarda()`
    // dopo aver preparato i dati.
});

/**
 * Uno strumento **dell'Ente B**, con la prova che ci sia davvero finito.
 *
 * La riga di `expect()` non e' pleonastica: e' l'unica guardia **meccanica**
 * della fixture. Se qualcuno aggiungesse un `actingAs()` al `beforeEach` — cioe'
 * copiasse la convenzione locale — `BelongsToTenant::creating` timbrerebbe
 * questi strumenti con l'Ente di chi guarda, e sette test su otto resterebbero
 * verdi provando l'esatto contrario del proprio nome. Un commento non diventa
 * rosso; questa riga si'.
 */
function strumentoDelClienteB(array $attributi = []): Strumento
{
    $strumento = Strumento::factory()->forNode(test()->enteB)->create($attributi);

    expect($strumento->tenant_id)->toBe(test()->enteB->id)
        ->and($strumento->tenant_id)->not->toBe(test()->superadmin->tenant_id);

    return $strumento;
}

/** Monta il registro come Superadmin, **dopo** che le fixture sono state create. */
function guarda(): Testable
{
    test()->actingAs(test()->superadmin->fresh());

    return Livewire::test(RegistroAudit::class);
}

/** Una riga di audit già scritta, senza passare dai gesti di dominio. */
function rigaAudit(array $attributi = []): Activity
{
    return Activity::create(array_merge([
        'log_name' => AuditLog::NAME,
        'description' => 'Modifica strumento',
        'event' => 'updated',
    ], $attributi));
}

/**
 * Model di **vendor** usati come soggetto di audit → [file che li scrive, token da trovarci].
 *
 * `glob(app_path('Models/*.php'))` non li vede, quindi qui non c'è niente da
 * derivare: l'elenco è a mano, e il test qui sotto lo tiene onesto verificando
 * che il file dichiarato contenga davvero la scrittura.
 */
const SOGGETTI_DI_VENDOR = [
    // L'editor dei permessi di ruolo (S6, ADR-016): il gesto è «al ruolo X è
    // stato tolto Y», quindi il soggetto è il `Role` e non il `Permission`.
    Role::class => ['app/Support/Rbac/MatriceRuoli.php', 'performedOn($riga)'],
];

it('shows the subject of another tenant, which is the whole point', function () {
    // 🔴 Il difetto per cui esiste `SoggettiAudit`. Lo strumento è dell'Ente B,
    // chi guarda è sull'Ente A: senza gli scope tolti la relazione torna `null`
    // e la colonna Soggetto resta muta.
    $strumento = strumentoDelClienteB(['nome' => 'Autoclave Bianchi']);

    rigaAudit(['subject_type' => $strumento->getMorphClass(), 'subject_id' => $strumento->id]);

    // ⚠️ E nello stesso corpo: lo scope **è vivo**. Senza questa riga il test
    // resterebbe verde anche se lo scope non ci fosse mai stato, e proverebbe
    // che il tenant non isola invece che il contrario.
    $this->actingAs($this->superadmin->fresh());

    expect(Strumento::query()->pluck('nome')->all())->not->toContain('Autoclave Bianchi');

    guarda()->assertSee('Autoclave Bianchi');
});

it('never loses the name of a trashed subject', function () {
    // Un audit che perde il nome di ciò che è stato cancellato è inutile
    // proprio nel caso che conta di più.
    $strumento = strumentoDelClienteB(['nome' => 'Centrifuga Dismessa']);

    rigaAudit([
        'subject_type' => $strumento->getMorphClass(),
        'subject_id' => $strumento->id,
        'description' => 'Eliminazione strumento',
        'event' => 'deleted',
    ]);

    $strumento->delete();

    guarda()
        ->assertSee('Centrifuga Dismessa')
        ->assertSee('cestinato');
});

it('never invents a name for a subject that no longer exists', function () {
    // Hard delete: la riga d'audit sopravvive al soggetto, e `subject_id` non è
    // una FK. La cella non deve restare vuota — «nessun soggetto» è un valore
    // semantico diverso, e confonderli nasconde una cancellazione.
    $strumento = strumentoDelClienteB();
    $tipo = $strumento->getMorphClass();
    $id = $strumento->id;

    rigaAudit(['subject_type' => $tipo, 'subject_id' => $id]);

    DB::table('strumenti')->where('id', $id)->delete();

    guarda()->assertSee('non più presente');
});

it('renders a row whose subject class no longer exists, instead of dying', function () {
    // 🔴 `MorphTo::createModelByType()` va in **fatal** su una classe che non
    // c'è più, e `activity_log` è append-only: sopravvive alle proprie classi —
    // `LetturaContaore` è stata cancellata in S3. Una riga orfana basterebbe a
    // far cadere l'intera pagina, non solo la propria cella.
    rigaAudit([
        'subject_type' => 'App\\Models\\LetturaContaore',
        'subject_id' => 42,
        'description' => 'Creazione lettura',
    ]);

    guarda()
        ->assertOk()
        ->assertSee('LetturaContaore')
        ->assertSee('non più presente');
});

it('names the machine for a subject that has no name of its own', function () {
    // Quattro modelli non hanno una colonna nome. «Modifica intervento» senza
    // dire su quale macchina non serve a ricostruire niente.
    $strumento = strumentoDelClienteB(['nome' => 'Spettrofotometro']);
    $intervento = Intervento::factory()->forStrumento($strumento)->create();

    rigaAudit([
        'subject_type' => $intervento->getMorphClass(),
        'subject_id' => $intervento->id,
        'description' => 'Modifica intervento',
    ]);

    guarda()->assertSee('Spettrofotometro');
});

it('says «nessun soggetto» instead of leaving the cell blank', function () {
    // `Login fallito` e `Invito NON consegnato` non hanno soggetto: se la riga
    // sparisse o la cella tacesse, sparirebbe l'unica traccia di quei gesti.
    rigaAudit(['subject_type' => null, 'subject_id' => null, 'description' => 'Login fallito', 'event' => null]);

    guarda()
        ->assertSee('Login fallito')
        ->assertSee('nessun soggetto');
});

it('names the machine of a spare-part warranty, which gets there by a double hop', function () {
    // 🔴 `garanzie.strumento_id` e' NULL **sse** `soggetto = ricambio`: per quelle
    // righe la macchina si raggiunge via `ricambioUtilizzo` (ADR-020). Senza il
    // doppio salto l'etichetta ricade su «Garanzia · #1» — e ricade su **meta'**
    // delle garanzie, quella che ADR-029 tratta come la piu' delicata. E anche
    // l'anello di mezzo va liberato dagli scope, o il salto si interrompe
    // attraverso i tenant: lo stesso difetto un livello piu' in basso.
    $strumento = strumentoDelClienteB(['nome' => 'Analizzatore Delta']);
    $intervento = Intervento::factory()->forStrumento($strumento)->create();
    $ricambio = Ricambio::factory()->create(['tenant_id' => $strumento->tenant_id]);
    $montaggio = RicambioUtilizzo::factory()->forIntervento($intervento)->create(['ricambio_id' => $ricambio->id]);
    $garanzia = Garanzia::factory()->forRicambio($montaggio)->create();

    expect($garanzia->strumento_id)->toBeNull();

    // ⚠️ **Il registro va svuotato prima.** Creare intervento, montaggio e
    // garanzia con le factory scrive righe di audit da sé (usano il trait), e
    // una di quelle ha per soggetto il `RicambioUtilizzo` — che allo strumento
    // arriva per via **diretta**. Il nome della macchina compariva quindi in
    // pagina da un'altra riga, e il test passava anche togliendo il doppio
    // salto: verde per la ragione sbagliata, trovato mutando.
    Activity::query()->delete();

    rigaAudit([
        'subject_type' => $garanzia->getMorphClass(),
        'subject_id' => $garanzia->id,
        'description' => 'Modifica garanzia',
    ]);

    guarda()->assertSee('Analizzatore Delta');
});

it('renders a row whose subject id is null, instead of dying on a type error', function () {
    // 🔴 `nullableMorphs()` rende le due colonne indipendenti: lo schema permette
    // `subject_type` valorizzato e `subject_id` NULL. Senza una firma che regga
    // il nullo, una riga malformata faceva cadere l'**intera** pagina — e non
    // serve nemmeno una classe cancellata per produrla.
    rigaAudit(['subject_type' => Strumento::class, 'subject_id' => null]);

    guarda()->assertOk()->assertSee('non più presente');
});

it('renders a row without a timestamp, instead of dying on it', function () {
    // `timestamps()` genera colonne nullabili, e il Blade motiva gia' il `?->` su
    // `properties` con «una riga inserita fuori dal logger». Stesso scenario, e
    // l'argomento era applicato a meta'.
    $riga = rigaAudit();
    DB::table('activity_log')->where('id', $riga->id)->update(['created_at' => null]);

    guarda()->assertOk();
});

it('names every global scope the subject models can register', function () {
    // 🔴 La guardia che tiene onesta `SoggettiAudit::SCOPE`: il quinto scope —
    // `GaranziaRicambioPrivacyScope`, che e' di **privacy** e non di tenancy —
    // era proprio quello che la prima stesura del piano aveva dimenticato. Il
    // giorno in cui nasce il sesto questo diventa rosso e dice quale aggiungere;
    // senza, la pagina tacerebbe in silenzio su un tipo di soggetto.
    $registrati = collect(array_keys(SoggettiAudit::tipi()))
        ->flatMap(fn (string $classe) => array_keys((new $classe)->getGlobalScopes()))
        ->unique();

    $nominati = collect(SoggettiAudit::scope())->push(SoftDeletingScope::class);

    expect($registrati->diff($nominati)->values()->all())->toBe([]);
});

it('covers every model the codebase can write as a subject', function () {
    // Meta-test autoregolante: un modello nuovo che finisce in audit senza
    // essere qui degraderebbe a «#12» senza che nulla lo dica.
    //
    // ⚠️ **Non bastano i modelli col trait**, ed era il difetto della prima
    // stesura: togliendo `UnitaOrganizzativa` e `User` dalla mappa restava tutto
    // verde, e sono i soggetti delle righe di **impersonazione** — cioè il
    // motivo per cui questa feature esiste. Chi scrive audit senza il trait lo
    // fa con `activity()` esplicite, e quei modelli sono nominati (con la loro
    // ragione) in `AuditCoverageGuardrailTest::ESENZIONI`.
    $modelli = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $f) => 'App\\Models\\'.basename($f, '.php'));

    $conTrait = $modelli->filter(fn (string $c) => in_array(
        AuditsDomainWrites::class,
        class_uses_recursive($c),
        true,
    ));

    // Chi scrive `activity()` a mano: si legge dal sorgente invece di ripetere
    // un elenco, così un modello nuovo che comincia a tracciare entra da sé.
    $espliciti = $modelli->filter(
        fn (string $c) => str_contains(file_get_contents(app_path('Models/'.class_basename($c).'.php')), 'activity(')
    );

    // ⚠️ **E i model di VENDOR, che il `glob` non può vedere.** La rete che il
    // progetto credeva di avere qui **non copriva** `Role`: `glob(app_path(
    // 'Models/*.php'))` guarda solo casa propria, e il soggetto delle righe
    // dell'editor dei permessi vive in `vendor/spatie`. Verificato: senza la
    // riga in `SoggettiAudit::SOGGETTI`, l'etichetta si legge «Role · #id».
    //
    // L'elenco è a mano perché non c'è niente da cui derivarlo — è la stessa
    // forma di `AuditCoverageGuardrailTest::ESENZIONI`, e come quella porta
    // accanto a ogni voce il gesto che la scrive, così una voce non resta un
    // permesso aperto su qualcosa che non esiste più.
    $noti = array_keys(SoggettiAudit::tipi());

    expect($conTrait->merge($espliciti)->merge(array_keys(SOGGETTI_DI_VENDOR))->unique()->diff($noti)->values()->all())
        ->toBe([], 'Soggetto di audit non nominato: aggiungilo a SoggettiAudit::SOGGETTI, o la riga si leggerà «Classe · #id».');
});

it('keeps the vendor subject list honest about what actually writes it', function () {
    // Il compagno obbligatorio del precedente: un elenco a mano di model di
    // vendor è un secondo elenco parallelo, e su elenchi paralleli questo
    // progetto ha già perso due volte (`letture_contaore.*`, `fornitori.view`).
    // Qui si verifica che il file dichiarato **contenga davvero** la scrittura,
    // sulla forma del guardrail di `sbloccaPerStripe()`. Il giorno in cui
    // `MatriceRuoli` smettesse di scrivere audit, questa voce diventerebbe una
    // riga morta nella mappa dei soggetti, e nessun altro test se ne
    // accorgerebbe.
    foreach (SOGGETTI_DI_VENDOR as $classe => [$file, $token]) {
        expect(file_exists(base_path($file)))
            ->toBeTrue("Il file dichiarato per {$classe} non esiste più: {$file}")
            ->and(str_contains(file_get_contents(base_path($file)), $token))
            ->toBeTrue("«{$token}» non c'è più in {$file}: {$classe} è ancora un soggetto di audit?");
    }
});

it('keeps every label column real', function () {
    // Una colonna rinominata farebbe degradare l'etichetta a «#id» in silenzio.
    foreach (SoggettiAudit::tipi() as $classe => [$sostantivo, $colonna]) {
        if ($colonna === null) {
            continue;
        }

        expect(Schema::hasColumn((new $classe)->getTable(), $colonna))
            ->toBeTrue("La colonna «{$colonna}» non esiste più su {$classe}.");
    }
});
