@props([
    'stato',
    'size' => 'sm',
    'label' => false,
])

@php
    use App\Enums\StatoSemaforo;

    // Tripletta del Design System §4: colore + FORMA + etichetta, mai il solo
    // colore (WCAG). A 8px un box non distinguerebbe la forma: il glifo sì.
    $mappa = [
        StatoSemaforo::Verde->value => ['text-success-500', '●', 'In regola'],
        StatoSemaforo::Arancione->value => ['text-warning-500', '◐', 'Azione richiesta'],
        StatoSemaforo::Rosso->value => ['text-danger-500', '■', 'Non idoneo'],
    ];
    [$colore, $simbolo, $etichetta] = $mappa[$stato->value];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5']) }} title="{{ $etichetta }}">
    <span aria-hidden="true" class="{{ $colore }} {{ $size === 'md' ? 'dot-md' : 'dot-sm' }}">{{ $simbolo }}</span>
    @if ($label)
        <span class="text-sm font-medium text-neutral-800">{{ $etichetta }}</span>
    @else
        <span class="sr-only">{{ $etichetta }}</span>
    @endif
</span>
