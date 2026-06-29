<?php

namespace App\Enums;

/**
 * Tipo di nodo nell'albero organizzativo (ERD §4.1). Profondità libera:
 * il valore è indicativo del livello, non vincola la struttura dell'albero.
 */
enum TipoUnitaOrganizzativa: string
{
    case Ente = 'ente';
    case Dipartimento = 'dipartimento';
    case Sottolaboratorio = 'sottolaboratorio';
}
