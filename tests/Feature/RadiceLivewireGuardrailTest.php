<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * 🔴 **Un componente Livewire ha UN solo elemento radice, e ciò che lo segue
 * non esiste.**
 *
 * Non è una convenzione di stile: è il modo in cui Livewire ritrova il proprio
 * frammento nel DOM per aggiornarlo. Tutto ciò che sta dopo la chiusura della
 * radice viene **scartato in silenzio** — nessun errore in pagina, nessuno in
 * console, nessun test rosso.
 *
 * ## Perché questa rete esiste
 *
 * Il 28 Ago 2026 la modale «Aggiungi una sede» è stata scritta dopo il `</div>`
 * di radice di `anagrafica/albero`. Il bottone chiamava l'azione, l'azione
 * metteva la property a `true`, e non si apriva nulla. L'ha trovato Marco
 * usando l'applicazione.
 *
 * ⚠️ **E il test scritto per coglierlo non lo coglieva.** Un
 * `Livewire::test(...)->call('apri')->assertSee('Nome della sede')` resta
 * VERDE: il renderer di prova restituisce tutto l'output del Blade, radice o
 * no, mentre il browser vero ne butta metà. Provato per mutazione — rimettendo
 * la modale fuori dalla radice, quel test non si accorgeva di niente. Quindi la
 * guardia non poteva vivere in un test di componente: doveva guardare il
 * SORGENTE.
 *
 * ## Cosa asserisce, e perché così
 *
 * Che ogni vista Livewire, tolti i commenti Blade, **finisca con un tag di
 * chiusura a colonna zero**. È un'euristica, non un parser HTML, e lo dice: un
 * file che finisce con `@endif` o con del testo ha quasi certamente qualcosa
 * fuori dalla radice, che è esattamente la forma del difetto. Verificata su
 * tutte e 31 le viste esistenti al momento della scrittura.
 */
it('never lets a Livewire view put anything after its root element', function () {
    $viste = collect(File::allFiles(resource_path('views/livewire')))
        ->filter(fn ($f) => Str::endsWith($f->getFilename(), '.blade.php'));

    expect($viste)->not->toBeEmpty();

    $colpevoli = $viste
        ->map(function ($file) {
            // I commenti Blade non contano: stanno fuori dalla radice senza
            // danno, e nel progetto se ne scrivono molti in coda.
            $senzaCommenti = rtrim((string) preg_replace(
                '/\{\{--.*?--\}\}/s',
                '',
                File::get($file->getPathname())
            ));

            $ultima = (string) Str::afterLast($senzaCommenti, "\n");

            return preg_match('/^<\/[a-zA-Z][\w.-]*>$/', trim($ultima)) === 1
                ? null
                : Str::after($file->getPathname(), resource_path('views/livewire/')).' → '.trim($ultima);
        })
        ->filter()
        ->values()
        ->all();

    expect($colpevoli)->toBe([], implode("\n", array_merge(
        ['Viste Livewire con qualcosa DOPO l\'elemento radice (Livewire lo scarta in silenzio):'],
        $colpevoli,
        ['', 'Rimedio: portare quel blocco DENTRO il tag di radice. Se è davvero fuori apposta, non può esserlo: un componente ha una sola radice.'],
    )));
});
