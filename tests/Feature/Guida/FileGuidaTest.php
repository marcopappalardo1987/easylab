<?php

use App\Support\Guide\Manuale as Libreria;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * I byte di una guida: chi li ottiene, e come risponde alle richieste parziali.
 *
 * 🔴 **Il Range non è un dettaglio di protocollo: è la funzione della pagina.**
 * Cliccando un passo scritto il video salta a quell'istante, e senza `206
 * Partial Content` il browser non può cercare — o riscarica tutto o si rifiuta
 * di muoversi. È la ragione per cui esiste `ServeFileGuida` invece di due righe
 * di `Storage::response()`.
 *
 * Il video finto è 2048 byte tutti uguali: gli intervalli si verificano sulla
 * lunghezza e sulle intestazioni, che è quel che il lettore guarda.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    discoGuideVuoto();
    guidaFinta('una-guida');
});

$rotta = fn (string $slug = 'una-guida', string $pezzo = 'video') => route('guida.file', compact('slug', 'pezzo'));

// --- Chi entra ---

it('sends a guest to the login', function () use ($rotta) {
    $this->get($rotta())->assertRedirect(route('login'));
});

it('refuses the bytes to every role without the platform permission', function (string $ruolo) use ($rotta) {
    // ⚠️ Il gate qui è tanto necessario quanto quello della pagina: una rotta
    // di file lasciata aperta è il modo classico di aggirare quello dell'elenco.
    $this->actingAs(utenteConRuolo($ruolo))->get($rotta())->assertForbidden();
})->with(RUOLI_SENZA_PIATTAFORMA);

// --- Che cosa risponde ---

it('serves the whole video when no range is asked', function () use ($rotta) {
    $risposta = $this->actingAs(utenteConRuolo('Superadmin'))->get($rotta());

    $risposta->assertOk()
        ->assertHeader('Content-Type', 'video/mp4')
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Length', '2048');

    expect(strlen($risposta->streamedContent()))->toBe(2048);
});

it('serves the poster as an image', function () use ($rotta) {
    $this->actingAs(utenteConRuolo('Superadmin'))
        ->get($rotta(pezzo: 'copertina'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('answers a range with 206 and the asked bytes', function () use ($rotta) {
    $risposta = $this->actingAs(utenteConRuolo('Superadmin'))
        ->get($rotta(), ['Range' => 'bytes=0-99']);

    $risposta->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-99/2048')
        ->assertHeader('Content-Length', '100');

    expect(strlen($risposta->streamedContent()))->toBe(100);
});

it('answers an open range from the offset to the end', function () use ($rotta) {
    // È la forma che manda un lettore video quando si sposta il cursore.
    $risposta = $this->actingAs(utenteConRuolo('Superadmin'))
        ->get($rotta(), ['Range' => 'bytes=1024-']);

    $risposta->assertStatus(206)->assertHeader('Content-Range', 'bytes 1024-2047/2048');

    expect(strlen($risposta->streamedContent()))->toBe(1024);
});

it('reads a suffix range from the END of the file', function () use ($rotta) {
    // ⛔ `bytes=-50` sono gli ULTIMI cinquanta byte, non i primi. Invertirlo
    // non dà errore: manda il lettore a leggere l'inizio credendo di avere la
    // coda, e il video sembra ripartire da capo alla fine.
    $risposta = $this->actingAs(utenteConRuolo('Superadmin'))
        ->get($rotta(), ['Range' => 'bytes=-50']);

    $risposta->assertStatus(206)->assertHeader('Content-Range', 'bytes 1998-2047/2048');

    expect(strlen($risposta->streamedContent()))->toBe(50);
});

it('clamps a range that runs past the end of the file', function () use ($rotta) {
    $risposta = $this->actingAs(utenteConRuolo('Superadmin'))
        ->get($rotta(), ['Range' => 'bytes=2000-99999']);

    $risposta->assertStatus(206)->assertHeader('Content-Range', 'bytes 2000-2047/2048');

    expect(strlen($risposta->streamedContent()))->toBe(48);
});

it('ignores a range it cannot understand, instead of failing', function () use ($rotta) {
    // Rispondere l'intero file è una risposta corretta a un Range non
    // soddisfacibile, e non lascia il lettore senza niente.
    $this->actingAs(utenteConRuolo('Superadmin'))
        ->get($rotta(), ['Range' => 'righe=1-2'])
        ->assertOk()
        ->assertHeader('Content-Length', '2048');
});

// --- Che cosa non esiste ---

it('gives 404 for an unknown piece', function () {
    $this->actingAs(utenteConRuolo('Superadmin'))
        ->get('/guida/una-guida/sorgente')
        ->assertNotFound();
});

it('gives 404 for a guide that is not published', function () use ($rotta) {
    $this->actingAs(utenteConRuolo('Superadmin'))
        ->get($rotta(slug: 'mai-girata'))
        ->assertNotFound();
});

it('gives 404 for a poster that was never rendered', function () use ($rotta) {
    guidaFinta('senza-copertina', conCopertina: false);

    expect(Libreria::trova('senza-copertina'))->not->toBeNull();

    $this->actingAs(utenteConRuolo('Superadmin'))
        ->get($rotta(slug: 'senza-copertina', pezzo: 'copertina'))
        ->assertNotFound();
});
