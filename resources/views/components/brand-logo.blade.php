@props([
    'mark' => false,
    'alt' => 'Easy Lab',
])

<img
    src="{{ asset($mark ? 'brand/easy-lab-mark-continuity.svg' : 'brand/easy-lab-continuity.svg') }}"
    alt="{{ $alt }}"
    {{ $attributes }}
>
