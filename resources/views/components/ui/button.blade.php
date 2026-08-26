@props([
    'variant' => 'primary',
    'href' => null,
])

{{-- Le quattro varianti di DS §5.1 sui token semantici (DS §8.2), nella forma
     del campione `.el-btn` — 🔗 `docs/Design/design-system.html`.

     Tre scelte non ovvie, scritte perché non si rifacciano al contrario:

     1. il **secondario** porta il bordo FORTE (`border-border-strong`, il
        contorno dei campi) e non quello dei divisori: è il bordo che lo tiene
        distinto dalla superficie su cui sta, in entrambi i temi;
     2. la **larghezza** del bordo sta nella base e il **colore** in ogni
        variante. La larghezza perché senza il secondario sarebbe 2px più alto
        degli altri e una fila «Annulla · Salva» non si allineerebbe; il colore
        perché due utility di colore sul bordo nella stessa classe non si
        risolvono nell'ordine in cui sono scritte, ma in quello del foglio
        generato — verificato: il trasparente vinceva, e il secondario restava
        senza contorno;
     3. l'anello di fuoco è **un token solo** (`ring-ring`, DS §8.2 nota 3),
        anche sul pericolo. ⚠️ Il colore dello *stacco* dell'anello va dichiarato:
        il suo valore predefinito è il bianco, che in tema scuro disegnerebbe un
        alone chiaro attorno a ogni bottone messo a fuoco. --}}
@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-md border px-4 py-2.5 text-sm font-medium transition focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-canvas disabled:opacity-60 disabled:cursor-not-allowed';
    $variants = [
        'primary' => 'border-transparent bg-brand text-brand-ink hover:bg-brand-hover',
        'secondary' => 'border-border-strong bg-surface text-ink hover:bg-surface-sunken',
        // Il campione scurisce il pericolo con un filtro invece che con un
        // secondo gradino: `--bad-dot` è l'unico token del rosso pieno, e nei
        // due temi parte da due valori diversi.
        'danger' => 'border-transparent bg-bad-dot text-ink-inverse hover:brightness-90',
        'ghost' => 'border-transparent text-ink-2 hover:bg-surface-sunken hover:text-ink',
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
