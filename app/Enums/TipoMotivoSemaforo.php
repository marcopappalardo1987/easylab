<?php

namespace App\Enums;

/**
 * Fonte che accende il semaforo (ADR-024): dice *da dove* nasce un motivo
 * della diagnosi, non come si scrive.
 *
 * Enum nudo come StatoSemaforo: la presentazione (testo, glifo, tab di
 * destinazione del link) vive nel blade, non qui.
 *
 * String-backed di proposito: in S5 i motivi finiscono nel corpo dell'"email
 * del futuro", che deve poterli serializzare.
 *
 * S4 aggiunge `GaranziaRicambio` (ADR-020): una variante in più, nessun
 * cambio di forma altrove — è la ragione per cui il tipo è un enum e non un
 * booleano "è una garanzia".
 */
enum TipoMotivoSemaforo: string
{
    case Intervento = 'intervento';
    case GaranziaMacchina = 'garanzia_macchina';
}
