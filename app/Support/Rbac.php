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

    /** Nomi dei ruoli, nell'ordine di `config/rbac.php`. */
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

    /**
     * Ruoli la cui **riga** l'editor della matrice non tocca (S6, ADR-016).
     *
     * Guardia diversa e ortogonale a `locked()`: quella è una **colonna** (un
     * permesso che nessun ruolo può guadagnare né perdere dalla UI), questa è
     * una **riga** (un ruolo di cui non si tocca nessuna cella, in nessuna
     * direzione).
     *
     * Vive in config e non in codice perché è la stessa forma di
     * `two_factor_required_roles`: un elenco di nomi di ruolo che governa il
     * comportamento della piattaforma. ⚠️ Non è nella matrice, quindi
     * cambiarla **non richiede** un riseeding.
     *
     * @return list<string>
     */
    public static function ruoliProtetti(): array
    {
        return config('rbac.protected_roles', []);
    }

    /**
     * **La** definizione di quale riga è inerte — una sola, mai una seconda copia.
     *
     * La leggono sia il metodo di dominio (`MatriceRuoli`) sia la vista, per la
     * disciplina che `OffreImpersonazione::candidatiDi()` documenta a proposito
     * di `canBeImpersonated()`: riscrivere la stessa regola una seconda volta
     * altrove crea una copia che, il giorno in cui la prima cambia, resta
     * indietro in silenzio.
     */
    public static function isRuoloProtetto(string $role): bool
    {
        return in_array($role, self::ruoliProtetti(), true);
    }

    /** Ruoli per cui il 2FA è obbligatorio. */
    public static function twoFactorRequiredRoles(): array
    {
        return config('rbac.two_factor_required_roles', []);
    }
}
