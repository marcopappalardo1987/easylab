<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\AccessoTecnico;
use App\Support\Tenancy\ClientiGestiti;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Scope multi-tenant (ADR-001/006/018/030).
 *
 * Filtra ogni query per `tenant_id` = Ente dell'utente corrente. Garantisce
 * che "il tenant A non legga mai dati del tenant B". Non filtra solo nei
 * contesti SENZA utente (console/seeder/job/guest). Per un utente autenticato
 * SENZA tenant è fail-closed (non vede nulla) — ADR-018.
 *
 * **Prima eccezione: chi lavora per portafoglio** — Tecnico (ADR-007/030) e, dal
 * 6 Ott 2026, Gestore (ADR-046). Non è un bypass — non vede *di più* di un
 * Ente, vede un insieme *diverso*: portafoglio ∪ assegnazioni, calcolato da
 * `AccessoTecnico`. Il ramo sta qui e non in uno scope a parte perché il
 * criterio **sostituisce** il confine Ente (per il tecnico esterno non c'è
 * alcun Ente da cui partire), mentre un secondo scope si sarebbe potuto
 * comporre solo in AND.
 *
 * **Seconda eccezione: il Superadmin sui clienti con manutenzione gestita da
 * EasyLab** (ADR-046): il proprio Ente ∪ le sedi di quegli account, calcolato
 * da `ClientiGestiti`. Senza clienti gestiti è il confine di sempre.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! CurrentTenant::shouldScope()) {
            return; // console/seeder/job/guest: nessuno scope
        }

        $tenantId = CurrentTenant::id();

        if (AccessoTecnico::siApplica()) {
            // Difesa in profondità (ADR-030): per il tecnico INTERNO il proprio
            // Ente resta una seconda barriera in AND — non è più il criterio di
            // accesso, ma un errore nel portafoglio non deve poterlo portare
            // fuori dal laboratorio per cui lavora. Per l'esterno (`tenant_id`
            // NULL) non c'è barriera da applicare e resta il solo criterio.
            if ($tenantId !== null) {
                $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
            }

            AccessoTecnico::applica($builder, $model);

            return;
        }

        if (ClientiGestiti::siApplica()) {
            ClientiGestiti::applica($builder, $model, $tenantId);

            return;
        }

        if ($tenantId === null) {
            // Autenticato ma senza tenant → non vede nulla (ADR-018).
            $builder->whereRaw('1 = 0');

            return;
        }

        // Livello 1: confine Ente (ADR-006).
        // Livello 2 (sotto-albero Responsabile) si innesta in DepartmentScope
        // sullo stesso builder.
        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
