<?php

namespace App\Support;

/**
 * Accesso tipizzato alla configurazione RBAC (config/rbac.php).
 * Risolve la matrice ruolo→permesso nelle sue forme (all / all+except / only).
 * Usato da RolesAndPermissionsSeeder, dal middleware 2FA e dalla futura UI (S6).
 */
class Rbac
{
    /** Elenco piatto di tutti i permessi del catalogo (§4). */
    public static function permissions(): array
    {
        return config('rbac.permissions', []);
    }

    /** Nomi dei 6 ruoli. */
    public static function roleNames(): array
    {
        return array_keys(config('rbac.roles', []));
    }

    /** Permessi effettivi di un ruolo, risolvendo all/except/only. */
    public static function permissionsForRole(string $role): array
    {
        $def = config("rbac.roles.{$role}");

        if ($def === null) {
            return [];
        }

        if (! empty($def['all'])) {
            return array_values(array_diff(self::permissions(), $def['except'] ?? []));
        }

        return array_values($def['only'] ?? []);
    }

    /** Permessi 🔒 non modificabili dalla UI Superadmin (§7, ADR-016). */
    public static function locked(): array
    {
        return config('rbac.locked', []);
    }

    public static function isLocked(string $permission): bool
    {
        return in_array($permission, self::locked(), true);
    }

    /** Ruoli per cui il 2FA è obbligatorio. */
    public static function twoFactorRequiredRoles(): array
    {
        return config('rbac.two_factor_required_roles', []);
    }
}
