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
                <x-brand-logo variante="completo" class="h-20 w-auto sm:h-24" />
                <h1 class="sr-only">Easy Lab — gestione strumentazione e manutenzione</h1>
            </div>

            {{-- Card --}}
            <div class="mt-8 rounded-lg border border-border bg-surface p-6 shadow-sm md:p-8">
                <h2 class="text-lg font-semibold text-ink">Accedi al tuo account</h2>
                <p class="mt-1 text-sm text-ink-2">Inserisci le tue credenziali per continuare.</p>

                {{-- Messaggio di stato (es. stub auth). ⚠️ Era `info-500` (alias del
                     brand, DS §2.4) su un'opacità calcolata sul fondo sottostante:
                     il campione tratta l'alert «info» con `--brand-soft` /
                     `--brand-line`, non con un'opacità (trappola nota). --}}
                @if (session('status'))
                    <div class="mt-4 rounded-md border border-brand-line bg-brand-soft px-3 py-2 text-sm text-brand-soft-ink">
                        {{ session('status') }}
                    </div>
                @endif

                {{-- Errori di validazione (operativi da Sprint 4 con Fortify) --}}
                @if ($errors->any())
                    <div class="mt-4 rounded-md border border-bad-dot bg-bad-soft px-3 py-2 text-sm text-bad-soft-ink">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
                    @csrf

                    {{-- Email. ⚠️ Consolidato su `x-ui.input`: il campo era ripetuto
                         a mano due volte in questo file (email e password), con lo
                         stesso markup di `x-ui.input` copiato invece che riusato —
                         da qui in poi la definizione del campo vive in un posto
                         solo (DS §8.2, componente `.el-field`). --}}
                    <x-ui.input label="Email" name="email" type="email" autocomplete="username" required autofocus
                        value="{{ old('email') }}" placeholder="nome@laboratorio.it" />

                    {{-- Password: il link "dimenticata" vive accanto all'etichetta,
                         quindi l'etichetta non passa da `x-ui.input` ma resta qui a
                         fianco del link, e solo il campo usa il componente. --}}
                    <div>
                        <div class="flex items-center justify-between">
                            <label for="password" class="block text-sm font-medium text-ink">Password</label>
                            <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand hover:text-brand-hover">Password dimenticata?</a>
                        </div>
                        <x-ui.input name="password" type="password" autocomplete="current-password" required
                            placeholder="••••••••" rivelabile />
                    </div>

                    {{-- Ricordami --}}
                    <div class="flex items-center">
                        <input id="remember" name="remember" type="checkbox"
                               class="h-4 w-4 rounded border border-border text-brand focus:ring-ring">
                        <label for="remember" class="ml-2 text-sm text-ink-2">Ricordami su questo dispositivo</label>
                    </div>

                    {{-- Submit (touch target ≥ 44px, full-width mobile) --}}
                    <button type="submit"
                            class="flex w-full items-center justify-center rounded-md bg-brand px-4 py-2.5 font-medium text-brand-ink transition hover:bg-brand-hover focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-canvas focus:outline-none">
                        Accedi
                    </button>
                </form>
            </div>

            <p class="mt-6 text-center text-xs text-ink-3">
                &copy; {{ date('Y') }} Easy Lab · Accesso protetto
            </p>
        </div>
    </main>
</x-guest-layout>
