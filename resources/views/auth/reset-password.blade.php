<x-guest-layout title="Reimposta password — Easy Lab">
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-sm">
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight text-ink">Reimposta password</h1>
                <p class="mt-1 text-sm text-ink-2">Scegli una nuova password per il tuo account.</p>
            </div>

            <div class="mt-8 rounded-lg border border-border bg-surface p-6 shadow-sm md:p-8">
                @if ($errors->any())
                    <div class="mb-4 rounded-md border border-bad-dot bg-bad-soft px-3 py-2 text-sm text-bad-soft-ink">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
                    @csrf
                    <input type="hidden" name="token" value="{{ $request->route('token') }}">

                    <x-ui.input label="Email" name="email" type="email" autocomplete="username" required autofocus
                        value="{{ old('email', $request->email) }}" />
                    <x-ui.input label="Nuova password" name="password" type="password" autocomplete="new-password" required />
                    <x-ui.input label="Conferma password" name="password_confirmation" type="password" autocomplete="new-password" required />

                    <button type="submit"
                            class="flex w-full items-center justify-center rounded-md bg-brand px-4 py-2.5 font-medium text-brand-ink transition hover:bg-brand-hover focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-canvas focus:outline-none">
                        Reimposta password
                    </button>
                </form>
            </div>
        </div>
    </main>
</x-guest-layout>
