@props([
    'variant' => 'neutral',
])

{{-- Le sei varianti sui token semantici — 🔗 DS §5.4, §8.2, campione
     `.el-badge`. Due scelte non ovvie:

     · **`neutral` porta un filo di bordo interno.** La superficie incassata su
       una card è quasi dello stesso colore della card, in entrambi i temi: senza
       quel filo la pill neutra smetterebbe di essere una pill. È ciò che fa il
       campione (`box-shadow: inset 0 0 0 1px var(--border)`), e non cambia
       l'ingombro come farebbe un bordo vero.

     · **`info` e `primary` sono due gradini dello STESSO blu, non due blu.**
       🔗 ADR-033: col marchio blu un secondo blu informativo sarebbe «simile
       senza essere uguale», il caso peggiore — chi guarda non sa se la
       differenza voglia dire qualcosa. `info` prende la superficie brand tenue
       (il ruolo che DS §8.2 chiama «alert info»), `primary` quella piena del
       campione. --}}
@php
    $variants = [
        'neutral' => 'bg-surface-sunken text-ink-2 inset-ring inset-ring-border',
        'success' => 'bg-ok-soft text-ok-soft-ink',
        'warning' => 'bg-warn-soft text-warn-soft-ink',
        'danger' => 'bg-bad-soft text-bad-soft-ink',
        'info' => 'bg-brand-soft text-brand-soft-ink',
        'primary' => 'bg-brand-soft-strong text-brand-soft-ink',
    ];
    $classes = 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium '.($variants[$variant] ?? $variants['neutral']);
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</span>
