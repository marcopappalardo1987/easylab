<?php

use App\Support\AuditLog;
use App\Support\Rbac;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fa nascere il ruolo **Gestore** sui database che i ruoli li hanno già
 * (🔗 ADR-046, ADR-016 la matrice).
 *
 * ## 🔴 Perché una migration, e non il seeder
 *
 * `RolesAndPermissionsSeeder` fa `syncPermissions` su **ogni** ruolo: rilanciato
 * in produzione cancellerebbe le personalizzazioni fatte da `/piattaforma/ruoli`
 * (CLAUDE.md lo vieta per riflesso). Qui si tocca **una riga sola**, e solo se
 * non c'è: un Gestore già presente — magari già ritoccato dalla UI — non viene
 * riportato ai default.
 *
 * Su un database senza ruoli (test, installazione nuova) non fa nulla: lì il
 * ruolo nasce dal seeder, con tutti gli altri.
 *
 * Legge i default da `config/rbac.php` e non da una copia: una seconda lista
 * sarebbe libera di divergere il giorno dopo. Se il ruolo uscisse dalla config,
 * questa migration non avrebbe più nulla da creare, e lo dice da sé.
 *
 * `down()` è vuoto apposta: togliere un ruolo stacca le persone che lo hanno.
 */
return new class extends Migration
{
    private const RUOLO = 'Gestore';

    public function up(): void
    {
        if (! Schema::hasTable('roles') || DB::table('roles')->count() === 0) {
            return;
        }

        if (! in_array(self::RUOLO, Rbac::roleNames(), true)) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $ruolo = Role::firstOrCreate(['name' => self::RUOLO, 'guard_name' => 'web']);

        if (! $ruolo->wasRecentlyCreated) {
            return;
        }

        $permessi = Rbac::permissionsForRole(self::RUOLO);

        foreach ($permessi as $permesso) {
            Permission::firstOrCreate(['name' => $permesso, 'guard_name' => 'web']);
        }

        $ruolo->syncPermissions($permessi);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        activity(AuditLog::NAME)
            ->causedByAnonymous()
            ->withProperties(['ruolo' => self::RUOLO, 'permessi' => implode(', ', $permessi)])
            ->log('Ruolo Gestore creato');
    }

    public function down(): void
    {
        //
    }
};
