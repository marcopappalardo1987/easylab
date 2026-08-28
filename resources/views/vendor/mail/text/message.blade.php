@props(['marchio' => null, 'logo' => null])
@php
    // Stesso fail-closed della resa HTML: in mancanza di marchio si scrive
    // «Easy Lab», mai il tenant corrente (ADR-018).
    $marchio ??= \App\Support\Mail\MarchioEmail::piattaforma();
@endphp
<x-mail::layout>
{{-- Testata: qui il logo NON entra — nel corpo testuale un'immagine non esiste,
     e il nome dell'Ente da solo è tutta la testata di cui c'è bisogno. --}}
<x-slot:header>
<x-mail::header>{{ $marchio->nome }}</x-mail::header>
</x-slot:header>

{{-- Corpo --}}
{{ $slot }}

{{-- Sottotesto --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Piè di pagina --}}
<x-slot:footer>
<x-mail::footer />
</x-slot:footer>
</x-mail::layout>
