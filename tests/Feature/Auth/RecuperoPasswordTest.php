<?php

use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\RecuperoPassword;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * «Password dimenticata»: il link parte in coda e la pagina risponde sempre
 * allo stesso modo, in italiano (23 Nov 2026: con la posta che non rispondeva
 * chi lo chiedeva vedeva un errore 500, e solo per gli indirizzi registrati).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $this->persona = User::factory()->create(['tenant_id' => $ente->id, 'email' => 'chi@esempio.test']);
    $this->persona->assignRole('Admin');

    $this->chiedi = fn (string $email) => $this->from('/forgot-password')->post('/forgot-password', ['email' => $email]);
});

it('sends the reset link through the queue, never inside the request', function () {
    Notification::fake();

    ($this->chiedi)('chi@esempio.test')->assertRedirect('/forgot-password');

    Notification::assertSentTo($this->persona, RecuperoPassword::class);
    expect(new RecuperoPassword('x'))->toBeInstanceOf(ShouldQueue::class);
});

it('answers the same Italian sentence to a registered and to an unknown address', function () {
    Notification::fake();

    $vera = ($this->chiedi)('chi@esempio.test');
    $atteso = trans('passwords.sent');

    expect($atteso)->toStartWith("Se l'indirizzo è registrato");
    $vera->assertSessionHas('status', $atteso)->assertSessionHasNoErrors();

    session()->flush();
    ($this->chiedi)('nessuno@esempio.test')->assertSessionHas('status', $atteso)->assertSessionHasNoErrors();
});

it('does not fail the page when the mail server is down', function () {
    // 🔴 Il caso di produzione: un server di posta che rifiuta la connessione.
    // In coda la pagina risponde come sempre, e il job resta da consegnare.
    config([
        'queue.default' => 'database',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 1,
    ]);

    ($this->chiedi)('chi@esempio.test')
        ->assertRedirect('/forgot-password')
        ->assertSessionHas('status', trans('passwords.sent'));

    expect(DB::table('jobs')->count())->toBe(1);
});

it('says in Italian that a login failed', function () {
    $this->from('/login')->post('/login', ['email' => 'chi@esempio.test', 'password' => 'sbagliata'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    expect(trans('auth.failed'))->toBe('Email o password non corrette.');
});
