<?php

use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Documento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * T1a (S7), T1aB-7 = R-T1c-2 — il tipo di documento arriva dal browser.
 *
 * Era validato come stringa qualunque e poi passato a `TipoDocumento::from()`:
 * un valore forgiato dava un 500 invece di un errore di validazione.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake(Documento::DISCO);

    $ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Laboratorio Rossi']);
    $dep = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create();
    $this->strumento = Strumento::factory()->forNode($dep)->create(['nome' => 'Autoclave']);

    $this->admin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

it('rejects a forged document type as a validation error, not a 500', function () {
    Livewire::actingAs($this->admin)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openCaricaDocumento')
        ->set('fileDocumento', UploadedFile::fake()->create('manuale.pdf', 12, 'application/pdf'))
        ->set('tipoDocumento', 'tipo_inventato')
        ->call('salvaDocumento')
        ->assertHasErrors(['tipoDocumento']);

    expect(Documento::withoutGlobalScopes()->count())->toBe(0);
});

it('still uploads with a genuine document type', function () {
    Livewire::actingAs($this->admin)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openCaricaDocumento')
        ->set('fileDocumento', UploadedFile::fake()->create('manuale.pdf', 12, 'application/pdf'))
        ->call('salvaDocumento')
        ->assertHasNoErrors();

    expect(Documento::withoutGlobalScopes()->count())->toBe(1);
});
