@props(['title' => null])
@php
    $user = auth()->user();
    $role = $user?->getRoleNames()->first();
    $initials = \Illuminate\Support\Str::of($user?->name ?? '')
        ->explode(' ')->filter()->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->take(2)->implode('');
@endphp
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
    <body class="h-full bg-neutral-50 font-sans text-neutral-800 antialiased">
        <div x-data="{ sidebarOpen: false }" class="min-h-full">

            {{-- Banner impersonation persistente (Design System §5.8) --}}
            @if (app('impersonate')->isImpersonating())
                <div class="flex items-center justify-between gap-3 bg-warning-500 px-4 py-2 text-sm text-neutral-900">
                    <span>Stai impersonando <strong>{{ $user->name }}</strong></span>
                    <a href="{{ route('impersonate.leave') }}"
                       class="rounded-md bg-neutral-900/10 px-3 py-1 font-medium hover:bg-neutral-900/20">
                        Esci dall'impersonation
                    </a>
                </div>
            @endif

            <div class="flex">

                {{-- Backdrop drawer mobile --}}
                <div x-show="sidebarOpen" x-cloak x-transition.opacity @click="sidebarOpen = false"
                     class="fixed inset-0 z-30 bg-neutral-900/40 md:hidden"></div>

                {{-- Sidebar (fissa su desktop, drawer su mobile) --}}
                <aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-neutral-200 bg-white transition-transform duration-200 md:static md:min-h-screen md:translate-x-0"
                       :class="sidebarOpen && 'translate-x-0'">
                    <div class="flex h-14 items-center gap-2 border-b border-neutral-200 px-4">
                        <span class="flex h-8 w-8 items-center justify-center rounded-md bg-primary-50 text-primary-600">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" />
                            </svg>
                        </span>
                        <span class="text-lg font-bold tracking-tight text-neutral-900">Easy Lab</span>
                    </div>

                    <nav class="flex-1 space-y-1 p-3">
                        <x-app.nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                            <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                            Dashboard
                        </x-app.nav-link>
                        <x-app.nav-link :href="route('settings.security')" :active="request()->routeIs('settings.security')">
                            <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.746 3.746 0 0 1 21 12Z" /></svg>
                            Sicurezza
                        </x-app.nav-link>

                        @can('unita_organizzativa.view')
                            <x-app.nav-link :href="route('anagrafica.index')" :active="request()->routeIs('anagrafica.*')">
                                <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                                Anagrafica
                            </x-app.nav-link>
                        @endcan
                        @can('strumenti.view')
                            <x-app.nav-link :href="route('strumenti.index')" :active="request()->routeIs('strumenti.*')">
                                <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" /></svg>
                                Strumenti
                            </x-app.nav-link>
                        @endcan

                        <p class="px-3 pt-4 pb-1 text-xs font-semibold tracking-wide text-neutral-400 uppercase">Prossimamente</p>
                        <x-app.nav-link :disabled="true">Interventi</x-app.nav-link>
                    </nav>
                </aside>

                {{-- Colonna contenuto --}}
                <div class="flex min-h-screen flex-1 flex-col">

                    {{-- Top bar (Design System §5.7) --}}
                    <header class="sticky top-0 z-20 flex h-14 items-center justify-between border-b border-neutral-200 bg-white px-4 shadow-sm">
                        <div class="flex items-center gap-2">
                            <button type="button" @click="sidebarOpen = true"
                                    class="-ml-1 flex h-10 w-10 items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-100 md:hidden"
                                    aria-label="Apri menù">
                                <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                            </button>
                            <span class="text-base font-semibold text-neutral-900 md:hidden">Easy Lab</span>
                        </div>

                        <div class="flex items-center gap-2">
                            {{-- Notifiche (placeholder, S5) --}}
                            <button type="button" title="Notifiche — prossimamente"
                                    class="flex h-10 w-10 items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-100">
                                <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
                            </button>

                            {{-- Menù utente --}}
                            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                <button type="button" @click="open = !open"
                                        class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-neutral-100">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-600 text-sm font-semibold text-white">{{ $initials ?: '·' }}</span>
                                    <span class="hidden text-left sm:block">
                                        <span class="block text-sm font-medium text-neutral-900">{{ $user->name }}</span>
                                        @if ($role)
                                            <span class="block text-xs text-neutral-500">{{ $role }}</span>
                                        @endif
                                    </span>
                                    <svg class="h-4 w-4 text-neutral-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                </button>

                                <div x-show="open" x-cloak x-transition
                                     class="absolute right-0 z-30 mt-2 w-56 overflow-hidden rounded-md border border-neutral-200 bg-white shadow-md">
                                    <div class="border-b border-neutral-100 px-4 py-3">
                                        <p class="text-sm font-medium text-neutral-900">{{ $user->name }}</p>
                                        <p class="truncate text-xs text-neutral-500">{{ $user->email }}</p>
                                    </div>
                                    <a href="{{ route('settings.security') }}" class="block px-4 py-2.5 text-sm text-neutral-700 hover:bg-neutral-50">Sicurezza</a>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="block w-full px-4 py-2.5 text-left text-sm text-danger-600 hover:bg-neutral-50">Esci</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </header>

                    <main class="flex-1">
                        {{ $slot }}
                    </main>
                </div>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
