<?php

namespace App\Support\Listino\Stripe;

use App\Models\Piano;

/**
 * Il confine fra il listino e Stripe (🔗 ADR-035).
 *
 * **Perché un'interfaccia qui e non in `easylab:abbona`.** Là il percorso
 * felice non ha test di suite ed è dichiarato: mockare `StripeClient`
 * produrrebbe un test che verifica il proprio mock. Qui la differenza è che la
 * logica **nostra** è sostanziale — l'idempotenza a due livelli, lo storico dei
 * prezzi, cosa resta scritto se Stripe rifiuta — e va provata. La finta di test
 * non verifica le risposte di Stripe: verifica le **nostre transizioni di
 * stato**.
 *
 * ⚠️ Ogni metodo di questa porta è una **chiamata di rete**, quindi vive fuori
 * da qualunque `DB::transaction`: una transazione che contiene un round-trip
 * tiene un lock aperto per la sua durata, e soprattutto il rollback non annulla
 * ciò che Stripe ha già creato.
 */
interface PortaListinoStripe
{
    /**
     * Crea il Product se il piano non ne ha uno, altrimenti ne aggiorna il
     * nome. Restituisce il product id.
     */
    public function creaOAggiornaProdotto(Piano $piano): string;

    /**
     * Crea un Price nuovo. Su Stripe un Price è **immutabile**: non esiste un
     * «aggiorna l'importo», esiste solo crearne un altro.
     *
     * `$chiaveIdempotenza` è il primo dei due livelli di protezione contro il
     * doppio invio, e **da solo non basta**: la chiave di Stripe scade dopo 24
     * ore, quindi non protegge il retry di domani. Il secondo livello è nostro
     * e sta in `GovernoListino`, che confronta importo e valuta con la riga
     * corrente e non chiama affatto se nulla è cambiato.
     */
    public function creaPrezzo(Piano $piano, int $importoCent, string $valuta, string $chiaveIdempotenza): PrezzoRemoto;

    /**
     * Archivia un price (`active = false`).
     *
     * Le subscription in essere **continuano a fatturare** su un price
     * archiviato — è ciò che rende attuabile «chi è già abbonato resta al suo»
     * — mentre nessuna nuova subscription può più agganciarlo.
     */
    public function archiviaPrezzo(string $priceId): void;

    /** Il Product come Stripe lo vede, o `null` se non esiste più. */
    public function leggiProdotto(string $productId): ?array;

    /** Il Price come Stripe lo vede, o `null` se non esiste. */
    public function leggiPrezzo(string $priceId): ?PrezzoRemoto;
}
