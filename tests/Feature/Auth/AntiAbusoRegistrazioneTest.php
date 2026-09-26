<?php

use App\Models\Registrazione;
use App\Models\User;
use App\Notifications\AccountGiaEsistente;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\BancoRegistrazione;

/**
 * 🔴 Le tre difese del modulo pubblico che non sono validazione (🔗 ADR-012):
 * anti-enumerazione, honeypot, rate limiting.
 *
 * ⚠️ **Sono difese, quindi si provano al contrario**: non «funziona», ma «non si
 * distingue». Il valore dell'anti-enumerazione sta tutto nel fatto che le due
 * risposte siano **identiche** — stesso status, stessa destinazione, stessa
 * sessione — e un test che guardasse solo il ramo felice resterebbe verde il
 * giorno in cui il ramo «esiste già» cominciasse a rispondere diversamente,
 * cioè il giorno in cui la difesa smette di esistere.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    BancoRegistrazione::apri();
});

/** Il POST del modulo, con i campi che un umano compila. */
function inviaModulo(array $sovrascritture = [], array $server = []): TestResponse
{
    return test()->withServerVariables($server)->post(route('registrazione.avvia'), array_merge([
        'nome_ente' => 'Laboratorio Aurora',
        'nome_referente' => 'Marta Bianchi',
        'email' => 'marta@laboratorio-aurora.it',
        'piano' => 'saas',
        'password' => 'ParolaSegreta!2026',
        'password_confirmation' => 'ParolaSegreta!2026',
    ], $sovrascritture));
}

// ─── Anti-enumerazione ───────────────────────────────────────────────────────

it('answers an address that is already a customer exactly like a new one', function () {
    // 🔴 Dire «esiste già» sarebbe pubblicare l'anagrafica commerciale di Easy
    // Lab un indirizzo alla volta. L'unica differenza sta in ciò che arriva
    // nella casella, che è raggiungibile dal solo proprietario.
    Notification::fake();

    User::factory()->create(['email' => 'gia-cliente@pec.it']);

    $nuova = inviaModulo(['email' => 'sconosciuta@pec.it']);
    $esistente = inviaModulo(['email' => 'gia-cliente@pec.it']);

    expect($esistente->getStatusCode())->toBe($nuova->getStatusCode())
        ->and($esistente->headers->get('Location'))->toBe($nuova->headers->get('Location'));

    $esistente->assertSessionHasNoErrors();
});

it('writes no row at all for an address that already belongs to somebody', function () {
    // Nessuna riga: una registrazione pendente per un indirizzo già cliente
    // sarebbe un hash di password parcheggiato per una persona che non l'ha
    // chiesto — e una riga in più da cui dedurre che quell'indirizzo esiste.
    Notification::fake();

    User::factory()->create(['email' => 'gia-cliente@pec.it']);

    inviaModulo(['email' => 'gia-cliente@pec.it']);

    expect(Registrazione::query()->count())->toBe(0);
});

it('warns the owner of the address instead of the person who typed it', function () {
    Notification::fake();

    User::factory()->create(['email' => 'gia-cliente@pec.it']);

    inviaModulo(['email' => 'gia-cliente@pec.it']);

    Notification::assertSentOnDemand(AccountGiaEsistente::class);

    // ⚠️ **Una sola notifica, ed è quella giusta**: se partisse anche il link di
    // verifica, chi ha digitato l'indirizzo di un altro riceverebbe comunque il
    // percorso — cioè la difesa sarebbe cosmetica.
    Notification::assertCount(1);
    Notification::assertNothingSentTo(User::query()->get()->all());
});

it('recognises an address already taken however it was typed', function () {
    // La normalizzazione prima del confronto: senza, «Gia-Cliente@PEC.it» non
    // sarebbe «già cliente» per il controllo di esistenza e lo sarebbe per
    // l'indice unique di `users` — cioè un'eccezione dopo il pagamento.
    Notification::fake();

    User::factory()->create(['email' => 'gia-cliente@pec.it']);

    inviaModulo(['email' => ' Gia-Cliente@PEC.it '])
        ->assertRedirect(route('registrazione.controlla-email'));

    expect(Registrazione::query()->count())->toBe(0);
});

// ─── Honeypot ────────────────────────────────────────────────────────────────

