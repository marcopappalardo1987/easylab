<?php

namespace App\Models\Scopes;

use App\Models\Strumento;
use App\Support\Tenancy\AccessibleNodes;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Livello 2 del Global Scope (ADR-006) per i modelli che NON hanno una
 * collocazione propria nell'albero ma appendono a uno strumento: la restrizione
 * al sotto-albero del Responsabile Reparto si applica INDIRETTAMENTE, via
 * `strumento_id`. In AND col livello 1 (TenantScope sul modello stesso).
 *
 * La subquery gira con `withoutGlobalScopes()` di proposito:
 *  - rompe in anticipo il ciclo con il futuro scope Tecnico su Strumento
 *    (ADR-007, S4: "strumenti con un intervento assegnato a me"), che
 *    interrogherà `interventi` → ricorsione infinita se qui applicassimo gli
 *    scope di Strumento. Stesso motivo per cui NON si usa `whereHas`;
 *  - non eredita SoftDeletingScope: gli interventi di uno strumento cestinato
 *    restano visibili, esattamente come li vede l'Admin (che non ha questo
 *    filtro). Questo scope fa una cosa sola: restringere per nodo.
 *
 * Il confine Ente è riapplicato DENTRO la subquery invece di delegarlo al
 * TenantScope del chiamante: AccessibleNodes prende le radici assegnate dal
 * pivot `responsabile_unita` senza verificarne il tenant (solo i discendenti
 * sono filtrati per Ente), e senza global scope la subquery perderebbe anche il
 * TenantScope di Strumento. Difesa in profondità su un'area a rischio.
 */
class DepartmentThroughStrumentoScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $ids = AccessibleNodes::forCurrentUser();

        if ($ids === null) {
            return; // nessuna restrizione di reparto (Admin/Tenant/Tecnico/console/guest)
        }

        if ($ids === []) {
            $builder->whereRaw('1 = 0'); // Responsabile senza assegnazioni: fail-safe

            return;
        }

        $builder->whereIn(
            $model->qualifyColumn($model->strumentoColumn()),
            Strumento::withoutGlobalScopes()
                ->where('tenant_id', CurrentTenant::id())
                ->whereIn('unita_organizzativa_id', $ids)
                ->select('strumenti.id')
        );
    }
}
