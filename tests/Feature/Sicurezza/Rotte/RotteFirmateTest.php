<?php

use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\URL;

/**
 * Le porte che si aprono con una firma invece che con una sessione (security
 * pass S7, T1b — 🔗 ADR-003 QR, ADR-012 invito, ADR-013 webhook).
 *
 * I casi base (firma assente, scaduta, invito già usato, QR di un altro Ente)
 * hanno già i loro test in `QrStrumentoTest`, `InvitoUtenteTest`,
 * `VerificaEmailRegistrazioneTest` e `StripeWebhookTest`. Qui ci sono i
 * trapianti: una firma VERA spostata su un'altra risorsa o su un altro corpo.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);
    $this->autoclave = Strumento::factory()->forNode($dip)->create(['nome' => 'Autoclave']);
    $this->centrifuga = Strumento::factory()->forNode($dip)->create(['nome' => 'Centrifuga']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

// --- QR ---

it('refuses the signature of one qr moved onto the token of another machine', function () {
    $firmato = URL::signedRoute('qr.strumento', ['token' => $this->autoclave->qr_token]);
    $trapiantato = str_replace($this->autoclave->qr_token, $this->centrifuga->qr_token, $firmato);

    expect($trapiantato)->not->toBe($firmato);

    $this->actingAs($this->admin)->get($trapiantato)->assertForbidden();
});

it('refuses a signed qr with a parameter added after the signature', function () {
    $firmato = URL::signedRoute('qr.strumento', ['token' => $this->autoclave->qr_token]);

    $this->actingAs($this->admin)->get($firmato.'&torna=https://esterno.example')->assertForbidden();
});

it('refuses a forged qr before the login, without saying anything about the machine', function () {
    // `ValidateSignature` sta davanti ad `AuthenticatesRequests` nella priority
    // list (bootstrap/app.php): la firma falsa cade PRIMA di auth, quindi niente
    // redirect al login con un `intended` costruito da chi ha forgiato l'URL.
    $falso = route('qr.strumento', ['token' => $this->autoclave->qr_token]).'?signature=inventata';

    $risposta = $this->get($falso);

    $risposta->assertForbidden();
    expect($risposta->headers->get('Location'))->toBeNull()
        ->and($risposta->getContent())->not->toContain(route('strumenti.show', $this->autoclave))
        ->and($risposta->getContent())->not->toContain('Autoclave');
    $this->assertGuest();
});

it('still sends a logged-out visitor with a genuine qr to the login', function () {
    $firmato = URL::signedRoute('qr.strumento', ['token' => $this->autoclave->qr_token]);

    $this->get($firmato)->assertRedirect(route('login'));
});

// --- Invito ---

it('refuses the signature of one invite moved onto another user', function () {
    $mario = User::factory()->unverified()->create();
    $luigi = User::factory()->unverified()->create();

    $firmato = URL::temporarySignedRoute('invito.mostra', now()->addDay(), ['user' => $mario->id]);
    $trapiantato = str_replace("/invito/{$mario->id}?", "/invito/{$luigi->id}?", $firmato);

    expect($trapiantato)->not->toBe($firmato);

    $this->get($trapiantato)->assertForbidden();
    $this->post($trapiantato, ['password' => 'Nuova-password-123', 'password_confirmation' => 'Nuova-password-123'])->assertForbidden();

    expect($luigi->fresh()->email_verified_at)->toBeNull();
});

it('refuses an invite whose expiry was pushed forward by hand', function () {
    $invitato = User::factory()->unverified()->create();
    $firmato = URL::temporarySignedRoute('invito.mostra', now()->subMinute(), ['user' => $invitato->id]);
    $scadenza = now()->subMinute()->getTimestamp();
    $allungato = str_replace("expires={$scadenza}", 'expires='.now()->addYear()->getTimestamp(), $firmato);

    expect($allungato)->not->toBe($firmato);

    $this->get($allungato)->assertForbidden();
});

// --- Webhook Stripe ---

it('refuses a real stripe signature pasted onto a different body', function () {
    config(['cashier.webhook.secret' => 'whsec_trapianto']);
    $t = time();
    $originale = json_encode(['id' => 'evt_1', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['customer' => 'cus_a']]]);
    $firma = hash_hmac('sha256', "{$t}.{$originale}", 'whsec_trapianto');
    $altro = json_encode(['id' => 'evt_1', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['customer' => 'cus_b']]]);

    $this->call('POST', '/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$firma}",
    ], $altro)->assertForbidden();
});

it('never reflects the query string on the public payment landing page', function () {
    $this->get('/pagamento/ricevuto?session_id=<script>alert(1)</script>&email=vittima@esterno.test')
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('vittima@esterno.test');
});
