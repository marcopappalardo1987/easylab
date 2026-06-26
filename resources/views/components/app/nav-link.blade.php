@props([
    'href' => '#',
    'active' => false,
    'disabled' => false,
])

@php
    $base = 'flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-medium transition';
    $state = $disabled
        ? 'text-neutral-400 cursor-not-allowed opacity-60'
        : ($active
            ? 'bg-primary-50 text-primary-700'
            : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900');
@endphp

@if ($disabled)
    <span {{ $attributes->merge(['class' => $base.' '.$state]) }} aria-disabled="true">
        {{ $slot }}
    </span>
@else
    <a href="{{ $href }}" @if ($active) aria-current="page" @endif
       {{ $attributes->merge(['class' => $base.' '.$state]) }}>
        {{ $slot }}
    </a>
@endif
