<?php

namespace App\Models\Scopes;

use App\Models\RicambioUtilizzo;
use App\Support\Tenancy\AccessibleNodes;
use App\Support\Tenancy\AccessibleStrumenti;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Livello 2 (ADR-006) per `garanzie`, che a uno strumento arriva per DUE strade
 * diverse a seconda del soggetto (ERD §6.1):
 *
 *   soggetto = macchina  →  garanzie.strumento_id
 *   soggetto = ricambio  →  garanzie.ricambio_utilizzo_id → ricambio_utilizzo.strumento_id
 *
 * **Perché uno scope dedicato e non `BelongsToOrgNodeThroughStrumento`**
 * (S4 blocco 2, debito S3 lettera b). Il trait generico filtra su una colonna
 * sola, e sulle righe `ricambio` quella colonna è NULL: `NULL IN (...)` è
 * UNKNOWN, quindi il Responsabile non vedeva le garanzie dei pezzi montati
 * sulle proprie macchine — fail-closed, ma sbagliato, perché il permesso ce
 * l'ha. Non era risolvibile affiancando un secondo scope: i due si applicano in
 * AND e il `whereIn` su `strumento_id` avrebbe continuato a scartare le stesse
 * righe. Il trait è stato quindi RIMOSSO da `Garanzia` e sostituito da qui,
 * ed è per questo che il ramo `macchina` deve restare coperto: senza,
 * chiudendo un debito di sicurezza se ne aprirebbe uno più grande — un
 * Responsabile vedrebbe le garanzie macchina di tutto l'Ente.
 *
 * **L'OR sta dentro una closure, e non è estetica.** Fuori dal gruppo si
 * legherebbe al `where` del `TenantScope` applicato prima, e il risultato
 * sarebbe «(tenant mio AND strumento nel mio sotto-albero) OR (utilizzo nel mio
 * sotto-albero)»: un ramo senza confine Ente. Uno scope mal parentesizzato non
 * restringe, ALLARGA. Un test lo congela leggendo le sole righe `macchina`.
 *
 * **La subquery interna gira `withoutGlobalScopes()`** per la stessa ragione di
 * `AccessibleStrumenti`: `RicambioUtilizzo` usa a sua volta
 * `BelongsToOrgNodeThroughStrumento`, e lasciarne attivi gli scope annidereb-
 * be un secondo livello 2 dentro il primo. Il prezzo è che si perde anche
 * `SoftDeletingScope`, quindi tenant e `deleted_at` vanno riapplicati a mano —
 * la consegna scritta nel docblock di `RicambioUtilizzo` quando la tabella è
 * nata: una riga cestinata non esiste per nessuna lettura di dominio.
 */
class GaranziaDepartmentScope implements Scope
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

        $builder->where(fn (Builder $query) => $query
            ->whereIn(
                $model->qualifyColumn('strumento_id'),
                AccessibleStrumenti::nei($ids)
            )
            ->orWhereIn(
                $model->qualifyColumn('ricambio_utilizzo_id'),
                RicambioUtilizzo::withoutGlobalScopes()
                    ->where('ricambio_utilizzo.tenant_id', CurrentTenant::id())
                    ->whereNull('ricambio_utilizzo.deleted_at')
                    ->whereIn('ricambio_utilizzo.strumento_id', AccessibleStrumenti::nei($ids))
                    ->select('ricambio_utilizzo.id')
            ));
    }
}
