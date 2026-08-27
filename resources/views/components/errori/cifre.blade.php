{{--
    🔴 **Le due cifre di una issue, nella stessa frase.**

    `errori.occorrenze` conta **tutti** gli avvenimenti; `errori.contesti`
    quante prove se ne sono conservate — al più `contesti_per_errore` (venti),
    non più di una ogni `finestra_contesto_secondi`. Sono due numeri diversi che
    rispondono a due domande diverse, e la seconda è quasi sempre molto più
    piccola della prima.

    ⚠️ **Stampare la prima da sola manda a cercare diecimila righe di dettaglio
    che non esistono.** Da cui questo componente, che è l'unico posto del
    progetto in cui quelle due cifre si scrivono: elenco e scheda lo includono
    entrambi, quindi non c'è modo di dire l'una dimenticando l'altra in una
    delle due pagine e non nell'altra. Non è una comodità di riuso — è la
    ragione per cui il componente esiste invece di due righe di Blade.

    Il separatore è un punto mediano e non una virgola: la frase è **una**, non
    un elenco di due voci fra cui scegliere.

    ⚠️ **Nessun colore, ed è deliberato** (F2.4 del restyling, DS §8.2): il
    componente porta la sola `tabular-nums` — le `font-variant-numeric` di
    `.el-num` nel campione, obbligatorie su ogni cifra (DS §3) — e **eredita**
    la tinta da chi lo include (la cella dell'elenco e il paragrafo della
    scheda, `text-neutral-700` finché F5 non li porta a `text-ink-2`).
    Ereditare è ciò che lo rende già corretto nei due temi senza
    possedere un token proprio; scrivergli addosso un `text-ink-2` «per
    coerenza» lo renderebbe **immune** al contesto, cioè romperebbe l'unico
    caso che conta: la stessa frase dentro una riga di tabella e dentro un
    paragrafo, che non hanno lo stesso grigio.

    ⚠️ Tutto su una riga sola di sorgente, e non è disattenzione: Blade
    conserverebbe gli a-capo dentro il testo, e la frase su cui i test
    asseriscono non sarebbe più contigua nell'HTML.
--}}
@props(['errore'])

<span {{ $attributes->merge(['class' => 'tabular-nums']) }}>occorrenze: {{ number_format($errore->occorrenze, 0, ',', '.') }} · prove raccolte dall'ultima riapertura: {{ number_format($errore->contesti, 0, ',', '.') }}</span>
