<?php

use App\Livewire\Documenti\ElencoDocumenti;
use App\Models\Documento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// T1a (S7), B5 da T3: `$this->cerca ?: null` tratta la stringa «0» come vuota: cercare «0»
// mostra tutto l'archivio, e l'export (che eredita i parametri) idem.
it('filters the archive when the search is the single character 0', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake(Documento::DISCO);

    $ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $strumento = Strumento::factory()->forNode($ente)->create(['nome' => 'Autoclave']);
    Documento::factory()->perStrumento($strumento)->create(['nome' => 'certificato-2020.pdf']);
    Documento::factory()->perStrumento($strumento)->create(['nome' => 'manuale-uso.pdf']);

    $admin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');
    $this->actingAs($admin);

    Livewire::test(ElencoDocumenti::class)
        ->set('cerca', '0')
        ->assertSee('certificato-2020.pdf')
        ->assertDontSee('manuale-uso.pdf');
});
