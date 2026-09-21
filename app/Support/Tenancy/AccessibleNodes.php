<?php

namespace App\Support\Tenancy;

use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Livello 2 del Global Scope (ADR-006): risolve i nodi dell'albero
 * organizzativo accessibili all'utente corrente quando ha una restrizione di
 * reparto (ruolo Responsabile Reparto). Sibling di CurrentTenant.
 */
class AccessibleNodes
{
    /**
     * Insieme degli id-nodo visibili al Responsabile corrente, oppure null se
     * NESSUNA restrizione di reparto si applica (guest/console, ruoli bypass,
     * o utente tenant-bound non Responsabile: Admin/Tenant vedono tutto l'Ente).
     *
     * Per un Responsabile: nodi assegnati (pivot responsabile_unita) + tutti i
     * loro discendenti nel proprio Ente. Senza assegnazioni → [] (fail-safe:
     * non vede nulla).
     *
     * @return list<int>|null
     */
    public static function forCurrentUser(): ?array
    {
        $user = Auth::user();

        return $user instanceof User ? self::forUser($user) : null;
    }

    /**
     * Stessa risposta di `forCurrentUser()`, per un utente qualunque invece che
     * per quello autenticato.
     *
     * Estratta in S5 per lo scheduler delle scadenze (ADR-011): il comando gira
     * in console, dove `Auth::user()` è null, e deve comunque sapere quali nodi
     * competono a ciascun Responsabile per non mandargli in email macchine di
     * reparti che in app non vedrebbe. Il criterio è quello — uno solo, in un
     * posto solo: se qui e nel digest divergessero, l'email diventerebbe il
     * canale che scavalca lo scope.
     *
     * @return list<int>|null
     */
    public static function forUser(User $user): ?array
    {
        if (! $user->isDepartmentScoped()) {
            return null;
        }

        $tenantId = $user->tenant_id;
        if ($tenantId === null) {
            return [];
        }

        // Letto direttamente dal pivot: interrogare la relazione passerebbe per
        // i global scope di UnitaOrganizzativa → ricorsione su DepartmentScope.
        $assigned = DB::table('responsabile_unita')
            ->where('user_id', $user->id)
            ->pluck('unita_organizzativa_id')
            ->all();

        if ($assigned === []) {
            return [];
        }

        return self::expandSubtrees($assigned, $tenantId);
    }

    /**
     * BFS DB-agnostico: dato l'insieme dei nodi radice assegnati, raccoglie
     * loro + tutti i discendenti. Carica solo l'albero dell'Ente (piccolo)
     * senza global scope per evitare la ricorsione di DepartmentScope.
     *
     * @param  list<int>  $roots
     * @return list<int>
     */
    protected static function expandSubtrees(array $roots, int $tenantId): array
    {
        $childrenByParent = [];
        $delTenant = [];
        UnitaOrganizzativa::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get(['id', 'parent_id'])
            ->each(function ($node) use (&$childrenByParent, &$delTenant) {
                $childrenByParent[$node->parent_id][] = (int) $node->id;
                $delTenant[(int) $node->id] = true;
            });

        // D-T2-2: una radice di `responsabile_unita` fuori dal tenant (dato
        // corrotto o scritto da fuori Eloquent) non diventa accessibile. Prima
        // lo impediva solo il TenantScope applicato insieme.
        $accessible = [];
        $queue = array_values(array_filter($roots, fn ($root) => isset($delTenant[(int) $root])));
        while ($queue !== []) {
            $id = (int) array_shift($queue);
            if (isset($accessible[$id])) {
                continue;
            }
            $accessible[$id] = true;
            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $queue[] = $childId;
            }
        }

        return array_map('intval', array_keys($accessible));
    }
}
