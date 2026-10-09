<?php

use App\Livewire\Settings\PreferenzeNotifiche;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * Le preferenze di notifica (🔗 ADR-011): il diritto di opposizione del registro
 * dei trattamenti (T4) con un posto dove esercitarlo.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $this->utente = User::factory()->create(['tenant_id' => $ente->id]);
    $this->utente->assignRole('Tenant');
});

it('starts with the digest enabled, because the reminder is the service', function () {
    $this->actingAs($this->utente);

    Livewire::test(PreferenzeNotifiche::class)
        ->assertSet('riceveEmailScadenze', true);
});

it('persists the opt-out', function () {
    $this->actingAs($this->utente);

    Livewire::test(PreferenzeNotifiche::class)
        ->set('riceveEmailScadenze', false)
        ->call('salva');

    expect($this->utente->fresh()->riceve_email_scadenze)->toBeFalse();
});

it('lets the user opt back in', function () {
    $this->utente->forceFill(['riceve_email_scadenze' => false])->save();
    $this->actingAs($this->utente);

    Livewire::test(PreferenzeNotifiche::class)
        ->assertSet('riceveEmailScadenze', false)
        ->set('riceveEmailScadenze', true)
        ->call('salva');

    expect($this->utente->fresh()->riceve_email_scadenze)->toBeTrue();
});

it('is reachable by any authenticated user, without a permission', function () {
    // Nessun `can:` sulla rotta: è la propria casella di posta, non un dato
    // dell'Ente. Il Tenant è il ruolo con meno permessi di tutti ed è
    // esattamente per questo che è lui a provarci.
    $this->actingAs($this->utente)
        ->get(route('settings.notifiche'))
        ->assertSuccessful();
});

it('never lets the preference be forged by mass assignment', function () {
    // La colonna sta fuori dall'attributo Fillable: senza quell'esclusione un
    // form dell'anagrafica potrebbe spegnere le email di un altro utente.
    $this->utente->fill(['riceve_email_scadenze' => false]);

    expect($this->utente->riceve_email_scadenze)->toBeTrue();
});

it('requires authentication', function () {
    $this->get(route('settings.notifiche'))->assertRedirect(route('login'));
});

// ─── Le email sugli interventi (🔗 ADR-047) ──────────────────────────────────

/** Una persona con quel ruolo, dentro un Ente o di piattaforma. */
function personaConRuolo(string $ruolo, bool $conEnte = true): User
{
    $u = User::factory()->create([
        'tenant_id' => $conEnte ? UnitaOrganizzativa::factory()->ente()->create()->id : null,
    ]);
    $u->assignRole($ruolo);

    return $u->fresh();
}

it('offers no switch for an email the platform has not turned on', function () {
    // Le tre email nascono spente: un interruttore per un'email che non
    // arriverebbe comunque sarebbe una promessa falsa.
    $this->actingAs($this->utente);

    Livewire::test(PreferenzeNotifiche::class)
        ->assertSee('Riepilogo email delle scadenze')
        ->assertDontSee('Macchina segnalata')
        ->assertDontSee('Intervento programmato')
        ->assertDontSee('Intervento eseguito')
        ->assertDontSee('Intervento assegnato a te');
});

it('offers each person only the emails their role can receive', function (string $ruolo, bool $conEnte, array $vede, array $nonVede) {
    foreach ([
        CatalogoEmail::MACCHINA_SEGNALATA,
        CatalogoEmail::INTERVENTO_PROGRAMMATO,
        CatalogoEmail::INTERVENTO_ESEGUITO,
        CatalogoEmail::INTERVENTO_ASSEGNATO,
    ] as $chiave) {
        InterruttoriEmail::imposta($chiave, true);
    }

    $this->actingAs(personaConRuolo($ruolo, $conEnte));

    $pagina = Livewire::test(PreferenzeNotifiche::class);

    foreach ($vede as $titolo) {
        $pagina->assertSee($titolo);
    }
    foreach ($nonVede as $titolo) {
        $pagina->assertDontSee($titolo);
    }
})->with([
    'Admin' => ['Admin', true, ['Macchina segnalata', 'Intervento programmato', 'Intervento eseguito'], ['Intervento assegnato a te']],
    'Tenant' => ['Tenant', true, ['Macchina segnalata', 'Intervento programmato', 'Intervento eseguito'], ['Intervento assegnato a te']],
    'Responsabile Reparto' => ['Responsabile Reparto', true, ['Macchina segnalata', 'Intervento programmato', 'Intervento eseguito'], ['Intervento assegnato a te']],
    'Tecnico di EasyLab' => ['Tecnico', false, ['Intervento assegnato a te'], ['Macchina segnalata', 'Intervento programmato', 'Intervento eseguito']],
    'Gestore' => ['Gestore', false, ['Intervento assegnato a te'], ['Macchina segnalata', 'Intervento programmato', 'Intervento eseguito']],
]);

