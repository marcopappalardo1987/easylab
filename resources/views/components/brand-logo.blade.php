@props([
    'variante' => 'compatto',
    'alt' => 'Easy Lab',
])

{{--
    Il marchio Easy Lab (🔗 ADR-033).

    Due varianti, e la ragione è la leggibilità e non la completezza:
    - **`compatto`** — il solo lettering. È il default perché quasi ovunque il
      logo sta in poche decine di pixel d'altezza (nella sidebar, 32px), e lì il
      claim «GESTIONE STRUMENTAZIONE E MANUTENZIONE» sarebbe alto due pixel:
      rumore, non informazione;
    - **`completo`** — lettering più claim, per le pagine di autenticazione e le
      superfici dove il marchio si presenta a chi non conosce ancora il prodotto.

    ⚠️ **`<img>` e non SVG inline, ed è lecito qui.** ADR-033 avverte che un
    `<img src>` **non eredita le variabili CSS della pagina**, quindi un logo che
    debba ricolorarsi va inlineato. Questo non deve: i suoi due blu (`#06589C` e
    `#2997D4`) e il gradiente sulla curva della «y» sono il marchio, non un
    token, e restano quelli su qualunque fondo. Vive su superfici chiare — la
    sidebar e le card di autenticazione sono bianche — e su un fondo scuro
    servirebbe comunque un secondo file, non una variabile.

    Il claim è **testo trasformato in tracciati**, quindi non è leggibile da uno
    screen reader: l'`alt` porta il nome, e nulla di più — ripetere il claim
    nell'alt lo farebbe annunciare a ogni pagina.
--}}
<img
    src="{{ asset($variante === 'completo' ? 'brand/easylab-logo.svg' : 'brand/easylab-logo-compatto.svg') }}"
    alt="{{ $alt }}"
    {{ $attributes->merge(['class' => 'h-8 w-auto']) }}
>
