<?php

use App\Models\Account;
use App\Models\Registrazione;
use App\Support\Registrazione\PortaleCheckout;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\URL;
use Tests\Support\BancoRegistrazione;

/**
 * 🔴 I due passi firmati fra il modulo e Stripe: la verifica della casella e
 * l'apertura del checkout (🔗 ADR-012, ADR-032).
 *
 * ⚠️ **Il contatore della finta è un'asserzione di prima classe.** I negativi di
 * questo file non provano soltanto che la risposta sia 403: provano che il
 * checkout **non sia stato aperto**, cioè che nessun oggetto sia nato su Stripe
 * per una casella che nessuno ha confermato. Senza il conteggio, una guardia
 * spostata *dopo* la chiamata di rete resterebbe verde — e lascerebbe dietro di
 * sé sessioni di pagamento che questa parte non può ripulire.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->portale = BancoRegistrazione::apri();
});

/** L'URL firmato di un passo, come lo costruisce il controller. */
function passoFirmato(string $rotta, Registrazione $registrazione, int $ore = 2): string
{
    // Il link di verifica porta anche l'impronta del contenuto della riga, come
    // lo costruisce `VerificaEmailRegistrazione` (🔗 Registrazione::improntaVerifica).
    $parametri = ['registrazione' => $registrazione->getKey()];

    if ($rotta === 'registrazione.verifica') {
        $parametri['impronta'] = $registrazione->improntaVerifica();
    }

    return URL::temporarySignedRoute($rotta, now()->addHours($ore), $parametri);
}

// ─── La verifica della casella ───────────────────────────────────────────────

it('stamps the mailbox as verified and hands over a signed payment link', function () {
    $riga = Registrazione::factory()->create();

    $risposta = $this->get(passoFirmato('registrazione.verifica', $riga));

    $risposta->assertRedirect();

    expect($riga->fresh()->emailVerificata())->toBeTrue()
        ->and($risposta->headers->get('Location'))->toContain('/pagamento')
        // La firma è il gate del passo successivo: un redirect nudo lo
        // renderebbe raggiungibile per enumerazione dell'id.
        ->and($risposta->headers->get('Location'))->toContain('signature=');
});

it('never moves the stamp when the link is opened twice', function () {
    // La data della verifica è un fatto: sovrascriverla la sposterebbe in avanti
    // ogni volta che qualcuno rilegge la posta.
    $riga = Registrazione::factory()->create(['email_verificata_at' => now()->subDays(3)]);
    $quando = $riga->email_verificata_at;

    $this->get(passoFirmato('registrazione.verifica', $riga))->assertRedirect();

    expect($riga->fresh()->email_verificata_at->equalTo($quando))->toBeTrue();
});

it('refuses an unsigned or tampered link, instead of saying which rows exist', function () {
    // 🔴 La firma cade **prima** del route-model binding: un id manomesso dà
    // 403, non un 404 che direbbe «questa riga non c'è, prova la prossima».
    $riga = Registrazione::factory()->create();
    $altra = Registrazione::factory()->create();

    $this->get(route('registrazione.verifica', $riga))->assertForbidden();

    $firmataPerUnAltra = str_replace(
        "/registrazione/{$altra->getKey()}/",
        "/registrazione/{$riga->getKey()}/",
        passoFirmato('registrazione.verifica', $altra),
    );

    $this->get($firmataPerUnAltra)->assertForbidden();

    expect($riga->fresh()->emailVerificata())->toBeFalse();
});

it('refuses an expired link', function () {
    $riga = Registrazione::factory()->create();

    $scaduto = URL::temporarySignedRoute(
        'registrazione.verifica',
        now()->subMinute(),
        ['registrazione' => $riga->getKey()],
    );

    $this->get($scaduto)->assertForbidden();

    expect($riga->fresh()->emailVerificata())->toBeFalse();
});

it('sends whoever is already a customer to the login, not to a second checkout', function () {
    $riga = Registrazione::factory()->create([
        'completata_at' => now(),
        'account_id' => Account::factory()->create()->getKey(),
    ]);

    $this->get(passoFirmato('registrazione.verifica', $riga))
        ->assertRedirect(route('login'));
});