it('offers only the emails that are on, one by one', function () {
    InterruttoriEmail::imposta(CatalogoEmail::INTERVENTO_ESEGUITO, true);

    $this->actingAs($this->utente);

    Livewire::test(PreferenzeNotifiche::class)
        ->assertSee('Intervento eseguito')
        ->assertDontSee('Intervento programmato');
});

it('starts with the three intervento emails enabled, and persists each opt-out on its own column', function () {
    $this->actingAs($this->utente);

    Livewire::test(PreferenzeNotifiche::class)
        ->assertSet('riceveEmailInterventiProgrammati', true)
        ->assertSet('riceveEmailInterventiEseguiti', true)
        ->assertSet('riceveEmailInterventiAssegnati', true)
        ->assertSet('riceveEmailMacchineSegnalate', true)
        ->set('riceveEmailInterventiEseguiti', false)
        ->call('salva');

    $dopo = $this->utente->fresh();

    expect($dopo->riceve_email_interventi_eseguiti)->toBeFalse()
        // Le altre restano dov'erano: ogni interruttore ha la sua colonna.
        ->and($dopo->riceve_email_interventi_programmati)->toBeTrue()
        ->and($dopo->riceve_email_interventi_assegnati)->toBeTrue()
        ->and($dopo->riceve_email_macchine_segnalate)->toBeTrue()
        ->and($dopo->riceve_email_scadenze)->toBeTrue();
});

it('persists the opt-out from the flagged-machine email on its own column', function () {
    $this->actingAs($this->utente);

    Livewire::test(PreferenzeNotifiche::class)
        ->set('riceveEmailMacchineSegnalate', false)
        ->call('salva');

    $dopo = $this->utente->fresh();

    expect($dopo->riceve_email_macchine_segnalate)->toBeFalse()
        ->and($dopo->riceve_email_interventi_eseguiti)->toBeTrue();

    Livewire::test(PreferenzeNotifiche::class)->assertSet('riceveEmailMacchineSegnalate', false);
});

it('shows each intervento email as the person left it, and lets them opt back in', function () {
    $this->utente->forceFill([
        'riceve_email_interventi_programmati' => false,
        'riceve_email_interventi_assegnati' => false,
    ])->save();
    $this->actingAs($this->utente);

    Livewire::test(PreferenzeNotifiche::class)
        ->assertSet('riceveEmailInterventiProgrammati', false)
        ->assertSet('riceveEmailInterventiEseguiti', true)
        ->assertSet('riceveEmailInterventiAssegnati', false)
        ->set('riceveEmailInterventiProgrammati', true)
        ->call('salva');

    expect($this->utente->fresh()->riceve_email_interventi_programmati)->toBeTrue()
        ->and($this->utente->fresh()->riceve_email_interventi_assegnati)->toBeFalse();
});

it('never lets the intervento preferences be forged by mass assignment', function () {
    $this->utente->update([
        'riceve_email_interventi_programmati' => false,
        'riceve_email_interventi_eseguiti' => false,
        'riceve_email_interventi_assegnati' => false,
        'riceve_email_macchine_segnalate' => false,
    ]);

    $dopo = $this->utente->fresh();

    expect($dopo->riceve_email_interventi_programmati)->toBeTrue()
        ->and($dopo->riceve_email_interventi_eseguiti)->toBeTrue()
        ->and($dopo->riceve_email_interventi_assegnati)->toBeTrue()
        ->and($dopo->riceve_email_macchine_segnalate)->toBeTrue();
});
