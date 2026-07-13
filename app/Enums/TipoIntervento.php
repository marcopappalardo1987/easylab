<?php

namespace App\Enums;

/**
 * Tipo di attività/intervento (ERD §5.2 — ADR-009).
 * Le tarature sono interventi `Taratura` con certificato allegato: nessun
 * motore di scadenze separato. Elenco estendibile.
 */
enum TipoIntervento: string
{
    case Manutenzione = 'manutenzione';
    case Taratura = 'taratura';
    case Ispezione = 'ispezione';
    case Riparazione = 'riparazione';
    case Altro = 'altro';
}
