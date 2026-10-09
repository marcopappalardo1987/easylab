<?php

namespace App\Support\Notifiche;

use App\Enums\StatoSemaforo;
use App\Models\Strumento;
use App\Notifications\MacchinaSegnalata;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use App\Support\Mail\MarchioEmail;

/**
 * L'email che segue la segnalazione di una macchina (🔗 ADR-047, aggiunta del
 * 9 Ott 2026; ADR-005 la forzatura del semaforo).
 *
 * Sibling di `AvvisiIntervento`, e fatto allo stesso modo: lo chiama il gesto
 * (la scheda della macchina, dopo la forzatura), legge tutto nella richiesta
 * di chi agisce e passa alla notifica solo scalari.
 *
 * ## Quando parte
 *
 * Quando il semaforo viene portato **a mano** su «Azione richiesta» o «Non
 * idoneo». Non quando viene portato su «In regola» — è una buona notizia, e non
 * è ciò che questa email promette — e non quando la forzatura viene tolta.
 *
 * Destinatari: gli stessi delle email sugli interventi
 * (`DestinatariEnte::perMacchina()`), cioè le persone del cliente che seguono
 * quella macchina, meno chi l'ha segnalata.
 *
 * ⚠️ Esce subito se l'email è spenta in piattaforma: nasce spenta.
 */
final class AvvisiStrumento
{
    public static function segnalata(Strumento $strumento): void
    {
        $stato = $strumento->forced_state;

        if (! in_array($stato, [StatoSemaforo::Arancione, StatoSemaforo::Rosso], true)
            || ! InterruttoriEmail::attiva(CatalogoEmail::MACCHINA_SEGNALATA)) {
            return;
        }

        $ente = MarchioEmail::perEnte($strumento->tenant_id)->nome;
        $ubicazione = $strumento->percorsoUbicazione();

        foreach (DestinatariEnte::perMacchina((int) $strumento->tenant_id, (int) $strumento->unita_organizzativa_id, auth()->id()) as $utente) {
            $utente->notify(new MacchinaSegnalata(
                enteId: (int) $strumento->tenant_id,
                enteNome: $ente,
                strumentoId: (int) $strumento->id,
                strumentoNome: (string) $strumento->nome,
                ubicazione: $ubicazione,
                stato: $stato->etichetta(),
                nonIdonea: $stato === StatoSemaforo::Rosso,
                motivo: $strumento->forced_reason,
                autore: auth()->user()?->name,
            ));
        }
    }
}
