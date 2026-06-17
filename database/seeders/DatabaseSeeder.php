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
        // Account Developer (AdVision Plus) — accesso tecnico totale alla piattaforma.
        // Credenziali da .env (DEVELOPER_EMAIL/DEVELOPER_PASSWORD): la password reale
        // non vive nel repo. Il ruolo "Developer" verrà assegnato qui non appena
        // spatie/laravel-permission sarà installato (Sprint 1 · punto 5).
        User::updateOrCreate(
            ['email' => env('DEVELOPER_EMAIL', 'info@advisionplus.com')],
            [
                'name' => 'AdVision Plus',
                'password' => Hash::make(env('DEVELOPER_PASSWORD', 'password')),
                'email_verified_at' => now(),
            ],
        );
    }
}
