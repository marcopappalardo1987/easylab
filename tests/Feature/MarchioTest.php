<?php

use App\Models\User;

/**
 * Il marchio è quello vero, e i file esistono (🔗 ADR-033).
 *
 * ⚠️ Un `<img src>` che punta a un file assente **non rompe niente**: la pagina
 * risponde 200 e mostra un rettangolo vuoto. È esattamente lo stato in cui il
 * progetto è stato per due mesi — `brand-logo.blade.php` puntava a due SVG che
 * ADR-033 dichiarava «il logo vecchio, non usato da nessuna parte» — e nessun
 * test poteva accorgersene. Questi due lo rendono un fallimento.
 */
it('serves both logo variants from public', function (string $file) {
    expect(public_path('brand/'.$file))->toBeFile();
})->with(['easylab-logo.svg', 'easylab-logo-compatto.svg']);

it('never points the brand component at a file that is not there', function () {
    // Il componente sceglie fra due percorsi: si estraggono dal sorgente invece
    // di ripeterli qui, o il test proverebbe la propria copia.
    preg_match_all("/'(brand\/[^']+)'/", file_get_contents(resource_path('views/components/brand-logo.blade.php')), $trovati);

    expect($trovati[1])->not->toBeEmpty();

    foreach ($trovati[1] as $percorso) {
        expect(public_path($percorso))->toBeFile("Il componente punta a «{$percorso}», che non esiste.");
    }
});

it('shows the compact mark in the shell and the full one when signing in', function () {
    // Nella sidebar il logo sta in 28px: il claim sarebbe alto due pixel. Sulla
    // pagina di accesso invece serve, perché è la prima superficie che vede chi
    // non conosce il prodotto.
    $utente = User::factory()->create(['two_factor_confirmed_at' => now()]);

    $this->actingAs($utente)->get('/dashboard')
        ->assertOk()
        ->assertSee('brand/easylab-logo-compatto.svg')
        ->assertDontSee('brand/easylab-logo.svg');

    $this->post(route('logout'));

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('brand/easylab-logo.svg');
});

it('keeps the page heading for whoever cannot see the logo', function () {
    // Il claim del logo è testo trasformato in **tracciati**: senza un `h1`, la
    // pagina di accesso non avrebbe alcuna intestazione per uno screen reader.
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Easy Lab — gestione strumentazione e manutenzione', false);
});
