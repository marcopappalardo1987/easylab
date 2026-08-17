<?php

namespace App\Models\Contracts;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Il modello sa restringere una query alle proprie righe che si riferiscono a
 * un insieme di strumenti (ERD §10 — ADR-006/007/030).
 *
 * Nasce da una convenzione implicita: `strumentoColumn()` è il metodo che
 * `BelongsToOrgNodeThroughStrumento` usa dal S3 per il livello 2 del Global
 * Scope. ADR-030 gli dà un secondo consumatore — il canale "assegnazione"
 * dell'accesso Tecnico — e due consumatori che dipendono da un `method_exists()`
 * sono un contratto che nessuno ha mai scritto.
 *
 * ⚠️ **Il contratto è la RESTRIZIONE, non la colonna**, ed è la lezione pagata
 * scrivendolo: `Garanzia` allo strumento arriva per due strade — `strumento_id`
 * sulle righe macchina, il doppio salto via `ricambio_utilizzo` su quelle
 * ricambio (vedi `GaranziaDepartmentScope`) — e con un contratto "dammi la
 * colonna" sarebbe stata inesprimibile: su una riga `ricambio` quella colonna è
 * NULL, e `NULL IN (...)` è UNKNOWN. Chiedere invece al modello di applicare il
 * proprio vincolo lascia che ognuno conosca le proprie strade, che è l'unico
 * posto dove quella conoscenza sta bene.
 *
 * Per lo stesso motivo l'implementazione deve tenere le proprie clausole in un
 * gruppo di parentesi proprio quando ne ha più d'una: un OR che sfugge al gruppo
 * si lega alla condizione applicata prima, e uno scope mal parentesizzato non
 * restringe — **allarga**.
 *
 * Chi dimentica di implementarlo su un modello nuovo con `strumento_id` non apre
 * una falla: il modello resta visibile al Tecnico per il solo portafoglio,
 * quindi fail-closed. Perde però un accesso legittimo in silenzio, ed è la
 * ragione del meta-test che accompagna ADR-030.
 */
interface ReachesStrumento
{
    /**
     * Restringe `$query` alle righe che si riferiscono agli strumenti elencati.
     *
     * @param  Builder<*>  $query
     * @param  Builder<*>|BuilderContract  $strumenti  sottoquery che seleziona id di strumenti
     */
    public function vincolaAStrumenti(Builder $query, Builder|BuilderContract $strumenti): void;
}
