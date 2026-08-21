<?php

namespace App\Support\Provisioning;

use RuntimeException;

/**
 * Il provisioning è stato rifiutato **prima di scrivere qualunque cosa**.
 *
 * Esiste come eccezione e non come valore di ritorno perché i due chiamanti —
 * il comando di console e la UI di S6 — hanno due modi diversi di dirlo (stderr
 * e un errore di form) ma la stessa identica regola. Il messaggio è già in
 * italiano e già destinato a un umano: chi la cattura lo mostra, non lo
 * reinterpreta.
 */
class ProvisioningRifiutato extends RuntimeException {}
