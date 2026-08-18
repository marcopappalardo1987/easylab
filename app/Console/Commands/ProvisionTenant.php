<?php

namespace App\Console\Commands;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Bootstrap di un tenant: crea il nodo Ente radice + un utente Admin associato.
 * Gira in console (CurrentTenant::shouldScope() = false), quindi può creare il
 * nodo ente con tenant_id NULL e poi valorizzarlo = id (FK self-reference).
 * È il seme del provisioning Superadmin di S5.
 */
class ProvisionTenant extends Command
{
    protected $signature = 'easylab:provision-tenant
        {nome : Ragione sociale dell\'Ente}
        {--admin-email= : Email dell\'utente Admin}
        {--admin-name= : Nome dell\'utente Admin}
        {--admin-password= : Password (se omessa viene generata)}';

    protected $description = 'Crea un Ente (tenant) e il suo utente Admin';

    public function handle(): int
    {
        $nome = $this->argument('nome');
        $adminEmail = $this->option('admin-email') ?: Str::slug($nome).'-admin@example.test';
        $adminName = $this->option('admin-name') ?: "Admin {$nome}";
        $generatedPassword = $this->option('admin-password') ?: Str::password(16);

        $ente = UnitaOrganizzativa::create([
            'tipo' => TipoUnitaOrganizzativa::Ente,
            'nome' => $nome,
            'parent_id' => null,
        ]);
        $ente->forceFill(['tenant_id' => $ente->id])->saveQuietly();

        $admin = User::firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => $adminName,
                'password' => Hash::make($generatedPassword),
                'email_verified_at' => now(),
            ],
        );

        // `tenant_id` è fuori dal Fillable (ADR-032): si scrive col forceFill,
        // e SOLO sull'utente appena nato — riscriverlo a uno esistente
        // significherebbe strapparlo al suo Ente per effetto collaterale.
        if ($admin->wasRecentlyCreated) {
            $admin->forceFill(['tenant_id' => $ente->id])->save();
        }

        if (! $admin->hasRole('Admin')) {
            $admin->assignRole('Admin');
        }

        $this->info("Ente «{$nome}» creato (id {$ente->id}).");
        $this->info("Admin: {$adminEmail}");
        if (! $this->option('admin-password')) {
            $this->warn("Password generata: {$generatedPassword}");
        }

        return self::SUCCESS;
    }
}
