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
 * S4 ha aggiunto `GaranziaRicambio` (ADR-020, blocco 4): una variante in più,
 * nessun cambio di forma altrove — è la ragione per cui il tipo è un enum e non
 * un booleano "è una garanzia".
 *
 * ⚠️ `GaranziaRicambio` è l'unico caso in cui **il tipo stesso è il dato
 * protetto**: dire "garanzia ricambio" a chi non ha `garanzie.ricambio.view`
 * rivela che sulla macchina c'è un pezzo sostituito (ADR-004). Il motivo si
 * costruisce comunque per tutti — il pallino è un aggregato dovuto a tutti
 * (ADR-020) — ed è il blade a renderlo in forma neutra. Qui non entra nessun
 * permesso: un enum che sapesse chi sta guardando sarebbe una regola di privacy
 * in più, nascosta nel posto in cui nessuno la cerca.
 */
enum TipoMotivoSemaforo: string
{
    case Intervento = 'intervento';
    case GaranziaMacchina = 'garanzia_macchina';
    case GaranziaRicambio = 'garanzia_ricambio';
}
