<x-guest-layout title="Conferma password — Easy Lab">
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-sm">
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight text-ink">Conferma password</h1>
                <p class="mt-1 text-sm text-ink-2">Area protetta: conferma la password per continuare.</p>
            </div>

            <div class="mt-8 rounded-lg border border-border bg-surface p-6 shadow-sm md:p-8">
                @if ($errors->any())
                    <div class="mb-4 rounded-md border border-bad-dot bg-bad-soft px-3 py-2 text-sm text-bad-soft-ink">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-5">
                    @csrf
                    <x-ui.input label="Password" name="password" type="password" autocomplete="current-password" required autofocus />
                    <button type="submit"
                            class="flex w-full items-center justify-center rounded-md bg-brand px-4 py-2.5 font-medium text-brand-ink transition hover:bg-brand-hover focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-canvas focus:outline-none">
                        Conferma
                    </button>
                </form>
            </div>
        </div>
    </main>
</x-guest-layout>
