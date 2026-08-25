@props([
    'href' => '#',
    'active' => false,
])

{{--
    Una voce della sidebar.

    ⚠️ **La prop `disabled` è stata rimossa il 25 Ago 2026 insieme al suo unico
    chiamante**, la voce «Prossimamente → Interventi». Esisteva per rendere una
    promessa, ed era la stessa bugia della card tolta dalla dashboard quattro
    giorni prima: un menù che mostra una pagina non cliccabile dice che qualcuno
    la sta scrivendo. Una voce di navigazione o porta da qualche parte, o non è
    una voce di navigazione — e ciò che manca si dichiara in roadmap, dove chi
    pianifica lo legge, non nel menù di chi lavora.
--}}
@php
    $base = 'flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-medium transition';
    $state = $active
        ? 'bg-primary-50 text-primary-700'
        : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900';
@endphp

<a href="{{ $href }}" @if ($active) aria-current="page" @endif
   {{ $attributes->merge(['class' => $base.' '.$state]) }}>
    {{ $slot }}
</a>
