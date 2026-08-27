<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * Apre il Billing Portal di Stripe per l'Account del richiedente (ADR-032).
 *
 * ⚠️ SEGNAPOSTO tenuto dall'orchestratore: il corpo arriva col blocco «Stripe
 * Billing Portal». L'autorizzazione va scritta QUI DENTRO — la rotta vive nel
 * gruppo `auth` nudo, senza `can:`.
 */
class AperturaPortaleStripe extends Controller
{
    public function __invoke(): RedirectResponse
    {
        abort(404);
    }
}
