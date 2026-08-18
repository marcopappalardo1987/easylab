<?php

use App\Enums\StatoIntervento;
use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * Report di fine lavoro (S4 blocco 10 — Wireframe §3, Elenco Funzionalità
 * «foglio di intervento»).
 *
 * È il testo che il tecnico scrive chiudendo il lavoro, col telefono in mano e
 * davanti alla macchina: è quello il momento in cui si ricorda cosa ha trovato,
 * e chiederlo dopo significa non averlo. L'export PDF (STRETCH) ne produrrà il
 * foglio — il testo è il dato, il foglio una sua resa.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create();
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($this->dept)->create();

    $this->tecnico = User::factory()->create([
        'tenant_id' => $this->ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->tecnico->assignRole('Tecnico');

    // Assegnato, o da ADR-030 il tecnico non vedrebbe la macchina.
    $this->intervento = Intervento::factory()->forStrumento($this->strumento)->scaduto()
        ->create(['tecnico_id' => $this->tecnico->id]);
});

it('records what the tecnico writes when closing the work', function () {
    Livewire::actingAs($this->tecnico)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openCompleta', $this->intervento->id)
        ->set('reportFineLavoro', "Guarnizione del portello usurata.\nSostituita, tenuta verificata.")
        ->call('completa')
        ->assertHasNoErrors();

    $fresco = $this->intervento->fresh();

    expect($fresco->stato)->toBe(StatoIntervento::Fatto)
        ->and($fresco->report_fine_lavoro)->toBe("Guarnizione del portello usurata.\nSostituita, tenuta verificata.");
});

it('closes the work just fine without a report', function () {
    // Facoltativo davvero: pretenderlo bloccherebbe la chiusura dei migliaia di
    // interventi storici che non ne hanno uno.
    Livewire::actingAs($this->tecnico)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openCompleta', $this->intervento->id)
        ->call('completa')
        ->assertHasNoErrors();

    expect($this->intervento->fresh())
        ->stato->toBe(StatoIntervento::Fatto)
        ->report_fine_lavoro->toBeNull();
});

it('stores nothing instead of blanks when the box is left with only spaces', function () {
    // «   » non è un report: salvarlo come testo farebbe comparire una riga
    // vuota nella scheda e, un domani, un foglio PDF con un paragrafo bianco.
    Livewire::actingAs($this->tecnico)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openCompleta', $this->intervento->id)
        ->set('reportFineLavoro', "   \n  ")
        ->call('completa');

    expect($this->intervento->fresh()->report_fine_lavoro)->toBeNull();
});

/**
 * Riaprire un intervento significa «c'è ancora da fare», non «non è mai
 * successo»: la stessa ragione per cui `riapri()` non riporta indietro le date
 * di montaggio dei ricambi. Ciò che una persona ha scritto non si perde per
 * effetto collaterale di un altro gesto.
 */
it('keeps the report when the intervento is reopened, and offers it back on the next close', function () {
    $scheda = Livewire::actingAs($this->tecnico)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openCompleta', $this->intervento->id)
        ->set('reportFineLavoro', 'Sostituita la sonda di temperatura.')
        ->call('completa');

    $scheda->call('riapri', $this->intervento->id);
    expect($this->intervento->fresh()->report_fine_lavoro)->toBe('Sostituita la sonda di temperatura.');

    // Ri-aprendo la modale il testo si ritrova: ripartire da vuoto farebbe
    // credere che la nota sia andata persa.
    $scheda->call('openCompleta', $this->intervento->id)
        ->assertSet('reportFineLavoro', 'Sostituita la sonda di temperatura.');
});

it('shows the report on the intervento row, so that it can be read and not only written', function () {
    $this->intervento->segnaFatto(today(), 'Cinghia sostituita, allineamento verificato.');

    Livewire::actingAs($this->tecnico)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->assertSee('Cinghia sostituita, allineamento verificato.');
});
