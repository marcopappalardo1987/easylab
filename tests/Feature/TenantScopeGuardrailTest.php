<?php

use App\Models\Account;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Contracts\ReachesStrumento;
use App\Models\Errore;
use App\Models\OccorrenzaErrore;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

use function PHPUnit\Framework\assertTrue;

/**
 * Rete di sicurezza: ogni modello di business in app/Models deve usare
 * BelongsToTenant (isolamento multi-tenant, ADR-001). Se un futuro modello dei
 * punti 4-5 dimentica il trait, questo test fallisce — red-flag della Policy di
 * Code Review. `BelongsToOrgNode` resta opt-in (solo modelli collocati
 * nell'albero, matrice ERD §10) e non è verificabile qui.
 */

// Modelli esentati, ciascuno col proprio perché — due nature diverse:
//  - User è l'identità di auth, non un dato tenant-scoped;
//  - Account (ADR-032) è il primo modello di PIATTAFORMA: vive sopra i tenant
//    come `resellers` (che un modello non ce l'ha), e scoparlo al tenant
//    corrente negherebbe la sua ragione d'essere — possiede N Enti. È il
//    pattern per i futuri modelli di questo livello.
//  - Errore e OccorrenzaErrore (error tracker interno, S6) sono i primi a
//    seguire quel pattern: un'eccezione PHP non è il dato di un Ente ma
//    dell'applicazione, e nasce anche in console e in coda, dove un tenant
//    corrente NON esiste. ⚠️ Il guasto non è quello che la prima stesura
//    descriveva («timbrerebbe null e poi filtrerebbe su quel null»): in
//    console e in coda `CurrentTenant::shouldScope()` è falso, quindi il hook
//    `creating` non timbra niente e lo scope non filtra affatto. Il guasto sta
//    a valle ed è peggiore: il Developer è tenant-bound (ADR-018), quindi in
//    una richiesta web lo scope filtrerebbe su **il tenant di chi guarda**, e
//    le righe nate in console — cioè quelle di scheduler, code e comandi —
//    sarebbero invisibili proprio a chi deve vederle. La pagina che le
//    legge è del solo Developer (`system.logs.view`): il confine qui non è
//    il tenant, è il permesso.
//    ⚠️ E non si chiude con una porta in `VistaPiattaforma`: sarebbe il
//    «bypass finto» di `BypassNudiGuardrailTest` — non c'è nessuno scope da
//    togliere. In cambio c'è un guardrail sulle SCRITTURE,
//    `ScrittureErroriGuardrailTest`, sulla forma di quello del pivot RBAC:
//    fuori da `Errore` e da `CatturaErrori` quelle due tabelle si leggono e
//    basta.
const NON_TENANT_MODELS = [
    User::class,
    Account::class,
    Errore::class,
    OccorrenzaErrore::class,
];

function businessModels(): array
{
    $models = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }
        if (in_array($class, NON_TENANT_MODELS, true)) {
            continue;
        }

        $models[] = $class;
    }

    return $models;
}

it('finds at least one business model to guard', function () {
    expect(businessModels())->not->toBeEmpty();
});

it('uses BelongsToTenant on every business model', function () {
    foreach (businessModels() as $class) {
        assertTrue(
            in_array(BelongsToTenant::class, class_uses_recursive($class), true),
            "{$class} non usa BelongsToTenant (isolamento mancante)"
        );
    }
});

/**
 * Seconda rete, ADR-030: chi sa raggiungere uno strumento deve **dichiararlo**.
 *
 * Dimenticare `ReachesStrumento` su un modello nuovo con `strumento_id` non apre
 * una falla — quel modello resta visibile al Tecnico per il solo portafoglio,
 * quindi fail-closed — ma gli toglie in SILENZIO un accesso legittimo: chi ha un
 * intervento assegnato non vedrebbe, poniamo, le letture contaore della macchina
 * su cui sta lavorando. È il tipo di difetto che nessuno segnala come bug perché
 * somiglia a «non c'è niente», e per questo va colto qui.
 *
 * Si guarda la COLONNA e non il trait: `Garanzia` non usa
 * `BelongsToOrgNodeThroughStrumento` (allo strumento arriva per due strade) ma il
 * contratto lo implementa lo stesso, e un controllo sul trait la mancherebbe.
 */
it('declares ReachesStrumento on every model that carries a strumento_id', function () {
    foreach (businessModels() as $class) {
        $model = new $class;

        if (! Schema::hasColumn($model->getTable(), 'strumento_id')) {
            continue;
        }

        assertTrue(
            $model instanceof ReachesStrumento,
            "{$class} ha `strumento_id` ma non dichiara ReachesStrumento: il Tecnico "
            .'non raggiungerà le sue righe per assegnazione (ADR-030).'
        );
    }
});

it('keeps every model where the guardrails can actually see it', function () {
    // 🔴 **Le tre reti di questo progetto sono cieche a una sottocartella, e la
    // cecità è silenziosa.** `TenantScopeGuardrailTest`, `AuditCoverageGuardrailTest`
    // e `SoggettiAuditTest` derivano i modelli con `glob(app_path('Models/*.php'))`,
    // che **non è ricorsivo**. Verificato il 23 Ago 2026 su un modello *esistente*:
    // spostando `AvvisoScadenza` in una sottocartella e togliendogli
    // `BelongsToTenant`, tutti e venti i test dei tre file restano **verdi** —
    // l'unico segnale è un'asserzione in meno nel sommario, che nessuno guarda.
    //
    // Cioè: oggi si può sottrarre un modello al controllo sull'isolamento fra
    // clienti **spostandolo di cartella**, e nessuna rete se ne accorge.
    //
    // Questo test chiude il buco dal verso opposto — invece di rendere ricorsivi
    // tre glob (tre posti in cui ricordarsene), impone che sotto `app/Models/`
    // non esistano modelli fuori dal piano piatto. Le tre sottocartelle ammesse
    // non contengono modelli: sono trait, contratti e scope.
    $ammesse = ['Concerns', 'Contracts', 'Scopes'];

    $fuoriPosto = collect(
        iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Models'))))
    )
        ->filter(fn ($f) => $f->isFile() && str_ends_with($f->getFilename(), '.php'))
        ->map(fn ($f) => str_replace(app_path('Models').'/', '', $f->getPathname()))
        ->filter(fn (string $relativo) => str_contains($relativo, '/'))
        ->reject(fn (string $relativo) => in_array(explode('/', $relativo)[0], $ammesse, true))
        ->values();

    expect($fuoriPosto)->toBeEmpty(
        'Un file sotto app/Models/ in una sottocartella non ammessa è invisibile ai tre guardrail '.
        '(glob non ricorsivo): non verrebbe controllato né per la tenancy, né per la copertura di '.
        'audit, né come soggetto del registro — e resterebbe tutto verde. Trovato in: '.$fuoriPosto->implode(', ')
    );
});
