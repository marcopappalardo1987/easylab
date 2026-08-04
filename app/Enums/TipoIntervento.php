<?php

namespace App\Enums;

/**
 * Tipo di attività/intervento (ERD §5.2 — ADR-009/021).
 *
 * L'elenco è quello fissato dal cliente nel briefing del 3 Agosto 2026 ed è
 * organizzato per **regime contrattuale di manutenzione**, non per natura
 * tecnica del lavoro: da qui i tre livelli di manutenzione. Le voci
 * `ispezione` e `riparazione` della prima bozza non esistono nel linguaggio
 * del cliente e sono state rimappate sui due regimi (vedi la migration
 * `remap_tipo_su_interventi_table`).
 *
 * `TaraturaECertificazione` è **una voce sola**, non due: nel linguaggio del
 * cliente le due cose viaggiano insieme (la taratura si chiude con il
 * certificato). È la voce con valenza documentale di ADR-009 — l'attività
 * porta il certificato allegato e la sua scadenza alimenta il semaforo, senza
 * un secondo motore di scadenze.
 *
 * `Altro` resta come fallback dichiarato (ADR-021): sul campo l'intervento
 * non classificabile deve comunque essere registrabile, e la `descrizione`
 * porta il dettaglio. Se cresce troppo, vuol dire che manca una voce.
 */
enum TipoIntervento: string
{
    case ManutenzioneOrdinaria = 'manutenzione_ordinaria';
    case ManutenzioneStraordinaria = 'manutenzione_straordinaria';
    case ManutenzioneFullRisk = 'manutenzione_full_risk';
    case TaraturaECertificazione = 'taratura_e_certificazione';
    case Altro = 'altro';

    /**
     * Etichetta mostrata all'utente — UNICA fonte (ADR-021).
     *
     * Serve un metodo, e non l'`ucfirst($value)` usato finora: da quando i
     * valori sono composti, `manutenzione_full_risk` diventerebbe
     * "Manutenzione_full_risk". Senza un punto unico la stessa stringa
     * finirebbe riscritta a mano in ogni vista che mostra il tipo (tabella
     * interventi, select del form, colonna "Prossima scadenza" dell'elenco),
     * libere di divergere.
     */
    public function label(): string
    {
        return match ($this) {
            self::ManutenzioneOrdinaria => 'Manutenzione ordinaria',
            self::ManutenzioneStraordinaria => 'Manutenzione straordinaria',
            self::ManutenzioneFullRisk => 'Manutenzione full risk',
            self::TaraturaECertificazione => 'Taratura e certificazione',
            self::Altro => 'Altro',
        };
    }
}
