<?php

namespace App\Support\Garanzie;

use DomainException;

/**
 * La macchina non ha la data di collaudo, quindi la sua garanzia non ha da
 * dove contare (🔗 ADR-065).
 *
 * Il messaggio è già in italiano e già destinato a chi sta lavorando, come
 * quello di `TettoStrumentiRaggiunto`: chi la intercetta lo mostra accanto al
 * gesto rifiutato, non lo reinterpreta.
 */
final class CollaudoMancante extends DomainException {}
