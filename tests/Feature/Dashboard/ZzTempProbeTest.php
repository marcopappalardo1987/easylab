<?php

use App\Livewire\Strumenti\ElencoStrumenti;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dip1 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip 1']);
    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

it('probe: ubicazioneId=0 filters but the message says the parco is empty', function () {
    Strumento::factory()->count(3)->forNode($this->dip1)
        ->create(['data_installazione' => today()->subYear()->toDateString()]);

    $c = Livewire::withQueryParams(['ubicazioneId' => 0])->actingAs($this->admin)->test(ElencoStrumenti::class);
    dump('ubicazioneId=0 -> total='.$c->viewData('strumenti')->total());
    $c->assertSee('Nessuno strumento.');   // <- il messaggio SBAGLIATO
    dump('MESSAGGIO: «Nessuno strumento.» su una lista FILTRATA e vuota (parco = 3)');
});

it('probe: enteId di un altro Ente filters but says the parco is empty', function () {
    Strumento::factory()->count(3)->forNode($this->dip1)
        ->create(['data_installazione' => today()->subYear()->toDateString()]);

    $c = Livewire::withQueryParams(['enteId' => 999999])->actingAs($this->admin)->test(ElencoStrumenti::class);
    dump('enteId=999999 -> total='.$c->viewData('strumenti')->total());
    $c->assertSee('Nessuno strumento.');
    dump('MESSAGGIO: «Nessuno strumento.» — enteId non e nemmeno nella condizione');
});

it('probe: stato=giallo does not filter but the message claims filters', function () {
    // parco davvero vuoto
    $c = Livewire::withQueryParams(['stato' => 'giallo'])->actingAs($this->admin)->test(ElencoStrumenti::class);
    dump('stato=giallo -> total='.$c->viewData('strumenti')->total());
    $c->assertSee('Nessun risultato per i filtri applicati.');
    dump('MESSAGGIO: «Nessun risultato per i filtri applicati.» su lista NON filtrata');
});
