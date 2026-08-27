@props([
    'variante' => 'compatto',
    'alt' => 'Easy Lab',
])

@php
    // ⚠️ I quattro percorsi si scrivono **per esteso** e non si compongono da un
    // prefisso: `MarchioTest` li estrae dal sorgente con una regex per
    // verificare che i file esistano davvero, e un percorso costruito a pezzi
    // gli farebbe cercare `brand/easylab-logo` senza estensione — cioè renderebbe
    // muta la guardia che esiste perché un `<img src>` verso un file assente
    // **non rompe niente**: pagina 200 e un rettangolo vuoto.
    [$chiaro, $scuro] = $variante === 'completo'
        ? ['brand/easylab-logo.svg', 'brand/easylab-logo-scuro.svg']
        : ['brand/easylab-logo-compatto.svg', 'brand/easylab-logo-compatto-scuro.svg'];
@endphp

{{--
    Il marchio Easy Lab (🔗 ADR-033/034).

    Due varianti, e la ragione è la leggibilità e non la completezza:
    - **`compatto`** — il solo lettering. È il default perché quasi ovunque il
      logo sta in poche decine di pixel d'altezza (nella sidebar, 28px), e lì il
      claim «GESTIONE STRUMENTAZIONE E MANUTENZIONE» sarebbe alto due pixel:
      rumore, non informazione;
    - **`completo`** — lettering più claim, per le pagine di autenticazione e le
      superfici dove il marchio si presenta a chi non conosce ancora il prodotto.

    ⚠️ **`<img>` e non SVG inline, ed è lecito qui.** ADR-033 avverte che un
    `<img src>` **non eredita le variabili CSS della pagina**, quindi un logo che
    debba ricolorarsi va inlineato. Questo non deve: i suoi due blu (`#06589C` e
    `#2997D4`) e il gradiente sulla curva della «y» sono il marchio, non un
    token.

    🌙 **Ma su fondo scuro il marchio non si vede**, ed è aritmetica: il blu
    profondo su `--surface` scuro `#111C2E` fa **1,9:1**. Servono quindi **due
    file** — che è esattamente ciò che ADR-033 aveva già previsto («su un fondo
    scuro servirebbe comunque un secondo file, non una variabile»). Nella
    variante scura il blu profondo **si alza** a `primary-200`; l'azzurro non
    cambia. Quale dei due si vede lo decide `app.css`, sugli stessi tre
    selettori del tema.

    ⚠️ **Le due immagini portano lo stesso `alt` di proposito.** Un'immagine
    nascosta con `display:none` esce dall'albero di accessibilità, quindi ne
    viene annunciata **una sola** — mentre metterne una con `alt=""` avrebbe
    lasciato il marchio muto proprio nel tema in cui quella è l'immagine
    visibile.

    ⛔ **Non passare una classe di `display`**: le due immagini ricevono le
    stesse classi e lo scambio avviene su `display`. Se un giorno servisse, va
    sul contenitore. Vedi il blocco «IL MARCHIO SUL FONDO SCURO» in `app.css`.

    Il claim è **testo trasformato in tracciati**, quindi non è leggibile da uno
    screen reader: l'`alt` porta il nome, e nulla di più — ripetere il claim
    nell'alt lo farebbe annunciare a ogni pagina.
--}}
<img
    data-marchio="chiaro"
    src="{{ asset($chiaro) }}"
    alt="{{ $alt }}"
    {{ $attributes->merge(['class' => 'h-8 w-auto']) }}
>
<img
    data-marchio="scuro"
    src="{{ asset($scuro) }}"
    alt="{{ $alt }}"
    {{ $attributes->merge(['class' => 'h-8 w-auto']) }}
>
