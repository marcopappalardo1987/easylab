<?php

namespace App\Models\Concerns;

use App\Models\Scopes\DepartmentThroughStrumentoScope;

/**
 * Opt-in per i modelli appesi a uno strumento e privi di collocazione propria
 * nell'albero (interventi, e in S3/S4 documenti, garanzie, letture contaore,
 * ricambio_utilizzo): applica il livello 2 del Global Scope passando per la
 * collocazione dello strumento (ADR-006).
 *
 * Da affiancare a BelongsToTenant. MUTUAMENTE ESCLUSIVO con BelongsToOrgNode,
 * che filtra su `unita_organizzativa_id`: su questi modelli la colonna non
 * esiste. La colonna che punta allo strumento è `strumento_id` per default;
 * sovrascrivere strumentoColumn() dove differisce.
 */
trait BelongsToOrgNodeThroughStrumento
{
    public static function bootBelongsToOrgNodeThroughStrumento(): void
    {
        static::addGlobalScope(new DepartmentThroughStrumentoScope);
    }

    public function strumentoColumn(): string
    {
        return 'strumento_id';
    }
}
