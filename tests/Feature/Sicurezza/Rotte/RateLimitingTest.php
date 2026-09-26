<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
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
 * ⚠️ Il blocco «caccia T1b» è ROSSO finché l'orchestratore non applica R-T1b-8
 * (risposta uniforme sul form di reset) e R-T1b-9 (limiter separati per link e
 * form, e input non stringa) in `FortifyServiceProvider`.
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

// --- Reset della password ---

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

// --- Caccia T1b (ROSSO finché R-T1b-8 e R-T1b-9 non sono applicate) ---

it('answers a forged reset token the same way for a customer and for a stranger', function () {
    // T1bA-2: il form di reset diceva `passwords.user` all'estraneo e
    // `passwords.token` al cliente: lo stesso oracolo chiuso sul link.
    $prova = fn (string $email) => $this->from('/reset-password/token-inventato')->post('/reset-password', [
        'token' => 'token-inventato',
        'email' => $email,
        'password' => 'Nuova-password-123',
        'password_confirmation' => 'Nuova-password-123',
    ])->assertSessionHasErrors('email') ? session('errors')->first('email') : null;

    expect($prova('mario.rossi@laboratorio.test'))->toBe($prova('nessuno@esterno.test'));
});

it('never lets a stranger stop a customer from completing a genuine reset', function () {
    // T1bB-3: link e form condividevano il secchio per email, quindi cinque
    // richieste di link da un'altra origine bloccavano il reset vero.
    $token = Password::broker()->createToken($this->utente);

    foreach (range(1, 5) as $_) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post('/forgot-password', ['email' => $this->utente->email]);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->post('/reset-password', [
            'token' => $token,
            'email' => $this->utente->email,
            'password' => 'Nuova-password-2026',
            'password_confirmation' => 'Nuova-password-2026',
        ])->assertStatus(302);

    expect(Hash::check('Nuova-password-2026', $this->utente->fresh()->password))->toBeTrue();
});

it('answers an array where the email should be with a validation error, not a 500', function (string $da, string $verso, array $campi) {
    // T1bA-3: i limiter facevano `Str::lower()` su un array (TypeError) prima
    // che la validazione di Fortify potesse rifiutarlo.
    $this->from($da)->post($verso, $campi)->assertRedirect($da);
})->with([
    'link di reset' => ['/forgot-password', '/forgot-password', ['email' => ['mario.rossi@laboratorio.test']]],
    'form di reset' => ['/reset-password/token', '/reset-password', ['token' => 'x', 'email' => ['a@b.it'], 'password' => 'Nuova-password-123', 'password_confirmation' => 'Nuova-password-123']],
    'login' => ['/login', '/login', ['email' => ['mario.rossi@laboratorio.test'], 'password' => 'x']],
]);
