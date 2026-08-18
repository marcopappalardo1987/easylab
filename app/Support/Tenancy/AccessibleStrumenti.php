<?php

namespace App\Support\Tenancy;

use App\Models\Strumento;
use Illuminate\Database\Eloquent\Builder;

/**
 * Subquery "gli strumenti del sotto-albero" (ADR-006), un gradino sotto
 * AccessibleNodes: quella risolve i NODI accessibili, questa gli strumenti che
 * ci stanno dentro.
 *
 * Esiste come classe a sé dal secondo consumatore in poi (S4 blocco 2): la
 * usano `DepartmentThroughStrumentoScope` — per interventi e ricambi montati —
 * e `GaranziaDepartmentScope`, che deve raggiungere le righe `soggetto =
 * ricambio` con un salto in più. Due copie della stessa subquery di sicurezza
 * sarebbero libere di divergere, e la prima correzione applicata a una sola
 * delle due aprirebbe un buco silenzioso.
 *
 * Porta con sé i due dettagli che è facile dimenticare riscrivendola:
 *
 * 1. **`withoutGlobalScopes()`**, che rompe in anticipo il ciclo con lo scope
 *    Tecnico di ADR-007 (S4 blocco 10): quello interrogherà `interventi` per
 *    decidere quali strumenti sono visibili, e se qui applicassimo gli scope di
 *    Strumento la ricorsione sarebbe garantita. È anche il motivo per cui i
 *    chiamanti NON usano `whereHas`.
 * 2. **Il confine Ente riapplicato a mano**, invece di delegarlo al TenantScope
 *    del chiamante: `AccessibleNodes` prende le radici dal pivot
 *    `responsabile_unita` senza verificarne il tenant (solo i discendenti sono
 *    filtrati per Ente), e senza global scope la subquery perderebbe anche il
 *    TenantScope di Strumento. Difesa in profondità su un'area rossa.
 *
 * Effetto collaterale voluto di (1): niente `SoftDeletingScope`, quindi le
 * righe appese a uno strumento cestinato restano visibili — esattamente come le
 * vede l'Admin, che questo filtro non ce l'ha. Questa classe fa una cosa sola:
 * restringere per nodo.
 */
final class AccessibleStrumenti
{
    /**
     * @param  list<int>  $nodi  id-nodo già espansi da AccessibleNodes
     * @return Builder<Strumento>
     */
    public static function nei(array $nodi): Builder
    {
        return Strumento::withoutGlobalScopes()
            ->where('strumenti.tenant_id', CurrentTenant::id())
            ->whereIn('strumenti.unita_organizzativa_id', $nodi)
            ->select('strumenti.id');
    }
}
