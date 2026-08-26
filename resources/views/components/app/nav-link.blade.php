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

    🔴 **L'hover EMERGE invece di affondare, e non è un gusto: è una
    conseguenza.** Nel campione (`.el-side`) la sidebar è una superficie
    **incassata** — `background:var(--surface-sunken)` — mentre la superficie
    piena è il contenuto. Su un fondo già incassato l'hover di prima
    (`hover:bg-neutral-100`, cioè *più scuro del fondo*) sparirebbe: il campione
    scrive infatti `.el-navlink:hover{background:var(--surface)}`, che sale di
    un gradino. ⚠️ Finché **F3** non ridipinge `layouts/app.blade.php` la
    sidebar è ancora `bg-white`, quindi qui l'hover coincide col fondo e **non
    si vede**: è atteso, e si chiude con quel task, non correggendo questa riga.

    ⚠️ **La voce attiva non si distingue solo per colore** (DS §1, la stessa
    regola del semaforo): porta `aria-current="page"` per chi legge con uno
    screen reader, il **grassetto** per chi non distingue le tinte, e uno
    **sfondo pieno** per tutti gli altri. Il gradino è `brand-soft-strong` — il
    più marcato dei tenui, riservato da DS §8.2 proprio alla voce di menù
    attiva — e non `brand-soft`, che è della riga selezionata e dell'alert info.

    ⚠️ Il peso del carattere vive **solo** nei due rami di `$state` e mai nella
    base: `font-medium` e `font-semibold` sono la stessa proprietà, e a decidere
    quale vince non è l'ordine nell'attributo `class` ma l'ordine nel foglio di
    stile generato. Due pesi sulla stessa voce sarebbero una scommessa.
--}}
@php
    $base = 'flex items-center gap-3 rounded-md px-3 py-2.5 text-sm transition';
    $state = $active
        ? 'bg-brand-soft-strong font-semibold text-brand-soft-ink'
        : 'font-medium text-ink-2 hover:bg-surface hover:text-ink';
@endphp

<a href="{{ $href }}" @if ($active) aria-current="page" @endif
   {{ $attributes->merge(['class' => $base.' '.$state]) }}>
    {{ $slot }}
</a>
