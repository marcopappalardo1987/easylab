<?php

namespace App\Enums;

/**
 * Natura di un documento allegato (ERD §8.1 — ADR-009).
 *
 * ⚠️ **È un'etichetta, non un permesso** (deciso il 15 Ago 2026). Sullo stesso
 * intervento possono convivere il certificato — che è del cliente — e il report
 * di fine lavoro, che contiene nomi di tecnici e osservazioni; si è scelto che
 * chi vede la macchina veda i suoi documenti, e che il tipo serva a
 * classificarli e non a filtrarli. Farne una regola d'accesso significherebbe
 * una privacy per RIGA, cioè un terzo global scope basato su permessi dopo
 * quello delle garanzie ricambio: va progettato, non aggiunto.
 */
enum TipoDocumento: string
{
    case Manuale = 'manuale';
    case Conformita = 'conformita';
    case CertificatoTaratura = 'certificato_taratura';
    case ReportFineLavoro = 'report_fine_lavoro';
    case Altro = 'altro';

    /** Unica fonte delle etichette, come TipoIntervento (ADR-021). */
    public function label(): string
    {
        return match ($this) {
            self::Manuale => 'Manuale d\'uso',
            self::Conformita => 'Dichiarazione di conformità',
            self::CertificatoTaratura => 'Certificato di taratura',
            self::ReportFineLavoro => 'Report di fine lavoro',
            self::Altro => 'Altro',
        };
    }
}
