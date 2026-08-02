<?php

namespace App\Enums;

/**
 * Come si determina la fine garanzia (ERD §6.1 — ADR-004).
 *
 * `Data`: dalla durata in mesi a partire da `data_inizio`.
 * `Ore`: da una soglia di ore di utilizzo — ma il motore non ragiona MAI su
 * ore: in V1 l'utente inserisce a mano la `data_scadenza_prevista` in cui
 * stima di raggiungerle. L'estrapolazione dalle letture contaore è V1.1.
 *
 * In entrambi i casi tutto converge in `data_scadenza_effettiva`.
 */
enum TipoScadenzaGaranzia: string
{
    case Data = 'data';
    case Ore = 'ore';
}
