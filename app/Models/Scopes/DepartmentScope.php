<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\AccessibleNodes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Livello 2 del Global Scope (ADR-006): restringe le query al sotto-albero
 * accessibile al Responsabile Reparto corrente. In AND col livello 1
 * (TenantScope). Separato da TenantScope perché si applica solo ai modelli con
 * una collocazione nell'albero (opt-in via trait BelongsToOrgNode).
 */
class DepartmentScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $ids = AccessibleNodes::forCurrentUser();

        if ($ids === null) {
            return; // nessuna restrizione di reparto (Admin/Tenant/bypass/console)
        }

        $builder->whereIn($model->qualifyColumn($model->orgNodeColumn()), $ids);
    }
}
