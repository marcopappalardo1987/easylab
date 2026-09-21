<?php

use App\Livewire\Piattaforma\ParcoGlobale;
use App\Livewire\Strumenti\ElencoStrumenti;
use App\Livewire\Strumenti\ModelliStrumenti;
use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * 🔴 `%` e `_` scritti in una ricerca sono caratteri, non jolly (S7, T1c).
 *
 * Senza `ESCAPE`, cercare «%» restituiva tutto il parco e «_» qualunque nome:
 * non è una fuga di dati (lo scope resta), ma una ricerca che mente. Stesso
 * idioma delle altre ricerche del progetto (`addcslashes(…, '%_\\')` +
 * `ESCAPE '\'`), non una variante nuova.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('treats % and _ literally in the instruments search', function (string $cerca, array $attesi, array $esclusi) {
    $ente = UnitaOrganizzativa::factory()->ente()->create();
    Strumento::factory()->forNode($ente)->create(['nome' => 'Centrifuga', 'modello' => 'CF-1']);
    Strumento::factory()->forNode($ente)->create(['nome' => 'Diluitore 50%', 'modello' => 'DL_2']);
    $admin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');

    $t = Livewire::actingAs($admin)->test(ElencoStrumenti::class)->set('search', $cerca);

    foreach ($attesi as $nome) {
        $t->assertSee($nome);
    }
    foreach ($esclusi as $nome) {
        $t->assertDontSee($nome);
    }
})->with([
    'percento' => ['%', ['Diluitore 50%'], ['Centrifuga']],
    'trattino basso' => ['_', ['Diluitore 50%'], ['Centrifuga']],
    'testo normale' => ['centri', ['Centrifuga'], ['Diluitore 50%']],
]);

it('treats % literally in the models search', function () {
    $ente = UnitaOrganizzativa::factory()->ente()->create();
    Strumento::factory()->forNode($ente)->create(['nome' => 'A', 'modello' => 'Modello Alfa']);
    Strumento::factory()->forNode($ente)->create(['nome' => 'B', 'modello' => 'Beta 10%']);
    $admin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');

    Livewire::actingAs($admin)->test(ModelliStrumenti::class)
        ->set('search', '%')
        ->assertSee('Beta 10%')
        ->assertDontSee('Modello Alfa');
});

it('treats % literally in the platform-wide fleet search', function () {
    $cliente = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi', 'piano' => 'free']);
    $sede = UnitaOrganizzativa::factory()->ente()->perAccount($cliente)->create(['nome' => 'Sede Rossi']);
    Strumento::factory()->forNode($sede)->create(['nome' => 'Autoclave Rossi']);
    Strumento::factory()->forNode($sede)->create(['nome' => 'Diluitore 50%']);

    $easylab = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);
    $sedeEasylab = UnitaOrganizzativa::factory()->ente()->perAccount($easylab)->create(['nome' => 'Sede EasyLab']);
    $superadmin = User::factory()->create(['tenant_id' => $sedeEasylab->id, 'two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');
    $easylab->aggiungiMembro($superadmin);
    $this->actingAs($superadmin->fresh());

    Livewire::test(ParcoGlobale::class)
        ->assertSee('Autoclave Rossi')
        ->set('search', '%')
        ->assertSee('Diluitore 50%')
        ->assertDontSee('Autoclave Rossi');
});