it('answers a filled trap field with the same success as everybody else', function () {
    // Un rifiuto esplicito insegnerebbe al bot come passare: la risposta è
    // quella del percorso felice, identica.
    Notification::fake();

    $umano = inviaModulo(['email' => 'umana@pec.it']);
    $bot = inviaModulo(['email' => 'bot@pec.it', 'sito_web' => 'https://spam.example']);

    expect($bot->getStatusCode())->toBe($umano->getStatusCode())
        ->and($bot->headers->get('Location'))->toBe($umano->headers->get('Location'))
        ->and(Registrazione::query()->where('email', 'bot@pec.it')->exists())->toBeFalse()
        ->and(Registrazione::query()->where('email', 'umana@pec.it')->exists())->toBeTrue();

    Notification::assertCount(1);
});

it('shows the same validation errors to a bot as to a distracted human', function () {
    // 🔴 La trappola si legge **dopo** la validazione: un bot che sbaglia anche
    // gli altri campi deve vedere gli stessi errori di un umano distratto, o la
    // risposta gli direbbe che esiste un secondo controllo.
    inviaModulo(['email' => '', 'sito_web' => 'https://spam.example'])
        ->assertSessionHasErrors('email');
});

it('keeps the trap field out of the browser password manager', function () {
    // ⚠️ Senza `autocomplete="off"` il gestore di password del browser
    // compilerebbe il campo al posto di un utente vero, e la difesa
    // bloccherebbe proprio i clienti.
    $html = $this->get(route('registrazione.mostra'))->assertOk()->getContent();

    expect($html)->toContain('name="sito_web"')
        ->and($html)->toContain('autocomplete="off"')
        ->and($html)->toContain('tabindex="-1"');
});

// ─── Rate limiting ───────────────────────────────────────────────────────────

it('stops the fourth attempt on the same address, even from a new address of origin', function () {
    // 🔴 La chiave **email**: la sola chiave IP si aggira con un proxy, ed è
    // esattamente ciò che questo test simula cambiando `REMOTE_ADDR`.
    for ($i = 0; $i < 3; $i++) {
        inviaModulo(['email' => 'martellata@pec.it'], ['REMOTE_ADDR' => '10.0.0.'.$i])
            ->assertRedirect(route('registrazione.controlla-email'));
    }

    inviaModulo(['email' => 'martellata@pec.it'], ['REMOTE_ADDR' => '10.0.0.99'])
        ->assertStatus(429);
});

it('stops the fourth attempt from the same origin, even on four different addresses', function () {
    // 🔴 La chiave **IP**: la sola chiave email si aggira cambiando indirizzo.
    for ($i = 0; $i < 3; $i++) {
        inviaModulo(['email' => "tentativo{$i}@pec.it"], ['REMOTE_ADDR' => '10.0.0.7'])
            ->assertRedirect(route('registrazione.controlla-email'));
    }

    inviaModulo(['email' => 'tentativo3@pec.it'], ['REMOTE_ADDR' => '10.0.0.7'])
        ->assertStatus(429);
});

it('counts the same mailbox as one however it is spelled', function () {
    // Il limiter normalizza come il controller: senza, tre maiuscole diverse
    // sarebbero tre contatori diversi per una sola casella.
    foreach (['martellata@pec.it', 'Martellata@PEC.it', ' MARTELLATA@pec.it '] as $i => $email) {
        inviaModulo(['email' => $email], ['REMOTE_ADDR' => '10.0.1.'.$i]);
    }

    inviaModulo(['email' => 'martellata@pec.it'], ['REMOTE_ADDR' => '10.0.1.9'])
        ->assertStatus(429);
});

it('keeps the named limiter on the POST, and never a bare throttle', function () {
    // 🔴 Il guardrail meccanico della difesa: `throttle:3,1` scritto inline
    // avrebbe la sola chiave IP — cioè si aggirerebbe con un proxy — e nessun
    // test funzionale saprebbe distinguerlo da quello giusto se non provando
    // due IP. Qui si nomina la rotta, così spostare il middleware diventa rosso.
    $middleware = collect(Route::getRoutes()->getByName('registrazione.avvia')->gatherMiddleware());

    expect($middleware)->toContain('throttle:registrazione')
        ->and($middleware)->toContain('guest');
});

it('never throttles away the return from Stripe, where the money is already in', function () {
    // ⚠️ Il ritorno da Stripe ha un limite suo, largo: strozzarlo come il modulo
    // significherebbe rispondere 429 a chi ha appena pagato.
    $middleware = collect(Route::getRoutes()->getByName('registrazione.completata')->gatherMiddleware());

    expect($middleware)->toContain('signed')
        ->and($middleware)->not->toContain('throttle:registrazione')
        ->and($middleware)->not->toContain('guest');
});
