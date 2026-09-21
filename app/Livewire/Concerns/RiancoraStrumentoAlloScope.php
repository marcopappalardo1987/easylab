<?php

namespace App\Livewire\Concerns;

use App\Models\Strumento;

/**
 * Rilegge `$this->strumento` **con i global scope attivi** a ogni richiesta
 * successiva al mount (ERD §Strumento; ADR-001/006/018).
 *
 * `public Strumento $strumento` torna dal browser reidratato da
 * `SupportModels\ModelSynth::hydrate()`, che usa `newQueryForRestoration()`,
 * cioè `newQueryWithoutScopes()`: TenantScope, DepartmentScope e soft delete
 * **non si applicano al ripristino**. Il binding di rotta verifica il legame
 * macchina↔contesto una volta sola; una scheda lasciata aperta mentre il
 * contesto cambia (uscita da un'impersonazione, cambio di sede, tecnico
 * esterno col `tenant_id` azzerato) continuerebbe a scrivere sulla macchina
 * di prima. Riprodotto in S7 (T1a): un Admin dell'Ente B, con lo snapshot
 * dell'Ente A, ne spostava la macchina nel proprio albero.
 *
 * Il checksum di Livewire impedisce di *cambiare* l'id, non di riusarlo in un
 * contesto diverso: la difesa è quindi la rilettura, non `#[Locked]`. È la
 * stessa scelta fatta su `MarchioEnte`, dove l'Ente non è più una property.
 * Fuori scope, o cestinata nel frattempo → 404, come sulla rotta.
 *
 * Hook di trait (`hydrate` + nome del trait): Livewire lo chiama prima di
 * aggiornare le property e prima dell'azione, e non collide con un
 * `hydrate()` del componente.
 */
trait RiancoraStrumentoAlloScope
{
    public function hydrateRiancoraStrumentoAlloScope(): void
    {
        $this->strumento = Strumento::query()->find($this->strumento->getKey()) ?? abort(404);
    }
}
