@props([
    'variant' => 'neutral',
])

@php
    $variants = [
        'neutral' => 'bg-neutral-100 text-neutral-600',
        'success' => 'bg-success-100 text-success-600',
        'warning' => 'bg-warning-100 text-warning-800',
        'danger' => 'bg-danger-100 text-danger-600',
        'info' => 'bg-info-500/10 text-info-500',
        'primary' => 'bg-primary-50 text-primary-700',
    ];
    $classes = 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium '.($variants[$variant] ?? $variants['neutral']);
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</span>
