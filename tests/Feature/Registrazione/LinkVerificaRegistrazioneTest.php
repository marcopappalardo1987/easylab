<?php

use App\Models\Registrazione;
use App\Models\User;
use App\Notifications\VerificaEmailRegistrazione;
use App\Support\Registrazione\CompletaRegistrazione;
use App\Support\Registrazione\EsitoCheckout;
use App\Support\Registrazione\RegistrazioneRifiutata;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Support\BancoRegistrazione;

/**
 * 🔴 Il link di verifica della casella vale per il **contenuto** della riga,
 * non solo per il suo id (🔗 ADR-012; caccia T3, A1/B1 e A6).
 *
 * Una riga non verificata si riusa quando qualcuno ripete il modulo, e il POST
 * è pubblico: chi conosce l'indirizzo riscriveva `password_hash`, e il link già
 * spedito alla vittima (firmato sul solo id) verificava la password
 * dell'attaccante. Ora ogni riscrittura cambia l'impronta nella URL firmata.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    BancoRegistrazione::apri();
});

function moduloVerifica(array $sovrascritture = []): array
{
    return array_merge([
        'nome_ente' => 'Laboratorio Aurora',
        'nome_referente' => 'Marta Bianchi',
        'email' => 'marta@laboratorio-aurora.it',
        'piano' => 'saas',
        'password' => 'PasswordDellaVittima!1',
        'password_confirmation' => 'PasswordDellaVittima!1',
    ], $sovrascritture);
}

/** I link di verifica spediti a una riga, nell'ordine d'invio. */
function linkSpediti(Registrazione $riga): array
{
    $link = [];

    Notification::assertSentTo($riga, VerificaEmailRegistrazione::class, function ($n) use ($riga, &$link) {
        $link[] = $n->toMail($riga->fresh())->viewData['url'];

        return true;
    });

    return $link;
}

it('refuses the link mailed before a third party rewrote the pending row', function () {
    Notification::fake();

    $this->post(route('registrazione.avvia'), moduloVerifica())->assertRedirect();
    $riga = Registrazione::query()->sole();

    // L'URL come la costruiva la mail al momento dell'invio alla vittima.
    $linkDellaVittima = URL::temporarySignedRoute('registrazione.verifica', now()->addHour(), [
        'registrazione' => $riga->getKey(),
        'impronta' => $riga->improntaVerifica(),
    ]);

    // Un terzo che conosce solo l'indirizzo ripete il modulo PRIMA del clic.
    $this->post(route('registrazione.avvia'), moduloVerifica([
        'password' => 'PasswordDellAttaccante!9',
        'password_confirmation' => 'PasswordDellAttaccante!9',
    ]))->assertRedirect();

    expect(Registrazione::query()->count())->toBe(1);

    // Il link vecchio non verifica più la riga riscritta.
    $this->get($linkDellaVittima)->assertForbidden();

    expect($riga->fresh()->emailVerificata())->toBeFalse();

    // E senza verifica nessun account nasce con la password dell'attaccante,
    // nemmeno se il checkout risultasse pagato.
    expect(fn () => app(CompletaRegistrazione::class)->esegui($riga->fresh(), new EsitoCheckout(
        pagato: true, sessionId: 'cs_x', customerId: 'cus_x', subscriptionId: 'sub_x', piano: 'saas',
    )))->toThrow(RegistrazioneRifiutata::class);

    expect(User::query()->where('email', 'marta@laboratorio-aurora.it')->exists())->toBeFalse();
});

it('still verifies with the most recent link, which carries the current fingerprint', function () {
    Notification::fake();

    $this->post(route('registrazione.avvia'), moduloVerifica())->assertRedirect();
    $this->post(route('registrazione.avvia'), moduloVerifica([
        'password' => 'PasswordNuova!2',
        'password_confirmation' => 'PasswordNuova!2',
    ]))->assertRedirect();

    $riga = Registrazione::query()->sole();
    $link = linkSpediti($riga);

    $this->get(end($link))->assertRedirect();

    expect($riga->fresh()->emailVerificata())->toBeTrue()
        ->and(Hash::check('PasswordNuova!2', $riga->fresh()->password_hash))->toBeTrue();
});

it('refuses a validly signed link that carries no fingerprint at all', function () {
    $riga = Registrazione::factory()->create();

    $senzaImpronta = URL::temporarySignedRoute('registrazione.verifica', now()->addHour(), ['registrazione' => $riga->getKey()]);

    $this->get($senzaImpronta)->assertForbidden();

    expect($riga->fresh()->emailVerificata())->toBeFalse();
});

it('answers the same status to an unsigned probe whether the registration exists or not', function () {
    // 🔴 La firma cade prima del binding (priority di `ValidateSignature` in
    // `bootstrap/app.php`): un id inesistente dà 403 come uno esistente, non un
    // 404 che direbbe quali righe ci sono (caccia T3, A6).
    $esistente = Registrazione::factory()->create();

    $suEsistente = $this->get("/registrazione/{$esistente->id}/verifica")->status();
    $suInesistente = $this->get('/registrazione/999999/verifica')->status();

    expect($suInesistente)->toBe(403)
        ->and($suEsistente)->toBe(403);
});
