<?php

namespace App\Support\Billing;

use RuntimeException;

/**
 * Il piano non consente altri strumenti (🔗 ADR-049).
 *
 * Il messaggio è già in italiano e già destinato a chi sta lavorando: chi la
 * intercetta lo mostra accanto al gesto rifiutato, non lo reinterpreta. Lo
 * scrive `TettoStrumenti::spiegazione()`.
 */
final class TettoStrumentiRaggiunto extends RuntimeException {}
