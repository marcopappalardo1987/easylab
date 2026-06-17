<x-guest-layout title="Accedi — Easy Lab">
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-sm">

            {{-- Logo / wordmark --}}
            <div class="flex flex-col items-center text-center">
                <span class="flex h-14 w-14 items-center justify-center rounded-lg bg-primary-50 text-primary-600">
                    <svg class="h-8 w-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                         stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" />
                    </svg>
                </span>
                <h1 class="mt-4 text-2xl font-bold tracking-tight text-neutral-900">Easy Lab</h1>
                <p class="mt-1 text-sm text-neutral-600">Gestione manutenzione strumenti di laboratorio</p>
            </div>

            {{-- Card --}}
            <div class="mt-8 rounded-lg border border-neutral-200 bg-white p-6 shadow-sm md:p-8">
                <h2 class="text-lg font-semibold text-neutral-900">Accedi al tuo account</h2>
                <p class="mt-1 text-sm text-neutral-600">Inserisci le tue credenziali per continuare.</p>

                {{-- Messaggio di stato (es. stub auth) --}}
                @if (session('status'))
                    <div class="mt-4 rounded-md border border-info-500/20 bg-info-500/5 px-3 py-2 text-sm text-info-500">
                        {{ session('status') }}
                    </div>
                @endif

                {{-- Errori di validazione (operativi da Sprint 4 con Fortify) --}}
                @if ($errors->any())
                    <div class="mt-4 rounded-md border border-danger-500/20 bg-danger-100 px-3 py-2 text-sm text-danger-600">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
                    @csrf

                    {{-- Email --}}
                    <div>
                        <label for="email" class="block text-sm font-medium text-neutral-800">Email</label>
                        <input id="email" name="email" type="email" autocomplete="username" required autofocus
                               value="{{ old('email') }}"
                               placeholder="nome@laboratorio.it"
                               class="mt-1 block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-neutral-900 placeholder:text-neutral-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none">
                    </div>

                    {{-- Password --}}
                    <div>
                        <div class="flex items-center justify-between">
                            <label for="password" class="block text-sm font-medium text-neutral-800">Password</label>
                            <a href="#" class="text-sm font-medium text-primary-600 hover:text-primary-700">Password dimenticata?</a>
                        </div>
                        <input id="password" name="password" type="password" autocomplete="current-password" required
                               placeholder="••••••••"
                               class="mt-1 block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-neutral-900 placeholder:text-neutral-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none">
                    </div>

                    {{-- Ricordami --}}
                    <div class="flex items-center">
                        <input id="remember" name="remember" type="checkbox"
                               class="h-4 w-4 rounded border-neutral-200 text-primary-600 focus:ring-primary-600">
                        <label for="remember" class="ml-2 text-sm text-neutral-600">Ricordami su questo dispositivo</label>
                    </div>

                    {{-- Submit (touch target ≥ 44px, full-width mobile) --}}
                    <button type="submit"
                            class="flex w-full items-center justify-center rounded-md bg-primary-600 px-4 py-2.5 font-medium text-white transition hover:bg-primary-700 focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 focus:outline-none">
                        Accedi
                    </button>
                </form>
            </div>

            <p class="mt-6 text-center text-xs text-neutral-400">
                &copy; {{ date('Y') }} Easy Lab · Accesso protetto
            </p>
        </div>
    </main>
</x-guest-layout>
