<!DOCTYPE html>
<html lang="it" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'Easy Lab') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-neutral-50 font-sans text-neutral-800 antialiased">
    {{ $slot }}
</body>
</html>
