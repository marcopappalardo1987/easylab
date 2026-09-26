<?php

use Illuminate\Support\Facades\File;

/**
 * 🔴 **Il pannello dello switcher non deve stare in un antenato che lo ritaglia.**
 *
 * Costato tre correzioni sbagliate il 28 Ago 2026, tutte su ipotesi plausibili e
 * tutte inutili: si è cercato in Alpine, nel ridisegno di Livewire e nel
 * caricamento pigro, mentre il pannello si apriva davvero e veniva **tagliato
 * via** da un `overflow-hidden` sul contenitore della barra in alto — che è alta
 * `h-14`, mentre il pannello è `absolute` e cade sotto.
 *
 * Nessun errore in console, `x-cloak` regolarmente rimosso, le sedi presenti nel
 * DOM: tutto sembrava sano perché tutto ERA sano, tranne il ritaglio.
 *
 * La prova che ha sciolto il caso è differenziale, e vale la pena ricordarla: il
 * **menù utente** usa lo stesso identico schema e ha sempre funzionato — e sta
 * nel contenitore accanto, che `overflow-hidden` non ce l'ha.
 *
 * ⚠️ Questo test guarda il SORGENTE, come gli altri guardrail di forma: il
 * ritaglio è un fatto di CSS in un browser, dove la suite non arriva. Ciò che si
 * può congelare è la riga che lo causa.
 */
it('never clips the top bar container that holds the sede switcher', function () {
    $layout = File::get(resource_path('views/components/layouts/app.blade.php'));

    // Il contenitore di sinistra della barra: quello che ospita
    // `<livewire:tenancy.switcher-ente />`.
    preg_match(
        '/<div class="([^"]*)">\s*(?:\{\{--.*?--\}\}\s*)?<button type="button" @click="sidebarOpen = true"/s',
        $layout,
        $trovato
    );

    expect($trovato)->not->toBeEmpty(
        'Contenitore della barra in alto non trovato: se il layout è cambiato, aggiorna questo test invece di cancellarlo.'
    );

    // ⚠️ UN SOLO ago: `toContain` è variadico, e un messaggio passato come
    // secondo argomento diventerebbe un secondo ago — rendendo l'asserzione
    // negativa sempre soddisfatta. La spiegazione sta nel nome del test.
    expect($trovato[1])->not->toContain('overflow-hidden');

    // E il pannello dev'essere ancora `absolute`, o il ritaglio non sarebbe
    // nemmeno il rischio da cui ci si difende.
    expect(File::get(resource_path('views/livewire/tenancy/switcher-ente.blade.php')))
        ->toContain('absolute left-0');
});
