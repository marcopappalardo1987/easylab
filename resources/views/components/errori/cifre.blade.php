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

    ⚠️ Tutto su una riga sola di sorgente, e non è disattenzione: Blade
    conserverebbe gli a-capo dentro il testo, e la frase su cui i test
    asseriscono non sarebbe più contigua nell'HTML.
--}}
@props(['errore'])

<span {{ $attributes->merge(['class' => 'tabular-nums']) }}>occorrenze: {{ number_format($errore->occorrenze, 0, ',', '.') }} · contesti conservati: {{ number_format($errore->contesti, 0, ',', '.') }}</span>
