<?php

namespace App\Support\Utenti;

use App\Models\Strumento;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Chi può essere **assegnatario** di un intervento su una macchina (🔗 ADR-038).
 *
 * Una definizione sola, consumata dalla tendina **e** dalla validazione al
 * salvataggio: è la regola che `SchedaStrumento` teneva in casa dal blocco 3 di
 * S3, spostata qui perché da oggi ha una seconda metà — il portafoglio — che
 * nasce in una schermata diversa.
 *
 * ```
 * tecnici interni con tenant_id = strumenti.tenant_id  (l'Ente della macchina)
 *   ∪  tecnici EasyLab con tenant_id IS NULL
 *      CHE SONO nel portafoglio `tecnico_cliente` di QUELLA sede
 * ```
 *
 * ## 🔴 Cosa è cambiato il 29 Ago 2026, e perché non era sostenibile
 *
 * Il secondo ramo non aveva la terza riga: **ogni** tecnico di piattaforma era
 * assegnabile su **ogni** cliente. Con il tecnico unico della demo non si
 * notava; con dieci tecnici veri ogni Admin cliente leggeva l'organigramma di
 * EasyLab in una `<select>` e poteva dare lavoro a chiunque.
 *
 * ⚠️ E le due metà **divergevano**: `AccessoTecnico` (🔗 ADR-007/030) dà a un
 * tecnico le macchine per **portafoglio ∪ assegnazione**, quindi un tecnico
 * fuori portafoglio era assegnabile su una macchina che non poteva vedere. Ora
 * la porta d'ingresso è la stessa da entrambi i lati: chi compare qui è chi
 * EasyLab ha messo su quel cliente.
 *
 * *Resta vero — e voluto — che l'assegnazione **puntuale** apra comunque quella
 * singola macchina: è il secondo canale di ADR-030, e non passa di qui.*
 *
 * Entrambi i rami richiedono il ruolo `Tecnico`: appartenere all'Ente non rende
 * una persona assegnabile. Admin, Tenant e ruoli di piattaforma non devono
 * comparire in una tendina operativa destinata ai tecnici.
 *
 * ## ⛔ `User` non ha global scope: la whitelist è l'unica barriera
 *
 * Nessun `TenantScope` filtra questa tabella (🔗 ADR-018 la esenta: è l'identità,
 * non il dominio). Quindi ciò che questa classe non esclude, **entra** — ed è la
 * ragione per cui la stessa query serve la tendina e la validazione, invece di
 * fidarsi che il browser rimandi indietro una delle opzioni offerte.
 *
 * *Da 🔗 ADR-038 una persona cestinata sparisce da sé, per il `SoftDeletes` del
 * model: non c'è un `whereNull('deleted_at')` da ricordare qui, ed è il punto
 * di aver scelto il cestino invece di un flag.*
 */
final class Assegnabili
{
    /**
     * @return Builder<User>
     */
    public static function perStrumento(Strumento $strumento): Builder
    {
        return self::perSede($strumento->tenant_id);
    }

    /**
     * La stessa regola a partire dall'**id della sede**, per chi lo strumento
     * non ce l'ha in mano.
     *
     * @return Builder<User>
     */
    public static function perSede(?int $sedeId): Builder
    {
        // ⛔ Fail-closed: in SQL `where('tenant_id', null)` diventa
        // `tenant_id IS NULL`. Senza questa guardia il primo ramo dell'unione
        // includerebbe quindi *ogni* identità di piattaforma, anche senza ruolo
        // Tecnico e senza portafoglio, proprio quando manca il confine sede.
        if ($sedeId === null) {
            return User::query()->whereRaw('1 = 0');
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', User::TECNICO_ROLE))
            ->where(fn (Builder $q) => $q
                ->where('tenant_id', $sedeId)
                ->orWhere(fn (Builder $q) => $q
                    ->whereNull('tenant_id')
                // 🔴 La riga che restringe: il tecnico di EasyLab è assegnabile
                // qui **solo se lavora qui**.
                //
                // ⛔ Letto dal pivot col query builder e **non** dalla relazione
                // `portafoglioClienti()`, che è Eloquent su `UnitaOrganizzativa`
                // e ne applicherebbe i global scope. Il secondo di quelli è
                // `DepartmentScope`: per un **Responsabile Reparto** — che
                // `interventi.assign` ce l'ha — il nodo Ente non sta nel proprio
                // sotto-albero, quindi la sottoquery non troverebbe nulla e la
                // tendina perderebbe **tutti** i tecnici EasyLab, in silenzio e
                // solo per quel ruolo. È la stessa ragione, e la stessa forma,
                // di `AccessoTecnico::portafoglio()`.
                    ->whereIn('users.id', DB::table('tecnico_cliente')
                        ->select('tecnico_cliente.tecnico_id')
                        ->where('tecnico_cliente.ente_id', $sedeId))));
    }
}
