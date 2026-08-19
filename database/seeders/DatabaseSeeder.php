<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ruoli e permessi (default da S0) prima di assegnarli agli utenti.
        $this->call(RolesAndPermissionsSeeder::class);

        // Account Developer (AdVision Plus) — accesso tecnico totale alla piattaforma.
        // Credenziali da .env (DEVELOPER_EMAIL/DEVELOPER_PASSWORD): la password reale
        // non vive nel repo.
        $developer = User::updateOrCreate(
            ['email' => env('DEVELOPER_EMAIL', 'info@advisionplus.com')],
            [
                'name' => 'AdVision Plus',
                'password' => Hash::make(env('DEVELOPER_PASSWORD', 'password')),
                'email_verified_at' => now(),
            ],
        );

        $developer->syncRoles(['Developer']);

        // Superadmin di piattaforma col proprio Account/Ente: sta in un seeder
        // a parte perché ha bisogno dei ruoli già seminati e perché salta da
        // solo quando le sue variabili d'ambiente non ci sono.
        $this->call(SuperadminSeeder::class);
    }
}
