<?php

namespace App\Enums;

/**
 * Tipo di spostamento strumento (ERD §5.4 — ADR-015).
 * V1 usa `Interno` (nodo→nodo) e `Ingresso` (esterno→nodo);
 * `Uscita` (nodo→esterno) e `CrossTenant` (tra Enti) sono predisposti.
 */
enum TipoSpostamento: string
{
    case Interno = 'interno';
    case Ingresso = 'ingresso';
    case Uscita = 'uscita';
    case CrossTenant = 'cross_tenant';
}
