<x-guest-layout title="Verifica in due passaggi — Easy Lab">
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-sm" x-data="{ recovery: false }">
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight text-ink">Verifica in due passaggi</h1>
                <p class="mt-1 text-sm text-ink-2">
                    <span x-show="!recovery">Inserisci il codice generato dalla tua app di autenticazione.</span>
                    <span x-show="recovery" x-cloak>Inserisci uno dei codici di recupero.</span>
                </p>
            </div>

            <div class="mt-8 rounded-lg border border-border bg-surface p-6 shadow-sm md:p-8">
                @if ($errors->any())
                    {{-- Il testo d'errore va a `text-bad-soft-ink`, non
                         `text-danger-600`/`-800`: su `--surface` scuro il gradino
                         pieno del rosso è sotto AA (DS §8.2 nota 4). --}}
                    <div class="mb-4 rounded-md border border-bad-dot bg-bad-soft px-3 py-2 text-sm text-bad-soft-ink">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('two-factor.login') }}" class="space-y-5">
                    @csrf

                    {{-- Codice TOTP e codice di recupero: due segreti, nessuna
                         condizione toccata. Consolidati su `x-ui.input` — l'`x-show`
                         resta sul contenitore che avvolge etichetta e campo, così
                         nasconde l'insieme esattamente come faceva prima. --}}
                    <div x-show="!recovery">
                        <label for="code" class="block text-sm font-medium text-ink">Codice di autenticazione</label>
                        <x-ui.input name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                            x-ref="code" placeholder="123456"
                            class="text-center text-lg tracking-widest tabular-nums" />
                    </div>

                    <div x-show="recovery" x-cloak>
                        <label for="recovery_code" class="block text-sm font-medium text-ink">Codice di recupero</label>
                        <x-ui.input name="recovery_code" type="text" autocomplete="one-time-code"
                            x-ref="recovery_code" placeholder="xxxxxxxx-xxxxxxxx" />
                    </div>

                    <button type="submit"
                            class="flex w-full items-center justify-center rounded-md bg-brand px-4 py-2.5 font-medium text-brand-ink transition hover:bg-brand-hover focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-canvas focus:outline-none">
                        Verifica
                    </button>
                </form>

                <button type="button" x-on:click="recovery = !recovery; $nextTick(() => (recovery ? $refs.recovery_code : $refs.code).focus())"
                        class="mt-4 w-full text-sm font-medium text-brand hover:text-brand-hover">
                    <span x-show="!recovery">Usa un codice di recupero</span>
                    <span x-show="recovery" x-cloak>Usa il codice dell'app</span>
                </button>
            </div>
        </div>
    </main>
</x-guest-layout>
