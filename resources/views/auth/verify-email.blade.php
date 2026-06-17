<x-guest-layout title="Verifica email — Easy Lab">
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-sm">
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Verifica la tua email</h1>
                <p class="mt-1 text-sm text-neutral-600">Ti abbiamo inviato un link di verifica. Controlla la posta.</p>
            </div>

            <div class="mt-8 rounded-lg border border-neutral-200 bg-white p-6 shadow-sm md:p-8">
                @if (session('status') == 'verification-link-sent')
                    <div class="mb-4 rounded-md border border-success-500/20 bg-success-100 px-3 py-2 text-sm text-success-600">
                        Un nuovo link di verifica è stato inviato.
                    </div>
                @endif

                <div class="flex flex-col gap-3">
                    <form method="POST" action="{{ route('verification.send') }}">
                        @csrf
                        <button type="submit"
                                class="flex w-full items-center justify-center rounded-md bg-primary-600 px-4 py-2.5 font-medium text-white transition hover:bg-primary-700 focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 focus:outline-none">
                            Reinvia link di verifica
                        </button>
                    </form>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full text-sm font-medium text-neutral-600 hover:text-neutral-800">Esci</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</x-guest-layout>
