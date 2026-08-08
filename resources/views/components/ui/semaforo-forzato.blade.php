@props(['strumento'])

{{--
    Pill "forzato" (Design System §4): elemento SEPARATO accanto al semaforo,
    non una variante del pallino — così x-ui.semaforo resta legato al solo enum
    e questa sa di Strumento. Wireframe §1/§2: «■ Non idoneo ⚑».

    Il tooltip nativo porta chi/quando/perché (ADR-005). Il "badge cliccabile"
    del wireframe, con pannello di dettaglio, è rimandato.
--}}
@if ($strumento->forced_state !== null)
    @php
        $dettaglio = 'Forzato da '.($strumento->forcedBy?->name ?? '—')
            .' il '.$strumento->forced_at?->format('d/m/Y')
            .($strumento->forced_reason ? ' — '.$strumento->forced_reason : '');
    @endphp

    <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full bg-warning-100 px-1.5 py-0.5 text-xs font-medium text-warning-800']) }}
        title="{{ $dettaglio }}">
        <span aria-hidden="true">⚑</span>
        <span class="sr-only">Semaforo forzato. {{ $dettaglio }}</span>
    </span>
@endif
