<?php

namespace App\Support\Listino\Stripe;

/**
 * Un Price come Stripe lo racconta (🔗 ADR-035).
 *
 * Un oggetto nostro e non l'oggetto del SDK: ciò che attraversa il confine del
 * dominio è un dato, non una risorsa remota con dentro un client HTTP. È anche
 * ciò che rende scrivibile una porta finta senza costruire mezzo SDK.
 *
 * ⚠️ **`prodotto` non è decorazione: è ciò che rende agganciabile un price.** Su
 * Stripe un Price appartiene sempre a un Product, e chi aggancia un price
 * esistente sta implicitamente dichiarando **anche** il prodotto del piano. Senza
 * questo campo `agganciaPrezzo()` non poteva né registrarlo — e il click
 * successivo su «Sincronizza» creava un secondo Product, cioè esattamente la
 * duplicazione che quell'azione esiste per evitare — né accorgersi che il price
 * incollato appartiene a un altro prodotto.
 *
 * `null` significa «Stripe non l'ha detto», non «nessun prodotto»: è la forma
 * che una risposta parziale avrebbe, e le guardie che lo consumano si astengono
 * invece di indovinare.
 */
final class PrezzoRemoto
{
    public function __construct(
        public string $id,
        public int $importoCent,
        public string $valuta,
        public bool $attivo,
        public ?string $prodotto = null,
    ) {}
}
