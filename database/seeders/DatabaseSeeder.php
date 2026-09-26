<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
        // Credenziali da `config/easylab.php` e NON da `env()` letto qui: con la
        // config cachata (`php artisan optimize` in build) il file .env non viene
        // più caricato e ogni `env()` fuori da config/ torna null — è così che su
        // staging questo account è nato con la password di default.
        $password = config('easylab.piattaforma.developer.password');

        if (blank($password)) {
            // Nessun ripiego su un valore noto: senza `DEVELOPER_PASSWORD` la
            // password è un tappo che nessuno conosce, e l'accesso si recupera
            // impostando la variabile e riseminando. Un default pubblicato nel
            // repo, su un ambiente raggiungibile da internet, è una porta aperta.
            $password = Str::password(64);

            $this->command?->warn(
                'DEVELOPER_PASSWORD assente: il Developer nasce con una password casuale. '.
                'Impostarla nell\'ambiente, ridistribuire e rilanciare il seeder.'
            );
        }

        $developer = User::updateOrCreate(
            ['email' => config('easylab.piattaforma.developer.email')],
            [
                'name' => 'AdVisionPlus',
                'password' => Hash::make($password),
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
