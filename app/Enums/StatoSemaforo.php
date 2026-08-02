<?php

namespace App\Enums;

/**
 * Stato semaforo (ADR-005). `Rosso` esiste già nell'enum ma il motore
 * calcolato NON lo produce mai: arriva solo dalla forzatura manuale (punto 5,
 * colonne forced_*). La presentazione (colore + forma + etichetta) vive in
 * x-ui.semaforo, non qui: gli enum del progetto restano nudi.
 */
enum StatoSemaforo: string
{
    case Verde = 'verde';
    case Arancione = 'arancione';
    case Rosso = 'rosso';
}
