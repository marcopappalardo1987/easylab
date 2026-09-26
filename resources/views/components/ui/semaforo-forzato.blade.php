@props(['strumento'])

{{--
    Pill "forzato" (Design System §4): elemento SEPARATO accanto al semaforo,
    non una variante del pallino — così x-ui.semaforo resta legato al solo enum
    e questa sa di Strumento. Wireframe §1/§2: «■ Non idoneo ⚑».

    Il tooltip nativo porta chi/quando/perché (ADR-005). Il "badge cliccabile"
    del wireframe, con pannello di dettaglio, è rimandato.

    I colori sono la coppia `warn-soft` / `warn-soft-ink` di DS §8.2: in tema
    chiaro valgono esattamente il `bg-warning-100 text-warning-800` che DS §4
    scrive per questa pill — cambia il gradino col tema, non il significato. Il
    glifo ⚑ e l'`sr-only` col dettaglio restano: la bandiera è la FORMA, e su un
    pill largo un dito è l'unica cosa che si distingue da un badge qualunque.
--}}
@if ($strumento->forced_state !== null)
    @php
        $dettaglio = 'Forzato da '.($strumento->forcedBy?->name ?? '—')
            .' il '.$strumento->forced_at?->format('d/m/Y')
            .($strumento->forced_reason ? ' — '.$strumento->forced_reason : '');
    @endphp

    <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full bg-warn-soft px-1.5 py-0.5 text-xs font-medium text-warn-soft-ink']) }}
        title="{{ $dettaglio }}">
        <span aria-hidden="true">⚑</span>
        <span class="sr-only">Semaforo forzato. {{ $dettaglio }}</span>
    </span>
@endif
