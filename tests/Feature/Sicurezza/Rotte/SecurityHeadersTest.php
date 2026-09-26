<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Gli header di sicurezza (security pass S7, T1b).
 *
 * Due blocchi: il comportamento del middleware, provato su una rotta di prova
 * che lo monta da sé, e la sua **registrazione globale**, provata sulle rotte
 * vere (`$middleware->append(SecurityHeaders::class)` in `bootstrap/app.php`):
 * è la rete che dice se l'aggancio c'è ancora.
 */
beforeEach(function () {
    Route::middleware(SecurityHeaders::class)->get('/_prova/intestazioni', fn () => 'ok');
    Route::middleware(SecurityHeaders::class)->get('/_prova/con-frame', fn () => response('ok')->header('X-Frame-Options', 'SAMEORIGIN'));
});

// --- Il middleware ---

it('sends the csp only as report-only, never enforced', function () {
    $risposta = $this->get('/_prova/intestazioni')->assertOk();

    // Una CSP applicata spegnerebbe Livewire e Alpine: deve esistere SOLO la variante report-only.
    expect($risposta->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($risposta->headers->get('Content-Security-Policy-Report-Only'))->toBe(SecurityHeaders::CSP);
});

it('forbids framing, sniffing and leaking the full url to other origins', function () {
    $this->get('/_prova/intestazioni')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('keeps the camera for the qr scanner and closes the rest', function () {
    $politica = $this->get('/_prova/intestazioni')->headers->get('Permissions-Policy');

    expect($politica)->toContain('camera=(self)')
        ->toContain('microphone=()')
        ->toContain('geolocation=()');
});

it('keeps scripts and frames to our own origin in the csp', function () {
    expect(SecurityHeaders::CSP)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->not->toContain('*');
});

it('never sends hsts on the local or testing environment', function (string $ambiente) {
    $this->app['env'] = $ambiente;

    expect($this->get('/_prova/intestazioni')->headers->has('Strict-Transport-Security'))->toBeFalse();
})->with(['local', 'testing']);

it('sends hsts on every environment reachable from the internet', function (string $ambiente) {
    $this->app['env'] = $ambiente;

    $this->get('/_prova/intestazioni')->assertHeader('Strict-Transport-Security', SecurityHeaders::HSTS);
})->with(['production', 'staging']);

it('never overwrites a header the response decided for itself', function () {
    $this->get('/_prova/con-frame')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

// --- La registrazione globale ---

it('puts the headers on the pages a guest reaches, and on the redirect that keeps a guest out', function (string $uri) {
    $this->get($uri)
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy-Report-Only', SecurityHeaders::CSP);
})->with(['/login', '/forgot-password', '/dashboard']);

it('puts the headers on an authenticated page too', function () {
    $utente = User::factory()->create();

    $this->actingAs($utente)->get('/dashboard')
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy-Report-Only', SecurityHeaders::CSP);
});

it('puts the headers on the stripe webhook too, which lives outside the web group', function () {
    config(['cashier.webhook.secret' => 'whsec_prova_intestazioni']);

    $this->postJson('/stripe/webhook', [])->assertHeader('X-Content-Type-Options', 'nosniff');
});
