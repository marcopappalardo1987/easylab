<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * I limiti di frequenza sulle porte che si aprono senza essere già dentro
 * (security pass S7, T1b).
 *
 * Il limiter della registrazione ha già i suoi test (`AntiAbusoRegistrazioneTest`):
 * qui non si ripetono. QR e webhook NON hanno throttle, con una ragione scritta
 * nell'inventario (`RotteInventarioTest`): la firma HMAC rende il tentativo a
 * forza bruta impossibile, e sul webhook un 429 farebbe perdere eventi di Stripe.
 *
 * ⚠️ Il blocco «reset della password» è ROSSO finché l'orchestratore non applica
 * la richiesta in board (limiter `password-reset` e risposta uniforme in
 * `FortifyServiceProvider`): Fortify non offre un'opzione di limiter su quelle
 * due rotte.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->utente = User::factory()->create([
        'email' => 'mario.rossi@laboratorio.test',
        'password' => Hash::make('password-giusta-123'),
    ]);
});

// --- Login e secondo fattore (limiter nominati di Fortify) ---

it('stops the sixth wrong password in a minute on the same account', function () {
    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $this->utente->email, 'password' => 'sbagliata'])->assertSessionHasErrors('email');
    }

    $this->post('/login', ['email' => $this->utente->email, 'password' => 'sbagliata'])->assertTooManyRequests();
});

it('keeps counting even when the right password arrives after the limit', function () {
    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $this->utente->email, 'password' => 'sbagliata']);
    }

    $this->post('/login', ['email' => $this->utente->email, 'password' => 'password-giusta-123'])->assertTooManyRequests();
    $this->assertGuest();
});

it('stops the sixth wrong code at the two factor challenge', function () {
    $this->utente->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    foreach (range(1, 5) as $_) {
        $this->withSession(['login.id' => $this->utente->id, 'login.remember' => false])
            ->post('/two-factor-challenge', ['code' => '000000']);
    }

    $this->withSession(['login.id' => $this->utente->id, 'login.remember' => false])
        ->post('/two-factor-challenge', ['code' => '000000'])
        ->assertTooManyRequests();
    $this->assertGuest();
});

// --- Invito ---

it('stops the seventh password submission on the same invite', function () {
    $invitato = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('invito.imposta', now()->addDay(), ['user' => $invitato->id]);

    foreach (range(1, 6) as $_) {
        $this->post($url, ['password' => 'x', 'password_confirmation' => 'x']);
    }

    $this->post($url, ['password' => 'x', 'password_confirmation' => 'x'])->assertTooManyRequests();
});

// --- Reset della password (ROSSO finché la richiesta in board non è applicata) ---

it('stops a burst of reset links from the same origin', function () {
    foreach (range(1, 5) as $i) {
        $this->post('/forgot-password', ['email' => "sconosciuto{$i}@esterno.test"]);
    }

    $this->post('/forgot-password', ['email' => 'sconosciuto6@esterno.test'])->assertTooManyRequests();
});

it('stops a burst of reset attempts on the token form', function () {
    foreach (range(1, 5) as $_) {
        $this->post('/reset-password', [
            'token' => 'token-inventato',
            'email' => $this->utente->email,
            'password' => 'Nuova-password-123',
            'password_confirmation' => 'Nuova-password-123',
        ]);
    }

    $this->post('/reset-password', [
        'token' => 'token-inventato',
        'email' => $this->utente->email,
        'password' => 'Nuova-password-123',
        'password_confirmation' => 'Nuova-password-123',
    ])->assertTooManyRequests();
});

it('answers a reset request for an unknown address exactly like one for a customer', function () {
    // Fortify di default risponde «non troviamo un utente con questo indirizzo»:
    // un oracolo per sapere chi è cliente. La registrazione pubblica ha già
    // chiuso lo stesso oracolo (AntiAbusoRegistrazioneTest); qui era aperto.
    $cliente = $this->from('/forgot-password')->post('/forgot-password', ['email' => $this->utente->email]);
    $estraneo = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nessuno@esterno.test']);

    $estraneo->assertSessionHasNoErrors();
    expect($estraneo->status())->toBe($cliente->status())
        ->and(session('status'))->toBe(trans('passwords.sent'));
});
