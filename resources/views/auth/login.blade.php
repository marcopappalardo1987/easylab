<x-guest-layout title="Accedi — Easy Lab">
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-sm">

            {{-- Il marchio (ADR-033), variante **completa**: qui il claim serve,
                 perché è la prima superficie che vede chi non conosce ancora il
                 prodotto — al contrario della sidebar, dove sarebbe alto due pixel.

                 L'`h1` resta, ma solo per chi legge con uno screen reader: il
                 claim del logo è testo trasformato in tracciati, quindi senza
                 questa riga la pagina non avrebbe alcuna intestazione. --}}
            <div class="flex flex-col items-center text-center">
                <x-brand-logo variante="completo" class="h-16 w-auto" />
                <h1 class="sr-only">Easy Lab — gestione strumentazione e manutenzione</h1>
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
                            <a href="{{ route('password.request') }}" class="text-sm font-medium text-primary-600 hover:text-primary-700">Password dimenticata?</a>
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
