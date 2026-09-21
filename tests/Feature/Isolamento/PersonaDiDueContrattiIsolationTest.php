<?php

use App\Livewire\Utenti\ElencoUtenti;
use App\Models\User;
use App\Notifications\InvitoUtente;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Isolamento\Support\MondoDueEnti;

/**
 * T2A-1 (ADR-032). Una persona membro di DUE account sta su un Ente alla
 * volta, ma ruoli (`teams = false`) e soft delete sono globali. Mentre è
 * sull'Ente B, l'Admin di B la vede in `/utenti` — ed è giusto — ma non deve
 * poterla retrocedere, cestinare, ripristinare o reinvitare: il gesto la
 * toccherebbe anche presso l'Ente A, un altro cliente.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mondo = MondoDueEnti::crea();
    $this->adminB = $this->mondo->riga('B', 'admin');

    $this->consulente = User::factory()->create(['name' => 'Consulente-Doppio', 'two_factor_confirmed_at' => now()]);
    $this->consulente->forceFill(['tenant_id' => $this->mondo->riga('A', 'ente')->id])->save();
    $this->consulente->assignRole('Admin');
    $this->consulente->accounts()->syncWithoutDetaching([
        $this->mondo->riga('A', 'account')->id,
        $this->mondo->riga('B', 'account')->id,
    ]);

    // Passa all'Ente B con lo switcher: gesto legittimo di un membro.
    $this->actingAs($this->consulente);
    expect($this->consulente->passaAllEnte($this->mondo->riga('B', 'ente')))->toBeTrue();
});

/** Esegue il gesto e pretende il 403 della guardia (Livewire lo traduce in stato della risposta). */
function rifiutatoCon403(Closure $gesto): void
{
    $gesto()->assertForbidden();
}

it('still lists the shared person on the users page of the Ente they are on', function () {
    $componente = Livewire::actingAs($this->adminB)->test(ElencoUtenti::class);

    expect($componente->html())->toContain('Consulente-Doppio')
        ->and($componente->viewData('condivise'))->toBe([$this->consulente->id]);
});

it('refuses to open the role change of a person who is also in another contract', function () {
    rifiutatoCon403(fn () => Livewire::actingAs($this->adminB)->test(ElencoUtenti::class)
        ->call('apriRuolo', $this->consulente->id));
});

it('refuses to demote a person who is also Admin of another contract', function () {
    rifiutatoCon403(fn () => Livewire::actingAs($this->adminB)->test(ElencoUtenti::class)
        ->set('utenteRuolo', $this->consulente->id));

    expect($this->consulente->fresh()->getRoleNames()->all())->toBe(['Admin']);
});

it('refuses to bin a person who still administers another contract', function () {
    rifiutatoCon403(fn () => Livewire::actingAs($this->adminB)->test(ElencoUtenti::class)
        ->call('confermaCestino', $this->consulente->id));
    rifiutatoCon403(fn () => Livewire::actingAs($this->adminB)->test(ElencoUtenti::class)
        ->set('utenteCestino', $this->consulente->id));

    expect(User::withTrashed()->find($this->consulente->id)->trashed())->toBeFalse()
        ->and($this->consulente->accounts()->pluck('accounts.id')->all())
        ->toEqualCanonicalizing([$this->mondo->riga('A', 'account')->id, $this->mondo->riga('B', 'account')->id]);
});

it('refuses to resend the invitation of a shared person who never logged in', function () {
    Notification::fake();
    $this->consulente->forceFill(['email_verified_at' => null])->save();

    rifiutatoCon403(fn () => Livewire::actingAs($this->adminB)->test(ElencoUtenti::class)
        ->call('reinvia', $this->consulente->id));

    Notification::assertNotSentTo($this->consulente, InvitoUtente::class);
});

it('refuses to restore a binned person who is still in another contract', function () {
    $this->consulente->delete();

    rifiutatoCon403(fn () => Livewire::actingAs($this->adminB)->test(ElencoUtenti::class)
        ->call('ripristina', $this->consulente->id));

    expect(User::withTrashed()->find($this->consulente->id)->trashed())->toBeTrue();
});

it('keeps administering a person who belongs to this contract only', function () {
    // Il positivo: la guardia non spegne la schermata per tutti.
    $solaB = $this->mondo->riga('B', 'responsabile');

    Livewire::actingAs($this->adminB)->test(ElencoUtenti::class)
        ->call('apriRuolo', $solaB->id)
        ->set('nuovoRuolo', User::TENANT_ROLE)
        ->call('cambiaRuolo')
        ->assertHasNoErrors();

    expect($solaB->fresh()->getRoleNames()->all())->toBe([User::TENANT_ROLE]);
});
