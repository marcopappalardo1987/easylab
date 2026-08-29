<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * 🔴 **Un colore di bordo senza una larghezza non disegna niente.**
 *
 * `border-border-strong` dice di che COLORE è il bordo; la larghezza la dà
 * `border` (o `border-2`, `border-x`…). Da sole, quelle classi non generano
 * nessuna riga visibile — e non danno errore: danno un campo senza contorno,
 * su una pagina che risponde 200.
 *
 * Segnalato da Marco il 29 Ago 2026 sui filtri dell'archivio documenti («non è
 * in stile come gli altri»), e la stessa forma era su altre cinque viste — fra
 * cui il registro di audit e la schermata errori, cioè due pagine della
 * piattaforma. Diciotto occorrenze in tutto: nessuna rotta, tutte invisibili.
 *
 * ⚠️ **Si guardano i soli attributi `class="…"`**, e non il file intero: le
 * varianti di `x-ui.button` vivono in un array PHP dove la larghezza è messa
 * dalla stringa `$base`, e un controllo sul testo grezzo le direbbe colpevoli
 * mentre sono sane. È la stessa disciplina del guardrail delle tendine —
 * misurare il costrutto giusto, o la rete boccia codice buono e viene disattivata.
 */
it('never colours a border without also giving it a width', function () {
    $viste = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($f) => Str::endsWith($f->getFilename(), '.blade.php'));

    expect($viste)->not->toBeEmpty();

    // Le utility che una larghezza la danno davvero.
    $daLarghezza = '/\bborder(-[0248]|-[xytrbl](-[0248])?)?(\s|$)/';

    $colpevoli = $viste
        ->flatMap(function ($file) use ($daLarghezza) {
            $sorgente = (string) preg_replace('/\{\{--.*?--\}\}/s', '', File::get($file->getPathname()));

            preg_match_all('/class="([^"]*)"/', $sorgente, $attributi);

            return collect($attributi[1] ?? [])
                ->filter(fn (string $classi) => str_contains($classi, 'border-border')
                    && preg_match($daLarghezza, $classi) !== 1)
                ->map(fn (string $classi) => Str::after($file->getPathname(), resource_path('views/'))
                    .' → '.Str::limit($classi, 90))
                ->all();
        })
        ->values()
        ->all();

    expect($colpevoli)->toBe([], implode("\n", array_merge(
        ['Bordi con un colore ma senza larghezza (non disegnano nulla, e la pagina risponde 200):'],
        $colpevoli,
        ['', 'Rimedio: aggiungere `border` accanto al colore.'],
    )));
});
