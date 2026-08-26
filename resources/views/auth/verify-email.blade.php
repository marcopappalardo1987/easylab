<x-guest-layout title="Verifica email — Easy Lab">
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-sm">
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight text-ink">Verifica la tua email</h1>
                <p class="mt-1 text-sm text-ink-2">Ti abbiamo inviato un link di verifica. Controlla la posta.</p>
            </div>

            <div class="mt-8 rounded-lg border border-border bg-surface p-6 shadow-sm md:p-8">
                @if (session('status') == 'verification-link-sent')
                    <div class="mb-4 rounded-md border border-ok-dot bg-ok-soft px-3 py-2 text-sm text-ok-soft-ink">
                        Un nuovo link di verifica è stato inviato.
                    </div>
                @endif

                <div class="flex flex-col gap-3">
                    <form method="POST" action="{{ route('verification.send') }}">
                        @csrf
                        <button type="submit"
                                class="flex w-full items-center justify-center rounded-md bg-brand px-4 py-2.5 font-medium text-brand-ink transition hover:bg-brand-hover focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-canvas focus:outline-none">
                            Reinvia link di verifica
                        </button>
                    </form>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full text-sm font-medium text-ink-2 hover:text-ink">Esci</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</x-guest-layout>
