@props([
    'stato',
    'size' => 'sm',
    'label' => false,
])

@php
    use App\Enums\StatoSemaforo;

    // Tripletta del Design System §4: colore + FORMA + etichetta, mai il solo
    // colore (WCAG). A 8px un box non distinguerebbe la forma: il glifo sì.
    //
    // ⚠️ Col tema scuro (DS §8.2) la tripletta conta DI PIÙ, non di meno: i tre
    // gradini si alzano per il fondo scuro e la coppia peggiore — verde↔arancione
    // — scende a ΔE 6,9 (DS §2.5), sotto la soglia di sicurezza. È legittima
    // **solo** perché ogni voce porta anche il glifo e l'etichetta. Il colore
    // arriva dai token SEMANTICI (`--ok-dot`/`--warn-dot`/`--bad-dot`), che il
    // tema riscrive sotto: qui non c'è, e non va aggiunta, nessuna `dark:`.
    $mappa = [
        StatoSemaforo::Verde->value => ['text-ok-dot', '●', 'In regola'],
        StatoSemaforo::Arancione->value => ['text-warn-dot', '◐', 'Azione richiesta'],
        StatoSemaforo::Rosso->value => ['text-bad-dot', '■', 'Non idoneo'],
    ];
    [$colore, $simbolo, $etichetta] = $mappa[$stato->value];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5']) }} title="{{ $etichetta }}">
    <span aria-hidden="true" class="{{ $colore }} {{ $size === 'md' ? 'dot-md' : 'dot-sm' }}">{{ $simbolo }}</span>
    @if ($label)
        <span class="text-sm font-medium text-ink">{{ $etichetta }}</span>
    @else
        <span class="sr-only">{{ $etichetta }}</span>
    @endif
</span>
