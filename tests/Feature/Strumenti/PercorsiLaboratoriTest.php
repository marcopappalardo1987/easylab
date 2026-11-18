<?php

use App\Livewire\Strumenti\ModelliStrumenti;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * Due laboratori con lo stesso nome si distinguono dal percorso dentro la sede
 * (7 Nov 2026): nella tendina di «Sposta» e nelle etichette di «Per modello»
 * prima c'erano due «Camera Bianca» uguali.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Sede di Milano']);
    $this->analisi = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Analisi Cliniche']);
    $this->micro = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Microbiologia']);
    $this->biancaAnalisi = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->analisi)->create(['nome' => 'Camera Bianca']);
    $this->biancaMicro = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->micro)->create(['nome' => 'Camera Bianca']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

it('writes the path inside the sede, never the sede itself', function () {
    expect(UnitaOrganizzativa::percorsiNellaSede([$this->biancaAnalisi->id, $this->biancaMicro->id, $this->analisi->id]))->toBe([
        $this->biancaAnalisi->id => 'Analisi Cliniche › Camera Bianca',
        $this->biancaMicro->id => 'Microbiologia › Camera Bianca',
        $this->analisi->id => 'Analisi Cliniche',
    ])->and(UnitaOrganizzativa::percorsiNellaSede([]))->toBe([]);
});

it('tells two homonymous laboratori apart in the move window', function () {
    $strumento = Strumento::factory()->forNode($this->analisi)->create();

    scheda($this->admin, $strumento)
        ->call('openMove')
        ->assertSeeHtml('>Analisi Cliniche › Camera Bianca</option>')
        ->assertSeeHtml('>Microbiologia › Camera Bianca</option>')
        ->assertDontSeeHtml('>Camera Bianca</option>');
});

it('tells two homonymous laboratori apart on the by-model page', function () {
    Strumento::factory()->forNode($this->biancaAnalisi)->create(['modello' => 'Cappa X1']);
    Strumento::factory()->forNode($this->biancaMicro)->create(['modello' => 'Cappa X1']);

    Livewire::actingAs($this->admin)->test(ModelliStrumenti::class)
        ->assertSee('Analisi Cliniche › Camera Bianca')
        ->assertSee('Microbiologia › Camera Bianca');
});
