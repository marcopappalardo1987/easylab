<?php

use App\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

use function PHPUnit\Framework\assertTrue;

/**
 * Rete di sicurezza: ogni modello di business in app/Models deve usare
 * BelongsToTenant (isolamento multi-tenant, ADR-001). Se un futuro modello dei
 * punti 4-5 dimentica il trait, questo test fallisce — red-flag della Policy di
 * Code Review. `BelongsToOrgNode` resta opt-in (solo modelli collocati
 * nell'albero, matrice ERD §10) e non è verificabile qui.
 */

// Modelli esentati: User è l'identità di auth, non un dato tenant-scoped.
const NON_TENANT_MODELS = [
    User::class,
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
