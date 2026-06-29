@props([
    'variant' => 'primary',
    'href' => null,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-md px-4 py-2.5 text-sm font-medium transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-60 disabled:cursor-not-allowed';
    $variants = [
        'primary' => 'bg-primary-600 text-white hover:bg-primary-700 focus:ring-primary-600',
        'secondary' => 'border border-neutral-200 bg-white text-neutral-800 hover:bg-neutral-50 focus:ring-primary-600',
        'danger' => 'bg-danger-500 text-white hover:bg-danger-600 focus:ring-danger-500',
        'ghost' => 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900 focus:ring-primary-600',
    ];
    $classes = $base.' '.($variants[$variant] ?? $variants['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $classes]) }}>
        {{ $slot }}
    </button>
@endif
