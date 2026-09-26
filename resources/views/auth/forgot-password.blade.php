<x-guest-layout title="Recupera password — Easy Lab">
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-sm">
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight text-ink">Password dimenticata</h1>
                <p class="mt-1 text-sm text-ink-2">Ti invieremo un link per reimpostarla.</p>
            </div>

            <div class="mt-8 rounded-lg border border-border bg-surface p-6 shadow-sm md:p-8">
                {{-- Esito positivo: coppia `ok-dot`/`ok-soft`/`ok-soft-ink`, la
                     stessa che monta `cabina.blade.php` per un esito riuscito —
                     non un'invenzione locale. --}}
                @if (session('status'))
                    <div class="mb-4 rounded-md border border-ok-dot bg-ok-soft px-3 py-2 text-sm text-ok-soft-ink">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-4 rounded-md border border-bad-dot bg-bad-soft px-3 py-2 text-sm text-bad-soft-ink">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
                    @csrf
                    <x-ui.input label="Email" name="email" type="email" autocomplete="username" required autofocus
                        value="{{ old('email') }}" placeholder="nome@laboratorio.it" />
                    <button type="submit"
                            class="flex w-full items-center justify-center rounded-md bg-brand px-4 py-2.5 font-medium text-brand-ink transition hover:bg-brand-hover focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-canvas focus:outline-none">
                        Invia link di reset
                    </button>
                </form>
            </div>

            <p class="mt-6 text-center text-sm text-ink-2">
                <a href="{{ route('login') }}" class="font-medium text-brand hover:text-brand-hover">&larr; Torna al login</a>
            </p>
        </div>
    </main>
</x-guest-layout>
