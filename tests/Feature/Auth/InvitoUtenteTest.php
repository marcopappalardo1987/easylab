<?php

use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Spatie\Activitylog\Models\Activity;

/**
 * L'invitato imposta la password da un link firmato (🔗 ADR-012) — area rossa
 * (autorizzazioni): la firma È il gate, quindi ogni modo di aggirarla ha il suo
 * test negativo, e il POST su un invito già usato non deve poter riscrivere la
 * password di un account in uso.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Come lo crea il provisioning: password random mai comunicata, casella
    // non ancora verificata.
    $this->invitato = User::factory()->unverified()->create([
        'email' => 'nuovo.admin@demo.test',
        'password' => Hash::make('tappo-random-mai-comunicato'),
    ]);
});

function linkInvito(User $utente, ?string $scadenza = null): string
{
    return URL::temporarySignedRoute(
        'invito.mostra',
        $scadenza === null ? now()->addDays(7) : now()->parse($scadenza),
        ['user' => $utente->id],
    );
}

it('shows the form on a valid signature', function () {
    $this->get(linkInvito($this->invitato))
        ->assertOk()
        ->assertSee('Imposta la password')
        ->assertSee('nuovo.admin@demo.test');
});

it('rejects a tampered signature', function () {
    $altro = User::factory()->unverified()->create();
    $url = linkInvito($this->invitato);

    // Stessa firma, id cambiato: è il tentativo di enumerare gli utenti.
    $manomesso = str_replace("/invito/{$this->invitato->id}?", "/invito/{$altro->id}?", $url);

    $this->get($manomesso)->assertForbidden();
});

it('rejects an expired signature', function () {
    $scaduto = URL::temporarySignedRoute(
        'invito.mostra', now()->subMinute(), ['user' => $this->invitato->id]
    );

    $this->get($scaduto)->assertForbidden();
});

it('rejects an unsigned url', function () {
    $this->get(route('invito.mostra', $this->invitato))->assertForbidden();
});

it('sends an already activated user to the login', function () {
    $this->invitato->forceFill(['email_verified_at' => now()])->save();

    $this->get(linkInvito($this->invitato))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');
});

it('sets the password, verifies the email and audits the acceptance', function () {
    $url = linkInvito($this->invitato);

    $this->post($url, [
        'password' => 'password-molto-robusta',
        'password_confirmation' => 'password-molto-robusta',
    ])->assertRedirect(route('login'))->assertSessionHas('status');

    $fresco = $this->invitato->fresh();
    expect(Hash::check('password-molto-robusta', $fresco->password))->toBeTrue()
        ->and($fresco->email_verified_at)->not->toBeNull();

    $riga = Activity::inLog(AuditLog::NAME)->where('description', 'Invito accettato')->first();
    expect($riga)->not->toBeNull()
        ->and($riga->subject_id)->toBe($this->invitato->id);
});

it('refuses a weak password without touching the account', function () {
    $this->post(linkInvito($this->invitato), [
        'password' => 'abc',
        'password_confirmation' => 'abc',
    ])->assertSessionHasErrors('password');

    $fresco = $this->invitato->fresh();
    expect(Hash::check('tappo-random-mai-comunicato', $fresco->password))->toBeTrue()
        ->and($fresco->email_verified_at)->toBeNull();
});

it('refuses a password that does not match its confirmation', function () {
    $this->post(linkInvito($this->invitato), [
        'password' => 'password-molto-robusta',
        'password_confirmation' => 'password-diversa-robusta',
    ])->assertSessionHasErrors('password');

    expect($this->invitato->fresh()->email_verified_at)->toBeNull();
});

it('never rewrites the password of an already used invite', function () {
    // Il negativo che conta: il link resta valido 7 giorni, e chi lo riapre
    // dopo l'attivazione non deve poter cambiare la password di un account in
    // uso — nemmeno replicando il POST.
    $url = linkInvito($this->invitato);
    $this->post($url, [
        'password' => 'password-scelta-dal-titolare',
        'password_confirmation' => 'password-scelta-dal-titolare',
    ]);

    $this->post($url, [
        'password' => 'password-di-un-intruso',
        'password_confirmation' => 'password-di-un-intruso',
    ])->assertRedirect(route('login'));

    expect(Hash::check('password-scelta-dal-titolare', $this->invitato->fresh()->password))->toBeTrue();
});

it('rejects a post without a valid signature', function () {
    $this->post(route('invito.mostra', $this->invitato), [
        'password' => 'password-molto-robusta',
        'password_confirmation' => 'password-molto-robusta',
    ])->assertForbidden();

    expect($this->invitato->fresh()->email_verified_at)->toBeNull();
});

it('lets the invited user log in with the new password', function () {
    $this->post(linkInvito($this->invitato), [
        'password' => 'password-molto-robusta',
        'password_confirmation' => 'password-molto-robusta',
    ]);

    $this->post('/login', [
        'email' => 'nuovo.admin@demo.test',
        'password' => 'password-molto-robusta',
    ]);

    $this->assertAuthenticatedAs($this->invitato->fresh());
});

it('sends an already authenticated visitor away', function () {
    $altro = User::factory()->create();

    $this->actingAs($altro)
        ->get(linkInvito($this->invitato))
        ->assertRedirect();
});
