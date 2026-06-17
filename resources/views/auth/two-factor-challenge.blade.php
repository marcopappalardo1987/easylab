<x-guest-layout title="Verifica in due passaggi — Easy Lab">
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-sm" x-data="{ recovery: false }">
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Verifica in due passaggi</h1>
                <p class="mt-1 text-sm text-neutral-600">
                    <span x-show="!recovery">Inserisci il codice generato dalla tua app di autenticazione.</span>
                    <span x-show="recovery" x-cloak>Inserisci uno dei codici di recupero.</span>
                </p>
            </div>

            <div class="mt-8 rounded-lg border border-neutral-200 bg-white p-6 shadow-sm md:p-8">
                @if ($errors->any())
                    <div class="mb-4 rounded-md border border-danger-500/20 bg-danger-100 px-3 py-2 text-sm text-danger-600">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('two-factor.login') }}" class="space-y-5">
                    @csrf

                    {{-- Codice TOTP --}}
                    <div x-show="!recovery">
                        <label for="code" class="block text-sm font-medium text-neutral-800">Codice di autenticazione</label>
                        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                               x-ref="code" placeholder="123456"
                               class="mt-1 block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-center text-lg tracking-widest tabular-nums text-neutral-900 placeholder:text-neutral-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none">
                    </div>

                    {{-- Recovery code --}}
                    <div x-show="recovery" x-cloak>
                        <label for="recovery_code" class="block text-sm font-medium text-neutral-800">Codice di recupero</label>
                        <input id="recovery_code" name="recovery_code" type="text" autocomplete="one-time-code"
                               x-ref="recovery_code" placeholder="xxxxxxxx-xxxxxxxx"
                               class="mt-1 block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-neutral-900 placeholder:text-neutral-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none">
                    </div>

                    <button type="submit"
                            class="flex w-full items-center justify-center rounded-md bg-primary-600 px-4 py-2.5 font-medium text-white transition hover:bg-primary-700 focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 focus:outline-none">
                        Verifica
                    </button>
                </form>

                <button type="button" x-on:click="recovery = !recovery; $nextTick(() => (recovery ? $refs.recovery_code : $refs.code).focus())"
                        class="mt-4 w-full text-sm font-medium text-primary-600 hover:text-primary-700">
                    <span x-show="!recovery">Usa un codice di recupero</span>
                    <span x-show="recovery" x-cloak>Usa il codice dell'app</span>
                </button>
            </div>
        </div>
    </main>
</x-guest-layout>
