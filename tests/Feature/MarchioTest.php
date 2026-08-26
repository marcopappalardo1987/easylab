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
 *
 * 🌙 **Dal 26 Ago 2026 i file sono quattro**, non due: su fondo scuro il marchio
 * ha una propria variante col blu profondo alzato a `primary-200` (ADR-034,
 * DS §8.2). ⚠️ E il guasto raddoppia con loro — anzi peggiora: un file scuro
 * mancante non si vede **nemmeno guardando la pagina**, perché in tema chiaro
 * quell'immagine è `display:none`. Sarebbe visibile solo a chi usa il tema
 * scuro, cioè forse a nessuno di chi ha scritto il codice.
 */
it('serves both logo variants from public', function (string $file) {
    expect(public_path('brand/'.$file))->toBeFile();
})->with([
    'easylab-logo.svg', 'easylab-logo-compatto.svg',
    // 🌙 Le varianti per fondo scuro (ADR-034): il blu profondo si ALZA a
    //    `primary-200`, perché su `--surface` scuro fa 1,9:1 e sparisce.
    'easylab-logo-scuro.svg', 'easylab-logo-compatto-scuro.svg',
]);

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

it('renders both the light and the dark mark, so the swap has something to swap', function () {
    // ⚠️ Lo scambio avviene in CSS, sugli stessi tre selettori del tema: la
    // pagina rende **entrambe** le immagini e ne nasconde una. Se il componente
    // ne rendesse una sola, il tema scuro mostrerebbe il marchio sbagliato senza
    // dare alcun errore — e in tema chiaro, cioè in sviluppo, sarebbe invisibile.
    $utente = User::factory()->create(['two_factor_confirmed_at' => now()]);

    $html = $this->actingAs($utente)->get('/dashboard')->assertOk()->getContent();

    expect(substr_count($html, 'data-marchio="chiaro"'))->toBe(1)
        ->and(substr_count($html, 'data-marchio="scuro"'))->toBe(1)
        ->and($html)->toContain('brand/easylab-logo-compatto-scuro.svg');
});

it('gives the dark mark the same alt, because a hidden image is not announced', function () {
    // Due immagini con lo stesso `alt` non fanno annunciare il marchio due volte:
    // quella nascosta con `display:none` **esce dall'albero di accessibilità**.
    // Metterne una con `alt=""` avrebbe invece lasciato il marchio muto proprio
    // nel tema in cui quella è l'immagine visibile.
    // ⚠️ **I commenti Blade si tolgono prima di guardare**, ed è una lezione già
    // pagata da questo progetto (`PaletteGuardrailTest`, guardrail della
    // copertura audit): il docblock qui sopra *spiega* perché non si usa
    // `alt=""`, e un meta-test che legge il testo invece del codice punisce chi
    // documenta. Verificato: senza questa riga il test è rosso sul proprio
    // commento.
    $sorgente = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents(
        resource_path('views/components/brand-logo.blade.php')
    ));

    expect(substr_count($sorgente, 'alt="{{ $alt }}"'))->toBe(2)
        ->and($sorgente)->not->toContain('alt=""');
});
