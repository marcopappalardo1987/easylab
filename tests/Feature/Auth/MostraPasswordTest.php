<?php

/**
 * Il pulsante che mostra la password in chiaro (🔗 `x-ui.input` prop `rivelabile`,
 * DS §5.1 e §5.6).
 *
 * ⚠️ **Questo file prova il MARKUP, non il comportamento**, e va detto subito:
 * lo scambio `password ↔ text` vive in `resources/js/app.js` e nessun test di
 * questa suite esegue JavaScript. Ciò che qui si verifica è che il pulsante
 * esista, sia collegato al campo giusto e nasca nello stato corretto — cioè le
 * premesse senza le quali quel JavaScript non potrebbe funzionare.
 *
 * 🔴 **Resta scoperto, e si verifica a mano** (elencato per nome, come fa
 * `ComboboxRicambiTest`):
 *  - il click che cambia davvero `type` e scambia le due icone;
 *  - `aria-pressed` e `aria-label` che si aggiornano;
 *  - il pulsante che **compare** solo quando il JavaScript gira;
 *  - i 44×44px reali del bersaglio;
 *  - il fuoco che **resta** sul pulsante invece di saltare al campo.
 */

use App\Models\User;

it('offers the reveal button on the sign-in password, and only there', function () {
    $html = $this->get(route('login'))->assertOk()->getContent();

    expect($html)->toContain('data-mostra-password')
        // Il pulsante governa il campo password, non un altro: `aria-controls`
        // deve puntare all'`id` vero, o il JavaScript non troverebbe nulla.
        ->and($html)->toContain('aria-controls="password"');

    // ⚠️ Uno solo. Un secondo pulsante con lo stesso `aria-controls` vorrebbe
    // dire due comandi per lo stesso campo, con gli stati che divergono.
    expect(substr_count($html, 'data-mostra-password'))->toBe(1);
});

it('never turns the email field into a revealable one', function () {
    // La prop è **opt-in**: se diventasse il default, ogni campo di testo
    // dell'applicazione si ritroverebbe un pulsante che non ha senso.
    $html = $this->get(route('login'))->assertOk()->getContent();

    expect($html)->not->toContain('aria-controls="email"');
});

it('starts hidden and unpressed, because without JavaScript it would be a dead control', function () {
    // 🔴 Le due metà di questo test sono la ragione per cui il pulsante è fatto
    // così: nasce `hidden` (lo rivela `app.js`, quindi senza JS non compare
    // affatto invece di restare lì inerte) e nasce `aria-pressed="false"`
    // perché il campo nasce oscurato.
    $bottone = bottoneMostraPassword($this->get(route('login'))->assertOk()->getContent());

    expect($bottone)->toContain('hidden')
        ->and($bottone)->toContain('aria-pressed="false"')
        ->and($bottone)->toContain('aria-label="Mostra la password"')
        // `type="button"`: senza, dentro un `<form>` il pulsante **invierebbe le
        // credenziali** al primo click. È il difetto più caro di questo markup.
        ->and($bottone)->toContain('type="button"');
});

it('uses stroke icons and not a glyph, so the colour follows the token', function () {
    // ⚠️ Un `👁` avrebbe presentazione **emoji** su macOS e ignorerebbe
    // `currentColor`: è già successo con `☀ ☾` nel selettore di tema e con `⏳`
    // nel badge obsoleto, entrambi misurati campionando il pixel reso.
    $bottone = bottoneMostraPassword($this->get(route('login'))->assertOk()->getContent());

    expect($bottone)->toContain('stroke="currentColor"')
        // Due icone: una visibile e una `hidden`, scambiate dal JavaScript.
        ->and(substr_count($bottone, 'data-icona='))->toBe(2)
        ->and($bottone)->toContain('data-icona="mostra"')
        ->and($bottone)->toContain('data-icona="nascondi"');
});

it('keeps the field reachable and the form working for whoever signs in', function () {
    // Il pulsante è un'aggiunta: non deve aver cambiato il campo sotto.
    $utente = User::factory()->create(['password' => bcrypt('password-di-prova')]);

    $this->get(route('login'))->assertOk()
        ->assertSee('name="password"', false)
        ->assertSee('autocomplete="current-password"', false);

    $this->post(route('login'), [
        'email' => $utente->email,
        'password' => 'password-di-prova',
    ])->assertRedirect();

    expect(auth()->check())->toBeTrue();
});

/** Il solo `<button>` del pulsante, estratto per non asserire sull'intera pagina. */
function bottoneMostraPassword(string $html): string
{
    // ⚠️ Si estrae il **blocco** invece di fare `assertSee` sulla pagina intera:
    // in questo stesso progetto un `assertSee('Clienti')` era verde anche con la
    // voce di menù rimossa, perché quella parola compariva altrove. Un'asserzione
    // che non può fallire è peggio di nessuna asserzione.
    preg_match('/<button[^>]*data-mostra-password.*?<\/button>/s', $html, $blocco);

    expect($blocco)->not->toBeEmpty('Pulsante «mostra password» non trovato in pagina.');

    return $blocco[0];
}
