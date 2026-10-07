<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\UnitaOrganizzativa;
use App\Support\Tenancy\ClientiGestiti;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait riusabile per i modelli di business multi-tenant (ADR-001/006).
 *
 * Aggiungere `use BelongsToTenant;` a ogni modello con colonna `tenant_id`:
 * - applica il Global Scope di isolamento (TenantScope, lato lettura);
 * - lato scrittura, per un utente tenant-bound FORZA `tenant_id` al proprio
 *   Ente in creazione e impedisce di cambiarlo in update: un tenant non può
 *   forgiare/regalare dati a un altro Ente. Chi non ha Ente (staff di
 *   piattaforma) e i contesti senza utente (console/seeder/job) restano liberi
 *   — provisioning del nodo ente e spostamenti cross-tenant (ADR-015).
 *
 * 🔴 **Un'eccezione sola, dal 6 Ott 2026 (ADR-046)**: il Superadmin che crea una
 * riga per una sede di un cliente con manutenzione gestita. Ha un Ente proprio
 * (quello di EasyLab), quindi senza eccezione la macchina registrata nel reparto
 * del cliente nascerebbe con il `tenant_id` di EasyLab: visibile a EasyLab,
 * invisibile al cliente. L'eccezione vale **solo** se il `tenant_id` dichiarato
 * è davvero una sede gestita (`ClientiGestiti::copre()`); ogni altro valore
 * viene riportato al proprio Ente, come per chiunque.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $tenantId = CurrentTenant::id();
            if (! CurrentTenant::shouldScope() || $tenantId === null) {
                return;
            }

            $dichiarato = $model->getAttribute('tenant_id');

            if ($dichiarato !== null
                && (int) $dichiarato !== $tenantId
                && ClientiGestiti::siApplica()
                && ClientiGestiti::copre((int) $dichiarato)) {
                return;
            }

            $model->setAttribute('tenant_id', $tenantId);
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
