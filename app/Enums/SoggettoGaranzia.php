<?php

namespace App\Enums;

/**
 * Soggetto della garanzia (ERD §6.1 — ADR-004): il motore è "sdoppiato" perché
 * la stessa tabella copre la garanzia del macchinario e quella del singolo
 * pezzo montato (`ricambio_utilizzo`, ADR-008).
 *
 * Le righe `Ricambio` sono visibili solo a chi ha `garanzie.ricambio.view`
 * (mai Tenant né Tecnico): il filtro è GaranziaRicambioPrivacyScope.
 */
enum SoggettoGaranzia: string
{
    case Macchina = 'macchina';
    case Ricambio = 'ricambio';
}
