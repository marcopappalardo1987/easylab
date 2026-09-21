<?php

use App\Livewire\Anagrafica\MarchioEnte;
use App\Livewire\Piattaforma\Cabina;
use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Account;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Mail\MarchioEmail;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * 🔴 Valori che SQLite salva e Postgres rifiuta (S7, T1c · caccia T1cB-5, T1cB-8).
 *
 * - `$` in una regex accetta un a-capo finale: «#06589c\n» sono 8 caratteri in
 *   un `varchar(7)`. Si ancora con `\z`.
 * - Una quantità senza tetto supera l'`integer` della colonna.
 * Entrambi verdi in locale e 500 in CI senza la regola: il test guarda la
 * VALIDAZIONE, non il DB.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('refuses a brand colour with a trailing newline, and keeps a clean one', function () {
    Storage::fake(MarchioEmail::DISCO);
    $ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Laboratorio Rossi']);
    $admin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');

    Livewire::actingAs($admin)->test(MarchioEnte::class)
        ->set('colore', "#06589c\n")
        ->call('salva')
        ->assertHasErrors('colore');

    Livewire::actingAs($admin)->test(MarchioEnte::class)
        ->set('colore', '#06589c')
        ->call('salva')
        ->assertHasNoErrors();

    expect($ente->fresh()->marchio_colore)->toBe('#06589c');
});

it('refuses an SDI recipient code with a trailing newline, and keeps six or seven characters', function (string $codice, bool $valido) {
    $superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');
    $this->actingAs($superadmin->fresh());

    $cliente = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    UnitaOrganizzativa::factory()->ente()->perAccount($cliente)->create(['nome' => 'Sede di Milano']);

    $t = Livewire::test(Cabina::class)
        ->call('apriFiscali', $cliente->id)
        ->set('fiscali.ragione_sociale', 'Gruppo Rossi')
        ->set('fiscali.codice_destinatario_sdi', $codice)
        ->call('salvaFiscali');

    $valido
        ? $t->assertHasNoErrors()
        : $t->assertHasErrors('fiscali.codice_destinatario_sdi');

    expect((string) $cliente->fresh()->codice_destinatario_sdi)->not->toContain("\n");
})->with([
    'sette con a-capo' => ["ABC1234\n", false],
    'sei con a-capo' => ["UFABCD\n", false],
    'otto' => ['ABCD1234', false],
    'sette' => ['ABC1234', true],
    'sei' => ['UFABCD', true],
]);

it('refuses a part quantity beyond the integer column, and keeps a plausible one', function (int $quantita, bool $valida) {
    $ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $strumento = Strumento::factory()->forNode($ente)->create(['nome' => 'Autoclave']);
    $ricambio = Ricambio::factory()->forTenant($ente)->create(['nome' => 'Guarnizione O-Ring']);
    $intervento = Intervento::factory()->forStrumento($strumento)->pianificato()->create();
    $utilizzo = RicambioUtilizzo::factory()->forStrumento($strumento)->forRicambio($ricambio)->forIntervento($intervento)->create();
    Garanzia::factory()->forRicambio($utilizzo)->scadenzaDichiarata(today()->addYear()->toDateString())->create();

    $admin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');

    $t = Livewire::actingAs($admin)
        ->test(SchedaStrumento::class, ['strumento' => $strumento])
        ->call('openCorreggiRicambio', $utilizzo->id)
        ->set('ricambioForm.quantita', $quantita)
        ->call('salvaRicambio');

    $valida
        ? $t->assertHasNoErrors('ricambioForm.quantita')
        : $t->assertHasErrors('ricambioForm.quantita');
})->with([
    'oltre int4' => [3_000_000_000, false],
    'appena oltre il tetto' => [100_001, false],
    'plausibile' => [12, true],
]);
