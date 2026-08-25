@props([
    'label' => null,
    'valore',
    'dettaglio' => null,
])

{{--
    Riquadro di un numero aggregato (Design System §5.2 — «KPI card: numero
    text-3xl font-semibold, label text-sm text-neutral-600»).

    Costruito sopra `x-ui.card` invece di ripeterne le classi: la superficie è
    una decisione sola, e duplicarla qui vorrebbe dire che il giorno in cui
    cambia il bordo delle card questa resta indietro.

    La **formattazione degli interi vive qui**, non nei quattro call site: senza,
    «Strumenti 5.105» e «Sedi 7» convivrebbero già oggi con due convenzioni
    diverse sulla stessa riga, e il giorno dei 1.234 Enti si vedrebbe. Chi ha un
    simbolo da aggiungere passa una stringa già composta.

    ⚠️ **`label` accetta anche uno SLOT**, e serve a una cosa sola: la dashboard
    per ruolo (S6) mette lì `x-ui.semaforo`, perché la tripletta del Design
    System §4 è colore **+ forma + etichetta** e un riquadro che dicesse solo
    «Azione richiesta» in grigio perderebbe le prime due. Passando invece la
    parola come prop **e** il pallino accanto, l'etichetta comparirebbe due
    volte nel testo della pagina — una visibile e una in `sr-only` — e ogni
    asserzione su quel testo diventerebbe ambigua.

    `$dettaglio` è la riga piccola sotto il numero — «di cui 2 🔒», «di cui 3
    SaaS» — e sta qui invece che in un secondo componente perché un numero senza
    il suo contesto è la cosa che poi viene citata in una riunione da sola.
--}}
<x-ui.card {{ $attributes }}>
    <p class="text-sm text-neutral-600">{{ $label ?? $slot }}</p>

    <p class="mt-1 text-3xl font-semibold tracking-tight text-neutral-900">{{ is_int($valore) ? number_format($valore, 0, ',', '.') : $valore }}</p>

    @if ($dettaglio)
        <p class="mt-1 text-xs text-neutral-500">{{ $dettaglio }}</p>
    @endif
</x-ui.card>
