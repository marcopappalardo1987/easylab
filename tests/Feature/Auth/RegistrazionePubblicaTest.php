<?php

use App\Models\Account;
use App\Models\PrezzoPiano;
use App\Models\Registrazione;
use App\Models\User;
use App\Notifications\VerificaEmailRegistrazione;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BancoRegistrazione;

/**
 * 🔴 Il modulo di `/registrati`: cosa scrive, e soprattutto cosa NON scrive
 * (🔗 ADR-012 il provisioning, ADR-032 l'Account intestatario).
 *
 * **Area rossa** della Policy di Code Review per la ragione più forte che il
 * progetto abbia incontrato: è l'unica superficie **pubblica, non autenticata e
 * in scrittura** dell'applicazione. I negativi sono quindi la maggioranza del
 * file, e il più importante di tutti — il riuso della riga pendente — è un
 * negativo di **sicurezza**, non di validazione: prova che un terzo che conosce
 * un indirizzo non possa sostituire la propria password a quella di chi sta per
 * pagare.
 *
 * L'anti-abuso (honeypot, anti-enumerazione, rate limiting) vive in
 * `AntiAbusoRegistrazioneTest`; i passi firmati in
 * `VerificaEmailRegistrazioneTest`; ciò che accade dopo il pagamento in
 * `tests/Feature/Registrazione/CompletaRegistrazioneTest.php`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->portale = BancoRegistrazione::apri();
});

/** I campi che un essere umano compila davvero, con la conferma della password. */
function datiModulo(array $sovrascritture = []): array
{
    return array_merge([
        'nome_ente' => 'Laboratorio Aurora',
        'nome_referente' => 'Marta Bianchi',
        'email' => 'marta@laboratorio-aurora.it',
        'piano' => 'saas',
        'password' => 'ParolaSegreta!2026',
        'password_confirmation' => 'ParolaSegreta!2026',
    ], $sovrascritture);
}

// ─── Ciò che il modulo scrive ────────────────────────────────────────────────

it('parks the intent in a pending row and sends the verification link', function () {
    Notification::fake();

    $this->post(route('registrazione.avvia'), datiModulo())
        ->assertRedirect(route('registrazione.controlla-email'));

    $riga = Registrazione::query()->sole();

    expect($riga->email)->toBe('marta@laboratorio-aurora.it')
        ->and($riga->nome_ente)->toBe('Laboratorio Aurora')
        ->and($riga->piano)->toBe('saas')
        ->and($riga->emailVerificata())->toBeFalse()
        ->and($riga->completata())->toBeFalse()
        // La password non viaggia in chiaro nemmeno nella sala d'attesa.
        ->and($riga->password_hash)->not->toBe('ParolaSegreta!2026')
        ->and(Hash::check('ParolaSegreta!2026', $riga->password_hash))->toBeTrue();

    Notification::assertSentTo($riga, VerificaEmailRegistrazione::class);
});

