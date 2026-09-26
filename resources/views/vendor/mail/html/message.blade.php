@props(['marchio' => null, 'logo' => null])
@php
    // 🔴 **Il default è Easy Lab, non il tenant corrente.** Una vista email che
    // dimenticasse di passare `:marchio` manda un'email NON brandizzata — un
    // difetto estetico. Il default opposto («leggi il tenant di chi è loggato»)
    // manderebbe l'email di un cliente col marchio di un altro, cioè una fuga
    // fra tenant. È il fail-closed di ADR-018 portato sulla posta.
    $marchio ??= \App\Support\Mail\MarchioEmail::piattaforma();
@endphp
<x-mail::layout>
{{-- Testata --}}
<x-slot:header>
<x-mail::header :url="config('app.url')" :nome="$marchio->nome" :logo="$logo" :colore="$marchio->colore" />
</x-slot:header>

{{-- Corpo --}}
{!! $slot !!}

{{-- Sottotesto --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Piè di pagina --}}
<x-slot:footer>
<x-mail::footer />
</x-slot:footer>
</x-mail::layout>
