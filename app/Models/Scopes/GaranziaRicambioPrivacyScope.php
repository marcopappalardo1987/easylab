<?php

namespace App\Models\Scopes;

use App\Enums\SoggettoGaranzia;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Privacy delle garanzie sui ricambi (ADR-004): «le garanzie sui ricambi
 * restano visibili solo a EasyLab/Admin, mai al Tenant». È anche nella
 * Definition of Done dello sprint.
 *
 * Primo global scope del progetto basato su un PERMESSO e non sulla tenancy:
 * chi non ha `garanzie.ricambio.view` (permesso del set 🔒 locked, mai
 * concedibile a Tenant né a Tecnico — ADR-016) vede solo le righe `macchina`.
 *
 * Fail-closed: si nasconde per difetto e si mostra solo con permesso esplicito,
 * quindi un ruolo nuovo o creato ad hoc parte cieco invece che indiscreto.
 *
 * Contesto senza utente (console/seeder/job) non filtrato, esattamente come
 * TenantScope — il seeder deve poter creare e verificare tutte le righe. I
 * guest HTTP non arrivano mai qui: ogni rotta sta dietro `auth`, ed è la stessa
 * postura già accettata per TenantScope.
 */
class GaranziaRicambioPrivacyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! CurrentTenant::shouldScope()) {
            return;
        }

        if (! Auth::user()?->can('garanzie.ricambio.view')) {
            $builder->where($model->qualifyColumn('soggetto'), SoggettoGaranzia::Macchina->value);
        }
    }
}
