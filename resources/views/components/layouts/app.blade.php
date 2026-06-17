@props(['title' => null])
<!DOCTYPE html>
<html lang="it" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'Easy Lab') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    {{-- Shell minima: la dashboard reale (top bar, albero, navigazione) è il punto 10 dello Sprint 1. --}}
    <body class="h-full bg-neutral-50 font-sans text-neutral-800 antialiased">
        {{ $slot }}

        @livewireScripts
    </body>
</html>
