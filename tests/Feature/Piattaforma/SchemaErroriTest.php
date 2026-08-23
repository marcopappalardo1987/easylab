<?php

use App\Models\Errore;
use App\Models\OccorrenzaErrore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Lo scheletro dell'error tracker interno (S6 — 🔗 `docs/Architettura/Error
 * Tracker Interno (piano).md`): le due tabelle, i due modelli, e le due
 * proprietà dello schema su cui poggia tutto il resto.
 *
 * Qui **non** si asserisce su `Schema::hasColumn()`: sarebbe la migration
 * riscritta una seconda volta con parole diverse — un test che non può fallire
 * se non riscrivendolo, cioè che non protegge niente. Si asserisce sui due
 * comportamenti che il codice dei blocchi successivi dà per scontati: che
 * l'impronta sia davvero unica (senza, il `firstOrCreate` del percorso caldo
 * duplica in silenzio sotto concorrenza) e che cancellare una issue porti via
 * le sue prove.
 */

/** Una issue coi campi minimi che il gestore delle eccezioni scriverà. */
function unErrore(array $attributi = []): Errore
{
    return Errore::create(array_merge([
        'impronta' => sha1('RuntimeException|app/Support/Errori/CatturaErrori.php:42'),
        'classe' => RuntimeException::class,
        'messaggio' => 'Autoclave 42 non trovata',
        'file' => 'app/Support/Errori/CatturaErrori.php',
        'riga' => 42,
        'prima_occorrenza_at' => now(),
        'ultima_occorrenza_at' => now(),
    ], $attributi));
}

/** Un contesto conservato per quella issue. */
function unContesto(Errore $errore, array $attributi = []): OccorrenzaErrore
{
    return $errore->occorrenzeErrore()->create(array_merge([
        'messaggio' => 'Autoclave 42 non trovata',
        'stack_trace' => "#0 app/Livewire/Strumenti/SchedaStrumento.php(88)\n#1 [internal function]",
        'percorso' => 'strumenti/42',
        'metodo' => 'GET',
        'codice_http' => 500,
        'contesto' => 'http',
        'avvenuta_at' => now(),
    ], $attributi));
}

it('keeps the new models where the meta-tests can see them', function () {
    // 🔴 **Il test che rende visibile un fatto che nessuno indovinerebbe: quei
    // glob non sono ricorsivi.** Tre meta-test derivano il proprio universo da
    // `glob(app_path('Models/*.php'))`, e in PHP quel pattern non scende nelle
    // sottocartelle: mettere questi due model in `app/Models/Errori/` non
    // farebbe fallire nessuno dei tre — li **toglierebbe** a tutti e tre
    // insieme, in silenzio, insieme alle loro esenzioni dichiarate. Da quel
    // momento un model di dominio nato là dentro senza `BelongsToTenant` non
    // troverebbe più nessuna rete.
    //
    // È lo stesso difetto già pagato su `LocalizzazioneTest:77`
    // (`glob('Livewire/**/*.php')`, dove nemmeno `**` è ricorsivo) — qui però
    // colto prima invece che dopo.
    //
    // Due asserzioni, e servono entrambe: che i tre file usino **davvero**
    // quella derivazione (se un domani uno passasse a una scansione ricorsiva,
    // questa riga cadrebbe e la seconda parte non avrebbe più senso), e che
    // quella derivazione **veda** i due model.
    $metaTest = [
        'tests/Feature/TenantScopeGuardrailTest.php',
        'tests/Feature/AuditCoverageGuardrailTest.php',
        'tests/Feature/Piattaforma/SoggettiAuditTest.php',
    ];

    foreach ($metaTest as $file) {
        expect(file_get_contents(base_path($file)))
            ->toContain("glob(app_path('Models/*.php'))");
    }

    // La stessa espressione dei tre, `class_exists` compreso: un file spostato
    // in una sottocartella senza toccarne il namespace non è nemmeno più
    // caricabile, quindi cade due volte.
    $visti = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $f) => 'App\\Models\\'.basename($f, '.php'))
        ->filter(fn (string $c) => class_exists($c))
        ->values()->all();

    // ⚠️ Due `toContain()` separate e non `toContain($a, $b)`: l'API è
    // variadica, e un secondo argomento è un secondo ago — non un messaggio.
    expect($visti)->toContain(Errore::class)
        ->and($visti)->toContain(OccorrenzaErrore::class);
});

it('cascades occurrences when an issue is deleted', function () {
    // Asserito **sul dato** e non sulla definizione della colonna: che la
    // migration dica `cascadeOnDelete` è una stringa in un file; che le righe
    // spariscano davvero dipende anche dal fatto che i vincoli siano attivi
    // sulla connessione (su SQLite sono un `PRAGMA`, non un dato di fatto).
    //
    // Non è pignoleria: queste righe portano stack trace, ip, user agent e
    // input: la cancellazione di una issue è la sola porta da cui quei dati
    // escono dal database. Se il cascade non scattasse resterebbero lì, orfane
    // e irraggiungibili da qualunque pagina — cioè invisibili anche a chi
    // dovesse cancellarle.
    $errore = unErrore();
    unContesto($errore);
    unContesto($errore, ['percorso' => 'strumenti/43']);

    // Che ci fossero davvero, prima: senza questa riga un cascade che non
    // scatta e una `create()` che non scrive si leggono uguali (zero righe alla
    // fine), e il test sarebbe verde nel caso peggiore.
    expect(OccorrenzaErrore::count())->toBe(2);

    $errore->delete();

    expect(OccorrenzaErrore::count())->toBe(0);
});

it('refuses a second issue with the same fingerprint', function () {
    // L'unicità di `impronta` **è** il raggruppamento: il percorso caldo farà
    // `firstOrCreate` su questa colonna, e due richieste concorrenti che vedono
    // la stessa eccezione arrivano entrambe al ramo «non esiste». Senza il
    // vincolo non ci sarebbe nessun errore da intercettare: nascerebbero due
    // issue per lo stesso bug, ciascuna con metà del contatore, e la pagina
    // mostrerebbe due righe gemelle senza che nulla lo segnali.
    $prima = unErrore();

    // ⚠️ **Il `DB::transaction()` non è decorativo, ed è una divergenza
    // SQLite/Postgres pagata proprio qui.** Su Postgres una query fallita
    // *aborta l'intera transazione*: dentro `RefreshDatabase` — che ne apre una
    // per test — ogni istruzione successiva risponde «current transaction is
    // aborted», quindi le due asserzioni qui sotto **erravano sul motore vero**
    // pur essendo verdi in locale. Il `transaction()` annidato apre un
    // SAVEPOINT, e il rollback torna a quello lasciando la transazione esterna
    // sana. Verificato il 23 Ago 2026 su `easylab_test`.
    expect(fn () => DB::transaction(fn () => unErrore(['messaggio' => 'Autoclave 43 non trovata'])))
        ->toThrow(QueryException::class);

    // E il rifiuto è asserito **sul dato**: dopo il tentativo la issue è ancora
    // una sola, ed è la prima — non una seconda riga nata a metà.
    expect(Errore::count())->toBe(1)
        ->and(Errore::sole()->id)->toBe($prima->id);
});
