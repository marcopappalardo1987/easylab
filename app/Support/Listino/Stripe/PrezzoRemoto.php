<?php

namespace App\Support\Listino\Stripe;

/**
 * Un Price come Stripe lo racconta (🔗 ADR-035).
 *
 * Un oggetto nostro e non l'oggetto del SDK: ciò che attraversa il confine del
 * dominio è un dato, non una risorsa remota con dentro un client HTTP. È anche
 * ciò che rende scrivibile una porta finta senza costruire mezzo SDK.
 */
final class PrezzoRemoto
{
    public function __construct(
        public string $id,
        public int $importoCent,
        public string $valuta,
        public bool $attivo,
    ) {}
}
