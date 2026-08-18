<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\AccessibleNodes;
use App\Support\Tenancy\AccessibleStrumenti;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Livello 2 del Global Scope (ADR-006) per i modelli che NON hanno una
 * collocazione propria nell'albero ma appendono a uno strumento: la restrizione
 * al sotto-albero del Responsabile Reparto si applica INDIRETTAMENTE, via
 * `strumento_id`. In AND col livello 1 (TenantScope sul modello stesso).
 *
 * La subquery vive in `AccessibleStrumenti`, condivisa con
 * `GaranziaDepartmentScope` (S4 blocco 2) — che deve raggiungere le righe
 * `soggetto = ricambio` con un salto in più e ha bisogno della stessa
 * definizione. Lì sono spiegati i due dettagli che la reggono:
 * `withoutGlobalScopes()` (rompe in anticipo il ciclo col futuro scope Tecnico,
 * ed è il motivo per cui NON si usa `whereHas`) e il confine Ente riapplicato
 * a mano dentro la subquery.
 *
 * Conseguenza voluta, che vale per i model che usano questo scope: gli
 * interventi e i ricambi montati su uno strumento cestinato restano visibili,
 * esattamente come li vede l'Admin, che questo filtro non ce l'ha.
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
            AccessibleStrumenti::nei($ids)
        );
    }
}
