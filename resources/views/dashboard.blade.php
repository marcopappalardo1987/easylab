<x-layouts.app title="Dashboard — Easy Lab">
    <div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">

        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Dashboard</h1>
            <p class="mt-1 text-sm text-neutral-600">Benvenuto in Easy Lab, {{ auth()->user()->name }}.</p>
        </div>

        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