it('never creates a user, an account or an Ente before the payment', function () {
    // 🔴 La decisione di prodotto dell'intera feature, in un test: fino al
    // pagamento riuscito esiste **solo** la riga di sala d'attesa. Il modulo che
    // Fortify avrebbe dato (`Features::registration()`) creava invece l'utente e
    // lo autenticava — è scritto in `config/fortify.php` perché quella riga resta
    // commentata.
    $this->post(route('registrazione.avvia'), datiModulo());

    expect(Registrazione::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(0)
        ->and(Account::query()->count())->toBe(0);
});

it('normalises the email before writing it, so two spellings are one person', function () {
    // Senza, «Marta@Aurora.it » e «marta@aurora.it» sono due persone per il
    // controllo di esistenza e una sola per l'indice unique di `users`: cioè
    // un'eccezione al posto di un messaggio, **dopo** il pagamento.
    $this->post(route('registrazione.avvia'), datiModulo(['email' => '  Marta@Laboratorio-Aurora.IT ']));

    expect(Registrazione::query()->sole()->email)->toBe('marta@laboratorio-aurora.it');
});

it('reuses the pending row instead of piling up rows nobody will complete', function () {
    // «Reinviare = ripetere lo stesso gesto» (ADR-012). Senza il riuso, chi
    // compila tre volte lascia tre righe e il primo link ricevuto punta a una
    // riga che nessuno completerà.
    $this->post(route('registrazione.avvia'), datiModulo(['nome_ente' => 'Primo tentativo']));
    $this->post(route('registrazione.avvia'), datiModulo(['nome_ente' => 'Laboratorio Aurora']));

    $riga = Registrazione::query()->sole();

    expect($riga->nome_ente)->toBe('Laboratorio Aurora');
});

// ─── 🔴 Il negativo di sicurezza: la riga verificata non si riusa ────────────

it('never lets a second submission take over a registration whose mailbox is verified', function () {
    // 🔴 Lo scenario: la vittima ha già cliccato il link e sta pagando. Un terzo
    // che conosce soltanto l'indirizzo ripete il POST con la PROPRIA password.
    // Se la riga verificata venisse riusata, `password_hash` diventerebbe quello
    // dell'attaccante mentre il timbro di verifica resta — e l'account che la
    // vittima sta pagando nascerebbe con la credenziale di un altro.
    $vittima = Registrazione::factory()->verificata()->create([
        'email' => 'aurora@pec.it',
        'nome_ente' => 'Laboratorio Aurora',
        'password_hash' => Hash::make('PasswordDellaVittima!1'),
    ]);

    $this->post(route('registrazione.avvia'), datiModulo([
        'email' => 'aurora@pec.it',
        'nome_ente' => 'Ente Dellattaccante',
        'password' => 'PasswordDellAttaccante!9',
        'password_confirmation' => 'PasswordDellAttaccante!9',
    ]))->assertRedirect(route('registrazione.controlla-email'));

    $riga = $vittima->fresh();

    expect(Hash::check('PasswordDellaVittima!1', $riga->password_hash))->toBeTrue()
        ->and(Hash::check('PasswordDellAttaccante!9', $riga->password_hash))->toBeFalse()
        ->and($riga->nome_ente)->toBe('Laboratorio Aurora')
        ->and($riga->emailVerificata())->toBeTrue();
});

it('gives the second submission a row of its own, which nobody has verified', function () {
    // Il compagno del precedente: non riusare non significa non rispondere. La
    // riga nuova esiste, ma parte da capo — chi non legge quella casella non può
    // verificarla, e senza verifica il checkout non si apre.
    Registrazione::factory()->verificata()->create(['email' => 'aurora@pec.it']);

    $this->post(route('registrazione.avvia'), datiModulo(['email' => 'aurora@pec.it']));

    $nuova = Registrazione::query()->orderByDesc('id')->first();

    expect(Registrazione::query()->where('email', 'aurora@pec.it')->count())->toBe(2)
        ->and($nuova->emailVerificata())->toBeFalse()
        ->and($nuova->stripe_session_id)->toBeNull();
});

it('never reuses a registration that is already complete', function () {
    // Una riga completata è la traccia di un cliente vero: riusarla
    // sovrascriverebbe lo storico di come quel contratto è entrato.
    $account = Account::factory()->create();
    $completata = Registrazione::factory()->create([
        'email' => 'gia-cliente@pec.it',
        'completata_at' => now(),
        'account_id' => $account->getKey(),
        'password_hash' => null,
    ]);

    $this->post(route('registrazione.avvia'), datiModulo(['email' => 'gia-cliente@pec.it']));

    expect($completata->fresh()->password_hash)->toBeNull()
        ->and(Registrazione::query()->where('email', 'gia-cliente@pec.it')->count())->toBe(2);
});

// ─── Validazione ─────────────────────────────────────────────────────────────

it('refuses a submission that is missing anything, without writing a row', function (string $campo) {
    $this->post(route('registrazione.avvia'), datiModulo([$campo => '']))
        ->assertSessionHasErrors($campo);

    expect(Registrazione::query()->count())->toBe(0);
})->with(['nome_ente', 'nome_referente', 'email', 'piano', 'password']);

it('refuses an address no MTA would deliver to', function () {
    // `email:filter` e non il solo `email`: qui la casella è l'unica prova
    // d'identità dell'intero percorso.
    $this->post(route('registrazione.avvia'), datiModulo(['email' => 'marta@laboratorio']))
        ->assertSessionHasErrors('email');

    expect(Registrazione::query()->count())->toBe(0);
});

it('refuses a password that the rest of the application would refuse too', function () {
    $this->post(route('registrazione.avvia'), datiModulo([
        'password' => 'corta',
        'password_confirmation' => 'corta',
    ]))->assertSessionHasErrors('password');

    expect(Registrazione::query()->count())->toBe(0);
});

it('refuses a password whose confirmation does not match', function () {
    $this->post(route('registrazione.avvia'), datiModulo([
        'password_confirmation' => 'UnAltraParola!2026',
    ]))->assertSessionHasErrors('password');

    expect(Registrazione::query()->count())->toBe(0);
});

it('refuses a plan that the public form cannot sell', function (string $piano) {
    // 🔴 `free` è a listino ma **gratuito**: offrirlo qui sarebbe l'unica porta
    // del progetto da cui chiunque, senza pagare, si crea un Ente e un ruolo
    // Admin. `inesistente` non è a listino affatto.
    $this->post(route('registrazione.avvia'), datiModulo(['piano' => $piano]))
        ->assertSessionHasErrors('piano');

    expect(Registrazione::query()->count())->toBe(0);
})->with(['free', 'inesistente']);

it('refuses a plan that has just been archived, without writing a row', function () {
    // Il listino resta vendibile — c'è un altro piano in vetrina — quindi il
    // rifiuto è davvero «questo piano non si vende più» e non «non c'è niente da
    // vendere», che è un guasto diverso e si legge diversamente.
    BancoRegistrazione::pianoDiProva();
    BancoRegistrazione::archivia('saas');

    $this->post(route('registrazione.avvia'), datiModulo())
        ->assertSessionHasErrors('piano');

    expect(Registrazione::query()->count())->toBe(0);
});

// ─── L'interruttore ──────────────────────────────────────────────────────────

it('answers 404 and not 403 when the door is closed', function () {
    // Un 403 dichiarerebbe che la pagina esiste e che qualcuno la può aprire,
    // cioè un invito a bussare.
    config(['easylab.registrazione.aperta' => false]);

    $this->get(route('registrazione.mostra'))->assertNotFound();
    $this->post(route('registrazione.avvia'), datiModulo())->assertNotFound();

    expect(Registrazione::query()->count())->toBe(0);
});

it('says «chiuso» instead of giving away an account when there is nothing to sell', function () {
    // 🔴 Guasto di **deploy**, non piano gratuito: nessun price configurato
    // significa che l'ambiente è mal configurato. Si dice, e non si regala un
    // account.
    PrezzoPiano::query()->delete();
    BancoRegistrazione::dimentica();

    $this->post(route('registrazione.avvia'), datiModulo())
        ->assertRedirect(route('registrazione.mostra'));

    expect(Registrazione::query()->count())->toBe(0);
});

it('shows the sellable plans on the form, and never the free one', function () {
    $this->get(route('registrazione.mostra'))
        ->assertOk()
        ->assertSee('SaaS')
        ->assertDontSee('value="free"', false);
});
