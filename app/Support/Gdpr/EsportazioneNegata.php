<?php

namespace App\Support\Gdpr;

use RuntimeException;

/**
 * L'export GDPR rifiutato prima di leggere una sola riga: operatore senza il
 * permesso di piattaforma, oppure chiamata da un contesto con un utente in
 * sessione (ADR-018). 🔗 `EsportazioneTenant::autorizza()`.
 */
final class EsportazioneNegata extends RuntimeException {}
