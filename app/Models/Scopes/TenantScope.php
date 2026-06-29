<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Scope multi-tenant (ADR-001/006/018).
 *
 * Filtra ogni query per `tenant_id` = Ente dell'utente corrente. Garantisce
 * che "il tenant A non legga mai dati del tenant B". Non filtra solo nei
 * contesti SENZA utente (console/seeder/job/guest). Per un utente autenticato
 * SENZA tenant è fail-closed (non vede nulla) — ADR-018.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! CurrentTenant::shouldScope()) {
            return; // console/seeder/job/guest: nessuno scope
        }

        $tenantId = CurrentTenant::id();

        if ($tenantId === null) {
            // Autenticato ma senza tenant → non vede nulla (ADR-018).
            $builder->whereRaw('1 = 0');

            return;
        }

        // Livello 1: confine Ente (ADR-006).
        // Livello 2 (sotto-albero Responsabile / unione Tecnico — S2 punto 2)
        // si innesta in DepartmentScope sullo stesso builder.
        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
