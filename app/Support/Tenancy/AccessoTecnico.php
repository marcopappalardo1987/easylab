<?php

namespace App\Support\Tenancy;

use App\Models\Contracts\ReachesStrumento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * L'accesso del Tecnico (ADR-007, esteso da ADR-030) — **l'unico posto** in cui
 * la regola è scritta. Sibling di CurrentTenant e AccessibleNodes.
 *
 * La regola è UNA SOLA e vale sia per il tecnico interno (dipendente del
 * laboratorio, `users.tenant_id` valorizzato) sia per quello esterno (staff
 * EasyLab, `tenant_id` NULL):
 *
 *   visibile ⇔ riga di un Ente nel **portafoglio**
 *              ∪ riga della macchina su cui ha un **intervento assegnato**
 *
 * I due canali hanno costi diversi, ed è la ragione per cui il codice li tiene
 * separati invece di fonderli in una condizione sola:
 * - il **portafoglio** è per-Ente, quindi si esprime come `tenant_id IN (...)`
 *   e vale identico per qualunque modello con `tenant_id`;
 * - l'**assegnazione** è per-macchina, quindi richiede il salto verso lo
 *   strumento — lo stesso problema già risolto da
 *   `DepartmentThroughStrumentoScope`, di cui qui si riusa il meccanismo
 *   (`ReachesStrumento::strumentoColumn()`) invece di scriverne un secondo.
 *
 * L'unione è un **OR fra i due canali**, chiusa in un solo gruppo di parentesi:
 * in AND si otterrebbe «solo le macchine assegnate degli Enti in portafoglio»,
 * cioè meno di entrambi i canali presi da soli — l'esatto contrario di ADR-007.
 *
 * **Nessuna cache.** Portafoglio e assegnazioni sono due *sottoquery SQL*, non
 * due liste caricate in PHP: costano zero roundtrip, restano vere anche se il
 * portafoglio cambia a metà richiesta (una revoca deve avere effetto subito) e
 * non hanno una cache da invalidare. È il motivo per cui questa classe non
 * assomiglia ad AccessibleNodes, che invece deve espandere un albero in PHP.
 */
class AccessoTecnico
{
    /**
     * Utente corrente se — e solo se — è un Tecnico. Il criterio si applica al
     * solo ruolo Tecnico: Superadmin e Developer restano tenant-bound
     * (ADR-018), e allargare questa porta a un altro ruolo significherebbe
     * dargli il portafoglio di qualcun altro (cioè: niente) e toglierli il
     * proprio Ente.
     */
    public static function tecnico(): ?User
    {
        $user = Auth::user();

        return $user instanceof User && $user->isTecnico() ? $user : null;
    }

    /** True quando la regola di ADR-030 sostituisce il normale confine Ente. */
    public static function siApplica(): bool
    {
        return self::tecnico() !== null;
    }

    /**
     * Innesta il criterio sul builder, in AND con quanto già presente.
     *
     * Entrambi i rami sono `IN (sottoquery)`: con una sottoquery vuota il
     * predicato è falso, quindi un tecnico senza portafoglio né assegnazioni
     * non vede nulla **senza bisogno di un ramo dedicato** — il fail-closed di
     * ADR-018 sopravvive per costruzione e non per una guardia da ricordarsi.
     *
     * Sui modelli che non sanno raggiungere uno strumento (`UnitaOrganizzativa`)
     * resta il solo portafoglio: l'assegnazione dà accesso a *una macchina*, non
     * all'albero organizzativo del cliente. È l'esposizione minima di ADR-007,
     * e la conseguenza — un tecnico che vede una macchina assegnata fuori
     * portafoglio non ne legge l'ubicazione — è dichiarata in ADR-030.
     */
    public static function applica(Builder $builder, Model $model): void
    {
        $tecnico = self::tecnico();

        if ($tecnico === null) {
            return;
        }

        $builder->where(function (Builder $criterio) use ($model, $tecnico): void {
            $criterio->whereIn($model->qualifyColumn('tenant_id'), self::portafoglio($tecnico));

            if (! $model instanceof ReachesStrumento) {
                return;
            }

            // Il vincolo del modello va in un gruppo PROPRIO, perché può essere
            // esso stesso un OR: `Garanzia` allo strumento arriva per due strade
            // (riga macchina / riga ricambio). Senza questo annidamento il suo
            // secondo ramo si legherebbe all'OR del portafoglio, e il risultato
            // sarebbe più largo dell'unione voluta invece che uguale.
            $criterio->orWhere(fn (Builder $assegnazione) => $model->vincolaAStrumenti(
                $assegnazione,
                self::strumentiAssegnati($tecnico),
            ));
        });
    }

