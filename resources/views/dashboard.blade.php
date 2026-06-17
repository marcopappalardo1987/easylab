<x-layouts.app title="Dashboard — Easy Lab">
    {{-- Landing autenticata minima. La shell dashboard reale (top bar, albero, navigazione) è il punto 10. --}}
    <div class="mx-auto w-full max-w-3xl px-4 py-10 sm:px-6">

        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Ciao, {{ auth()->user()->name }}</h1>
                <p class="mt-1 text-sm text-neutral-600">{{ auth()->user()->email }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="inline-flex items-center justify-center rounded-md border border-neutral-200 bg-white px-4 py-2.5 font-medium text-neutral-800 transition hover:bg-neutral-50">
                    Esci
                </button>
            </form>
        </div>

        <div class="mt-8 grid gap-4 sm:grid-cols-2">
            {{-- Sicurezza / 2FA --}}
            <a href="{{ route('settings.security') }}"
               class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm transition hover:border-primary-600">
                <h2 class="font-semibold text-neutral-900">🛡 Sicurezza</h2>
                <p class="mt-1 text-sm text-neutral-600">Gestisci la verifica in due passaggi (2FA).</p>
                <p class="mt-3 text-sm font-medium text-primary-600">Vai alle impostazioni &rarr;</p>
            </a>

            {{-- Placeholder funzionalità future --}}
            <div class="rounded-lg border border-dashed border-neutral-200 bg-neutral-50 p-6">
                <h2 class="font-semibold text-neutral-600">Prossimamente</h2>
                <p class="mt-1 text-sm text-neutral-400">Anagrafica, strumenti e semaforo arriveranno dagli sprint successivi.</p>
            </div>
        </div>
    </div>
</x-layouts.app>
