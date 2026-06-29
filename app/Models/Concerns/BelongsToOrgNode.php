<?php

namespace App\Models\Concerns;

use App\Models\Scopes\DepartmentScope;

/**
 * Opt-in per i modelli collocati nell'albero organizzativo: applica il livello
 * 2 del Global Scope (sotto-albero Responsabile Reparto, ADR-006).
 *
 * Da affiancare a BelongsToTenant sui modelli con una collocazione (strumenti,
 * documenti, ricambio_utilizzo… al punto 4). La colonna che indica il nodo è
 * `unita_organizzativa_id` per default; sovrascrivere orgNodeColumn() dove
 * differisce (es. UnitaOrganizzativa filtra per il proprio `id`).
 */
trait BelongsToOrgNode
{
    public static function bootBelongsToOrgNode(): void
    {
        static::addGlobalScope(new DepartmentScope);
    }

    public function orgNodeColumn(): string
    {
        return 'unita_organizzativa_id';
    }
}