    /**
     * Canale 1 — Enti del portafoglio (pivot `tecnico_cliente`).
     *
     * Letto con il query builder e non con la relazione Eloquent: passare da
     * `UnitaOrganizzativa` significherebbe applicarne i global scope, e il primo
     * di quelli è proprio il TenantScope che sta chiamando questo metodo →
     * ricorsione. Stessa ragione, stessa forma di AccessibleNodes.
     */
    protected static function portafoglio(User $tecnico): BuilderContract
    {
        return DB::table('tecnico_cliente')
            ->where('tecnico_cliente.tecnico_id', $tecnico->id)
            ->select('tecnico_cliente.ente_id');
    }

    /**
     * Canale 2 — strumenti su cui il tecnico ha un intervento assegnato.
     *
     * `withoutGlobalScopes()` rompe il ciclo Intervento→(scope)→Intervento: è
     * la stessa precauzione, e per lo stesso motivo, di
     * `DepartmentThroughStrumentoScope`, che l'aveva già presa "in anticipo"
     * proprio per questo scope.
     *
     * L'alias è necessario, non estetico: quando il modello scopato è
     * `Intervento` la sottoquery legge la **stessa tabella** dell'outer query, e
     * senza alias `interventi.tecnico_id` sarebbe una colonna ambigua.
     *
     * `deleted_at IS NULL` è invece una scelta di sicurezza, ed è il punto in
     * cui questo scope **diverge** da DepartmentThroughStrumentoScope: là il
     * soft delete si ignora perché la subquery serve solo a collocare la riga
     * nell'albero; qui la riga *è* il permesso, e un'assegnazione cestinata è
     * un'assegnazione revocata. Senza questa riga si toglierebbe l'intervento
     * dalla lista e il tecnico continuerebbe a vedere la macchina.
     */
    protected static function strumentiAssegnati(User $tecnico): Builder
    {
        return Intervento::withoutGlobalScopes()
            ->from('interventi as interventi_assegnati')
            ->where('interventi_assegnati.tecnico_id', $tecnico->id)
            ->whereNull('interventi_assegnati.deleted_at')
            ->select('interventi_assegnati.strumento_id');
    }

    /**
     * Audit dell'apertura di una scheda macchina (ADR-007, ribadito da ADR-030).
     *
     * È la **seconda eccezione** al perimetro di ADR-027 «si tracciano le
     * scritture, non le letture» — la prima è il download dei documenti
     * (ADR-026). Vale per **tutti** i tecnici, interni compresi: la traccia
     * documenta un accesso a esposizione minima, e distinguere interno da
     * esterno vorrebbe dire fidarsi di `users.tenant_id`, che ADR-030 declassa
     * espressamente da criterio di accesso a difesa in profondità.
     *
     * No-op per chiunque non sia un Tecnico, così ogni punto d'ingresso —
     * scheda, e in S4 la scansione QR — può chiamarla senza replicare il
     * controllo di ruolo.
     */
    public static function tracciaAperturaScheda(Strumento $strumento): void
    {
        $tecnico = self::tecnico();

        if ($tecnico === null) {
            return;
        }

        activity(AuditLog::NAME)
            ->causedBy($tecnico)
            ->performedOn($strumento)
            ->withProperties([
                'tenant_id_strumento' => $strumento->tenant_id,
                'tenant_id_tecnico' => $tecnico->tenant_id,
                // Il dato che rende il registro leggibile: l'accesso di un
                // tecnico ai dati di un Ente che non è il suo è il caso che
                // ADR-007 voleva tracciabile.
                'cross_tenant' => $strumento->tenant_id !== $tecnico->tenant_id,
            ])
            ->log('Scheda strumento aperta da un tecnico');
    }
}
