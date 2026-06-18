<?php

namespace App\Support;

/**
 * Convenzione per l'audit di sicurezza (spatie/laravel-activitylog).
 * Tutte le azioni sensibili (impersonation, login/logout, 2FA e — dagli sprint
 * successivi — semaforo.force, lockout, accessi tecnici) vanno nel log dedicato
 * `audit`, così la vista Audit (S6) può filtrarle in modo netto dal log default.
 */
class AuditLog
{
    /** Nome del canale di log dedicato alle azioni di sicurezza. */
    public const NAME = 'audit';
}
