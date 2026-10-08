<?php

namespace App\Support\Tenancy;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Il perimetro del Superadmin sui clienti la cui manutenzione è **gestita da
 * EasyLab** (🔗 ADR-046; ADR-018 il confine che allarga, ADR-032 l'Account su
 * cui sta il segno). Sibling di `AccessoTecnico`.
 *
 *   visibile ⇔ riga del **proprio Ente**
 *              ∪ riga di una sede di un account con `manutenzione_gestita`
 *
 * ## 🔴 Perché non è un bypass di ADR-018
 *
 * ADR-018 dice che nessun ruolo scavalca lo scope, e resta vero: il Superadmin
 * non vede «tutto», vede un insieme **nominato cliente per cliente**, che lui
 * stesso accende e spegne (`Account::affidaManutenzione()`, tracciato in audit).
 * Un cliente che si gestisce da sé resta dov'era — Parco in sola lettura e
 * impersonazione — e senza nessun cliente gestito questa classe restituisce
 * esattamente il confine di prima.
 *
 * ## Perché un OR col proprio Ente, e non un AND come per il Tecnico interno
 *
 * Il Tecnico interno è un dipendente del laboratorio: il suo Ente è una barriera
 * in più. Il Superadmin sta sull'Ente di EasyLab, che non è cliente di nessuno:
 * in AND non vedrebbe mai una sede gestita.
 *
 * ## Per ruolo, non per permesso
 *
 * Come `AccessoTecnico` e `DepartmentScope`. Un permesso lo erediterebbe il
 * Developer (`all => true`), che per decisione resta fuori: fa debug
 * impersonando, non lavora sui dati dei clienti.
 *
 * **Nessuna cache**, per la ragione di `AccessoTecnico`: è una sottoquery, e
 * togliere il segno a un cliente deve avere effetto alla query successiva.
 */
class ClientiGestiti
{
    public static function superadmin(): ?User
    {
        $user = Auth::user();

        return $user instanceof User && $user->isSuperadmin() ? $user : null;
    }

    public static function siApplica(): bool
    {
        return self::superadmin() !== null;
    }

    /**
     * Innesta il criterio sul builder, in AND con quanto già presente.
     *
     * Le due clausole stanno in un gruppo di parentesi proprio: fuori dal gruppo
     * l'OR si legherebbe alla condizione applicata prima e **allargherebbe**.
     *
     * Con `$tenantId` null resta la sola sottoquery, e con nessun cliente
     * gestito quella è vuota: il fail-closed di ADR-018 sopravvive per
     * costruzione, senza un ramo da ricordarsi.
     */
    public static function applica(Builder $builder, Model $model, ?int $tenantId): void
    {
        $builder->where(function (Builder $criterio) use ($model, $tenantId): void {
            $criterio->whereIn($model->qualifyColumn('tenant_id'), self::sedi());

            if ($tenantId !== null) {
                $criterio->orWhere($model->qualifyColumn('tenant_id'), $tenantId);
            }
        });
    }

    /** True se almeno un cliente è gestito: senza, la regola non aggiunge nulla. */
    public static function esistono(): bool
    {
        return self::sedi()->exists();
    }

    /**
     * True se `$enteId` è una sede di un cliente gestito: la domanda di chi
     * **scrive** (`BelongsToTenant`), dove non c'è un builder su cui innestarsi.
     */
    public static function copre(int $enteId): bool
    {
        return self::sedi()->where('sedi_gestite.id', $enteId)->exists();
    }

    /**
     * Le sedi dei clienti gestiti.
     *
     * Query builder e non Eloquent: `UnitaOrganizzativa` porta il `TenantScope`
     * che sta chiamando questo metodo → ricorsione (stessa forma di
     * `AccessoTecnico::portafoglio()`).
     *
     * L'alias è necessario: quando il modello scopato è `UnitaOrganizzativa` la
     * sottoquery legge la stessa tabella dell'outer query.
     *
     * Nessun filtro sul tipo di nodo: `account_id` lo portano solo i nodi Ente
     * (invariante di `UnitaOrganizzativa::saving`, ADR-032).
     *
     * ⚠️ Fuori le sedi e gli account **cestinati**: qui la riga *è* il permesso,
     * e un cliente archiviato è un rapporto chiuso. Il lockout invece non
     * conta: chiude la porta agli utenti del cliente, non il lavoro di EasyLab
     * sulle sue macchine.
     */
    protected static function sedi(): BuilderContract
    {
        return DB::table('unita_organizzativa as sedi_gestite')
            ->whereNull('sedi_gestite.deleted_at')
            ->whereIn('sedi_gestite.account_id', DB::table('accounts')
                ->where('accounts.manutenzione_gestita', true)
                ->whereNull('accounts.deleted_at')
                ->select('accounts.id'))
            ->select('sedi_gestite.id');
    }
}