// ─── 🔴 La guardia che rende obbligatoria la verifica ────────────────────────

it('never opens a checkout for a mailbox nobody confirmed', function () {
    // 🔴 Il negativo che conta: chi ha compilato il modulo con la casella di un
    // altro ha una firma valida per questo POST — la riga è sua, l'indirizzo no.
    // Senza questa guardia aprirebbe un checkout intestato a un indirizzo che
    // nessuno ha confermato.
    $riga = Registrazione::factory()->create();

    $this->post(passoFirmato('registrazione.verso-stripe', $riga))->assertForbidden();

    expect($this->portale->aperture())->toBe(0)
        ->and($riga->fresh()->stripe_session_id)->toBeNull();
});

it('never shows the payment page for a mailbox nobody confirmed', function () {
    // 403 e non un redirect gentile: un redirect direbbe dove sta il passo
    // mancante, cioè insegnerebbe il percorso a chi non dovrebbe averlo.
    $riga = Registrazione::factory()->create();

    $this->get(passoFirmato('registrazione.pagamento', $riga))->assertForbidden();
});

it('opens exactly one checkout and writes the session id before the redirect', function () {
    // ⛔ L'id si scrive **prima** del redirect ed è l'unica copia che avremo: il
    // `success_url` non può portarlo (la firma copre la query string), e
    // leggerlo dalla nostra riga è anche l'unico modo di non fidarsi del
    // browser.
    $riga = Registrazione::factory()->verificata()->create();

    $this->post(passoFirmato('registrazione.verso-stripe', $riga))
        ->assertRedirect($this->portale->url);

    expect($this->portale->aperture())->toBe(1)
        ->and($riga->fresh()->stripe_session_id)->toBe($this->portale->sessionId);
});

it('hands Stripe two signed urls of our own, and never a bare route', function () {
    $riga = Registrazione::factory()->verificata()->create();

    $this->post(passoFirmato('registrazione.verso-stripe', $riga));

    $apertura = $this->portale->aperture[0];

    expect($apertura['registrazione_id'])->toBe($riga->getKey())
        ->and($apertura['success_url'])->toContain('signature=')
        ->and($apertura['success_url'])->toContain('/completata')
        ->and($apertura['cancel_url'])->toContain('signature=')
        ->and($apertura['cancel_url'])->toContain('/pagamento');
});

it('never opens a checkout on a plan that has left the shop window', function () {
    // Fra il modulo e questo POST `/piattaforma/piani` può aver archiviato il
    // piano. Qui nessuno ha ancora pagato, quindi il fail-closed non costa
    // niente: si torna alla pagina di pagamento, che lo dice.
    $riga = Registrazione::factory()->verificata()->create();

    BancoRegistrazione::pianoDiProva();
    BancoRegistrazione::archivia('saas');

    $risposta = $this->post(passoFirmato('registrazione.verso-stripe', $riga));

    $risposta->assertRedirect();

    expect($risposta->headers->get('Location'))->toContain('/pagamento')
        ->and($this->portale->aperture())->toBe(0)
        ->and($riga->fresh()->stripe_session_id)->toBeNull();
});

it('says on the payment page that the plan is no longer available', function () {
    $riga = Registrazione::factory()->verificata()->create();

    BancoRegistrazione::pianoDiProva();
    BancoRegistrazione::archivia('saas');

    $this->get(passoFirmato('registrazione.pagamento', $riga))
        ->assertOk()
        ->assertSee('non è più disponibile');
});

it('never opens a second checkout for a registration that is already complete', function () {
    $riga = Registrazione::factory()->conSessione()->create([
        'completata_at' => now(),
        'account_id' => Account::factory()->create()->getKey(),
    ]);

    $this->post(passoFirmato('registrazione.verso-stripe', $riga))
        ->assertRedirect(route('login'));

    expect($this->portale->aperture())->toBe(0);
});

it('keeps the fake in the container, so no test of this file talks to Stripe', function () {
    // La riga che tiene onesto il banco: se il binding saltasse, i test qui
    // sopra proverebbero la porta vera — cioè fallirebbero per la ragione
    // sbagliata, o peggio creerebbero oggetti su Stripe.
    expect(app(PortaleCheckout::class))->toBe($this->portale);
});
