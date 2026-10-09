<?php

namespace App\Support\Notifiche;

use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\User;
use App\Notifications\InterventoAssegnato;
use App\Notifications\InterventoEseguito;
use App\Notifications\InterventoProgrammato;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use App\Support\Mail\MarchioEmail;

/**
 * Le email che seguono un gesto su un intervento: programmato, eseguito,
 * assegnato (🔗 ADR-047; ADR-011 le notifiche, ADR-030 chi lavora per il
 * cliente, ADR-006 il sotto-albero del Responsabile).
 *
 * ## Si chiama dal gesto, non da un evento del model
 *
 * Un `Intervento` nasce anche da un seeder, da un import, da un comando, e
 * dall'inserimento **storico** del backlog (un lavoro di tre anni fa registrato
 * oggi): nessuno di questi è «qualcuno ha programmato un intervento», e un
 * observer li scambierebbe tutti per tale. A chiamare è la scheda della
 * macchina, nel punto in cui sa quale dei due gesti sta compiendo.
 *
 * ## Tutto ciò che si legge, si legge QUI
 *
 * Destinatari, nomi e ubicazione si risolvono nella richiesta di chi compie il
 * gesto, e alla notifica arrivano **solo scalari**. Due ragioni:
 *
 * - sul worker i global scope si ritirano, e un model riletto lì non ha più
 *   confine (vedi il docblock di `InterventoProgrammato`);
 * - la scelta dei destinatari legge ruoli, e nel worker la cache dei permessi
 *   può essere quella di un altro processo — è la regola che
 *   `PermessiInCodaGuardrailTest` tiene ferma.
 *
 * ## Chi le riceve
 *
 * Programmato ed eseguito: gli stessi del riepilogo delle scadenze
 * (`DestinatariEnte`) — Admin e Tenant per tutto l'Ente, Responsabile Reparto
 * solo se la macchina sta nel suo sotto-albero. **Mai chi ha compiuto il
 * gesto**: lo sa già. Assegnato: la sola persona assegnata, e non a sé stessa.
 *
 * ⚠️ Ogni metodo esce subito se l'email è spenta in piattaforma: nasce spenta,
 * e finché lo resta non costa che una lettura.
 */
final class AvvisiIntervento
{
    public static function programmato(Intervento $intervento, Strumento $strumento): void
    {
        if (! InterruttoriEmail::attiva(CatalogoEmail::INTERVENTO_PROGRAMMATO)) {
            return;
        }

        $ente = MarchioEmail::perEnte($intervento->tenant_id)->nome;
        $ubicazione = $strumento->percorsoUbicazione();
        $assegnatario = $intervento->tecnico_id !== null ? User::find($intervento->tecnico_id)?->name : null;

        foreach (self::delCliente($intervento, $strumento) as $utente) {
            $utente->notify(new InterventoProgrammato(
                enteId: (int) $intervento->tenant_id,
                enteNome: $ente,
                strumentoId: (int) $strumento->id,
                strumentoNome: (string) $strumento->nome,
                ubicazione: $ubicazione,
                tipo: $intervento->tipo->label(),
                descrizione: (string) $intervento->descrizione,
                scadenza: $intervento->data_scadenza->format('d/m/Y'),
                assegnatario: $assegnatario,
                autore: auth()->user()?->name,
            ));
        }
    }

    /**
     * @param  ?Intervento  $successiva  la taratura pianificata nello stesso gesto,
     *                                   se c'è: si dice in questa email invece
     *                                   di mandarne una seconda.
     */
    public static function eseguito(Intervento $intervento, Strumento $strumento, ?Intervento $successiva = null): void
    {
        if (! InterruttoriEmail::attiva(CatalogoEmail::INTERVENTO_ESEGUITO)) {
            return;
        }

        $ente = MarchioEmail::perEnte($intervento->tenant_id)->nome;
        $ubicazione = $strumento->percorsoUbicazione();

        foreach (self::delCliente($intervento, $strumento) as $utente) {
            $utente->notify(new InterventoEseguito(
                enteId: (int) $intervento->tenant_id,
                enteNome: $ente,
                strumentoId: (int) $strumento->id,
                strumentoNome: (string) $strumento->nome,
                ubicazione: $ubicazione,
                tipo: $intervento->tipo->label(),
                descrizione: (string) $intervento->descrizione,
                eseguitoIl: ($intervento->data_esecuzione ?? now())->format('d/m/Y'),
                autore: auth()->user()?->name,
                conReport: filled($intervento->report_fine_lavoro),
                prossima: $successiva?->data_scadenza?->format('d/m/Y'),
            ));
        }
    }

    public static function assegnato(Intervento $intervento, Strumento $strumento): void
    {
        if ($intervento->tecnico_id === null
            // Chi se lo assegna da sé lo sa già.
            || (int) $intervento->tecnico_id === (int) auth()->id()
            || ! InterruttoriEmail::attiva(CatalogoEmail::INTERVENTO_ASSEGNATO)) {
            return;
        }

        // `find()` e non `withTrashed()`: a una persona cestinata non si scrive.
        User::find($intervento->tecnico_id)?->notify(new InterventoAssegnato(
            enteId: (int) $intervento->tenant_id,
            enteNome: MarchioEmail::perEnte($intervento->tenant_id)->nome,
            strumentoId: (int) $strumento->id,
            strumentoNome: (string) $strumento->nome,
            ubicazione: $strumento->percorsoUbicazione(),
            tipo: $intervento->tipo->label(),
            descrizione: (string) $intervento->descrizione,
            scadenza: $intervento->data_scadenza->format('d/m/Y'),
            assegnatoDa: auth()->user()?->name,
        ));
    }

    /**
     * Le persone del cliente che seguono questa macchina, meno chi sta agendo:
     * la regola sta in `DestinatariEnte::perMacchina()`, condivisa con
     * `AvvisiStrumento`.
     *
     * @return list<User>
     */
    private static function delCliente(Intervento $intervento, Strumento $strumento): array
    {
        return DestinatariEnte::perMacchina(
            (int) $intervento->tenant_id,
            (int) $strumento->unita_organizzativa_id,
            auth()->id(),
        );
    }
}
