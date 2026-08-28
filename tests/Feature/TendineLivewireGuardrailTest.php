<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * 🔴 **In un componente Livewire, una tendina che si riempie con un giro sul
 * server non può tenere il proprio stato in Alpine.**
 *
 * Il gesto è: click → `$wire.apri()` → il server calcola l'elenco → Livewire
 * **ridisegna il frammento**. Uno stato tenuto in `x-data="{ open: false }"`
 * riparte da capo proprio in quel momento, quindi il pannello si richiude
 * nell'istante in cui arrivano i dati per riempirlo. Non dà nessun errore: la
 * console resta pulita e il click sembra non fare niente.
 *
 * Trovato il 28 Ago 2026 sullo switcher delle sedi, segnalato da Marco. La
 * campanella aveva la stessa forma ed è stata allineata nello stesso giro.
 *
 * ⚠️ **Non vale per il Blade fuori da Livewire**: il menù utente in
 * `layouts/app.blade.php` usa `x-data="{ open: false }"` ed è corretto, perché
 * lì non c'è nessuna chiamata al server e quindi nessun ridisegno a cui
 * sopravvivere. La differenza non è di stile: è se esiste o no un `$wire`.
 *
 * ⚠️ **Questa rete guarda il SORGENTE, e lo dichiara.** Il comportamento vero è
 * nel browser, dove la suite non arriva: nessun test PHP può accorgersi che un
 * pannello si richiude da solo. Quello che si può congelare è la regola che lo
 * evita.
 */
it('never lets a Livewire dropdown keep its open state in Alpine instead of the server', function () {
    $viste = collect(File::allFiles(resource_path('views/livewire')))
        ->filter(fn ($f) => Str::endsWith($f->getFilename(), '.blade.php'));

    expect($viste)->not->toBeEmpty();

    $colpevoli = $viste
        ->map(function ($file) {
            $sorgente = (string) preg_replace(
                '/\{\{--.*?--\}\}/s',
                '',
                File::get($file->getPathname())
            );

            // Il difetto esiste solo dove le due cose convivono: uno stato
            // locale di Alpine E una chiamata al server che ridisegna.
            $statoLocale = str_contains($sorgente, 'x-data="{ open');
            $giroSulServer = str_contains($sorgente, '$wire.');

            return $statoLocale && $giroSulServer
                ? Str::after($file->getPathname(), resource_path('views/livewire/'))
                : null;
        })
        ->filter()
        ->values()
        ->all();

    expect($colpevoli)->toBe([], implode("\n", array_merge(
        ['Tendine Livewire con lo stato in Alpine E una chiamata al server:'],
        $colpevoli,
        ['', 'Il pannello si richiuderà da solo appena il server risponde, senza errori in console.'],
        ['Rimedio: guidare `x-show` dalla property Livewire (`x-show="$wire.aperto"`) invece che da un flag di `x-data`.'],
    )));
});
