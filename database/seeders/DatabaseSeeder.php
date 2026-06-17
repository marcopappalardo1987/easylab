<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Account Developer (AdVision Plus) — accesso tecnico totale alla piattaforma.
        // Il ruolo "Developer" verrà assegnato qui non appena spatie/laravel-permission
        // sarà installato (Sprint 1 · punto 5). Password di default: "password" (da cambiare).
        User::factory()->create([
            'name' => 'AdVision Plus',
            'email' => 'info@advisionplus.com',
        ]);
    }
}
