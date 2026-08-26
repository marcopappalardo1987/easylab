<x-guest-layout title="Imposta la password — Easy Lab">
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-sm">
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight text-ink">Benvenuto su Easy Lab</h1>
                <p class="mt-1 text-sm text-ink-2">Scegli la password del tuo account per iniziare.</p>
            </div>

            <div class="mt-8 rounded-lg border border-border bg-surface p-6 shadow-sm md:p-8">
                @if ($errors->any())
                    <div class="mb-4 rounded-md border border-bad-dot bg-bad-soft px-3 py-2 text-sm text-bad-soft-ink">
                        {{ $errors->first() }}
                    </div>
                @endif

                {{-- L'email si mostra ma non è un campo: chi sei lo dice la firma
                     dell'URL, non un input che si potrebbe cambiare. --}}
                <p class="text-sm text-ink-2">Account</p>
                <p class="mt-0.5 truncate font-medium text-ink">{{ $invitato->email }}</p>

                <hr class="my-6 border-border">

                {{-- L'action è la URL corrente, firma compresa: il POST vale solo
                     con la stessa firma che ha aperto questa pagina. --}}
                <form method="POST" action="{{ request()->fullUrl() }}" class="space-y-5">
                    @csrf

                    <x-ui.input label="Password" name="password" type="password" autocomplete="new-password" required autofocus />
                    <x-ui.input label="Conferma password" name="password_confirmation" type="password" autocomplete="new-password" required />

                    <button type="submit"
                            class="flex w-full items-center justify-center rounded-md bg-brand px-4 py-2.5 font-medium text-brand-ink transition hover:bg-brand-hover focus:ring-2 focus:ring-ring focus:outline-none focus:ring-offset-2 focus:ring-offset-canvas">
                        Imposta la password
                    </button>
                </form>
            </div>
        </div>
    </main>
</x-guest-layout>
