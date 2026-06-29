<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\UnitaOrganizzativa;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait riusabile per i modelli di business multi-tenant (ADR-001/006).
 *
 * Aggiungere `use BelongsToTenant;` a ogni modello con colonna `tenant_id`:
 * - applica il Global Scope di isolamento (TenantScope, lato lettura);
 * - lato scrittura, per un utente tenant-bound FORZA `tenant_id` al proprio
 *   Ente in creazione e impedisce di cambiarlo in update: un tenant non può
 *   forgiare/regalare dati a un altro Ente. I ruoli bypass (Superadmin/
 *   Developer) e i contesti senza utente (console/seeder/job) restano liberi
 *   — provisioning del nodo ente e spostamenti cross-tenant (ADR-015).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $tenantId = CurrentTenant::id();
            if (CurrentTenant::shouldScope() && $tenantId !== null) {
                $model->setAttribute('tenant_id', $tenantId);
            }
        });

        static::updating(function ($model): void {
            if (CurrentTenant::shouldScope() && CurrentTenant::id() !== null && $model->isDirty('tenant_id')) {
                $model->setAttribute('tenant_id', $model->getOriginal('tenant_id'));
            }
        });
    }

    /**
     * Nodo ente (= tenant) proprietario del record.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(UnitaOrganizzativa::class, 'tenant_id');
    }
}
