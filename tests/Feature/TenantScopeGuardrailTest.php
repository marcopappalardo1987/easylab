<?php

use App\Models\Account;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Contracts\ReachesStrumento;
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
const NON_TENANT_MODELS = [
    User::class,
    Account::class,
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
