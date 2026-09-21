<?php

use App\Http\Middleware\RimuoviByteNul;
use App\Livewire\Fornitori\ElencoFornitori;
use App\Models\Fornitore;
use App\Models\Registrazione;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\BancoRegistrazione;

/**
 * 🔴 Il byte NUL non arriva a una riga (S7, T1c · caccia T1cB-4).
 *
 * Postgres rifiuta `\0` in una colonna di testo: su SQLite passa e si salva, su
 * Postgres è un 500 all'INSERT — anche dal modulo di registrazione pubblica.
 * ⚠️ I due test d'integrazione richiedono il cablaggio chiesto in board
 * (R-T1c-7: `bootstrap/app.php` + `AppServiceProvider`).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('removes NUL from strings at any depth and leaves the rest alone', function () {
    expect(RimuoviByteNul::ripulisci("Rossi\0Forniture"))->toBe('RossiForniture')
        ->and(RimuoviByteNul::ripulisci(['a' => ["x\0y", 3, null, true]]))->toBe(['a' => ['xy', 3, null, true]])
        ->and(RimuoviByteNul::ripulisci("Unità d'Igiene"))->toBe("Unità d'Igiene");
});

it('never lets a NUL byte from the public sign-up form reach the pending row', function () {
    Notification::fake();
    BancoRegistrazione::apri();

    $this->post(route('registrazione.avvia'), [
        'nome_ente' => "Laboratorio\0Aurora",
        'nome_referente' => 'Marta Bianchi',
        'email' => 'marta@laboratorio-aurora.it',
        'piano' => 'saas',
        'password' => 'ParolaSegreta!2026',
        'password_confirmation' => 'ParolaSegreta!2026',
    ]);

    $nomi = Registrazione::query()->pluck('nome_ente');

    // Positivo: la riga esiste, col NUL tolto — non è stata semplicemente scartata.
    expect($nomi->all())->toBe(['LaboratorioAurora']);
});

it('never lets a NUL byte from a Livewire form reach the row', function () {
    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $admin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');

    Livewire::actingAs($admin)->test(ElencoFornitori::class)
        ->call('nuovo')
        ->set('form.ragione_sociale', "Rossi\0Forniture")
        ->call('save');

    expect(Fornitore::withoutGlobalScopes()->pluck('ragione_sociale')->all())->toBe(['RossiForniture']);
});
