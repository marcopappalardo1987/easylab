<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;

/**
 * `x-ui.stat-tile` — il riquadro di un numero aggregato (Design System §5.2).
 *
 * Un file per un componente di quaranta righe esiste per una ragione sola: dal
 * 25 Ago 2026 la sua `label` può arrivare **anche da uno slot** (la dashboard
 * per ruolo ci mette `x-ui.semaforo`, perché la tripletta è colore + forma +
 * etichetta), e per permetterlo il prop è diventato opzionale. Prima era
 * obbligatorio, quindi ometterlo era un errore rumoroso; senza questa rete
 * sarebbe diventato **un `<p>` vuoto sopra un numero** — cioè «il numero senza
 * il suo contesto», la cosa che il componente esiste per impedire.
 */
it('refuses to render a number without saying what it is', function () {
    // ⚠️ `ViewException` e non `InvalidArgumentException`: Blade avvolge ciò che
    // viene lanciato dentro un template. Asserire la classe nuda avrebbe reso il
    // test rosso con la guardia **funzionante** — cioè per la ragione sbagliata,
    // dal verso opposto al solito.
    expect(fn () => Blade::render('<x-ui.stat-tile :valore="3" />'))
        ->toThrow(ViewException::class, 'x-ui.stat-tile richiede una label');
});

it('takes the label as a prop, the way the cabina has always passed it', function () {
    $html = Blade::render('<x-ui.stat-tile label="Clienti" :valore="1234" />');

    // E formatta gli interi da sé, che è l'altra ragione per cui esiste.
    expect($html)->toContain('Clienti')->toContain('1.234');
});

it('takes the label as a slot, which is what the dashboard needs', function () {
    $html = Blade::render('<x-ui.stat-tile :valore="7"><span>◐ Azione richiesta</span></x-ui.stat-tile>');

    expect($html)->toContain('◐ Azione richiesta')->toContain('7');
});
