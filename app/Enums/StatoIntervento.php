<?php

namespace App\Enums;

/**
 * Stato dell'attività/intervento (ERD §5.2 — ADR-005): la spunta "Fatto".
 * `Fatto` implica `data_esecuzione` valorizzata — invariante imposta da
 * App\Models\Intervento (hook `saving`).
 */
enum StatoIntervento: string
{
    case NonFatto = 'non_fatto';
    case Fatto = 'fatto';
}
