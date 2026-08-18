<?php

use App\Livewire\Notifiche\Campanella;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\DigestScadenze;
use App\Support\Notifiche\RigaAvviso;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * La campanella (🔗 ADR-011): il conteggio, il pannello e — la cosa che conta
 * davvero — il fatto che ciascuno veda la propria posta e nessun'altra.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->strumento = Strumento::factory()->forNode($this->ente)->create(['nome' => 'Agitatore 2358']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id]);
    $this->admin->assignRole('Admin');
});

function notificaA(User $utente, UnitaOrganizzativa $ente, Strumento $strumento): void
{
    $intervento = Intervento::factory()->forStrumento($strumento)->create([
        'data_scadenza' => today()->addDays(10)->toDateString(),
    ]);

    $utente->notify(new DigestScadenze($ente->id, $ente->nome, [
        RigaAvviso::daIntervento($intervento, $strumento->nome, $strumento->unita_organizzativa_id),
    ]));
}

it('shows no badge when there is nothing unread', function () {
    $this->actingAs($this->admin);

    Livewire::test(Campanella::class)->assertSet('nonLette', 0);
});

it('counts the unread notifications', function () {
    notificaA($this->admin, $this->ente, $this->strumento);
    notificaA($this->admin, $this->ente, $this->strumento);
    $this->actingAs($this->admin);

    Livewire::test(Campanella::class)->assertSet('nonLette', 2);
});

it('loads the list only when the panel is opened', function () {
    notificaA($this->admin, $this->ente, $this->strumento);
    $this->actingAs($this->admin);

    Livewire::test(Campanella::class)
        // Chiusa: nessuna riga letta dal database — il componente si monta su
        // ogni pagina dell'applicazione.
        ->assertDontSee('Ente A')
        ->call('apri')
        ->assertSee('Ente A')
        ->assertSee('1 in arrivo');
});

it('marks everything as read', function () {
    notificaA($this->admin, $this->ente, $this->strumento);
    $this->actingAs($this->admin);

    Livewire::test(Campanella::class)
        ->call('segnaTutteLette')
        ->assertSet('nonLette', 0);

    expect($this->admin->fresh()->unreadNotifications()->count())->toBe(0);
});

it('never shows the notifications of another user', function () {
    $altro = User::factory()->create(['tenant_id' => $this->ente->id]);
    $altro->assignRole('Admin');
    notificaA($altro, $this->ente, $this->strumento);

    $this->actingAs($this->admin);

    Livewire::test(Campanella::class)
        ->assertSet('nonLette', 0)
        ->call('apri')
        ->assertDontSee('Ente A');
});

it('appears in the app shell for an authenticated user', function () {
    // Un Tenant e non l'Admin: i ruoli privilegiati sono rediretti all'attivazione
    // della 2FA, quindi non arriverebbero mai al layout (TwoFactorEnforcement).
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');
    notificaA($tenant, $this->ente, $this->strumento);

    $this->actingAs($tenant)
        ->get('/dashboard')
        ->assertOk()
        ->assertSeeLivewire(Campanella::class);
});
