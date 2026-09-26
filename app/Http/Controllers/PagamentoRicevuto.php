<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Dove atterra chi ha appena pagato su un **Payment Link** (🔗 ADR-039).
 *
 * ## ⛔ Non provisiona, e non deve
 *
 * `RegistrazionePubblica::completata()` completa davvero, perché lì la riga
 * esiste già ed è raggiunta da un URL **firmato**: la firma dice chi è. Qui no.
 * L'unico parametro è un id di sessione di Stripe, che non è un segreto e non
 * prova niente — e su di esso non si può far nascere un account, o chiunque
 * indovinasse un `cs_...` scatenerebbe un provisioning.
 *
 * Il webhook è la **sola** strada, ed è anche quella giusta: chi chiude la
 * scheda prima del redirect ottiene comunque il suo account. Due strade
 * sarebbero due gesti che divergono al primo cambiamento.
 *
 * ## Perché la pagina esiste allora
 *
 * Perché il momento fra «ha pagato 49 €» e «riceve l'invito» è il più delicato
 * del percorso: il cliente ha dato dei soldi e non ha ancora niente in mano.
 * Senza questa pagina resterebbe sulla ricevuta generica di Stripe, senza una
 * parola nostra su cosa succede adesso. Non afferma nulla che non sappia: dice
 * cosa aspettarsi e dove guardare.
 */
class PagamentoRicevuto extends Controller
{
    public function __invoke(Request $request)
    {
        return view('auth.pagamento-ricevuto');
    }
}
