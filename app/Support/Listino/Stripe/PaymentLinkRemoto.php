<?php

namespace App\Support\Listino\Stripe;

/**
 * Un Payment Link come Stripe lo racconta (🔗 ADR-035, ADR-039).
 *
 * Un dato nostro e non l'oggetto del SDK, per la stessa ragione scritta in
 * `PrezzoRemoto`: ciò che attraversa il confine del dominio è un dato, ed è
 * anche ciò che rende scrivibile una porta finta senza costruire mezzo SDK.
 *
 * `attivo` è la sola leva che spegne un link già mandato a qualcuno: un plink è
 * pubblico e permanente, quindi «smettere di venderlo» significa `active: false`
 * su Stripe, non toglierlo da una pagina.
 */
final class PaymentLinkRemoto
{
    public function __construct(
        public string $id,
        public string $url,
        public bool $attivo = true,
    ) {}
}
