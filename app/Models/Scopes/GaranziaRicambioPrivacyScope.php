<?php

namespace App\Models\Scopes;

use App\Enums\SoggettoGaranzia;
use App\Models\Garanzia;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Privacy delle garanzie sui ricambi. Chi non ha titolo a vederle legge solo le
 * righe `macchina`.
 *
 * Primo global scope del progetto basato su un PERMESSO e non sulla tenancy —
 * e, da ADR-029, non più sul solo permesso: la domanda «questo utente ha titolo
 * a vederle?» ha ora due termini, e la risposta sta tutta nella Policy.
 *
 * **Cosa è cambiato con ADR-029** (deciso il 9, attuato il 15 Ago 2026). Prima
 * valeva ADR-004: mai al Tenant, punto, con `garanzie.ricambio.*` nel set 🔒.
 * Ora il Tenant ha i
 * permessi per default e a nascondergli le righe è l'impostazione del suo Ente
 * (`nascosta`). Lo scope non lo sa e non deve saperlo: delega a
 * `GaranziaRicambioPolicy::view()`, che tiene insieme permesso e impostazione —
 * altrimenti la regola vivrebbe in due posti liberi di divergere, e uno dei due
 * è un global scope, cioè il posto in cui una divergenza non si vede.
 *
 * ⚠️ **Nascosto ≠ non modificabile.** Questo scope conosce solo `nascosta`;
 * `lettura` non toglie righe ma scrittura, e vive in `manage()`. Confondere i
 * due piani — nascondere una riga per negarne la modifica — renderebbe il tab
 * Ricambi incomprensibile.
 *
 * Fail-closed: si nasconde per difetto e si mostra solo con titolo esplicito,
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

        if (! Gate::forUser(Auth::user())->allows('view', Garanzia::class)) {
            $builder->where($model->qualifyColumn('soggetto'), SoggettoGaranzia::Macchina->value);
        }
    }
}
